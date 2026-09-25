<?php

$routes = json_decode(file_get_contents('routes.json'), true);

// Approximate real-world coordinates for each stop, used only for map
// display (not for routing/scoring — the algorithms still run purely
// on routes.json's time/fare graph). Most of these are well-known
// landmarks (MRT/LRT stations, malls, churches) and are reasonably
// accurate. "EDSA Crossing" and "Stop & Shop" are informal jeepney
// loading-point names rather than official addresses, so their
// coordinates are best-effort placements along the corridor, not
// verified pins — flagged here for anyone editing this list later.
$stopCoordinates = [
    'Boni'            => [14.5729, 121.0396], // MRT-3 Boni Station
    'EDSA Crossing'   => [14.5765, 121.0393], // approximate — informal stop name
    'Quiapo'          => [14.598782, 120.983783], // Quiapo Church, verified
    'SM Manila'       => [14.5904, 120.9819],
    'Shaw Boulevard'  => [14.5822, 121.0530], // MRT-3 Shaw Blvd Station
    'Kalentong'       => [14.5919, 121.0367], // Kalentong St, Mandaluyong
    'Sta. Mesa'       => [14.5975, 121.0198],
    'Stop & Shop'     => [14.5975, 121.0140], // approximate — informal stop name
    'V. Mapa'         => [14.5989, 121.0093], // LRT-2 V. Mapa Station
    'Legarda'         => [14.6013, 120.9866], // LRT-2 Legarda Station
    'Recto'           => [14.6037, 120.9822], // LRT-2 Recto Station
    'Cubao'           => [14.6198, 121.0537], // Araneta Center Cubao
];



function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function buildGraph($routes)
{
    $graph = [];

    foreach ($routes as $route) {

        $graph[$route['from']][] = $route;
    }

    return $graph;
}

function dijkstra($graph, $start, $end)
{
    $queue = [];
    $visited = [];

    $queue[] = [
        'node' => $start,
        'time' => 0,
        'fare' => 0,
        'path' => []
    ];

    while (!empty($queue)) {

        usort($queue, function($a, $b) {
            return $a['time'] <=> $b['time'];
        });

        $current = array_shift($queue);

        if ($current['node'] == $end) {
            return $current;
        }

        if (isset($visited[$current['node']])) {
            continue;
        }

        $visited[$current['node']] = true;

        if (!isset($graph[$current['node']])) {
            continue;
        }

        foreach ($graph[$current['node']] as $neighbor) {

            $newPath = $current['path'];

            $newPath[] = [
                'from' => $neighbor['from'],
                'to' => $neighbor['to'],
                'route' => $neighbor['route'],
                'time' => $neighbor['time'],
                'fare' => $neighbor['fare']
            ];

            $queue[] = [
                'node' => $neighbor['to'],
                'time' => $current['time'] + $neighbor['time'],
                'fare' => $current['fare'] + $neighbor['fare'],
                'path' => $newPath
            ];
        }
    }

    return null;
}

function getNeighbors($graph, $node)
{
    return $graph[$node] ?? [];
}

function estimateRemainingHops(
    $graph,
    $current,
    $destination
) {
    if ($current === $destination) {
        return 0;
    }

    $queue = [
        [
            'node' => $current,
            'hops' => 0
        ]
    ];

    $visited = [];

    while (!empty($queue)) {

        $state = array_shift($queue);

        if (
            $state['node']
            ===
            $destination
        ) {
            return $state['hops'];
        }

        if (
            isset(
                $visited[$state['node']]
            )
        ) {
            continue;
        }

        $visited[$state['node']] = true;

        foreach (
            getNeighbors(
                $graph,
                $state['node']
            )
            as $neighbor
        ) {

            $queue[] = [
                'node' => $neighbor['to'],
                'hops' =>
                    $state['hops'] + 1
            ];
        }
    }

    return 999;
}

function astar($graph, $start, $end)
{
    $queue = [];

    $queue[] = [
        'node' => $start,
        'g' => 0,
        'f' => estimateRemainingHops(
            $graph,
            $start,
            $end
        ),
        'fare' => 0,
        'path' => []
    ];

    $visited = [];

    while (!empty($queue)) {

        usort(
            $queue,
            function ($a, $b) {
                return $a['f'] <=> $b['f'];
            }
        );

        $current =
            array_shift($queue);

        if (
            $current['node']
            ===
            $end
        ) {
            return [
                'node' => $current['node'],
                'time' => $current['g'],
                'fare' => $current['fare'],
                'path' => $current['path']
            ];
        }

        if (
            isset(
                $visited[
                    $current['node']
                ]
            )
        ) {
            continue;
        }

        $visited[
            $current['node']
        ] = true;

        foreach (
            getNeighbors(
                $graph,
                $current['node']
            )
            as $neighbor
        ) {

            $newPath =
                $current['path'];

            $newPath[] = [
                'from' =>
                    $neighbor['from'],
                'to' =>
                    $neighbor['to'],
                'route' =>
                    $neighbor['route'],
                'time' =>
                    $neighbor['time'],
                'fare' =>
                    $neighbor['fare']
            ];

            $g =
                $current['g']
                +
                $neighbor['time'];

            $h =
                estimateRemainingHops(
                    $graph,
                    $neighbor['to'],
                    $end
                );

            $queue[] = [
                'node' =>
                    $neighbor['to'],
                'g' => $g,
                'f' => $g + $h,
                'fare' =>
                    $current['fare']
                    +
                    $neighbor['fare'],
                'path' =>
                    $newPath
            ];
        }
    }

    return null;
}

