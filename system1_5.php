<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/search_logger.php';

mapasaheStartSession();

// Timers for the admin dashboard. They only measure; nothing below
// changes how the graph is built or how the models search.
$requestStartedV09 = hrtime(true);
$modelTimingsV09 = [];

ini_set('memory_limit', '256M');

$datasetPath = __DIR__ . '/data/mapasahe_graph_v2.json';

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

/*
 * Optional road-path file, used ONLY for drawing lines on the map.
 * It is produced once by build_matched_paths.php and belongs to
 * graph v2 (it was built from that graph's stops). The paths are
 * ESTIMATES (road paths inferred from coarse stop coordinates, from
 * OSRM / OpenStreetMap), not surveyed jeepney paths.
 *
 * Pathfinding never reads this file. If it is missing or unreadable,
 * the map simply draws straight lines between stops, as in 0.9.
 *
 * (1.0 read data/mapasahe_edge_geometry_v1.json, which stored one
 * path per stop pair. 1.1 reads the file below, which stores one path
 * per route + stop pair.)
 */
$edgeGeometryPath = __DIR__ . '/data/mapasahe_edge_geometry_v3.json';

// Path statuses that are drawn. Any other status (for example
// 'kept_straight_detour' or 'no_path') keeps the straight line.
$drawablePathStatusesV11 = [
    'osrm_matched',
    'osrm_route_fallback',
    'osrm_estimated'
];
$edgePathsV10 = [];

