<?php

ini_set('memory_limit', '256M');

$datasetPath = __DIR__ . '/data/mapasahe_graph_v1.json';

if (!file_exists($datasetPath)) {
    die('Dataset file not found: ' . $datasetPath);
}

try {
    $dataset = json_decode(
        file_get_contents($datasetPath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $error) {
    die('Invalid dataset JSON: ' . $error->getMessage());
}

$nodes = $dataset['nodes'] ?? [];
$edges = $dataset['edges'] ?? [];
$routeDefinitions = $dataset['routes'] ?? [];

// Build a fast node lookup table.
$nodesById = [];

foreach ($nodes as $node) {
    $nodeId = $node['node_id'] ?? null;

    if ($nodeId !== null) {
        $nodesById[$nodeId] = $node;
    }
}

// Build the directed adjacency graph.
$graph = [];

$validEdgeCount = 0;
$invalidEdgeCount = 0;
$jeepneyEdgeCount = 0;
$walkingEdgeCount = 0;

foreach ($edges as $edge) {
    $from = $edge['from'] ?? null;
    $to = $edge['to'] ?? null;

    // Reject edges whose endpoints are missing from the node dataset.
    if (
        $from === null ||
        $to === null ||
        !isset($nodesById[$from]) ||
        !isset($nodesById[$to])
    ) {
        $invalidEdgeCount++;
        continue;
    }

    $edgeType = $edge['edge_type'] ?? 'unknown';

    $rideTime = (float) ($edge['ride_time_min'] ?? 0);
    $walkingTime = (float) ($edge['walking_time_min'] ?? 0);
    $transferPenalty = (float) ($edge['transfer_penalty_min'] ?? 0);

    $normalizedEdge = [
        'edge_id' => $edge['edge_id'] ?? null,
        'from' => $from,
        'to' => $to,
        'edge_type' => $edgeType,

        'route_id' => $edge['route_id'] ?? null,
        'route_name' => $edgeType === 'walking_transfer'
            ? 'Walk'
            : ($edge['route_name'] ?? 'Unknown route'),

        'from_route_id' => $edge['from_route_id'] ?? null,
        'to_route_id' => $edge['to_route_id'] ?? null,

        'distance_m' => (float) ($edge['distance_m'] ?? 0),
        'ride_time_min' => $rideTime,
        'walking_time_min' => $walkingTime,
        'transfer_penalty_min' => $transferPenalty,

        // Physical movement time without the transfer penalty.
        'travel_time_min' => $rideTime + $walkingTime,

        // Cost used later by the pathfinding algorithms.
        'cost_min' => (float) (
            $edge['generalized_cost_min']
            ?? ($rideTime + $walkingTime + $transferPenalty)
        )
    ];

    $graph[$from][] = $normalizedEdge;
    $validEdgeCount++;

    if ($edgeType === 'jeepney') {
        $jeepneyEdgeCount++;
    }

    if ($edgeType === 'walking_transfer') {
        $walkingEdgeCount++;
    }
}

function dijkstraV08($graph, $start, $destination)
{
    if ($start === $destination) {
        return [
            'node' => $destination,
            'cost_min' => 0,
            'ride_time_min' => 0,
            'walking_time_min' => 0,
            'transfer_penalty_min' => 0,
            'distance_m' => 0,
            'walking_transfer_count' => 0,
            'nodes_explored' => 0,
            'path' => []
        ];
    }

    /*
     * SplPriorityQueue is a maximum-priority queue.
     * Negative costs make lower-cost paths receive higher priority.
     */
    $queue = new SplPriorityQueue();
    $queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    $queue->insert($start, 0);

    $distances = [
        $start => 0.0
    ];

    $previousEdges = [];
    $visited = [];
    $nodesExplored = 0;

    while (!$queue->isEmpty()) {
        $queueEntry = $queue->extract();
        $currentNode = $queueEntry['data'];

        if (isset($visited[$currentNode])) {
            continue;
        }

        $visited[$currentNode] = true;
        $nodesExplored++;

        if ($currentNode === $destination) {
            break;
        }

        foreach ($graph[$currentNode] ?? [] as $edge) {
            $nextNode = $edge['to'];
            $newCost =
                $distances[$currentNode] +
                (float) $edge['cost_min'];

            if (
                !isset($distances[$nextNode]) ||
                $newCost < $distances[$nextNode]
            ) {
                $distances[$nextNode] = $newCost;
                $previousEdges[$nextNode] = $edge;

                $queue->insert($nextNode, -$newCost);
            }
        }
    }

    if (!isset($distances[$destination])) {
        return null;
    }

    // Reconstruct the path from destination back to origin.
    $reversedPath = [];
    $currentNode = $destination;

    while ($currentNode !== $start) {
        if (!isset($previousEdges[$currentNode])) {
            return null;
        }

        $edge = $previousEdges[$currentNode];
        $reversedPath[] = $edge;
        $currentNode = $edge['from'];
    }

    $path = array_reverse($reversedPath);

    $rideTime = 0;
    $walkingTime = 0;
    $transferPenalty = 0;
    $distance = 0;
    $walkingTransferCount = 0;

    foreach ($path as $edge) {
        $rideTime += $edge['ride_time_min'];
        $walkingTime += $edge['walking_time_min'];
        $transferPenalty += $edge['transfer_penalty_min'];
        $distance += $edge['distance_m'];

        if ($edge['edge_type'] === 'walking_transfer') {
            $walkingTransferCount++;
        }
    }

    return [
        'node' => $destination,
        'cost_min' => $distances[$destination],
        'ride_time_min' => $rideTime,
        'walking_time_min' => $walkingTime,
        'transfer_penalty_min' => $transferPenalty,
        'distance_m' => $distance,
        'walking_transfer_count' => $walkingTransferCount,
        'nodes_explored' => $nodesExplored,
        'path' => $path
    ];
}

function geographicHeuristicMinutesV08(
    $nodesById,
    $fromNode,
    $destinationNode,
    $maximumSpeedKmh = 25.0
) {
    if (
        !isset($nodesById[$fromNode]) ||
        !isset($nodesById[$destinationNode])
    ) {
        return 0.0;
    }

    $from = $nodesById[$fromNode];
    $destination = $nodesById[$destinationNode];

    $latitude1 = deg2rad((float) $from['latitude']);
    $longitude1 = deg2rad((float) $from['longitude']);
    $latitude2 = deg2rad((float) $destination['latitude']);
    $longitude2 = deg2rad((float) $destination['longitude']);

    $latitudeDifference = $latitude2 - $latitude1;
    $longitudeDifference = $longitude2 - $longitude1;

    $a =
        sin($latitudeDifference / 2) ** 2 +
        cos($latitude1) *
        cos($latitude2) *
        sin($longitudeDifference / 2) ** 2;

    $a = min(1.0, max(0.0, $a));

    $earthRadiusMeters = 6371000;
    $distanceMeters =
        $earthRadiusMeters *
        2 *
        atan2(sqrt($a), sqrt(1 - $a));

    $metersPerMinute = ($maximumSpeedKmh * 1000) / 60;

    return $distanceMeters / $metersPerMinute;
}

function buildRouteResultV08(
    $path,
    $destination,
    $nodesExplored
) {
    $cost = 0;
    $rideTime = 0;
    $walkingTime = 0;
    $transferPenalty = 0;
    $distance = 0;
    $walkingTransferCount = 0;

    foreach ($path as $edge) {
        $cost += $edge['cost_min'];
        $rideTime += $edge['ride_time_min'];
        $walkingTime += $edge['walking_time_min'];
        $transferPenalty += $edge['transfer_penalty_min'];
        $distance += $edge['distance_m'];

        if ($edge['edge_type'] === 'walking_transfer') {
            $walkingTransferCount++;
        }
    }

    return [
        'node' => $destination,
        'cost_min' => $cost,
        'ride_time_min' => $rideTime,
        'walking_time_min' => $walkingTime,
        'transfer_penalty_min' => $transferPenalty,
        'distance_m' => $distance,
        'walking_transfer_count' => $walkingTransferCount,
        'nodes_explored' => $nodesExplored,
        'path' => $path
    ];
}

function astarV08(
    $graph,
    $nodesById,
    $start,
    $destination,
    $maximumSpeedKmh = 25.0
) {
    if ($start === $destination) {
        return buildRouteResultV08(
            [],
            $destination,
            0
        );
    }

    $queue = new SplPriorityQueue();
    $queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

    $startingHeuristic = geographicHeuristicMinutesV08(
        $nodesById,
        $start,
        $destination,
        $maximumSpeedKmh
    );

    $queue->insert($start, -$startingHeuristic);

    $gScores = [
        $start => 0.0
    ];

    $previousEdges = [];
    $visited = [];
    $nodesExplored = 0;

    while (!$queue->isEmpty()) {
        $queueEntry = $queue->extract();
        $currentNode = $queueEntry['data'];

        if (isset($visited[$currentNode])) {
            continue;
        }

        $visited[$currentNode] = true;
        $nodesExplored++;

        if ($currentNode === $destination) {
            break;
        }

        foreach ($graph[$currentNode] ?? [] as $edge) {
            $nextNode = $edge['to'];

            $tentativeGScore =
                $gScores[$currentNode] +
                (float) $edge['cost_min'];

            if (
                !isset($gScores[$nextNode]) ||
                $tentativeGScore < $gScores[$nextNode]
            ) {
                $gScores[$nextNode] = $tentativeGScore;
                $previousEdges[$nextNode] = $edge;

                $heuristic = geographicHeuristicMinutesV08(
                    $nodesById,
                    $nextNode,
                    $destination,
                    $maximumSpeedKmh
                );

                $fScore = $tentativeGScore + $heuristic;

                $queue->insert($nextNode, -$fScore);
            }
        }
    }

    if (!isset($gScores[$destination])) {
        return null;
    }

    $reversedPath = [];
    $currentNode = $destination;

    while ($currentNode !== $start) {
        if (!isset($previousEdges[$currentNode])) {
            return null;
        }

        $edge = $previousEdges[$currentNode];
        $reversedPath[] = $edge;
        $currentNode = $edge['from'];
    }

    return buildRouteResultV08(
        array_reverse($reversedPath),
        $destination,
        $nodesExplored
    );
}

function gbfsV08(
    $graph,
    $nodesById,
    $start,
    $destination,
    $maximumSpeedKmh = 25.0
) {
    if ($start === $destination) {
        return buildRouteResultV08(
            [],
            $destination,
            0
        );
    }

    $queue = new SplPriorityQueue();
    $queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

    $startingHeuristic = geographicHeuristicMinutesV08(
        $nodesById,
        $start,
        $destination,
        $maximumSpeedKmh
    );

    $queue->insert($start, -$startingHeuristic);

    $discovered = [
        $start => true
    ];

    $previousEdges = [];
    $nodesExplored = 0;
    $destinationFound = false;

    while (!$queue->isEmpty()) {
        $queueEntry = $queue->extract();
        $currentNode = $queueEntry['data'];
        $nodesExplored++;

        if ($currentNode === $destination) {
            $destinationFound = true;
            break;
        }

        foreach ($graph[$currentNode] ?? [] as $edge) {
            $nextNode = $edge['to'];

            if (isset($discovered[$nextNode])) {
                continue;
            }

            $discovered[$nextNode] = true;
            $previousEdges[$nextNode] = $edge;

            $heuristic = geographicHeuristicMinutesV08(
                $nodesById,
                $nextNode,
                $destination,
                $maximumSpeedKmh
            );

            $queue->insert($nextNode, -$heuristic);
        }
    }

    if (!$destinationFound) {
        return null;
    }

    $reversedPath = [];
    $currentNode = $destination;

    while ($currentNode !== $start) {
        if (!isset($previousEdges[$currentNode])) {
            return null;
        }

        $edge = $previousEdges[$currentNode];
        $reversedPath[] = $edge;
        $currentNode = $edge['from'];
    }

    return buildRouteResultV08(
        array_reverse($reversedPath),
        $destination,
        $nodesExplored
    );
}

function edgeAgreementKeyV08($edge)
{
    if (!empty($edge['edge_id'])) {
        return $edge['edge_id'];
    }

    return
        $edge['from'] . '->' .
        $edge['to'] . '|' .
        $edge['edge_type'] . '|' .
        ($edge['route_id'] ?? 'none');
}

function findAgreedChainsV08(
    $dijkstraPath,
    $astarPath,
    $gbfsPath
) {
    $astarEdges = [];

    foreach ($astarPath as $edge) {
        $astarEdges[edgeAgreementKeyV08($edge)] = true;
    }

    $gbfsEdges = [];

    foreach ($gbfsPath as $edge) {
        $gbfsEdges[edgeAgreementKeyV08($edge)] = true;
    }

    $agreedChains = [];
    $currentChain = [];

    /*
     * Dijkstra supplies the reference order, but an edge is accepted
     * only when the exact same directed edge also occurs in A* and GBFS.
     */
    foreach ($dijkstraPath as $edge) {
        $edgeKey = edgeAgreementKeyV08($edge);

        $isAgreed =
            isset($astarEdges[$edgeKey]) &&
            isset($gbfsEdges[$edgeKey]);

        if ($isAgreed) {
            $currentChain[] = $edge;
        } else {
            if (!empty($currentChain)) {
                $agreedChains[] = $currentChain;
                $currentChain = [];
            }
        }
    }

    if (!empty($currentChain)) {
        $agreedChains[] = $currentChain;
    }

    return $agreedChains;
}

function countAgreedEdgesV08($agreedChains)
{
    $total = 0;

    foreach ($agreedChains as $chain) {
        $total += count($chain);
    }

    return $total;
}

function sliceCandidateSegmentV08(
    $path,
    $fromNode,
    $toNode
) {
    if ($fromNode === $toNode) {
        return [];
    }

    $startIndex = null;

    foreach ($path as $index => $edge) {
        if ($edge['from'] === $fromNode) {
            $startIndex = $index;
            break;
        }
    }

    if ($startIndex === null) {
        return null;
    }

    $segment = [];

    for ($index = $startIndex; $index < count($path); $index++) {
        $segment[] = $path[$index];

        if ($path[$index]['to'] === $toNode) {
            return $segment;
        }
    }

    return null;
}

function segmentCostV08($segment)
{
    $cost = 0;

    foreach ($segment as $edge) {
        $cost += $edge['cost_min'];
    }

    return $cost;
}

function fillHybridGapV08(
    $graph,
    $gap,
    $dijkstraPath,
    $astarPath,
    $gbfsPath
) {
    $candidatePaths = [
        [
            'algorithm' => 'Dijkstra',
            'priority' => 1,
            'path' => $dijkstraPath
        ],
        [
            'algorithm' => 'A*',
            'priority' => 2,
            'path' => $astarPath
        ],
        [
            'algorithm' => 'GBFS',
            'priority' => 3,
            'path' => $gbfsPath
        ]
    ];

    $eligibleCandidates = [];

    foreach ($candidatePaths as $candidate) {
        $segment = sliceCandidateSegmentV08(
            $candidate['path'],
            $gap['from'],
            $gap['to']
        );

        if ($segment === null) {
            continue;
        }

        $eligibleCandidates[] = [
            'algorithm' => $candidate['algorithm'],
            'priority' => $candidate['priority'],
            'segment' => $segment,
            'cost_min' => segmentCostV08($segment)
        ];
    }

    /*
     * Use direct Dijkstra only when none of the three original
     * candidate paths can connect the required gap boundaries.
     */
    if (empty($eligibleCandidates)) {
        $fallback = dijkstraV08(
            $graph,
            $gap['from'],
            $gap['to']
        );

        if ($fallback === null) {
            return null;
        }

        return [
            'segment' => $fallback['path'],
            'filled_by' => 'Fallback Dijkstra',
            'cost_min' => $fallback['cost_min'],
            'used_fallback' => true
        ];
    }

    usort(
        $eligibleCandidates,
        function ($candidateA, $candidateB) {
            $difference =
                $candidateA['cost_min'] -
                $candidateB['cost_min'];

            if (abs($difference) > 0.000000001) {
                return $difference < 0 ? -1 : 1;
            }

            return
                $candidateA['priority'] <=>
                $candidateB['priority'];
        }
    );

    $winner = $eligibleCandidates[0];

    return [
        'segment' => $winner['segment'],
        'filled_by' => $winner['algorithm'],
        'cost_min' => $winner['cost_min'],
        'used_fallback' => false
    ];
}

function synthesizeHybridRouteV08(
    $graph,
    $dijkstraRoute,
    $astarRoute,
    $gbfsRoute,
    $origin,
    $destination
) {
    if (
        $dijkstraRoute === null ||
        $astarRoute === null ||
        $gbfsRoute === null
    ) {
        return null;
    }

    $agreedChains = findAgreedChainsV08(
        $dijkstraRoute['path'],
        $astarRoute['path'],
        $gbfsRoute['path']
    );

    $stitchedPath = [];
    $segmentLog = [];

    $cursor = $origin;
    $chainIndex = 0;
    $gapEdgeCount = 0;
    $fallbackCount = 0;

    while (true) {
        $hasNextChain = isset($agreedChains[$chainIndex]);

        $nextBoundary = $hasNextChain
            ? $agreedChains[$chainIndex][0]['from']
            : $destination;

        if ($cursor !== $nextBoundary) {
            $gap = [
                'from' => $cursor,
                'to' => $nextBoundary
            ];

            $filledGap = fillHybridGapV08(
                $graph,
                $gap,
                $dijkstraRoute['path'],
                $astarRoute['path'],
                $gbfsRoute['path']
            );

            if ($filledGap === null) {
                return null;
            }

            foreach ($filledGap['segment'] as $edge) {
                $stitchedPath[] = $edge;
            }

            $gapEdgeCount += count($filledGap['segment']);

            if ($filledGap['used_fallback']) {
                $fallbackCount++;
            }

            $segmentLog[] = [
                'type' => 'gap',
                'from' => $gap['from'],
                'to' => $gap['to'],
                'edge_count' => count($filledGap['segment']),
                'filled_by' => $filledGap['filled_by'],
                'cost_min' => $filledGap['cost_min']
            ];
        }

        if (!$hasNextChain) {
            break;
        }

        $chain = $agreedChains[$chainIndex];

        foreach ($chain as $edge) {
            $stitchedPath[] = $edge;
        }

        $chainEnd = $chain[count($chain) - 1]['to'];

        $segmentLog[] = [
            'type' => 'agreed',
            'from' => $chain[0]['from'],
            'to' => $chainEnd,
            'edge_count' => count($chain),
            'filled_by' => 'All three algorithms',
            'cost_min' => segmentCostV08($chain)
        ];

        $cursor = $chainEnd;
        $chainIndex++;
    }

    if (empty($stitchedPath)) {
        return null;
    }

    // Validate the origin and destination.
    if ($stitchedPath[0]['from'] !== $origin) {
        return null;
    }

    $lastEdge = $stitchedPath[count($stitchedPath) - 1];

    if ($lastEdge['to'] !== $destination) {
        return null;
    }

    // Validate every connection in the stitched route.
    for ($index = 1; $index < count($stitchedPath); $index++) {
        $previousEdge = $stitchedPath[$index - 1];
        $currentEdge = $stitchedPath[$index];

        if ($previousEdge['to'] !== $currentEdge['from']) {
            return null;
        }
    }

    $candidateNodesExplored =
        $dijkstraRoute['nodes_explored'] +
        $astarRoute['nodes_explored'] +
        $gbfsRoute['nodes_explored'];

    $hybridRoute = buildRouteResultV08(
        $stitchedPath,
        $destination,
        $candidateNodesExplored
    );

    $hybridRoute['agreed_edge_count'] =
        countAgreedEdgesV08($agreedChains);

    $hybridRoute['gap_edge_count'] = $gapEdgeCount;
    $hybridRoute['fallback_count'] = $fallbackCount;
    $hybridRoute['segment_log'] = $segmentLog;
    $hybridRoute['continuity_valid'] = true;

    return $hybridRoute;
}

function coordinateDistanceMetersV08(
    $latitude1,
    $longitude1,
    $latitude2,
    $longitude2
) {
    $earthRadiusMeters = 6371000;

    $latitude1 = deg2rad((float) $latitude1);
    $longitude1 = deg2rad((float) $longitude1);
    $latitude2 = deg2rad((float) $latitude2);
    $longitude2 = deg2rad((float) $longitude2);

    $latitudeDifference = $latitude2 - $latitude1;
    $longitudeDifference = $longitude2 - $longitude1;

    $a =
        sin($latitudeDifference / 2) ** 2 +
        cos($latitude1) *
        cos($latitude2) *
        sin($longitudeDifference / 2) ** 2;

    $a = min(1.0, max(0.0, $a));

    return
        $earthRadiusMeters *
        2 *
        atan2(sqrt($a), sqrt(1 - $a));
}

function findNearestGraphNodeV08(
    $nodesById,
    $graph,
    $selectedLatitude,
    $selectedLongitude,
    $requireOutgoingEdge = false
) {
    $nearestNode = null;
    $nearestDistance = INF;

    foreach ($nodesById as $nodeId => $node) {
        /*
         * An origin needs at least one outgoing edge.
         * A destination is allowed to be a terminal node.
         */
        if (
            $requireOutgoingEdge &&
            empty($graph[$nodeId])
        ) {
            continue;
        }

        $distance = coordinateDistanceMetersV08(
            $selectedLatitude,
            $selectedLongitude,
            $node['latitude'],
            $node['longitude']
        );

        if ($distance < $nearestDistance) {
            $nearestDistance = $distance;
            $nearestNode = $node;
        }
    }

    if ($nearestNode === null) {
        return null;
    }

    $nearestNode['snap_distance_m'] = $nearestDistance;

    return $nearestNode;
}

// ============================================================
// VERSION 0.8 USER INTERFACE
// ============================================================

function eV08($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

$originLatitudeValue =
    $_POST['origin_latitude'] ?? '14.65920';

$originLongitudeValue =
    $_POST['origin_longitude'] ?? '121.06094';

$destinationLatitudeValue =
    $_POST['destination_latitude'] ?? '14.65571';

$destinationLongitudeValue =
    $_POST['destination_longitude'] ?? '121.05275';

$uiErrorV08 = null;
$snappedOriginV08 = null;
$snappedDestinationV08 = null;
$routeResultsV08 = [];

$maximumSpeedKmh = (float) (
    $dataset['metadata']['assumptions']['jeep_speed_kmh']
    ?? 25.0
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !is_numeric($originLatitudeValue) ||
        !is_numeric($originLongitudeValue) ||
        !is_numeric($destinationLatitudeValue) ||
        !is_numeric($destinationLongitudeValue)
    ) {
        $uiErrorV08 = 'Please provide valid map coordinates.';
    } else {
        $snappedOriginV08 = findNearestGraphNodeV08(
            $nodesById,
            $graph,
            (float) $originLatitudeValue,
            (float) $originLongitudeValue,
            true
        );

        $snappedDestinationV08 = findNearestGraphNodeV08(
            $nodesById,
            $graph,
            (float) $destinationLatitudeValue,
            (float) $destinationLongitudeValue,
            false
        );

        if (
            $snappedOriginV08 === null ||
            $snappedDestinationV08 === null
        ) {
            $uiErrorV08 =
                'The selected locations could not be matched to the route network.';
        } else {
            $originNodeV08 = $snappedOriginV08['node_id'];
            $destinationNodeV08 =
                $snappedDestinationV08['node_id'];

            if ($originNodeV08 === $destinationNodeV08) {
                $uiErrorV08 =
                    'Please select two different locations.';
            } else {
                $dijkstraResultV08 = dijkstraV08(
                    $graph,
                    $originNodeV08,
                    $destinationNodeV08
                );

                $astarResultV08 = astarV08(
                    $graph,
                    $nodesById,
                    $originNodeV08,
                    $destinationNodeV08,
                    $maximumSpeedKmh
                );

                $gbfsResultV08 = gbfsV08(
                    $graph,
                    $nodesById,
                    $originNodeV08,
                    $destinationNodeV08,
                    $maximumSpeedKmh
                );

                $hybridResultV08 = synthesizeHybridRouteV08(
                    $graph,
                    $dijkstraResultV08,
                    $astarResultV08,
                    $gbfsResultV08,
                    $originNodeV08,
                    $destinationNodeV08
                );

                $routeResultsV08 = [
                    'Dijkstra' => $dijkstraResultV08,
                    'A*' => $astarResultV08,
                    'GBFS' => $gbfsResultV08,
                    'Hybrid' => $hybridResultV08
                ];
            }
        }
    }
}

$routeColorsV08 = [
    'Dijkstra' => '#0047AB',
    'A*' => '#1E90FF',
    'GBFS' => '#00BFFF',
    'Hybrid' => '#FF7518'
];

function buildMapSegmentsV08(
    $route,
    $nodesById,
    $isHybrid = false
) {
    if ($route === null || empty($route['path'])) {
        return [];
    }

    $hybridTags = [];

    if (
        $isHybrid &&
        !empty($route['segment_log'])
    ) {
        foreach ($route['segment_log'] as $segment) {
            for (
                $index = 0;
                $index < $segment['edge_count'];
                $index++
            ) {
                $hybridTags[] = [
                    'type' => $segment['type'],
                    'filled_by' => $segment['filled_by']
                ];
            }
        }
    }

    $segments = [];

    foreach ($route['path'] as $index => $edge) {
        if (
            !isset($nodesById[$edge['from']]) ||
            !isset($nodesById[$edge['to']])
        ) {
            continue;
        }

        $fromNode = $nodesById[$edge['from']];
        $toNode = $nodesById[$edge['to']];

        $hybridTag = $hybridTags[$index] ?? null;

        $segments[] = [
            'points' => [
                [
                    (float) $fromNode['latitude'],
                    (float) $fromNode['longitude']
                ],
                [
                    (float) $toNode['latitude'],
                    (float) $toNode['longitude']
                ]
            ],
            'from' => $edge['from'],
            'to' => $edge['to'],
            'edge_type' => $edge['edge_type'],
            'route_name' => $edge['route_name'],
            'distance_m' => $edge['distance_m'],
            'synthesis_type' =>
                $hybridTag['type'] ?? null,
            'filled_by' =>
                $hybridTag['filled_by'] ?? null
        ];
    }

    return $segments;
}

$mapRoutesV08 = [];

foreach (
    $routeResultsV08 as
    $routeLabelV08 => $routeResultV08
) {
    if ($routeResultV08 === null) {
        continue;
    }

    $mapRoutesV08[$routeLabelV08] = [
        'color' => $routeColorsV08[$routeLabelV08],
        'segments' => buildMapSegmentsV08(
            $routeResultV08,
            $nodesById,
            $routeLabelV08 === 'Hybrid'
        )
    ];
}

$minimumLatitudeV08 = INF;
$maximumLatitudeV08 = -INF;
$minimumLongitudeV08 = INF;
$maximumLongitudeV08 = -INF;

foreach ($nodesById as $nodeV08) {
    $latitudeV08 = (float) $nodeV08['latitude'];
    $longitudeV08 = (float) $nodeV08['longitude'];

    $minimumLatitudeV08 = min(
        $minimumLatitudeV08,
        $latitudeV08
    );

    $maximumLatitudeV08 = max(
        $maximumLatitudeV08,
        $latitudeV08
    );

    $minimumLongitudeV08 = min(
        $minimumLongitudeV08,
        $longitudeV08
    );

    $maximumLongitudeV08 = max(
        $maximumLongitudeV08,
        $longitudeV08
    );
}

$datasetBoundsV08 = [
    [
        $minimumLatitudeV08,
        $minimumLongitudeV08
    ],
    [
        $maximumLatitudeV08,
        $maximumLongitudeV08
    ]
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>MapaSahe — Jeepney Route Planner</title>

    <link rel="stylesheet" href="style.css">

    <link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
>

<script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
    crossorigin=""
></script>

    <style>
        .coordinate-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .coordinate-input {
            width: 100%;
            padding: 10px 11px;
            font-size: 13px;
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-main);
            outline: none;
        }

        .coordinate-input:focus {
            border-color: var(--blue-secondary);
            box-shadow: 0 0 0 3px rgba(30,144,255,0.15);
        }

        .location-summary {
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 12px;
            background: #F8FAFC;
            font-size: 11px;
            line-height: 1.6;
        }

        .location-summary strong {
            color: var(--blue-dark);
        }

        .interface-placeholder {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            background:
                radial-gradient(
                    circle at center,
                    #E8F0FC,
                    #C8D8EB
                );
        }

        .placeholder-card {
            max-width: 540px;
            padding: 30px;
            background: rgba(255,255,255,0.94);
            border: 1.5px solid var(--border-color);
            border-radius: 14px;
            text-align: center;
            box-shadow: 0 8px 30px rgba(0,36,86,0.12);
        }

        .placeholder-card h2 {
            color: var(--blue-dark);
            margin-bottom: 10px;
        }

        .placeholder-card p {
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 8px;
        }

        .dataset-stat {
            display: inline-block;
            padding: 5px 10px;
            margin: 4px;
            background: var(--blue-light);
            color: var(--blue-dark);
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
    </style>
</head>

<body>

<header class="main-header">
    <div>
        <h1>MapaSahe Route Planner</h1>
        <p>
            Cooperative Hybrid Graph-Based Jeepney Route Planning
        </p>
    </div>
</header>

<div class="layout">

    <div class="left-panel">
        <div class="sheet-handle">
            <div class="sheet-handle-bar"></div>
        </div>

        <div class="panel-header">
            <h2>Plan your commute</h2>
            <p>
                Enter map coordinates to generate candidate routes.
            </p>
        </div>

        <div class="panel-body">

            <div class="status-strip <?= !empty($routeResultsV08) ? 'ok' : '' ?>">
                <div class="pulse-dot"></div>

                <span>
                    <?php if (!empty($routeResultsV08)): ?>
                        Route network matched successfully.
                    <?php else: ?>
                        Waiting for origin and destination.
                    <?php endif; ?>
                </span>
            </div>

            <form method="POST">

                <div class="form-group">
                    <label>Origin coordinates</label>

                    <div class="coordinate-grid">
                        <input
                            class="coordinate-input"
                            type="number"
                            step="any"
                            name="origin_latitude"
                            aria-label="Origin latitude"
                            value="<?= eV08($originLatitudeValue) ?>"
                            required
                        >

                        <input
                            class="coordinate-input"
                            type="number"
                            step="any"
                            name="origin_longitude"
                            aria-label="Origin longitude"
                            value="<?= eV08($originLongitudeValue) ?>"
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label>Destination coordinates</label>

                    <div class="coordinate-grid">
                        <input
                            class="coordinate-input"
                            type="number"
                            step="any"
                            name="destination_latitude"
                            aria-label="Destination latitude"
                            value="<?= eV08($destinationLatitudeValue) ?>"
                            required
                        >

                        <input
                            class="coordinate-input"
                            type="number"
                            step="any"
                            name="destination_longitude"
                            aria-label="Destination longitude"
                            value="<?= eV08($destinationLongitudeValue) ?>"
                            required
                        >
                    </div>
                </div>

                <button type="submit">
                    Generate Routes
                </button>
            </form>

            <hr class="section-divider">

            <?php if ($uiErrorV08 !== null): ?>
                <div class="error-card">
                    <?= eV08($uiErrorV08) ?>
                </div>
            <?php endif; ?>

            <?php if (
                $snappedOriginV08 !== null &&
                $snappedDestinationV08 !== null
            ): ?>
                <div class="location-summary">
                    <strong>Origin:</strong>
                    <?= eV08($snappedOriginV08['route_name']) ?>
                    <br>
                    <?= eV08($snappedOriginV08['node_id']) ?>
                    —
                    <?= number_format(
                        $snappedOriginV08['snap_distance_m'],
                        2
                    ) ?>
                    m from selection

                    <br><br>

                    <strong>Destination:</strong>
                    <?= eV08($snappedDestinationV08['route_name']) ?>
                    <br>
                    <?= eV08($snappedDestinationV08['node_id']) ?>
                    —
                    <?= number_format(
                        $snappedDestinationV08['snap_distance_m'],
                        2
                    ) ?>
                    m from selection
                </div>
            <?php endif; ?>

            <?php if (!empty($routeResultsV08)): ?>

                <p class="results-hint">
                    Candidate routes generated from the same selected locations.
                </p>

                <?php
                $routeIndexV08 = 0;

                foreach (
                    $routeResultsV08 as
                    $routeLabelV08 => $routeResultV08
                ):
                    $routeColorV08 =
                        $routeColorsV08[$routeLabelV08];

                    $isActiveV08 = $routeIndexV08 === 0;
                ?>

                    <div
                      class="result-card <?= $isActiveV08 ? 'active' : '' ?>"
                        data-route="<?= eV08($routeLabelV08) ?>"
                        style="--route-color: <?= eV08($routeColorV08) ?>;"
                    >

                        <div class="card-head">
                            <div
                                class="card-label"
                                style="color: <?= eV08($routeColorV08) ?>;"
                            >
                                <span
                                    class="color-dot"
                                    style="background: <?= eV08($routeColorV08) ?>;"
                                ></span>

                                <?= eV08($routeLabelV08) ?>
                            </div>

                            <?php if ($routeResultV08 !== null): ?>
                                <div class="card-stats-right">
                                    <span class="big-stat">
                                        <?= number_format(
                                            $routeResultV08['cost_min'],
                                            2
                                        ) ?>
                                    </span>

                                    <span class="arrive-time">
                                        generalized minutes
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($routeResultV08 === null): ?>
                            <span class="no-route">
                                No path found
                            </span>
                        <?php else: ?>

                            <div class="stat-row">
                                <span class="stat-chip">
                                    <?= count($routeResultV08['path']) ?>
                                    edges
                                </span>

                                <span class="stat-chip">
                                    <?= number_format(
                                        $routeResultV08['ride_time_min'],
                                        2
                                    ) ?>
                                    min riding
                                </span>

                                <span class="stat-chip">
                                    <?= number_format(
                                        $routeResultV08['walking_time_min'],
                                        2
                                    ) ?>
                                    min walking
                                </span>

                                <span class="stat-chip">
                                    <?= eV08(
                                        $routeResultV08[
                                            'walking_transfer_count'
                                        ]
                                    ) ?>
                                    transfers
                                </span>
                            </div>

                            <div class="card-steps">
                                <div class="timeline-step">
                                    <div
                                        class="timeline-dot"
                                        style="background: <?= eV08($routeColorV08) ?>;"
                                    ></div>

                                    <div class="timeline-content">
                                        <div class="step-route">
                                            Search performance
                                        </div>

                                        <div class="step-detail">
                                            Explored
                                            <?= eV08(
                                                $routeResultV08[
                                                    'nodes_explored'
                                                ]
                                            ) ?>
                                            candidate nodes.
                                        </div>
                                    </div>
                                </div>

                                <?php if (
                                    $routeLabelV08 === 'Hybrid'
                                ): ?>
                                    <div class="timeline-step">
                                        <div
                                            class="timeline-dot"
                                            style="background: <?= eV08($routeColorV08) ?>;"
                                        ></div>

                                        <div class="timeline-content">
                                            <div class="step-route">
                                                Cooperative synthesis
                                            </div>

                                            <div class="step-detail">
                                                <?= eV08(
                                                    $routeResultV08[
                                                        'agreed_edge_count'
                                                    ]
                                                ) ?>
                                                agreed edges and

                                                <?= eV08(
                                                    $routeResultV08[
                                                        'gap_edge_count'
                                                    ]
                                                ) ?>
                                                gap-filling edges.
                                            </div>

                                            <div class="step-meta">
                                                Fallback searches:
                                                <?= eV08(
                                                    $routeResultV08[
                                                        'fallback_count'
                                                    ]
                                                ) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                        <?php endif; ?>
                    </div>

                <?php
                    $routeIndexV08++;
                endforeach;
                ?>

            <?php endif; ?>

        </div>
    </div>

    <div class="right-map">
    <div id="map"></div>

    <div class="map-legend">
        <strong>Map selection</strong>

        <div
            id="map-selection-status"
            class="legend-hint"
        >
            Click the map to choose your origin.
        </div>

        <?php foreach (
            $routeColorsV08 as
            $legendLabelV08 => $legendColorV08
        ): ?>
            <div
                class="legend-item"
                data-route="<?= eV08($legendLabelV08) ?>"
            >
                <span
                    class="legend-swatch"
                    style="background: <?= eV08($legendColorV08) ?>;"
                ></span>

                <?= eV08($legendLabelV08) ?>
            </div>
        <?php endforeach; ?>

        <div class="legend-hint">
    Dijkstra: solid · A*: dashed · GBFS: dotted.<br>
    Hybrid: solid = agreed; dashed = gap-filled.<br>
    Purple circles and purple dotted lines indicate walking transfers.
</div>
    </div>
</div>

<script>
const routeDataV08 =
    <?= json_encode(
        $mapRoutesV08,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

const datasetBoundsV08 =
    <?= json_encode($datasetBoundsV08) ?>;

const mapV08 = L.map('map');

L.tileLayer(
    'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    {
        maxZoom: 19,
        attribution:
            '&copy; OpenStreetMap contributors'
    }
).addTo(mapV08);

if (
    Array.isArray(datasetBoundsV08) &&
    datasetBoundsV08.length === 2
) {
    mapV08.fitBounds(
        datasetBoundsV08,
        {
            padding: [25, 25]
        }
    );
} else {
    mapV08.setView(
        [14.5995, 121.0369],
        12
    );
}

const originLatitudeInputV08 =
    document.querySelector(
        '[name="origin_latitude"]'
    );

const originLongitudeInputV08 =
    document.querySelector(
        '[name="origin_longitude"]'
    );

const destinationLatitudeInputV08 =
    document.querySelector(
        '[name="destination_latitude"]'
    );

const destinationLongitudeInputV08 =
    document.querySelector(
        '[name="destination_longitude"]'
    );

const selectionStatusV08 =
    document.getElementById(
        'map-selection-status'
    );

let selectionStepV08 = 0;
let originMarkerV08 = null;
let destinationMarkerV08 = null;

function placeOriginMarkerV08(latitude, longitude) {
    if (originMarkerV08 !== null) {
        mapV08.removeLayer(originMarkerV08);
    }

    originMarkerV08 = L.marker(
        [latitude, longitude]
    )
        .addTo(mapV08)
        .bindPopup('Selected origin');
}

function placeDestinationMarkerV08(
    latitude,
    longitude
) {
    if (destinationMarkerV08 !== null) {
        mapV08.removeLayer(destinationMarkerV08);
    }

    destinationMarkerV08 = L.marker(
        [latitude, longitude]
    )
        .addTo(mapV08)
        .bindPopup('Selected destination');
}

const initialOriginLatitudeV08 =
    parseFloat(originLatitudeInputV08.value);

const initialOriginLongitudeV08 =
    parseFloat(originLongitudeInputV08.value);

const initialDestinationLatitudeV08 =
    parseFloat(destinationLatitudeInputV08.value);

const initialDestinationLongitudeV08 =
    parseFloat(destinationLongitudeInputV08.value);

if (
    Number.isFinite(initialOriginLatitudeV08) &&
    Number.isFinite(initialOriginLongitudeV08)
) {
    placeOriginMarkerV08(
        initialOriginLatitudeV08,
        initialOriginLongitudeV08
    );
}

if (
    Number.isFinite(initialDestinationLatitudeV08) &&
    Number.isFinite(initialDestinationLongitudeV08)
) {
    placeDestinationMarkerV08(
        initialDestinationLatitudeV08,
        initialDestinationLongitudeV08
    );
}

mapV08.on('click', function (event) {
    const latitude =
        event.latlng.lat.toFixed(6);

    const longitude =
        event.latlng.lng.toFixed(6);

    if (selectionStepV08 === 0) {
        originLatitudeInputV08.value = latitude;
        originLongitudeInputV08.value = longitude;

        placeOriginMarkerV08(
            latitude,
            longitude
        );

        selectionStepV08 = 1;

        selectionStatusV08.textContent =
            'Origin selected. Click the map for the destination.';
    } else {
        destinationLatitudeInputV08.value = latitude;
        destinationLongitudeInputV08.value = longitude;

        placeDestinationMarkerV08(
            latitude,
            longitude
        );

        selectionStepV08 = 0;

        selectionStatusV08.textContent =
            'Destination selected. Press Generate Routes.';
    }
});

const routeLayersV08 = {};
const routeTransferMarkersV08 = {};

mapV08.createPane('transferPaneV08');
mapV08.getPane('transferPaneV08').style.zIndex = 650;

Object.entries(routeDataV08).forEach(
    function ([routeLabel, routeInformation]) {
        const layerGroup = L.layerGroup();

        routeInformation.segments.forEach(
            function (segment) {
                const isWalking =
    segment.edge_type ===
    'walking_transfer';

let dashPatternV08 = null;
let lineWeightV08 = 6;

if (isWalking) {
    dashPatternV08 = '4, 12';
    lineWeightV08 = 6;
} else if (routeLabel === 'A*') {
    dashPatternV08 = '24, 14';
} else if (routeLabel === 'GBFS') {
    dashPatternV08 = '2, 14';
} else if (routeLabel === 'Hybrid') {
    if (segment.synthesis_type === 'agreed') {
        dashPatternV08 = null;
        lineWeightV08 = 8;
    } else if (
        segment.filled_by ===
        'fallback_direct_search'
    ) {
        dashPatternV08 = '2, 14';
    } else {
        dashPatternV08 = '16, 10';
    }
}

const line = L.polyline(
    segment.points,
    {
        color: isWalking
            ? '#6f42c1'
            : routeInformation.color,
        weight: lineWeightV08,
        opacity: 0.22,
        dashArray: dashPatternV08,
        lineCap: 'round'
    }
);

                let tooltip =
                    segment.route_name +
                    ': ' +
                    segment.from +
                    ' → ' +
                    segment.to;

                if (isWalking) {
                    tooltip +=
                        ' — walk ' +
                        Number(
                            segment.distance_m
                        ).toFixed(1) +
                        ' m';
                }

                if (
                    routeLabel === 'Hybrid' &&
                    segment.synthesis_type
                ) {
                    tooltip +=
                        ' — ' +
                        segment.synthesis_type;

                    if (segment.filled_by) {
                        tooltip +=
                            ' (' +
                            segment.filled_by +
                            ')';
                    }
                }

                line.bindTooltip(tooltip);
                line.addTo(layerGroup);
            }
        );

        const transferMarkersV08 = [];
const seenTransfersV08 = new Set();

routeInformation.segments.forEach(
    function (segment, segmentIndex) {
        const previousSegment =
            segmentIndex > 0
                ? routeInformation.segments[
                    segmentIndex - 1
                ]
                : null;

        const nextSegment =
            segmentIndex <
            routeInformation.segments.length - 1
                ? routeInformation.segments[
                    segmentIndex + 1
                ]
                : null;

        const isWalkingTransfer =
            segment.edge_type ===
            'walking_transfer';

        let transferPointV08 = null;
        let transferMessageV08 = null;
        let transferColorV08 =
            routeInformation.color;

        if (isWalkingTransfer) {
            const startPointV08 =
                segment.points[0];

            const endPointV08 =
                segment.points[
                    segment.points.length - 1
                ];

            transferPointV08 = [
                (
                    startPointV08[0] +
                    endPointV08[0]
                ) / 2,
                (
                    startPointV08[1] +
                    endPointV08[1]
                ) / 2
            ];

            const previousRouteV08 =
                previousSegment &&
                previousSegment.route_name
                    ? previousSegment.route_name
                    : 'previous jeepney route';

            const nextRouteV08 =
                nextSegment &&
                nextSegment.route_name
                    ? nextSegment.route_name
                    : 'next jeepney route';

            transferMessageV08 =
                'Walking transfer: ' +
                previousRouteV08 +
                ' → ' +
                nextRouteV08 +
                ' (' +
                Number(
                    segment.distance_m
                ).toFixed(1) +
                ' m)';

            transferColorV08 = '#6f42c1';
        } else if (
            previousSegment &&
            previousSegment.edge_type !==
                'walking_transfer' &&
            previousSegment.route_name !==
                segment.route_name
        ) {
            transferPointV08 =
                segment.points[0];

            transferMessageV08 =
                'Jeepney transfer: ' +
                previousSegment.route_name +
                ' → ' +
                segment.route_name;
        }

        if (
            transferPointV08 === null ||
            transferMessageV08 === null
        ) {
            return;
        }

        const transferKeyV08 =
            transferPointV08[0].toFixed(6) +
            ',' +
            transferPointV08[1].toFixed(6);

        if (
            seenTransfersV08.has(
                transferKeyV08
            )
        ) {
            return;
        }

        seenTransfersV08.add(
            transferKeyV08
        );

        const transferMarkerV08 =
            L.circleMarker(
                transferPointV08,
                {
                    pane: 'transferPaneV08',
                    radius: 10,
                    color: '#ffffff',
                    weight: 4,
                    fillColor:
                        transferColorV08,
                    opacity: 0,
                    fillOpacity: 0,
                    isTransferMarkerV08: true
                }
            )
            .bindTooltip(
                transferMessageV08
            )
            .bindPopup(
                transferMessageV08
            )
            .addTo(layerGroup);

        transferMarkersV08.push(
            transferMarkerV08
        );
    }
);

routeTransferMarkersV08[routeLabel] =
    transferMarkersV08;

        layerGroup.addTo(mapV08);
        routeLayersV08[routeLabel] = layerGroup;
    }
);

function selectRouteV08(routeLabel) {
    Object.entries(routeLayersV08).forEach(
        function ([label, layerGroup]) {
            layerGroup.eachLayer(
    function (layer) {
        const isSelectedV08 =
            label === routeLabel;

        if (
            layer.options &&
            layer.options
                .isTransferMarkerV08
        ) {
            layer.setStyle({
                opacity:
                    isSelectedV08
                        ? 1
                        : 0,
                fillOpacity:
                    isSelectedV08
                        ? 0.95
                        : 0,
                weight: 4
            });

            if (isSelectedV08) {
                layer.bringToFront();
            }

            return;
        }

        layer.setStyle({
            opacity:
                isSelectedV08
                    ? 0.95
                    : 0.05,
            weight:
                isSelectedV08
                    ? (
                        layer.options
                            .dashArray
                            ? 6
                            : 7
                    )
                    : 3
        });
    }
);
        }
    );

    document.querySelectorAll(
        '.result-card'
    ).forEach(function (card) {
        const isSelected =
            card.dataset.route === routeLabel;

        card.classList.toggle(
            'active',
            isSelected
        );

        card.classList.toggle(
            'dimmed',
            !isSelected
        );
    });

    document.querySelectorAll(
        '.legend-item[data-route]'
    ).forEach(function (item) {
        const isSelected =
            item.dataset.route === routeLabel;

        item.classList.toggle(
            'active',
            isSelected
        );

        item.classList.toggle(
            'dimmed',
            !isSelected
        );
    });

    const selectedLayer =
        routeLayersV08[routeLabel];

    if (selectedLayer) {
        const selectedBounds =
            selectedLayer.getBounds();

        if (selectedBounds.isValid()) {
            mapV08.fitBounds(
                selectedBounds,
                {
                    padding: [30, 30]
                }
            );
        }
    }
}

document.querySelectorAll(
    '.result-card[data-route]'
).forEach(function (card) {
    card.addEventListener(
        'click',
        function () {
            selectRouteV08(
                card.dataset.route
            );
        }
    );
});

const availableRouteLabelsV08 =
    Object.keys(routeLayersV08);

if (availableRouteLabelsV08.length > 0) {
    selectRouteV08(
        availableRouteLabelsV08[0]
    );
}
</script>

</body>
</html>
<?php

exit;