function gbfs($graph, $start, $end)
{
    $queue = [];

    $queue[] = [
        'node' => $start,
        'heuristic' => 0,
        'fare' => 0,
        'path' => []
    ];

    $visited = [];

    while (!empty($queue)) {

        usort(
            $queue,
            function ($a, $b) {
                return
                    $a['heuristic']
                    <=>
                    $b['heuristic'];
            }
        );

        $current =
            array_shift($queue);

        if (
            $current['node']
            ===
            $end
        ) {

            $totalTime = 0;

            foreach (
                $current['path']
                as $step
            ) {
                $totalTime +=
                    $step['time'];
            }

            return [
                'node' =>
                    $current['node'],
                'time' =>
                    $totalTime,
                'fare' =>
                    $current['fare'],
                'path' =>
                    $current['path']
            ];
        }

        if (
            isset(
                $visited[
                    $current['node']
                ]
            )
        ) {
            continue;
        }

        $visited[
            $current['node']
        ] = true;

        foreach (
            getNeighbors(
                $graph,
                $current['node']
            )
            as $neighbor
        ) {

            $newPath =
                $current['path'];

            $newPath[] = [
                'from' =>
                    $neighbor['from'],
                'to' =>
                    $neighbor['to'],
                'route' =>
                    $neighbor['route'],
                'time' =>
                    $neighbor['time'],
                'fare' =>
                    $neighbor['fare']
            ];

            $queue[] = [
                'node' =>
                    $neighbor['to'],

                'heuristic' =>
                    estimateRemainingHops(
                        $graph,
                        $neighbor['to'],
                        $end
                    ),

                'fare' =>
                    $current['fare']
                    +
                    $neighbor['fare'],

                'path' =>
                    $newPath
            ];
        }
    }

    return null;
}

function scoreRoute($route)
{
    if (!$route) {
        return PHP_INT_MAX;
    }

    $instructions =
        consolidateRoutes(
            $route['path']
        );

    $transferCount =
        max(
            count($instructions) - 1,
            0
        );

    // ESTIMATE — NEEDS VERIFICATION BEFORE FINAL DEMO/DEFENSE.
    // 10 min/transfer is a reasoned placeholder, not a hard citation:
    // Komyut/Inquirer (2019) cites average bus wait ~22 min, train ~26 min.
    // Jeepneys historically have shorter headways (higher frequency,
    // informal wave-to-hail), so ~half the bus figure is used here — but
    // MMDA/Philstar (2025) data shows jeepney unit count roughly halved
    // over the past decade (phaseout), which has likely worsened real
    // wait times since. Replace with a measured/cited jeepney-specific
    // figure before finals, or clearly label this as a modeling
    // assumption in the methodology writeup if it stays an estimate.
    $transferPenaltyMinutes = 10;

    return
        $route['time']
        +
        ($transferCount * $transferPenaltyMinutes);
}

function consolidateRoutes($path)
{
    if (empty($path)) {
        return [];
    }

    $consolidated = [];

    $current = [
        'route' => $path[0]['route'],
        'from' => $path[0]['from'],
        'to' => $path[0]['to'],
        'time' => $path[0]['time'],

        // ONLY CHARGE ONCE PER CONTINUOUS RIDE
        'fare' => $path[0]['fare'],

        'passed' => []
    ];

    for ($i = 1; $i < count($path); $i++) {

        $step = $path[$i];

        // SAME ROUTE = SAME JEEP
        if ($step['route'] === $current['route']) {

            $current['passed'][] = $current['to'];

            $current['to'] = $step['to'];

            $current['time'] += $step['time'];

            // DO NOT ADD FARE AGAIN

        } else {

            $consolidated[] = $current;

            $current = [
                'route' => $step['route'],
                'from' => $step['from'],
                'to' => $step['to'],
                'time' => $step['time'],

                // NEW JEEP = NEW FARE
                'fare' => $step['fare'],

                'passed' => []
            ];
        }
    }

    $consolidated[] = $current;

    return $consolidated;
}

// ============================================================
// BASELINE SELECTION — picks the single best-scoring of the 3
// independent algorithms. Renamed from cooperativeHybridRouting():
// this does NOT synthesize anything, it only selects, so it should
// not be called "hybrid" or "cooperative".
// ============================================================
function selectBestScoringRoute($dijkstraRoute, $astarRoute, $gbfsRoute)
{
    $candidates = [];

    if ($dijkstraRoute) {
        $candidates[] = [
            'algorithm' => 'Dijkstra',
            'score' => scoreRoute($dijkstraRoute)
        ];
    }

    if ($astarRoute) {
        $candidates[] = [
            'algorithm' => 'A*',
            'score' => scoreRoute($astarRoute)
        ];
    }

    if ($gbfsRoute) {
        $candidates[] = [
            'algorithm' => 'GBFS',
            'score' => scoreRoute($gbfsRoute)
        ];
    }

    if (empty($candidates)) {
        return null;
    }

    usort($candidates, function ($a, $b) {
        return $a['score'] <=> $b['score'];
    });

    return $candidates[0];
}