if (file_exists($edgeGeometryPath)) {
    $edgeGeometryFile = json_decode(
        file_get_contents($edgeGeometryPath),
        true
    );

    if (
        is_array($edgeGeometryFile) &&
        isset($edgeGeometryFile['paths']) &&
        is_array($edgeGeometryFile['paths'])
    ) {
        $edgePathsV10 = $edgeGeometryFile['paths'];
    }
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

$graphLoadMsV09 = (hrtime(true) - $requestStartedV09) / 1e6;

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

/*
 * NOTE: coordinateDistanceMetersV08() and findNearestGraphNodeV08()
 * below are no longer called by the main origin/destination flow --
 * that was replaced with a curated picker (buildRouteTerminiV08())
 * because free-form map-click coordinates could silently snap to a
 * graph node arbitrarily far away, with no distance limit, producing
 * confusing results. Kept here as they could still be useful for a
 * future "use my location" feature (snapping a real GPS position to
 * the nearest stop), not because they're still part of the active
 * pathfinding flow.
 */
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

/*
 * Computes the curated origin/destination picker lists directly from
 * the loaded dataset's route chains -- no change to the data file
 * needed. For each route_id, the chain's first stop is an eligible
 * origin and its last stop is an eligible destination.
 *
 * Relies on edges belonging to the same route_id appearing in the
 * dataset in real travel order (true for this build -- edges were
 * generated by walking each route's verified stop sequence in
 * order), so the first-seen 'from' and the last-seen 'to' per
 * route_id give that route's real endpoints.
 *
 * A node can legitimately be an origin via one route and a
 * destination via a different route at the same time (e.g. two
 * routes happen to start/end near each other) -- both lists are
 * independent, a node can appear in both.
 */
function buildRouteTerminiV08($edges, $nodesById)
{
    $routeFirstFrom = [];
    $routeLastTo = [];

    foreach ($edges as $edge) {
        if (($edge['edge_type'] ?? null) !== 'jeepney') {
            continue;
        }

        $routeId = $edge['route_id'] ?? null;

        if ($routeId === null) {
            continue;
        }

        if (!isset($routeFirstFrom[$routeId])) {
            $routeFirstFrom[$routeId] = $edge['from'];
        }

        // Overwritten on every matching edge, so it ends up holding
        // the LAST edge's 'to' for this route_id once the loop ends.
        $routeLastTo[$routeId] = $edge['to'];
    }

    $originTermini = [];
    $destinationTermini = [];

    foreach ($routeFirstFrom as $routeId => $originNodeId) {
        if (isset($nodesById[$originNodeId])) {
            $originTermini[$originNodeId] = $nodesById[$originNodeId];
        }
    }

    foreach ($routeLastTo as $routeId => $destinationNodeId) {
        if (isset($nodesById[$destinationNodeId])) {
            $destinationTermini[$destinationNodeId] = $nodesById[$destinationNodeId];
        }
    }

    uasort($originTermini, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    uasort($destinationTermini, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    return [
        'origins' => $originTermini,
        'destinations' => $destinationTermini
    ];
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

$routeTerminiV08 = buildRouteTerminiV08($edges, $nodesById);

$originOptionsV08 = $routeTerminiV08['origins'];
$destinationOptionsV08 = $routeTerminiV08['destinations'];

$originNodeValue = $_POST['origin_node_id'] ?? '';
$destinationNodeValue = $_POST['destination_node_id'] ?? '';

$uiErrorV08 = null;
$selectedOriginV08 = null;
$selectedDestinationV08 = null;
$routeResultsV08 = [];

$maximumSpeedKmh = (float) (
    $dataset['metadata']['assumptions']['jeep_speed_kmh']
    ?? 25.0
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate against the curated lists server-side -- never trust
    // that the submitted value actually came from our own <select>.
    if (
        $originNodeValue === '' ||
        $destinationNodeValue === '' ||
        !isset($originOptionsV08[$originNodeValue]) ||
        !isset($destinationOptionsV08[$destinationNodeValue])
    ) {
        $uiErrorV08 = 'Please choose an origin and destination from the lists.';
    } elseif ($originNodeValue === $destinationNodeValue) {
        $uiErrorV08 = 'Please select two different locations.';
    } else {
        $selectedOriginV08 = $originOptionsV08[$originNodeValue];
        $selectedDestinationV08 = $destinationOptionsV08[$destinationNodeValue];

        $originNodeV08 = $originNodeValue;
        $destinationNodeV08 = $destinationNodeValue;

        $timerV09 = hrtime(true);

        $dijkstraResultV08 = dijkstraV08(
            $graph,
            $originNodeV08,
            $destinationNodeV08
        );

        $modelTimingsV09['Dijkstra'] = (hrtime(true) - $timerV09) / 1e6;
        $timerV09 = hrtime(true);

        $astarResultV08 = astarV08(
            $graph,
            $nodesById,
            $originNodeV08,
            $destinationNodeV08,
            $maximumSpeedKmh
        );

        $modelTimingsV09['A*'] = (hrtime(true) - $timerV09) / 1e6;
        $timerV09 = hrtime(true);

        $gbfsResultV08 = gbfsV08(
            $graph,
            $nodesById,
            $originNodeV08,
            $destinationNodeV08,
            $maximumSpeedKmh
        );

        $modelTimingsV09['GBFS'] = (hrtime(true) - $timerV09) / 1e6;
        $timerV09 = hrtime(true);

        $hybridResultV08 = synthesizeHybridRouteV08(
            $graph,
            $dijkstraResultV08,
            $astarResultV08,
            $gbfsResultV08,
            $originNodeV08,
            $destinationNodeV08
        );

        // Stitching only; the three searches are timed separately above.
        $modelTimingsV09['Hybrid'] = (hrtime(true) - $timerV09) / 1e6;

        $routeResultsV08 = [
            'Dijkstra' => $dijkstraResultV08,
            'A*' => $astarResultV08,
            'GBFS' => $gbfsResultV08,
            'Hybrid' => $hybridResultV08
        ];
    }
}

/*
 * 1.5: one colour per model, used on the map lines and on the cards,
 * key and comparison table. Yellow is a deep amber so it stays visible
 * on top of OpenStreetMap's light-yellow roads.
 */
$routeColorsV08 = [
    'Dijkstra' => '#D62828',  // red
    'A*' => '#2A9D3F',        // green
    'GBFS' => '#1D4ED8',      // blue
    'Hybrid' => '#E6A800'     // yellow
];

function buildMapSegmentsV08(
    $route,
    $nodesById,
    $isHybrid = false,
    $edgePaths = [],
    $drawableStatuses = ['osrm_estimated']
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

        // Default drawing: one straight line from stop to stop.
        $points = [
            [
                (float) $fromNode['latitude'],
                (float) $fromNode['longitude']
            ],
            [
                (float) $toNode['latitude'],
                (float) $toNode['longitude']
            ]
        ];

        /*
         * If an estimated road path was saved for this hop of this
         * route, draw that instead. The key includes the route_id
         * because the same two stops can be joined by different
         * streets on different routes. Walking transfers always stay
         * straight.
         *
         * 1.2 change: the stop coordinates are NO LONGER added to the
         * two ends of a road path. Stop coordinates in the source
         * data can be about 50 m away from the road, so adding them
         * drew a short stray branch from the road to the stop and
         * back at every stop. The hops are joined to each other after
         * this loop instead.
         */
        $pathKey =
            ($edge['route_id'] ?? '') . '|' .
            $edge['from'] . '->' . $edge['to'];
        $hasRoadPath =
            $edge['edge_type'] === 'jeepney' &&
            isset($edgePaths[$pathKey]['points']) &&
            in_array(
                $edgePaths[$pathKey]['status'] ?? '',
                $drawableStatuses,
                true
            ) &&
            count($edgePaths[$pathKey]['points']) >= 2;

        if ($hasRoadPath) {
            $points = $edgePaths[$pathKey]['points'];
        }

        $segments[] = [
            'points' => $points,
            'road_path_estimated' => $hasRoadPath,
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

    if (empty($segments)) {
        return $segments;
    }

    /*
     * Join the hops into one unbroken line.
     *
     * A hop drawn as a road path starts and ends ON THE ROAD, while a
     * straight hop or a walking transfer starts and ends on the stop
     * coordinates, so two neighbouring hops may not meet exactly.
     * Wherever one hop ends at a different point from where the next
     * one starts, one short connecting point is added.
     *
     * Walking transfers are left exactly stop-to-stop, so the
     * connecting point is added to the jeepney hop next to them.
     */
    for ($index = 1; $index < count($segments); $index++) {
        $previousPoints = $segments[$index - 1]['points'];
        $previousEnd = $previousPoints[count($previousPoints) - 1];
        $currentStart = $segments[$index]['points'][0];

        if ($previousEnd === $currentStart) {
            continue;
        }

        if ($segments[$index - 1]['edge_type'] === 'walking_transfer') {
            array_unshift($segments[$index]['points'], $previousEnd);
        } else {
            $segments[$index - 1]['points'][] = $currentStart;
        }
    }

    /*
     * Keep the two ends of the whole trip attached to the origin and
     * destination markers, which sit on the stop coordinates.
     */
    $firstSegment = $segments[0];
    $lastIndex = count($segments) - 1;
    $lastSegment = $segments[$lastIndex];

    if (isset($nodesById[$firstSegment['from']])) {
        $originPoint = [
            (float) $nodesById[$firstSegment['from']]['latitude'],
            (float) $nodesById[$firstSegment['from']]['longitude']
        ];

        if ($firstSegment['points'][0] !== $originPoint) {
            array_unshift($segments[0]['points'], $originPoint);
        }
    }

    if (isset($nodesById[$lastSegment['to']])) {
        $destinationPoint = [
            (float) $nodesById[$lastSegment['to']]['latitude'],
            (float) $nodesById[$lastSegment['to']]['longitude']
        ];

        $lastPoints = $segments[$lastIndex]['points'];

        if ($lastPoints[count($lastPoints) - 1] !== $destinationPoint) {
            $segments[$lastIndex]['points'][] = $destinationPoint;
        }
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
            $routeLabelV08 === 'Hybrid',
            $edgePathsV10,
            $drawablePathStatusesV11
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

/*
 * Bounds of just the current search results, not the whole dataset.
 * With the dataset now spanning all of City of Manila + Mandaluyong
 * City (479 stops), fitting to the full dataset after every search
 * made a single local route look like a tiny, barely-visible scrap
 * lost in a huge zoomed-out map -- this fixes that by fitting to only
 * what was actually found, falling back to the dataset bounds only
 * when there's nothing to show yet.
 */
$resultBoundsV08 = null;

if (!empty($mapRoutesV08)) {
    $resultMinLatitudeV08 = INF;
    $resultMaxLatitudeV08 = -INF;
    $resultMinLongitudeV08 = INF;
    $resultMaxLongitudeV08 = -INF;
    $hasAnyPointV08 = false;

    foreach ($mapRoutesV08 as $mapRouteV08) {
        foreach ($mapRouteV08['segments'] as $segmentV08) {
            foreach ($segmentV08['points'] as $pointV08) {
                $hasAnyPointV08 = true;

                $resultMinLatitudeV08 = min($resultMinLatitudeV08, $pointV08[0]);
                $resultMaxLatitudeV08 = max($resultMaxLatitudeV08, $pointV08[0]);
                $resultMinLongitudeV08 = min($resultMinLongitudeV08, $pointV08[1]);
                $resultMaxLongitudeV08 = max($resultMaxLongitudeV08, $pointV08[1]);
            }
        }
    }

    if ($hasAnyPointV08) {
        $resultBoundsV08 = [
            [$resultMinLatitudeV08, $resultMinLongitudeV08],
            [$resultMaxLatitudeV08, $resultMaxLongitudeV08]
        ];
    }
}


// ============================================================
// VERSION 0.9 VIEW HELPERS
// Presentation only. Nothing below changes the graph, the three
// searches, the hybrid synthesis, or the POST parameter names.
// ============================================================

/*
 * "Alchan, Mandaluyong City, Manila" -> "Alchan". The city is shown
 * separately, so repeating it in every stop name is just noise.
 */
function stopLabelV09($node)
{
    $name = trim((string) ($node['name'] ?? ''));

    $short = preg_replace(
        '/(,\s*(Mandaluyong City|Quezon City|Manila))+$/u',
        '',
        $name
    );

    $short = trim((string) $short);

    return $short !== '' ? $short : $name;
}

function formatMinutesV09($minutes)
{
    $minutes = (float) $minutes;

    if ($minutes < 1) {
        return '<1 min';
    }

    $rounded = (int) round($minutes);

    if ($rounded < 60) {
        return $rounded . ' min';
    }

    $rest = $rounded % 60;

    return intdiv($rounded, 60) . ' h' . ($rest > 0 ? ' ' . $rest . ' min' : '');
}

function formatDistanceV09($meters)
{
    $meters = (float) $meters;

    if ($meters < 1000) {
        return (int) round($meters) . ' m';
    }

    return number_format($meters / 1000, 1) . ' km';
}

/*
 * Every jeepney route that runs each stop-to-stop hop, keyed "from>to".
 * Many routes share the same streets, so one hop often has several.
 */
function buildHopRoutesV09($edges)
{
    $hopRoutes = [];

    foreach ($edges as $edge) {
        if (($edge['edge_type'] ?? null) !== 'jeepney') {
            continue;
        }

        $routeId = $edge['route_id'] ?? null;

        if ($routeId === null) {
            continue;
        }

        $hopRoutes[$edge['from'] . '>' . $edge['to']][$routeId] =
            $edge['route_name'] ?? $routeId;
    }

    return $hopRoutes;
}

/*
 * FARE (1.4). Display only: the searches never see it, so it does not
 * change which route is chosen.
 *
 * ESTIMATE. Traditional jeepney rate approved by the LTFRB, effective
 * 19 March 2026: P14.00 for the first 4 km, plus P2.00 for each
 * succeeding km. The P2.00 is inferred from news reports (one report
 * gives "+P2" and "+P0.20" in the same article; P1.80 + P0.20 = P2.00)
 * and must be checked against the official LTFRB fare matrix before
 * the final defense. Modern jeepneys (P17.00 / P2.40) are not shown
 * because the dataset does not say which type runs each route.
 *
 * Every started kilometre past the first 4 is charged, and the result
 * is rounded UP to the next whole peso. No discounts are shown.
 */
$fareRuleV14 = [
    'base_fare' => 14.00,
    'base_km' => 4.0,
    'per_km' => 2.00
];

function estimateFareV14($distanceMeters, $fareRule)
{
    $kilometres = max(0.0, (float) $distanceMeters) / 1000;
    $extraKilometres = (int) ceil(
        max(0.0, $kilometres - $fareRule['base_km']) - 0.000001
    );

    $fare =
        $fareRule['base_fare'] +
        max(0, $extraKilometres) * $fareRule['per_km'];

    return (int) ceil($fare - 0.000001);
}

/*
 * Distance used for the fare of one stop-to-stop hop: the matched road
 * distance from the road-path file when that hop has a drawable road
 * path, otherwise the straight-line distance from the graph (which
 * runs short, so those fares can come out slightly low).
 */
function fareDistanceForEdgeV14($edge, $edgePaths, $drawableStatuses)
{
    $pathKey =
        ($edge['route_id'] ?? '') . '|' .
        $edge['from'] . '->' . $edge['to'];

    $saved = $edgePaths[$pathKey] ?? null;

    if (
        is_array($saved) &&
        in_array($saved['status'] ?? '', $drawableStatuses, true) &&
        isset($saved['road_distance_m']) &&
        (float) $saved['road_distance_m'] > 0
    ) {
        return (float) $saved['road_distance_m'];
    }

    return (float) $edge['distance_m'];
}

/*
 * Collapses a path of stop-to-stop edges into rider-facing legs:
 * one leg per jeepney ridden, one per walk between stops.
 *
 * The searches charge nothing for switching between routes that share
 * a stop, so a lowest-cost path can name a different route on almost
 * every hop even where one jeepney covers the whole stretch. When
 * $hopRoutes is given, each stretch is labelled with the route that
 * stays on the path longest, which gives the fewest changes for the
 * same stops, in the same order, at the same cost. Pass null to label
 * every hop exactly as the search returned it.
 */
function buildItineraryLegsV09(
    $route,
    $nodesById,
    $hopRoutes = null,
    $edgePaths = [],
    $drawableStatuses = [],
    $fareRule = null
) {
    $path = array_values($route['path'] ?? []);
    $count = count($path);
    $legs = [];
    $index = 0;

    while ($index < $count) {
        $edge = $path[$index];
        $isWalk = $edge['edge_type'] === 'walking_transfer';
        $routeName = $edge['route_name'];
        $end = $index + 1;

        if ($isWalk) {
            while (
                $end < $count &&
                $path[$end]['edge_type'] === 'walking_transfer'
            ) {
                $end++;
            }
        } elseif ($hopRoutes === null) {
            while (
                $end < $count &&
                $path[$end]['edge_type'] !== 'walking_transfer' &&
                ($path[$end]['route_id'] ?? null) === ($edge['route_id'] ?? null)
            ) {
                $end++;
            }
        } else {
            $ownRoute = [
                (string) ($edge['route_id'] ?? '') => $edge['route_name']
            ];

            $candidates =
                ($hopRoutes[$edge['from'] . '>' . $edge['to']] ?? []) + $ownRoute;

            while (
                $end < $count &&
                $path[$end]['edge_type'] !== 'walking_transfer'
            ) {
                $next = $path[$end];

                $stillRunning = array_intersect_key(
                    $candidates,
                    ($hopRoutes[$next['from'] . '>' . $next['to']] ?? []) + [
                        (string) ($next['route_id'] ?? '') => true
                    ]
                );

                if (empty($stillRunning)) {
                    break;
                }

                $candidates = $stillRunning;
                $end++;
            }

            // Keep the search's own choice when it lasts just as long.
            $ownId = (string) ($edge['route_id'] ?? '');

            $routeName = isset($candidates[$ownId])
                ? $candidates[$ownId]
                : reset($candidates);
        }

        $leg = [
            'mode' => $isWalk ? 'walk' : 'ride',
            'route_name' => $routeName,
            'from' => $edge['from'],
            'to' => $path[$end - 1]['to'],
            'stops' => $end - $index,
            'minutes' => 0.0,
            'distance_m' => 0.0,
            'fare_distance_m' => 0.0,
            'fare' => null
        ];

        for ($cursor = $index; $cursor < $end; $cursor++) {
            $leg['minutes'] += $path[$cursor]['travel_time_min'];
            $leg['distance_m'] += $path[$cursor]['distance_m'];
            $leg['fare_distance_m'] += fareDistanceForEdgeV14(
                $path[$cursor],
                $edgePaths,
                $drawableStatuses
            );
        }

        // One fare per jeepney boarded. Walking is free.
        if (!$isWalk && $fareRule !== null) {
            $leg['fare'] = estimateFareV14($leg['fare_distance_m'], $fareRule);
        }

        $leg['from_name'] = isset($nodesById[$leg['from']])
            ? stopLabelV09($nodesById[$leg['from']])
            : $leg['from'];

        $leg['to_name'] = isset($nodesById[$leg['to']])
            ? stopLabelV09($nodesById[$leg['to']])
            : $leg['to'];

        $legs[] = $leg;
        $index = $end;
    }

    return $legs;
}

/*
 * Which jeepney routes start (or end) at each terminus. Shown under
 * each stop in the picker, and it tells apart stops that share a name.
 * Same first-seen / last-seen rule as buildRouteTerminiV08().
 */
function buildTerminusRouteNamesV09($edges)
{
    $first = [];
    $last = [];
    $names = [];

    foreach ($edges as $edge) {
        if (($edge['edge_type'] ?? null) !== 'jeepney') {
            continue;
        }

        $routeId = $edge['route_id'] ?? null;

        if ($routeId === null) {
            continue;
        }

        if (!isset($first[$routeId])) {
            $first[$routeId] = $edge['from'];
        }

        $last[$routeId] = $edge['to'];
        $names[$routeId] = $edge['route_name'] ?? $routeId;
    }

    $result = ['origins' => [], 'destinations' => []];

    foreach ($first as $routeId => $nodeId) {
        $result['origins'][$nodeId][] = $names[$routeId];
    }

    foreach ($last as $routeId => $nodeId) {
        $result['destinations'][$nodeId][] = $names[$routeId];
    }

    return $result;
}

function groupOptionsByCityV09($options)
{
    $groups = [];

    foreach ($options as $nodeId => $node) {
        $city = trim((string) ($node['city'] ?? '')) ?: 'Other';
        $groups[$city][$nodeId] = $node;
    }

    ksort($groups);

    return $groups;
}

function routeHintV09($routeNames)
{
    $routeNames = array_values(array_unique($routeNames));
    $shown = array_slice($routeNames, 0, 2);
    $extra = count($routeNames) - count($shown);

    return implode(' · ', $shown) . ($extra > 0 ? ' +' . $extra . ' more' : '');
}

$terminusRouteNamesV09 = buildTerminusRouteNamesV09($edges);

$pickersV09 = [
    'origin' => [
        'label' => 'From',
        'name' => 'origin_node_id',
        'placeholder' => 'Search a starting terminal',
        'empty' => 'Select an origin',
        'value' => $originNodeValue,
        'groups' => groupOptionsByCityV09($originOptionsV08),
        'routes' => $terminusRouteNamesV09['origins']
    ],
    'destination' => [
        'label' => 'To',
        'name' => 'destination_node_id',
        'placeholder' => 'Search an end terminal',
        'empty' => 'Select a destination',
        'value' => $destinationNodeValue,
        'groups' => groupOptionsByCityV09($destinationOptionsV08),
        'routes' => $terminusRouteNamesV09['destinations']
    ]
];

// true  = directions name the fewest jeepneys that cover the path.
// false = directions repeat the route label on every edge as returned.
$simplifyRideLabelsV09 = true;
$hopRoutesV09 = buildHopRoutesV09($edges);

// The hybrid is the system's own answer, so it leads. Reorder this
// list to change the card order; the first route with a path opens.
$displayOrderV09 = ['Hybrid', 'Dijkstra', 'A*', 'GBFS'];

$routeBlurbsV09 = [
    'Hybrid' => 'Combined from all three searches',
    'Dijkstra' => 'Lowest-cost search',
    'A*' => 'Lowest-cost search, guided by distance',
    'GBFS' => 'Greedy search, heads straight for the destination'
];

$cardsV09 = [];
$lowestCostV09 = null;
$seenPathsV09 = [];
$stopNamesV09 = [];

foreach ($displayOrderV09 as $labelV09) {
    if (!array_key_exists($labelV09, $routeResultsV08)) {
        continue;
    }

    $resultV09 = $routeResultsV08[$labelV09];

    $cardV09 = [
        'label' => $labelV09,
        'color' => $routeColorsV08[$labelV09],
        'blurb' => $routeBlurbsV09[$labelV09] ?? '',
        'result' => $resultV09,
        'legs' => [],
        'ride_count' => 0,
        'fare' => 0,
        'same_as' => null
    ];

    if ($resultV09 !== null) {
        $cardV09['legs'] = buildItineraryLegsV09(
            $resultV09,
            $nodesById,
            $simplifyRideLabelsV09 ? $hopRoutesV09 : null,
            $edgePathsV10,
            $drawablePathStatusesV11,
            $fareRuleV14
        );

        // The map draws the same legs the directions list shows.
        if (isset($mapRoutesV08[$labelV09])) {
            $mapRoutesV08[$labelV09]['legs'] = array_map(
                function ($legV09) {
                    return [
                        'mode' => $legV09['mode'],
                        'route_name' => $legV09['route_name'],
                        'edges' => $legV09['stops']
                    ];
                },
                $cardV09['legs']
            );
        }

        foreach ($cardV09['legs'] as $legV09) {
            if ($legV09['mode'] === 'ride') {
                $cardV09['ride_count']++;
                $cardV09['fare'] += (int) $legV09['fare'];
            }
        }

        $signatureV09 = implode(
            '>',
            array_map('edgeAgreementKeyV08', $resultV09['path'])
        );

        if (isset($seenPathsV09[$signatureV09])) {
            $cardV09['same_as'] = $seenPathsV09[$signatureV09];
        } else {
            $seenPathsV09[$signatureV09] = $labelV09;
        }

        if (
            $lowestCostV09 === null ||
            $resultV09['cost_min'] < $lowestCostV09
        ) {
            $lowestCostV09 = $resultV09['cost_min'];
        }

        foreach ($resultV09['path'] as $edgeV09) {
            foreach ([$edgeV09['from'], $edgeV09['to']] as $idV09) {
                if (isset($nodesById[$idV09])) {
                    $stopNamesV09[$idV09] = stopLabelV09($nodesById[$idV09]);
                }
            }
        }
    }

    $cardsV09[] = $cardV09;
}

$foundCountV09 = count($mapRoutesV08);
$defaultRouteV09 = null;

foreach ($cardsV09 as $cardV09) {
    if ($cardV09['result'] !== null) {
        $defaultRouteV09 = $cardV09['label'];
        break;
    }
}

if ($uiErrorV08 !== null) {
    $resultsStateV09 = 'error';
    $resultsMessageV09 = $uiErrorV08;
} elseif (!empty($routeResultsV08) && $foundCountV09 > 0) {
    $resultsStateV09 = 'ok';
    $resultsMessageV09 = $foundCountV09 === 1
        ? '1 route found.'
        : $foundCountV09 . ' routes found. Tap one to see it on the map.';
} elseif (!empty($routeResultsV08)) {
    $resultsStateV09 = 'empty';
    $resultsMessageV09 = 'No jeepney connection found between these stops.';
} else {
    $resultsStateV09 = 'idle';
    $resultsMessageV09 = 'Choose where you are starting and where you are going.';
}

$terminiPayloadV09 = [];

foreach ($originOptionsV08 + $destinationOptionsV08 as $nodeIdV09 => $nodeV09) {
    $terminiPayloadV09[$nodeIdV09] = [
        'latitude' => (float) $nodeV09['latitude'],
        'longitude' => (float) $nodeV09['longitude'],
        'name' => stopLabelV09($nodeV09)
    ];
}

$pagePayloadV09 = [
    'routes' => (object) $mapRoutesV08,
    'defaultRoute' => $defaultRouteV09,
    'resultBounds' => $resultBoundsV08,
    'datasetBounds' => $datasetBoundsV08,
    'termini' => (object) $terminiPayloadV09,
    'stopNames' => (object) $stopNamesV09
];


// ============================================================
// MODEL COMPARISON (view only)
// Numbers that show what each of the three models contributed.
// ============================================================

$modelLabelsV09 = ['Dijkstra', 'A*', 'GBFS'];

$cardsByLabelV09 = [];

foreach ($cardsV09 as $cardV09) {
    $cardsByLabelV09[$cardV09['label']] = $cardV09;
}

$hybridResultV09 = $routeResultsV08['Hybrid'] ?? null;

// Which hybrid edges each model also found on its own.
$hybridEdgeKeysV09 = [];

if ($hybridResultV09 !== null) {
    foreach ($hybridResultV09['path'] as $edgeV09) {
        $hybridEdgeKeysV09[edgeAgreementKeyV08($edgeV09)] = true;
    }
}

$maxNodesExploredV09 = 0;

foreach ($modelLabelsV09 as $labelV09) {
    $resultV09 = $routeResultsV08[$labelV09] ?? null;

    if ($resultV09 !== null) {
        $maxNodesExploredV09 = max(
            $maxNodesExploredV09,
            $resultV09['nodes_explored']
        );
    }
}

$compareRowsV09 = [];

foreach ($modelLabelsV09 as $labelV09) {
    $resultV09 = $routeResultsV08[$labelV09] ?? null;
    $sharedV09 = 0;

    if ($resultV09 !== null) {
        foreach ($resultV09['path'] as $edgeV09) {
            if (isset($hybridEdgeKeysV09[edgeAgreementKeyV08($edgeV09)])) {
                $sharedV09++;
            }
        }
    }

    $compareRowsV09[] = [
        'label' => $labelV09,
        'color' => $routeColorsV08[$labelV09],
        'result' => $resultV09,
        'shared_with_hybrid' => $sharedV09,
        'explored_share' => ($resultV09 !== null && $maxNodesExploredV09 > 0)
            ? $resultV09['nodes_explored'] / $maxNodesExploredV09
            : 0
    ];
}

/*
 * How the hybrid route was put together, straight from its
 * segment_log: edges all three models agree on, plus the edges each
 * model supplied to bridge the gaps between those agreed stretches.
 */
$hybridMixV09 = [];

if ($hybridResultV09 !== null) {
    $mixCountsV09 = [];

    foreach ($hybridResultV09['segment_log'] ?? [] as $segmentV09) {
        $sourceV09 = $segmentV09['type'] === 'agreed'
            ? 'All three agree'
            : $segmentV09['filled_by'];

        $mixCountsV09[$sourceV09] =
            ($mixCountsV09[$sourceV09] ?? 0) + $segmentV09['edge_count'];
    }

    $mixColorsV09 = [
        'All three agree' => $routeColorsV08['Hybrid'],
        'Fallback Dijkstra' => '#64748B'
    ] + $routeColorsV08;

    $mixTotalV09 = max(1, array_sum($mixCountsV09));

    foreach ($mixCountsV09 as $sourceV09 => $countV09) {
        if ($countV09 < 1) {
            continue;
        }

        $hybridMixV09[] = [
            'source' => $sourceV09,
            'edges' => $countV09,
            'percent' => 100 * $countV09 / $mixTotalV09,
            'color' => $mixColorsV09[$sourceV09] ?? '#64748B'
        ];
    }
}

function iconV09($name)
{
    if ($name === 'walk') {
        return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="13" cy="4.5" r="2.2"/><path d="M10.2 9.2 7 11v4h2v-2.8l1.6-.9-1.1 5.6L6 22h2.3l2.6-4 2.1 2v2h2v-3.5l-2.1-2.2.6-3.1c1 1.3 2.5 2.1 4.5 2.1v-2c-1.6 0-2.8-.8-3.5-2l-1-1.7c-.4-.7-1.100-1-1.8-1-.5 0-1 .2-1.5.6z"/></svg>';
    }

    // Jeepney: long body, flat hood, two wheels.
    return '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h13l2.5 3H21a1 1 0 0 1 1 1v5h-1.6a2.7 2.7 0 0 1-5.3 0H8.900a2.7 2.7 0 0 1-5.3 0H2V8a1 1 0 0 1 1-1zm1 2v2.5h3V9H4zm4.5 0v2.5h3V9h-3zm4.5 0v2.5h3.7L15 9h-2z"/><circle cx="6.25" cy="16.5" r="1.6"/><circle cx="17.750" cy="16.5" r="1.6"/></svg>';
}

/*
 * One route option, laid out as a row: model tag and leg strip on the
 * left, big duration on the right, directions inside when opened.
 * Used for the hybrid and for each of the three models.
 */
function renderRouteCardV09($cardV09, $contextV09)
{
    $resultV09 = $cardV09['result'];
    $labelV09 = $cardV09['label'];
    $bodyIdV09 = 'route-body-' . preg_replace('/[^a-z0-9]+/i', '', $labelV09 === 'A*' ? 'astar' : $labelV09);
    $isActiveV09 = $labelV09 === $contextV09['default'];
    $isHybridV09 = $labelV09 === 'Hybrid';

    if ($resultV09 === null) { ?>
        <article
            class="route-card is-unavailable"
            style="--route-color: <?= eV08($cardV09['color']) ?>;"
        >
            <div class="route-card__head">
                <span class="route-card__main">
                    <span class="model-tag"><?= eV08($labelV09) ?></span>
                    <span class="route-card__blurb">
                        <?= $isHybridV09
                            ? 'Needs a path from all three models to build a hybrid.'
                            : 'This model found no path.' ?>
                    </span>
                </span>
            </div>
        </article>
        <?php return;
    }

    $transfersV09 = max(0, $cardV09['ride_count'] - 1);

    $isLowestV09 =
        abs($resultV09['cost_min'] - $contextV09['lowest']) < 0.000000001;

    $stripLegsV09 = array_slice($cardV09['legs'], 0, 4);
    $hiddenLegsV09 = count($cardV09['legs']) - count($stripLegsV09);
    ?>
    <article
        class="route-card <?= $isHybridV09 ? 'route-card--hybrid' : '' ?> <?= $isActiveV09 ? 'is-active' : '' ?>"
        data-route="<?= eV08($labelV09) ?>"
        style="--route-color: <?= eV08($cardV09['color']) ?>;"
    >
        <button
            type="button"
            class="route-card__head"
            aria-expanded="<?= $isActiveV09 ? 'true' : 'false' ?>"
            aria-controls="<?= eV08($bodyIdV09) ?>"
        >
            <span class="route-card__main">
                <span class="route-card__tags">
                    <span class="model-tag"><?= eV08($labelV09) ?></span>
                    <?php if ($isLowestV09): ?>
                        <span class="badge">Lowest cost</span>
                    <?php endif; ?>
                    <?php if ($cardV09['same_as'] !== null): ?>
                        <span class="badge badge--plain">Same path as <?= eV08($cardV09['same_as']) ?></span>
                    <?php endif; ?>
                </span>

                <span class="leg-strip">
                    <?php foreach ($stripLegsV09 as $legIndexV09 => $legV09): ?>
                        <?php if ($legIndexV09 > 0): ?>
                            <span class="leg-strip__sep" aria-hidden="true">›</span>
                        <?php endif; ?>

                        <?php if ($legV09['mode'] === 'walk'): ?>
                            <span class="leg leg--walk">
                                <?= iconV09('walk') ?>
                                <span class="visually-hidden">Walk</span>
                                <?= eV08(formatMinutesV09($legV09['minutes'])) ?>
                            </span>
                        <?php else: ?>
                            <span class="leg leg--ride" title="<?= eV08($legV09['route_name']) ?>">
                                <?= iconV09('jeep') ?>
                                <span class="leg__name"><?= eV08($legV09['route_name']) ?></span>
                            </span>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($hiddenLegsV09 > 0): ?>
                        <span class="leg-strip__sep" aria-hidden="true">›</span>
                        <span class="leg leg--more">+<?= eV08($hiddenLegsV09) ?> more</span>
                    <?php endif; ?>
                </span>

                <span class="route-card__meta">
                    <strong>&#8369;<?= eV08($cardV09['fare']) ?></strong>
                    est. fare
                    ·
                    <?= eV08($cardV09['ride_count']) ?>
                    <?= $cardV09['ride_count'] === 1 ? 'jeepney' : 'jeepneys' ?>
                    ·
                    <?= eV08($transfersV09) ?>
                    <?= $transfersV09 === 1 ? 'transfer' : 'transfers' ?>
                    ·
                    <?= eV08(formatDistanceV09($resultV09['distance_m'])) ?>
                    <?php if (!$isHybridV09): ?>
                        ·
                        <strong><?= number_format($resultV09['nodes_explored']) ?></strong>
                        nodes explored
                    <?php endif; ?>
                </span>
            </span>

            <span class="route-card__time">
                <strong><?= eV08(preg_replace('/\s*min$/', '', formatMinutesV09($resultV09['cost_min']))) ?></strong>
                <span>min</span>
            </span>
        </button>

        <?php if ($isHybridV09 && !empty($contextV09['mix'])): ?>
            <div class="mix">
                <p class="mix__title">How the three models built this route</p>

                <div class="mix__bar" aria-hidden="true">
                    <?php foreach ($contextV09['mix'] as $partV09): ?>
                        <span style="width: <?= round($partV09['percent'], 2) ?>%; background: <?= eV08($partV09['color']) ?>;"></span>
                    <?php endforeach; ?>
                </div>

                <ul class="mix__legend">
                    <?php foreach ($contextV09['mix'] as $partV09): ?>
                        <li>
                            <span class="mix__swatch" style="background: <?= eV08($partV09['color']) ?>;"></span>
                            <?php if ($partV09['source'] === 'All three agree'): ?>
                                All three agree
                            <?php else: ?>
                                Gap filled by <?= eV08($partV09['source']) ?>
                            <?php endif; ?>
                            <strong>
                                <?= eV08($partV09['edges']) ?>
                                <?= $partV09['edges'] === 1 ? 'edge' : 'edges' ?>
                            </strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="route-card__body" id="<?= eV08($bodyIdV09) ?>">

            <ol class="steps">
                <li class="step step--start">
                    <span class="step__marker" aria-hidden="true"></span>
                    <p class="step__title">
                        Start at <strong><?= eV08($contextV09['origin']) ?></strong>
                    </p>
                </li>

                <?php foreach ($cardV09['legs'] as $legV09): ?>
                    <?php if ($legV09['mode'] === 'ride'): ?>
                        <li class="step step--ride">
                            <span class="step__marker" aria-hidden="true"></span>
                            <p class="step__title">
                                Ride
                                <span class="signboard"><?= eV08($legV09['route_name']) ?></span>
                            </p>
                            <p class="step__meta">
                                Get off at
                                <strong><?= eV08($legV09['to_name']) ?></strong>
                                ·
                                <?= eV08($legV09['stops']) ?>
                                <?= $legV09['stops'] === 1 ? 'stop' : 'stops' ?>
                                ·
                                <?= eV08(formatMinutesV09($legV09['minutes'])) ?>
                                <?php if ($legV09['fare'] !== null): ?>
                                    ·
                                    <strong>&#8369;<?= eV08($legV09['fare']) ?></strong>
                                <?php endif; ?>
                            </p>
                        </li>
                    <?php else: ?>
                        <li class="step step--walk">
                            <span class="step__marker" aria-hidden="true"></span>
                            <p class="step__title">
                                Walk
                                <?= eV08(formatDistanceV09($legV09['distance_m'])) ?>
                                to
                                <strong><?= eV08($legV09['to_name']) ?></strong>
                            </p>
                            <p class="step__meta">
                                About <?= eV08(formatMinutesV09($legV09['minutes'])) ?>
                            </p>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>

                <li class="step step--end">
                    <span class="step__marker" aria-hidden="true"></span>
                    <p class="step__title">
                        Arrive at <strong><?= eV08($contextV09['destination']) ?></strong>
                    </p>
                </li>
            </ol>

            <?php if ($cardV09['ride_count'] > 0): ?>
                <p class="step__meta fare-note">
                    Estimated fare: &#8369;<?= eV08($cardV09['fare']) ?> total,
                    one fare per jeepney. Traditional jeepney rate
                    (LTFRB, March 2026): &#8369;14 for the first 4 km,
                    &#8369;2 per km after, rounded up. Distances are
                    estimated, so the actual fare may differ.
                </p>
            <?php endif; ?>

            <button
                type="button"
                class="btn btn--ghost show-on-map"
                data-action="show-map"
            >
                Show on map
            </button>

            <details class="tech">
                <summary>Search details</summary>

                <dl class="tech__list">
                    <div>
                        <dt>Generalized cost</dt>
                        <dd><?= number_format($resultV09['cost_min'], 2) ?> min</dd>
                    </div>
                    <div>
                        <dt>Riding</dt>
                        <dd><?= number_format($resultV09['ride_time_min'], 2) ?> min</dd>
                    </div>
                    <div>
                        <dt>Walking</dt>
                        <dd><?= number_format($resultV09['walking_time_min'], 2) ?> min</dd>
                    </div>
                    <div>
                        <dt>Transfer penalty</dt>
                        <dd><?= number_format($resultV09['transfer_penalty_min'], 2) ?> min</dd>
                    </div>
                    <div>
                        <dt>Edges in path</dt>
                        <dd><?= count($resultV09['path']) ?></dd>
                    </div>
                    <div>
                        <dt>Walking transfers</dt>
                        <dd><?= eV08($resultV09['walking_transfer_count']) ?></dd>
                    </div>
                    <div>
                        <dt><?= $isHybridV09 ? 'Nodes explored (all three)' : 'Nodes explored' ?></dt>
                        <dd><?= number_format($resultV09['nodes_explored']) ?></dd>
                    </div>

                    <?php if ($isHybridV09): ?>
                        <div>
                            <dt>Agreed edges</dt>
                            <dd><?= eV08($resultV09['agreed_edge_count']) ?></dd>
                        </div>
                        <div>
                            <dt>Gap-filling edges</dt>
                            <dd><?= eV08($resultV09['gap_edge_count']) ?></dd>
                        </div>
                        <div>
                            <dt>Fallback searches</dt>
                            <dd><?= eV08($resultV09['fallback_count']) ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </details>
        </div>
    </article>
    <?php
}

// ============================================================
// SEARCH LOG (for the admin dashboard)
// ============================================================

$currentUserV09 = mapasaheUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $logModelsV09 = [];

    foreach ($routeResultsV08 as $labelV09 => $resultV09) {
        $logModelsV09[$labelV09] = [
            'found' => $resultV09 !== null,
            'ms' => $modelTimingsV09[$labelV09] ?? 0.0,
            'nodes' => $resultV09['nodes_explored'] ?? 0,
            'cost' => $resultV09['cost_min'] ?? null,
            'edges' => $resultV09 !== null ? count($resultV09['path']) : 0
        ];
    }

    $logStopsV09 = [];

    if ($selectedOriginV08 !== null && $selectedDestinationV08 !== null) {
        $logStopsV09[] = [
            'id' => $originNodeValue,
            'name' => stopLabelV09($selectedOriginV08),
            'role' => 'origin'
        ];

        // Stops along the suggested route, between the two ends.
        $suggestedV09 = $defaultRouteV09 !== null
            ? $routeResultsV08[$defaultRouteV09]
            : null;

        foreach (array_slice($suggestedV09['path'] ?? [], 0, -1) as $edgeV09) {
            $logStopsV09[] = [
                'id' => $edgeV09['to'],
                'name' => $stopNamesV09[$edgeV09['to']] ?? $edgeV09['to'],
                'role' => 'via'
            ];
        }

        $logStopsV09[] = [
            'id' => $destinationNodeValue,
            'name' => stopLabelV09($selectedDestinationV08),
            'role' => 'destination'
        ];
    }

    mapasaheLogSearch([
        'user_id' => $currentUserV09['id'] ?? null,
        'status' => $resultsStateV09 === 'ok'
            ? 'ok'
            : ($resultsStateV09 === 'empty' ? 'no_route' : 'invalid'),
        'origin_id' => (string) $originNodeValue,
        'origin_name' => $selectedOriginV08 !== null
            ? stopLabelV09($selectedOriginV08)
            : '(not chosen)',
        'destination_id' => (string) $destinationNodeValue,
        'destination_name' => $selectedDestinationV08 !== null
            ? stopLabelV09($selectedDestinationV08)
            : '(not chosen)',
        'load_ms' => $graphLoadMsV09,
        'total_ms' => (hrtime(true) - $requestStartedV09) / 1e6,
        'models' => $logModelsV09,
        'stops' => $logStopsV09
    ]);
}

$cardContextV09 = [
    'default' => $defaultRouteV09,
    'lowest' => $lowestCostV09,
    'mix' => $hybridMixV09,
    'origin' => $selectedOriginV08 !== null ? stopLabelV09($selectedOriginV08) : '',
    'destination' => $selectedDestinationV08 !== null ? stopLabelV09($selectedDestinationV08) : ''
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0047AB">

    <title>MapaSahe — Jeepney Route Planner</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;600;700;800&display=swap">
    <link rel="preconnect" href="https://unpkg.com" crossorigin>
    <link rel="preconnect" href="https://tile.openstreetmap.org">

    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >
    <link rel="stylesheet" href="style1_3.css?v=<?= (int) @filemtime(__DIR__ . '/style1_3.css') ?>">

    <script
        defer
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""
    ></script>
    <script defer src="app1_5.js?v=<?= (int) @filemtime(__DIR__ . '/app1_5.js') ?>"></script>

    <style>
        /* 1.5: map key samples follow the selected route's colour
           (app1_5.js sets --map-route); walks are broken lines. */
        #map-key .key-line { border-top-color: var(--map-route, var(--brand)); }
        #map-key .key-line--walk { border-top-style: dashed; border-top-width: 3px; }
        #map-key .key-dot { background: var(--map-route, var(--brand)); }
        #map-key .key-dot--walk {
            background: #fff;
            border: 3px solid var(--map-route, var(--brand));
            box-shadow: none;
        }
    </style>
</head>

<body>

<a class="skip-link" href="#planner">Skip to route planner</a>

<header class="app-header">
    <a class="brand" href="splash.html" aria-label="MapaSahe home">
        <svg class="brand__mark" viewBox="0 0 32 32" aria-hidden="true">
            <path d="M8 24c0-9 16-7 16-16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-dasharray="0.1 6"/>
            <circle cx="8" cy="24" r="4.5" fill="#fff"/>
            <circle cx="24" cy="8" r="4.5" fill="#FF7518"/>
        </svg>
        <span class="brand__name">MapaSahe</span>
    </a>

    <ul class="model-strip" aria-label="Search models used">
        <?php foreach ($modelLabelsV09 as $labelV09): ?>
            <li style="--route-color: <?= eV08($routeColorsV08[$labelV09]) ?>;"><?= eV08($labelV09) ?></li>
        <?php endforeach; ?>
        <li class="model-strip__sum" style="--route-color: <?= eV08($routeColorsV08['Hybrid']) ?>;">Hybrid</li>
    </ul>

    <nav class="auth-links" aria-label="Account">
        <?php if ($currentUserV09 === null): ?>
            <a class="auth-link" href="loginMapa.php" target="_top">Log in</a>
            <a class="auth-link auth-link--solid" href="registerMapa.php" target="_top">Sign up</a>
        <?php else: ?>
            <?php if ($currentUserV09['role'] === 'admin'): ?>
                <a class="auth-link" href="admin_dashboard.php" target="_top">Admin</a>
            <?php endif; ?>
            <a class="auth-link auth-link--solid" href="user_dashboard.php" target="_top">
                <?= eV08($currentUserV09['username']) ?>
            </a>
            <form method="POST" action="logout.php" target="_top" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= eV08(mapasaheCsrfToken()) ?>">
                <button type="submit" class="auth-link">Log out</button>
            </form>
        <?php endif; ?>
    </nav>
</header>

<main class="app">

    <section class="planner" id="planner" aria-label="Route planner">

        <button
            type="button"
            class="sheet-handle"
            id="sheet-handle"
            aria-controls="planner-body"
            aria-expanded="true"
        >
            <span class="sheet-handle__bar" aria-hidden="true"></span>
            <span class="sheet-handle__label" id="sheet-label">Plan your trip</span>
        </button>

        <div class="planner__scroll" id="planner-body">

            <div class="search-block">
                <h1 class="planner__title">Get me somewhere</h1>

                <form method="POST" class="trip-form" id="trip-form">

                    <div class="trip-fields">
                        <?php foreach ($pickersV09 as $pickerKeyV09 => $pickerV09): ?>
                            <div class="field field--<?= eV08($pickerKeyV09) ?>">
                                <label
                                    class="field__label"
                                    for="<?= eV08($pickerV09['name']) ?>"
                                ><?= eV08($pickerV09['label']) ?></label>

                                <select
                                    class="field__select"
                                    id="<?= eV08($pickerV09['name']) ?>"
                                    name="<?= eV08($pickerV09['name']) ?>"
                                    data-combobox
                                    data-placeholder="<?= eV08($pickerV09['placeholder']) ?>"
                                    required
                                >
                                    <option value=""><?= eV08($pickerV09['empty']) ?></option>

                                    <?php foreach ($pickerV09['groups'] as $cityV09 => $groupV09): ?>
                                        <optgroup label="<?= eV08($cityV09) ?>">
                                            <?php foreach ($groupV09 as $nodeId => $node): ?>
                                                <option
                                                    value="<?= eV08($nodeId) ?>"
                                                    data-city="<?= eV08($cityV09) ?>"
                                                    data-routes="<?= eV08(routeHintV09($pickerV09['routes'][$nodeId] ?? [])) ?>"
                                                    <?= $pickerV09['value'] === $nodeId ? 'selected' : '' ?>
                                                ><?= eV08(stopLabelV09($node)) ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="btn btn--primary" id="trip-submit">
                        <span class="btn__spinner" aria-hidden="true"></span>
                        <span class="btn__label">Find routes</span>
                    </button>
                </form>
            </div>

            <div class="planner__content">

                <p
                    class="status status--<?= eV08($resultsStateV09) ?>"
                    id="status"
                    role="status"
                    aria-live="polite"
                >
                    <span class="status__dot" aria-hidden="true"></span>
                    <span id="status-text"><?= eV08($resultsMessageV09) ?></span>
                </p>

                <div
                    class="results"
                    id="results"
                    data-state="<?= eV08($resultsStateV09) ?>"
                    data-message="<?= eV08($resultsMessageV09) ?>"
                >

                    <?php if ($resultsStateV09 === 'idle'): ?>
                        <div class="intro">
                            <h2>Three models, one route</h2>
                            <p>
                                Every search runs three pathfinding models over
                                the same jeepney network, then combines them.
                            </p>

                            <ol class="intro__list">
                                <li style="--route-color: <?= eV08($routeColorsV08['Dijkstra']) ?>;">
                                    <strong>Dijkstra</strong>
                                    Checks every option outward from the start and returns the lowest-cost path.
                                </li>
                                <li style="--route-color: <?= eV08($routeColorsV08['A*']) ?>;">
                                    <strong>A*</strong>
                                    Same lowest-cost goal, steered toward the destination by straight-line distance.
                                </li>
                                <li style="--route-color: <?= eV08($routeColorsV08['GBFS']) ?>;">
                                    <strong>GBFS</strong>
                                    Greedy best-first: always moves to the stop that looks closest to the destination.
                                </li>
                                <li style="--route-color: <?= eV08($routeColorsV08['Hybrid']) ?>;">
                                    <strong>Hybrid</strong>
                                    Keeps the stretches all three agree on and fills the gaps with the cheapest of their answers.
                                </li>
                            </ol>
                        </div>
                    <?php endif; ?>

                    <?php if ($resultsStateV09 === 'empty'): ?>
                        <div class="empty-card">
                            <h2>No route between these stops</h2>
                            <p>
                                None of the three models found a jeepney
                                connection, even with a short walk between
                                stops. Try a nearby terminal on either end.
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ($resultsStateV09 === 'ok'): ?>

                        <h2 class="results__title">
                            <?= eV08($cardContextV09['origin']) ?>
                            <span aria-hidden="true">→</span>
                            <span class="visually-hidden">to</span>
                            <?= eV08($cardContextV09['destination']) ?>
                        </h2>

                        <?php if ($currentUserV09 !== null): ?>
                            <form method="POST" action="user_actions.php" target="_top" class="save-route">
                                <input type="hidden" name="csrf_token" value="<?= eV08(mapasaheCsrfToken()) ?>">
                                <input type="hidden" name="action" value="save_route">
                                <input type="hidden" name="origin_node_id" value="<?= eV08($originNodeValue) ?>">
                                <input type="hidden" name="origin_name" value="<?= eV08($cardContextV09['origin']) ?>">
                                <input type="hidden" name="destination_node_id" value="<?= eV08($destinationNodeValue) ?>">
                                <input type="hidden" name="destination_name" value="<?= eV08($cardContextV09['destination']) ?>">
                                <button type="submit" class="btn btn--ghost">☆ Save this trip</button>
                            </form>
                        <?php else: ?>
                            <p class="save-route save-route--hint">
                                <a href="loginMapa.php" target="_top">Log in</a> to save this trip.
                            </p>
                        <?php endif; ?>

                        <section class="group">
                            <h3 class="group__title">Suggested</h3>
                            <p class="group__note">
                                The hybrid route, combined from the three models below.
                            </p>

                            <?php renderRouteCardV09($cardsByLabelV09['Hybrid'], $cardContextV09); ?>
                        </section>

                        <section class="group">
                            <h3 class="group__title">The three models</h3>
                            <p class="group__note">
                                Each one searched the same network on its own. Tap a model to see its route.
                            </p>

                            <?php foreach ($modelLabelsV09 as $labelV09): ?>
                                <?php renderRouteCardV09($cardsByLabelV09[$labelV09], $cardContextV09); ?>
                            <?php endforeach; ?>
                        </section>

                        <section class="group">
                            <h3 class="group__title">Model comparison</h3>

                            <div class="compare-wrap">
                                <table class="compare">
                                    <thead>
                                        <tr>
                                            <th scope="col">Model</th>
                                            <th scope="col">Cost</th>
                                            <th scope="col">Nodes explored</th>
                                            <th scope="col">In hybrid</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($compareRowsV09 as $rowV09): ?>
                                            <tr style="--route-color: <?= eV08($rowV09['color']) ?>;">
                                                <th scope="row">
                                                    <span class="model-tag"><?= eV08($rowV09['label']) ?></span>
                                                </th>

                                                <?php if ($rowV09['result'] === null): ?>
                                                    <td colspan="3" class="compare__none">No path found</td>
                                                <?php else: ?>
                                                    <td><?= number_format($rowV09['result']['cost_min'], 2) ?></td>
                                                    <td>
                                                        <?= number_format($rowV09['result']['nodes_explored']) ?>
                                                        <span class="compare__bar" aria-hidden="true">
                                                            <span style="width: <?= round(100 * $rowV09['explored_share'], 1) ?>%;"></span>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($hybridResultV09 === null): ?>
                                                            —
                                                        <?php else: ?>
                                                            <?= eV08($rowV09['shared_with_hybrid']) ?>
                                                            of
                                                            <?= count($hybridResultV09['path']) ?>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <p class="group__note">
                                Cost is generalized minutes. Fewer nodes explored
                                means the model did less work to find its path.
                                "In hybrid" counts the hybrid route's edges that
                                the model also found on its own.
                            </p>
                        </section>

                    <?php endif; ?>
                </div>

                <p class="fineprint">
                    Times and distances are estimates from straight-line stop
                    spacing, and route data was last serviced in 2020. Check the
                    jeepney's signboard before you board.
                </p>
            </div>
        </div>
    </section>

    <section class="map-area" aria-label="Map">
        <div id="map"></div>

        <details class="map-key" id="map-key" open>
            <summary>Map key</summary>

            <ul class="map-key__list">
                <li><span class="key-pin key-pin--a">A</span> Start</li>
                <li><span class="key-pin key-pin--b">B</span> Destination</li>
                <li><span class="key-line"></span> Jeepney ride</li>
                <li><span class="key-line key-line--walk"></span> Walk between stops</li>
                <li><span class="key-dot"></span> Jeepney change</li>
                <li><span class="key-dot key-dot--walk"></span> Walking transfer</li>
            </ul>

            <p class="map-key__hint">
                Route colours: Hybrid yellow, Dijkstra red, A* green,
                GBFS blue. Tap a model in the list to draw its route;
                the others stay as a faint trace.<br>
                <?php if (!empty($edgePathsV10)): ?>
                    Lines follow estimated road paths (OpenStreetMap / OSRM),
                    not surveyed jeepney paths.
                <?php else: ?>
                    Lines are straight between stops and do not follow roads.
                <?php endif; ?>
            </p>
        </details>
    </section>

</main>

<script type="application/json" id="mapasahe-data"><?= json_encode(
    $pagePayloadV09,
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?></script>

</body>
</html>
<?php

exit;