// ============================================================
// CONSENSUS HYBRID SYNTHESIS
// ============================================================

function edgeKey($edge)
{
    return $edge['from'] . '→' . $edge['to'];
}

// Finds contiguous runs of edges that appear in ALL THREE paths
// (edge-level agreement — the strictest of the three tiers discussed:
// edge > node > route-name. Only edge-level is implemented so far).
function findAgreedChains($dijkstraPath, $astarPath, $gbfsPath)
{
    $astarKeys = [];
    foreach ($astarPath as $edge) {
        $astarKeys[edgeKey($edge)] = true;
    }

    $gbfsKeys = [];
    foreach ($gbfsPath as $edge) {
        $gbfsKeys[edgeKey($edge)] = true;
    }

    // Dijkstra's path is used as the reference ordering to walk. This
    // is not a bias toward Dijkstra's route: an edge can only count as
    // "agreed by all three" if it's on Dijkstra's path too, so walking
    // any one of the three paths to find contiguous agreed runs covers
    // the full agreed set either way.
    $chains = [];
    $currentChain = [];

    foreach ($dijkstraPath as $edge) {

        $isAgreed =
            isset($astarKeys[edgeKey($edge)]) &&
            isset($gbfsKeys[edgeKey($edge)]);

        if ($isAgreed) {
            $currentChain[] = $edge;
        } else {
            if (!empty($currentChain)) {
                $chains[] = $currentChain;
                $currentChain = [];
            }
        }
    }

    if (!empty($currentChain)) {
        $chains[] = $currentChain;
    }

    return $chains;
}

// Slices the sub-path of $path that runs from $fromNode to $toNode, in
// order — this is the connectivity filter: a candidate segment is only
// eligible if the algorithm's own path actually passes through both
// boundary nodes, in that order. Returns null if it doesn't.
function sliceSegment($path, $fromNode, $toNode)
{
    $startIndex = null;

    foreach ($path as $i => $edge) {
        if ($edge['from'] === $fromNode) {
            $startIndex = $i;
            break;
        }
    }

    if ($startIndex === null) {
        return null;
    }

    $endIndex = null;

    for ($i = $startIndex; $i < count($path); $i++) {
        if ($path[$i]['to'] === $toNode) {
            $endIndex = $i;
            break;
        }
    }

    if ($endIndex === null) {
        return null;
    }

    return array_slice($path, $startIndex, $endIndex - $startIndex + 1);
}

function segmentTime($segment)
{
    $total = 0;

    foreach ($segment as $edge) {
        $total += $edge['time'];
    }

    return $total;
}

// Fills one gap between agreed chains (or origin/destination). Only
// candidates that pass the connectivity filter are scored; if none of
// the three algorithms has an eligible segment, falls back to a fresh
// Dijkstra search confined to this gap, and says so honestly in the
// output rather than attributing it to an algorithm that didn't
// actually produce it.
function fillGap($graph, $gap, $dijkstraPath, $astarPath, $gbfsPath)
{
    if ($gap['from'] === $gap['to']) {
        return [
            'segment' => [],
            'filled_by' => null
        ];
    }

    $candidates = [];

    $dijkstraSlice = sliceSegment($dijkstraPath, $gap['from'], $gap['to']);
    if ($dijkstraSlice !== null) {
        $candidates[] = [
            'algorithm' => 'Dijkstra',
            'segment' => $dijkstraSlice,
            'time' => segmentTime($dijkstraSlice)
        ];
    }

    $astarSlice = sliceSegment($astarPath, $gap['from'], $gap['to']);
    if ($astarSlice !== null) {
        $candidates[] = [
            'algorithm' => 'A*',
            'segment' => $astarSlice,
            'time' => segmentTime($astarSlice)
        ];
    }

    $gbfsSlice = sliceSegment($gbfsPath, $gap['from'], $gap['to']);
    if ($gbfsSlice !== null) {
        $candidates[] = [
            'algorithm' => 'GBFS',
            'segment' => $gbfsSlice,
            'time' => segmentTime($gbfsSlice)
        ];
    }

    if (empty($candidates)) {

        $fallbackRoute = dijkstra($graph, $gap['from'], $gap['to']);

        if (!$fallbackRoute) {
            // Graph is not connected between these two nodes at all —
            // synthesis cannot proceed for this gap.
            return null;
        }

        return [
            'segment' => $fallbackRoute['path'],
            'filled_by' => 'fallback_direct_search'
        ];
    }

    usort($candidates, function ($a, $b) {

        if ($a['time'] === $b['time']) {
            if ($a['algorithm'] === 'Dijkstra') return -1;
            if ($b['algorithm'] === 'Dijkstra') return 1;
            return 0;
        }

        return $a['time'] <=> $b['time'];
    });

    $winner = $candidates[0];

    return [
        'segment' => $winner['segment'],
        'filled_by' => $winner['algorithm']
    ];
}

// Orchestrates the full synthesis: find agreed chains, fill the gaps
// between/around them (with the connectivity + scoring + fallback
// rules above), stitch everything into one continuous path, and
// validate that it's actually continuous before returning it.
function synthesizeHybridRoute(
    $graph,
    $dijkstraRoute,
    $astarRoute,
    $gbfsRoute,
    $origin,
    $destination
) {
    if (!$dijkstraRoute || !$astarRoute || !$gbfsRoute) {
        // If any one algorithm failed to find a path at all, there is
        // nothing meaningful to "agree" on — treat the whole route as
        // zero agreement rather than comparing against a missing path.
        $chains = [];
    } else {
        $chains = findAgreedChains(
            $dijkstraRoute['path'],
            $astarRoute['path'],
            $gbfsRoute['path']
        );
    }

    $dijkstraPath = $dijkstraRoute ? $dijkstraRoute['path'] : [];
    $astarPath = $astarRoute ? $astarRoute['path'] : [];
    $gbfsPath = $gbfsRoute ? $gbfsRoute['path'] : [];

    $stitchedPath = [];
    $segmentLog = [];

    $cursor = $origin;
    $chainIndex = 0;

    while (true) {

        $nextChainStart = isset($chains[$chainIndex])
            ? $chains[$chainIndex][0]['from']
            : $destination;

        if ($cursor !== $nextChainStart) {

            $gap = [
                'from' => $cursor,
                'to' => $nextChainStart
            ];

            $filled = fillGap(
                $graph,
                $gap,
                $dijkstraPath,
                $astarPath,
                $gbfsPath
            );

            if ($filled === null) {
                // Cannot bridge this gap — the graph itself has no
                // connection here. Fail rather than return a broken route.
                return null;
            }

            foreach ($filled['segment'] as $edge) {
                $stitchedPath[] = $edge;
            }

            if (!empty($filled['segment'])) {
                $segmentLog[] = [
                    'type' => 'gap',
                    'from' => $gap['from'],
                    'to' => $gap['to'],
                    'filled_by' => $filled['filled_by']
                ];
            }
        }

        if (!isset($chains[$chainIndex])) {
            break;
        }

        foreach ($chains[$chainIndex] as $edge) {
            $stitchedPath[] = $edge;
        }

        $segmentLog[] = [
            'type' => 'agreed',
            'from' => $chains[$chainIndex][0]['from'],
            'to' => end($chains[$chainIndex])['to'],
            'filled_by' => null
        ];

        $cursor = end($chains[$chainIndex])['to'];
        $chainIndex++;
    }

    // Validate continuity before trusting the stitched result.
    for ($i = 1; $i < count($stitchedPath); $i++) {
        if ($stitchedPath[$i - 1]['to'] !== $stitchedPath[$i]['from']) {
            return null;
        }
    }

    if (empty($stitchedPath)) {
        return null;
    }

    $totalTime = 0;
    $totalFareRaw = 0;

    foreach ($stitchedPath as $edge) {
        $totalTime += $edge['time'];
        $totalFareRaw += $edge['fare'];
    }

    return [
        'node' => $destination,
        'time' => $totalTime,
        'fare' => $totalFareRaw,
        'path' => $stitchedPath,
        'segment_log' => $segmentLog
    ];
}

// Real, derived explanation of the hybrid route — built from what
// synthesis actually did on this run, not static per-algorithm text.
function generateHybridNotes($segmentLog)
{
    if (empty($segmentLog)) {
        return ['No segments were generated for this route.'];
    }

    $notes = [];

    foreach ($segmentLog as $seg) {

        if ($seg['type'] === 'agreed') {
            $notes[] =
                "Segment {$seg['from']} → {$seg['to']}: agreed upon by " .
                "Dijkstra, A*, and GBFS.";

        } elseif ($seg['filled_by'] === 'fallback_direct_search') {
            $notes[] =
                "Segment {$seg['from']} → {$seg['to']}: no algorithm " .
                "agreement here; filled using a direct shortest-path search.";

        } else {
            $notes[] =
                "Segment {$seg['from']} → {$seg['to']}: no full agreement; " .
                "filled using {$seg['filled_by']}'s path (lowest segment " .
                "time among eligible candidates).";
        }
    }

    return $notes;
}

// Short static description of what an algorithm generally does — used
// only for the 3 baseline results, never for the hybrid (which gets
// real derived notes above).
function algorithmDescription($label)
{
    $descriptions = [
        'Dijkstra' =>
            "Searched for the path with the lowest total travel time.",
        'A*' =>
            "Explored paths guided by an estimated number of remaining " .
            "hops to the destination.",
        'GBFS' =>
            "Advanced toward whichever stop appeared closest to the " .
            "destination by hop count, ignoring accumulated travel time."
    ];

    return $descriptions[$label] ?? '';
}

// Shared finalizer used for all 4 displayed routes (3 baselines +
// hybrid): consolidates same-jeep legs, recalculates real fare, and
// packages everything the display needs.
function finalizeRouteForDisplay($route, $label)
{
    if (!$route) {
        return null;
    }

    $instructions = consolidateRoutes($route['path']);

    $totalFare = 0;
    foreach ($instructions as $instruction) {
        $totalFare += $instruction['fare'];
    }

    return [
        'label' => $label,
        'time' => $route['time'],
        'fare' => $totalFare,
        'instructions' => $instructions,
        'transfer_count' => max(count($instructions) - 1, 0),
        'score' => scoreRoute($route)
    ];
}

$locations = [];

foreach ($routes as $route) {

    $locations[] = $route['from'];

    $locations[] = $route['to'];
}

$locations = array_unique($locations);

sort($locations);

$sameLocationError = false;

$routeResults = [];

$bestBaselineLabel = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $origin = $_POST['origin'];

    $destination = $_POST['destination'];

    if ($origin !== '' && $destination !== '' && $origin === $destination) {

        // Origin and destination are the same — nothing to route.
        // Skip the search entirely instead of letting dijkstra()/astar()/gbfs()
        // return an empty path that would render as a blank result.
        $sameLocationError = true;

    } else {

        $graph = buildGraph($routes);

        $dijkstraRoute = dijkstra($graph, $origin, $destination);
        $astarRoute = astar($graph, $origin, $destination);
        $gbfsRoute = gbfs($graph, $origin, $destination);

        $bestBaseline = selectBestScoringRoute(
            $dijkstraRoute,
            $astarRoute,
            $gbfsRoute
        );

        $bestBaselineLabel = $bestBaseline ? $bestBaseline['algorithm'] : null;

        $hybridRoute = synthesizeHybridRoute(
            $graph,
            $dijkstraRoute,
            $astarRoute,
            $gbfsRoute,
            $origin,
            $destination
        );

        $routeResults['Dijkstra'] = finalizeRouteForDisplay($dijkstraRoute, 'Dijkstra');

        $routeResults['A*'] = finalizeRouteForDisplay($astarRoute, 'A*');

        $routeResults['GBFS'] = finalizeRouteForDisplay($gbfsRoute, 'GBFS');

        $routeResults['Hybrid'] = finalizeRouteForDisplay($hybridRoute, 'Hybrid Consensus');

        if ($hybridRoute && $routeResults['Hybrid']) {
            $routeResults['Hybrid']['notes'] =
                generateHybridNotes($hybridRoute['segment_log']);
        }
    }
}

// Build the data the map needs: every stop's coordinates (always sent,
// so the base map can show all stops even before a search), plus one
// set of per-edge line segments per algorithm/hybrid result that
// actually found a route this request. Per-edge (not one long
// polyline per route) so each edge can carry its own tooltip, and so
// the hybrid route can visually distinguish agreed segments from
// gap-filled ones instead of rendering as one flat color.
$mapStops = [];

foreach ($locations as $location) {
    if (isset($stopCoordinates[$location])) {
        $mapStops[] = [
            'name' => $location,
            'lat'  => $stopCoordinates[$location][0],
            'lng'  => $stopCoordinates[$location][1],
        ];
    }
}

$routeColors = [
    'Dijkstra' => '#e63946',
    'A*'       => '#457b9d',
    'GBFS'     => '#2a9d8f',
    'Hybrid'   => '#f4a300',
];

// Turns one graph edge into a map segment: two points plus a tooltip
// describing the ride. Returns null (skips, doesn't crash) if either
// endpoint has no known coordinates.
function edgeToSegment($edge, $stopCoordinates, $style, $extraLabel = null)
{
    if (!isset($stopCoordinates[$edge['from']]) || !isset($stopCoordinates[$edge['to']])) {
        return null;
    }

    $tooltip = $edge['route'] . ': ' . $edge['from'] . ' → ' . $edge['to'] .
        ' (' . $edge['time'] . ' min, ₱' . $edge['fare'] . ')';

    if ($extraLabel) {
        $tooltip .= ' — ' . $extraLabel;
    }

    return [
        'points'  => [$stopCoordinates[$edge['from']], $stopCoordinates[$edge['to']]],
        'tooltip' => $tooltip,
        'style'   => $style,
    ];
}

// For the hybrid route only: tags each edge in its path with whether
// it came from an agreed chain or a filled gap (and by whom), by
// walking segment_log in the same order the path was stitched.
function tagHybridEdges($hybridRoute)
{
    $tags = [];

    if (empty($hybridRoute)) {
        return $tags;
    }

    $pathEdges = $hybridRoute['path'];
    $cursor = 0;

    foreach ($hybridRoute['segment_log'] as $seg) {
        while ($cursor < count($pathEdges)) {
            $edge = $pathEdges[$cursor];
            $tags[$cursor] = [
                'type'      => $seg['type'],
                'filled_by' => $seg['filled_by'],
            ];
            $cursor++;
            if ($edge['to'] === $seg['to']) {
                break;
            }
        }
    }

    return $tags;
}

$mapRoutes = [];

foreach ($routeResults as $label => $routeResult) {

    if (!$routeResult) {
        continue;
    }

    $rawPath = null;

    if ($label === 'Dijkstra' && isset($dijkstraRoute)) $rawPath = $dijkstraRoute['path'];
    if ($label === 'A*' && isset($astarRoute)) $rawPath = $astarRoute['path'];
    if ($label === 'GBFS' && isset($gbfsRoute)) $rawPath = $gbfsRoute['path'];
    if ($label === 'Hybrid' && isset($hybridRoute)) $rawPath = $hybridRoute['path'];

    if (!$rawPath) {
        continue;
    }

    $segments = [];

    if ($label === 'Hybrid') {

        $edgeTags = tagHybridEdges($hybridRoute);

        foreach ($rawPath as $i => $edge) {

            $tag = $edgeTags[$i] ?? ['type' => 'gap', 'filled_by' => null];

            if ($tag['type'] === 'agreed') {
                $style = 'agreed';
                $note = 'agreed by all 3 algorithms';
            } elseif ($tag['filled_by'] === 'fallback_direct_search') {
                $style = 'fallback';
                $note = 'no agreement here — filled via direct search';
            } else {
                $style = 'gap';
                $note = 'filled using ' . $tag['filled_by'] . '’s segment';
            }

            $seg = edgeToSegment($edge, $stopCoordinates, $style, $note);
            if ($seg) $segments[] = $seg;
        }

    } else {

        foreach ($rawPath as $edge) {
            $seg = edgeToSegment($edge, $stopCoordinates, 'baseline');
            if ($seg) $segments[] = $seg;
        }
    }

    if (!empty($segments)) {
        $mapRoutes[] = [
            'label'    => $label,
            'color'    => $routeColors[$label] ?? '#666666',
            'segments' => $segments,
        ];
    }
}

$mapData = [
    'stops'  => $mapStops,
    'routes' => $mapRoutes,
];?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>MapaSahe — Jeepney Transit Route Planner</title>
  <link rel="stylesheet" href="style.css"/>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
          integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
</head>
<body>

<header class="main-header">
  <div>
    <h1>MapaSahe Route Planner</h1>
    <p>Cooperative Hybrid Graph-Based Approach for Jeepney Transit Routing</p>
  </div>
</header>

<div class="layout">

  <!-- ══ LEFT PANEL ══ -->
  <div class="left-panel">
    <div class="sheet-handle"><div class="sheet-handle-bar"></div></div>

    <div class="panel-header">
      <h2>Plan your commute</h2>
      <p>Select your stops and we'll find the best jeepney route.</p>
    </div>

    <div class="panel-body">

      <!-- GPS status strip -->
      <div class="status-strip" id="status-strip">
        <div class="pulse-dot" id="pulse-dot"></div>
        <span id="status-text">Locating nearest terminal point…</span>
      </div>

      <!-- Form -->
      <form method="POST" id="route-form">
        <div class="form-group">
          <label>Starting Location</label>
          <select name="origin" id="origin-select" required>
            <option value="">Select Origin Stop</option>
            <?php foreach ($locations as $location): ?>
              <option value="<?= e($location) ?>"
                <?= (isset($origin) && $origin == $location) ? 'selected' : '' ?>>
                <?= e($location) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Destination</label>
          <select name="destination" id="dest-select" required>
            <option value="">Select Destination Stop</option>
            <?php foreach ($locations as $location): ?>
              <option value="<?= e($location) ?>"
                <?= (isset($destination) && $destination == $location) ? 'selected' : '' ?>>
                <?= e($location) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <hr class="section-divider"/>

        <!-- Results -->
        <?php if ($sameLocationError): ?>
          <div class="error-card">Please select a different origin and destination.</div>

        <?php elseif (!empty($routeResults)): ?>
          <p class="results-hint">Tap a route to highlight it on the map.</p>
          <div class="results-section">
            <?php
            $routeColors = [
              'Dijkstra' => '#0047AB',
              'A*'       => '#1E90FF',
              'GBFS'     => '#00BFFF',
              'Hybrid'   => '#FF7518',
            ];
            $idx = 0;
            foreach ($routeResults as $label => $routeResult):
              $color   = $routeColors[$label] ?? '#0047AB';
              $isFirst = ($idx === 0);
            ?>
              <div class="result-card <?= $isFirst ? 'active' : '' ?>"
                   data-route="<?= e($label) ?>"
                   style="--route-color:<?= $color ?>;"
                   onclick="selectRoute('<?= e($label) ?>')">

                <?php if (!$routeResult): ?>
                  <div class="card-head">
                    <div class="card-label" style="color:<?= $color ?>">
                      <span class="color-dot" style="background:<?= $color ?>"></span>
                      <?= e($label) ?>
                    </div>
                    <span class="no-route">No path found</span>
                  </div>

                <?php else: ?>
                  <div class="card-head">
                    <div class="card-label" style="color:<?= $color ?>">
                      <span class="color-dot" style="background:<?= $color ?>"></span>
                      <?= e($label) ?>
                      <?php if ($label === $bestBaselineLabel): ?>
                        <span class="best-badge">Best</span>
                      <?php endif; ?>
                    </div>
                    <div class="card-stats-right">
                      <span class="big-stat"><?= $routeResult['time'] ?> min</span>
                      <span class="arrive-time" id="arrive-<?= e($label) ?>"></span>
                    </div>
                  </div>

                  <div class="stat-row">
                    <span class="stat-chip">₱<?= $routeResult['fare'] ?></span>
                    <span class="stat-chip"><?= $routeResult['transfer_count'] + 1 ?> segment<?= $routeResult['transfer_count'] > 0 ? 's' : '' ?></span>
                    <span class="stat-chip">Score <?= $routeResult['score'] ?></span>
                  </div>

                  <!-- Timeline steps — shown only when card is active -->
                  <div class="card-steps">
                    <?php foreach ($routeResult['instructions'] as $k => $instruction): ?>
                      <div class="timeline-step">
                        <div class="timeline-dot" style="background:<?= $color ?>"></div>
                        <div class="timeline-content">
                          <div class="step-route">🚌 <?= e($instruction['route']) ?></div>
                          <div class="step-detail">
                            Board <strong><?= e($instruction['from']) ?></strong>
                            <?php if (!empty($instruction['passed'])): ?>
                              → <?= e(implode(' → ', $instruction['passed'])) ?>
                            <?php endif; ?>
                            → Alight <strong><?= e($instruction['to']) ?></strong>
                          </div>
                          <div class="step-meta"><?= $instruction['time'] ?> mins · ₱<?= $instruction['fare'] ?></div>
                        </div>
                      </div>
                      <?php if ($k < count($routeResult['instructions']) - 1): ?>
                        <div class="transfer-badge">Transfer</div>
                      <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($label === 'Hybrid' && isset($routeResult['notes'])): ?>
                      <ul class="algo-notes">
                        <?php foreach ($routeResult['notes'] as $note): ?>
                          <li><?= e($note) ?></li>
                        <?php endforeach; ?>
                      </ul>
                    <?php else: ?>
                      <p class="algo-desc"><?= e(algorithmDescription($label)) ?></p>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php $idx++; endforeach; ?>
          </div>
        <?php endif; ?>

      </div><!-- end panel-body -->
    </form>
  </div><!-- end left-panel -->

  <!-- ══ RIGHT: Map ══ -->
  <div class="right-map">
    <div id="map"></div>
    <div id="map-legend" class="map-legend"></div>
  </div>

</div><!-- end layout -->

<!-- Sticky CTA -->
<div class="cta-bar">
  <button type="submit" form="route-form">Search Jeepney Route</button>
</div>

<script>
const mapData     = <?= json_encode($mapData) ?>;
const routeColors = { 'Dijkstra':'#0047AB', 'A*':'#1E90FF', 'GBFS':'#00BFFF', 'Hybrid':'#FF7518' };

// ── Arrival time estimates ────────────────────────────────────────────
document.querySelectorAll('[id^="arrive-"]').forEach(function (el) {
  const card = el.closest('.result-card');
  if (!card) return;
  const mins = parseInt(card.querySelector('.big-stat')?.textContent) || 0;
  const arrival = new Date(Date.now() + mins * 60000);
  el.textContent = 'arrives ~' + arrival.toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' });
});

// ── Init map ──────────────────────────────────────────────────────────
const map = L.map('map', { zoomControl: false, attributionControl: true })
             .setView([14.5955, 121.0000], 13);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '&copy; OpenStreetMap contributors', maxZoom: 19
}).addTo(map);

L.control.zoom({ position: 'topright' }).addTo(map);

// ── Stop markers ──────────────────────────────────────────────────────
const stopMarkers = {};
let boundsPoints  = [];

mapData.stops.forEach(function (stop) {
  const marker = L.circleMarker([stop.lat, stop.lng], {
    radius: 7, color: '#002244', weight: 2,
    fillColor: '#1E90FF', fillOpacity: 0.95
  }).addTo(map).bindPopup('<b>' + stop.name + '</b>');
  stopMarkers[stop.name] = marker;
  boundsPoints.push([stop.lat, stop.lng]);
});

// ── Per-segment style ─────────────────────────────────────────────────
function segmentStyle(route, segment, dimmed) {
  const opacity = dimmed ? 0.1 : (route.label !== 'Hybrid' ? 0.75 : 0.95);
  const weight  = dimmed ? 2   : (segment.style === 'agreed' ? 6 : 4);
  if (route.label !== 'Hybrid') {
    return { color: route.color, weight, opacity, dashArray: '6 5' };
  }
  if (segment.style === 'agreed')   return { color: route.color, weight, opacity, dashArray: null };
  if (segment.style === 'fallback') return { color: route.color, weight, opacity, dashArray: '2 8' };
  return { color: route.color, weight, opacity, dashArray: '10 4' };
}

// ── Route polylines + legend ──────────────────────────────────────────
const routeLayers    = {};
const routePolylines = {};
const legend         = document.getElementById('map-legend');

mapData.routes.forEach(function (route) {
  const layerGroup = L.layerGroup().addTo(map);
  const polylines  = [];

  route.segments.forEach(function (segment) {
    const latlngs = segment.points.map(function (p) { return [p[0], p[1]]; });
    const style   = segmentStyle(route, segment, false);
    const line    = L.polyline(latlngs, style)
                     .bindTooltip(segment.tooltip, { sticky: true })
                     .addTo(layerGroup);
    polylines.push({ line, segment });
    boundsPoints = boundsPoints.concat(latlngs);
  });

  routeLayers[route.label]    = layerGroup;
  routePolylines[route.label] = polylines;

  const item = document.createElement('label');
  item.className   = 'legend-item';
  item.dataset.route = route.label;
  item.innerHTML   =
    '<span class="legend-swatch" style="background:' + route.color + '"></span>' +
    route.label;
  legend.appendChild(item);
});

if (mapData.routes.length > 0) {
  const hint = document.createElement('div');
  hint.className   = 'legend-hint';
  hint.textContent = 'Hybrid: thick solid = agreed by all 3, dashed = gap-filled. Tap a card to highlight.';
  legend.appendChild(hint);
}

if (boundsPoints.length > 0) {
  map.fitBounds(boundsPoints, { padding: [30, 30] });
}

// ── Card → map highlight ──────────────────────────────────────────────
let activeRoute = null;

const firstCard = document.querySelector('.result-card[data-route]');
if (firstCard) {
  activeRoute = firstCard.dataset.route;
  applyHighlight(activeRoute);
}

function selectRoute(label) {
  if (activeRoute === label) {
    activeRoute = null;
    Object.keys(routePolylines).forEach(function (l) {
      routePolylines[l].forEach(function (p) {
        p.line.setStyle(segmentStyle({ label: l, color: routeColors[l] }, p.segment, false));
      });
    });
    document.querySelectorAll('.result-card').forEach(function (c) {
      c.classList.remove('active', 'dimmed');
    });
    document.querySelectorAll('.legend-item').forEach(function (i) {
      i.classList.remove('active', 'dimmed');
    });
    return;
  }
  activeRoute = label;
  applyHighlight(label);
}

function applyHighlight(label) {
  document.querySelectorAll('.result-card').forEach(function (card) {
    const isActive = card.dataset.route === label;
    card.classList.toggle('active',  isActive);
    card.classList.toggle('dimmed', !isActive);
  });
  document.querySelectorAll('.legend-item').forEach(function (item) {
    const isActive = item.dataset.route === label;
    item.classList.toggle('active',  isActive);
    item.classList.toggle('dimmed', !isActive);
  });
  Object.keys(routePolylines).forEach(function (l) {
    const isDimmed = (l !== label);
    routePolylines[l].forEach(function (p) {
      p.line.setStyle(segmentStyle({ label: l, color: routeColors[l] }, p.segment, isDimmed));
      if (!isDimmed) p.line.bringToFront();
    });
  });
  const active = routePolylines[label];
  if (active && active.length > 0) {
    const pts = [];
    active.forEach(function (p) {
      p.line.getLatLngs().forEach(function (ll) { pts.push(ll); });
    });
    if (pts.length > 0) {
      map.fitBounds(L.latLngBounds(pts), { padding: [40, 40], maxZoom: 15 });
    }
  }
}

// ── GPS ───────────────────────────────────────────────────────────────
const statusEl  = document.getElementById('status-strip');
const statusTxt = document.getElementById('status-text');
const pulseDot  = document.getElementById('pulse-dot');

function haversineKm(lat1, lng1, lat2, lng2) {
  const R = 6371, r = d => d * Math.PI / 180;
  const dLat = r(lat2-lat1), dLng = r(lng2-lng1);
  const a = Math.sin(dLat/2)**2 + Math.cos(r(lat1))*Math.cos(r(lat2))*Math.sin(dLng/2)**2;
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

function setStatus(msg, type) {
  pulseDot.style.display = type === 'locating' ? 'block' : 'none';
  statusEl.className = 'status-strip' + (type === 'ok' ? ' ok' : type === 'warn' ? ' warn' : '');
  statusTxt.textContent = msg;
}

if (navigator.geolocation) {
  navigator.geolocation.getCurrentPosition(
    function (position) {
      const userLat = position.coords.latitude;
      const userLng = position.coords.longitude;

      L.marker([userLat, userLng], {
        icon: L.divIcon({ className: 'user-location-dot', iconSize: [14, 14] })
      }).addTo(map).bindPopup('Your Current Location').openPopup();

      let nearestStop = null, nearestDist = Infinity, nearestCoords = null;
      mapData.stops.forEach(function (stop) {
        const d = haversineKm(userLat, userLng, stop.lat, stop.lng);
        if (d < nearestDist) {
          nearestDist = d; nearestStop = stop.name;
          nearestCoords = [stop.lat, stop.lng];
        }
      });

      if (nearestStop) {
        setStatus('Nearest stop: ' + nearestStop + ' (~' + nearestDist.toFixed(1) + ' km)', 'ok');
        L.polyline([[userLat, userLng], nearestCoords], {
          color: '#1E90FF', weight: 2, opacity: 0.8, dashArray: '4 4'
        }).addTo(map);
        const sel = document.getElementById('origin-select');
        if (sel && !sel.value) sel.value = nearestStop;
      } else {
        setStatus('Location found, but no known stop nearby.', 'warn');
      }
    },
    function () { setStatus('Location unavailable — select stops manually.', 'warn'); }
  );
} else {
  setStatus('Geolocation not supported by this browser.', 'warn');
}

setTimeout(function () { map.invalidateSize(); }, 400);
</script>

</body>
</html>
