<?php

/*
 * build_matched_paths.php
 *
 * ONE-TIME helper script. It is NOT part of the route planner itself.
 * Third attempt at road paths for the map, after:
 *   build_edge_geometry.php  (one hop at a time)      -> U-turns, loops
 *   build_road_paths.php     (whole route, no U-turn) -> worse detours
 *
 * Why those failed:
 *   The stop coordinates in the source feed are coarse (longitude has
 *   only 3 decimals, so a stop can be about 54 m off east-west). The
 *   earlier scripts always put each stop on the NEAREST road, which is
 *   often the wrong street.
 *
 * What this script does differently:
 *   It uses OSRM's "map matching" service, which is made for imprecise
 *   points. For each route it sends all stops in order with a
 *   tolerance radius. OSRM considers every road within that radius of
 *   each stop and picks the combination that makes the most plausible
 *   continuous drive. Stops it cannot fit are left out, not forced.
 *
 *   For hops that get no matched path, the script reuses the path from
 *   data/mapasahe_edge_geometry_v1.json (the first attempt) if that
 *   file exists, and marks it as a fallback.
 *
 *   Output: data/mapasahe_edge_geometry_v3.json
 *   system1_1.php uses it ONLY to draw route lines. Pathfinding costs
 *   are not touched.
 *
 * IMPORTANT - these paths are ESTIMATES, not surveyed jeepney paths.
 * Verify or remove them before the final defense.
 *
 * How to run (from the project folder, needs internet):
 *   C:\xampp\php\php.exe build_matched_paths.php
 *
 * The public server refuses a map-matching request with more than
 * 10 stops, so longer routes are sent in pieces of at most 10 stops
 * (each piece starts at the stop where the previous one ended).
 *
 * If it stops halfway, run it again: finished routes are skipped.
 * Routes that an earlier run recorded as refused ("TooBig") are
 * redone automatically.
 */

if (PHP_SAPI !== 'cli') {
    die('Run this from the command line: php build_matched_paths.php');
}

set_time_limit(0);
ini_set('memory_limit', '256M');

$graphPath = __DIR__ . '/data/mapasahe_graph_v2.json';
$fallbackPath = __DIR__ . '/data/mapasahe_edge_geometry_v1.json';
$outputPath = __DIR__ . '/data/mapasahe_edge_geometry_v3.json';

$osrmServer = 'router.project-osrm.org';

// Tolerance (metres) given to OSRM for each stop's position. 25 m is
// the value that worked on the first run: every route the server
// accepted had all of its hops matched.
$radiusMeters = 25;

// The public server answers "TooBig" for more than 10 stops in one
// map-matching request (found on the first run: every route with up
// to 10 stops was accepted, every route with 11 or more was refused).
$maximumStopsPerRequest = 10;

// A hop's road path is NOT drawn (the straight line is used) when it
// is BOTH more than 3 times the straight-line distance AND more than
// 300 m longer than it. The path is still saved in the file.
$maximumDetourRatio = 3.0;
$maximumDetourExtraMeters = 300.0;

$secondsBetweenRequests = 1;
$requestTimeoutSeconds = 60;
$maximumConsecutiveFailures = 5;

function straightDistanceMetersV11($latitude1, $longitude1, $latitude2, $longitude2)
{
    $earthRadiusMeters = 6371000;

    $latitude1 = deg2rad($latitude1);
    $longitude1 = deg2rad($longitude1);
    $latitude2 = deg2rad($latitude2);
    $longitude2 = deg2rad($longitude2);

    $a =
        sin(($latitude2 - $latitude1) / 2) ** 2 +
        cos($latitude1) *
        cos($latitude2) *
        sin(($longitude2 - $longitude1) / 2) ** 2;

    $a = min(1.0, max(0.0, $a));

    return $earthRadiusMeters * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/*
 * Downloads one URL. Returns ['body' => string|null, 'error' => string].
 * Uses cURL when the extension is enabled, otherwise file_get_contents.
 * OSRM sends a JSON body even with HTTP 400, so the body is returned
 * whatever the status code.
 */
function fetchUrlV11($url, $timeoutSeconds)
{
    $userAgent = 'MapaSahe-student-thesis/1.1';

    if (function_exists('curl_init')) {
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => $userAgent
        ]);

        $body = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            return ['body' => null, 'error' => $error];
        }

        return ['body' => $body, 'error' => ''];
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => $timeoutSeconds,
            'user_agent' => $userAgent,
            'ignore_errors' => true
        ]
    ]);

    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        $lastError = error_get_last();

        return [
            'body' => null,
            'error' => $lastError['message'] ?? 'download failed'
        ];
    }

    return ['body' => $body, 'error' => ''];
}

/*
 * Turns one OSRM "leg" (the drive from one stop to the next) into a
 * list of [latitude, longitude] points. A leg is made of steps; the
 * last point of one step repeats as the first point of the next, so
 * repeated points are dropped while joining them.
 */
function legToPointsV11($leg)
{
    $points = [];

    foreach ($leg['steps'] ?? [] as $step) {
        foreach ($step['geometry']['coordinates'] ?? [] as $coordinate) {
            // GeoJSON is [longitude, latitude]; Leaflet wants
            // [latitude, longitude].
            $point = [
                round((float) $coordinate[1], 5),
                round((float) $coordinate[0], 5)
            ];

            $lastPoint = empty($points)
                ? null
                : $points[count($points) - 1];

            if ($lastPoint !== $point) {
                $points[] = $point;
            }
        }
    }

    return $points;
}

function saveMatchedPathFileV11($outputPath, $metadata, $routesDone, $paths)
{
    $counts = [];

    foreach ($paths as $path) {
        $counts[$path['status']] = ($counts[$path['status']] ?? 0) + 1;
    }

    $metadata['counts'] = $counts;
    $metadata['last_saved'] = date('c');

    $json = json_encode(
        [
            'metadata' => $metadata,
            'routes_done' => $routesDone,
            'paths' => $paths
        ],
        JSON_UNESCAPED_SLASHES
    );

    // Write to a temporary file first so an interrupted save cannot
    // leave a half-written (corrupt) file behind.
    $temporaryPath = $outputPath . '.tmp';
    file_put_contents($temporaryPath, $json);
    rename($temporaryPath, $outputPath);
}

// ------------------------------------------------------------
// Load the graph and list each route's hops in travel order.
// ------------------------------------------------------------

if (!file_exists($graphPath)) {
    die("Graph file not found: $graphPath\n");
}

$dataset = json_decode(file_get_contents($graphPath), true);

if (!is_array($dataset)) {
    die("Graph file is not valid JSON.\n");
}

$nodesById = [];

foreach ($dataset['nodes'] as $node) {
    $nodesById[$node['node_id']] = $node;
}

// Jeepney edges of one route appear in the dataset in travel order.
// Walking transfers are skipped: they stay straight dotted lines.
$routeHops = [];

foreach ($dataset['edges'] as $edge) {
    if (($edge['edge_type'] ?? null) !== 'jeepney') {
        continue;
    }

    $routeId = $edge['route_id'] ?? null;

    if (
        $routeId === null ||
        !isset($nodesById[$edge['from']]) ||
        !isset($nodesById[$edge['to']])
    ) {
        continue;
    }

    $routeHops[$routeId][] = [$edge['from'], $edge['to']];
}

// First-attempt paths (keyed "from->to"), used only as a fallback.
$fallbackPaths = [];

if (file_exists($fallbackPath)) {
    $fallbackFile = json_decode(file_get_contents($fallbackPath), true);

    if (is_array($fallbackFile) && isset($fallbackFile['paths'])) {
        $fallbackPaths = $fallbackFile['paths'];
    }
}

// ------------------------------------------------------------
// Resume from an earlier run if the output file already exists.
// ------------------------------------------------------------

$paths = [];
$routesDone = [];

if (file_exists($outputPath)) {
    $existing = json_decode(file_get_contents($outputPath), true);

    if (is_array($existing) && isset($existing['paths'])) {
        $paths = $existing['paths'];
        $routesDone = $existing['routes_done'] ?? [];
    }
}

$metadata = [
    'file_name' => 'mapasahe_edge_geometry_v3.json',
    'belongs_to_graph' => 'mapasahe_graph_v2.json',
    'purpose' =>
        'Map drawing only. Not used for pathfinding costs.',
    'status' =>
        'ESTIMATED. Road paths inferred from coarse stop coordinates, ' .
        'not surveyed jeepney paths. Verify or remove before the final defense.',
    'source' =>
        'OSRM public demo server (' . $osrmServer . '), ' .
        'road data (c) OpenStreetMap contributors (ODbL).',
    'method' =>
        'OSRM map matching per jeepney route (stops in order, ' .
        $radiusMeters . ' m tolerance per stop, sent in pieces of at most ' .
        $maximumStopsPerRequest . ' stops), split into one path per hop. ' .
        'Hops without a match reuse mapasahe_edge_geometry_v1.json when available.',
    'key_format' => 'route_id|from_node_id->to_node_id',
    'point_format' => '[latitude, longitude]',
    'status_values' => [
        'osrm_matched' => 'path from map matching (drawn)',
        'osrm_route_fallback' => 'path from the first attempt, hop by hop (drawn)',
        'kept_straight_detour' => 'path saved but judged too long a detour (straight line drawn)',
        'no_path' => 'no path available (straight line drawn)'
    ],
    'rejection_rule' =>
        'kept_straight_detour when road distance is more than ' .
        $maximumDetourRatio . 'x the straight-line distance and more than ' .
        $maximumDetourExtraMeters . ' m longer than it.'
];

$totalRoutes = count($routeHops);
$alreadyDone = 0;

foreach ($routeHops as $routeId => $hops) {
    if (
        isset($routesDone[$routeId]) &&
        strpos($routesDone[$routeId], 'TooBig') !== 0
    ) {
        $alreadyDone++;
    }
}

echo "Jeepney routes: $totalRoutes\n";
echo "Already done in an earlier run: $alreadyDone\n";
echo "To request now: " . ($totalRoutes - $alreadyDone) . "\n";
echo "First-attempt paths available as fallback: " . count($fallbackPaths) . "\n\n";

// ------------------------------------------------------------
// Main loop: one map-matching request per route.
// ------------------------------------------------------------

$scheme = 'https';
$consecutiveFailures = 0;
$failedRoutes = [];

foreach ($routeHops as $routeId => $hops) {
    // Skip finished routes, but redo any that an earlier run recorded
    // as refused by the server.
    if (
        isset($routesDone[$routeId]) &&
        strpos($routesDone[$routeId], 'TooBig') !== 0
    ) {
        continue;
    }

    // Stop list: the first hop's start, then every hop's end.
    $stopIds = [$hops[0][0]];

    foreach ($hops as $hop) {
        $stopIds[] = $hop[1];
    }

    $lastStopIndex = count($stopIds) - 1;

    $routeRequestFailed = false;
    $matchedHopCount = 0;
    $requestCount = 0;
    $answerCodes = [];

    /*
     * Send the route in pieces of at most $maximumStopsPerRequest
     * stops. A piece covers stops $pieceStart .. $pieceEnd, and the
     * next piece starts at $pieceEnd, so every hop is in exactly one
     * piece.
     */
    $pieceStart = 0;

    while ($pieceStart < $lastStopIndex) {
        $pieceEnd = min(
            $pieceStart + $maximumStopsPerRequest - 1,
            $lastStopIndex
        );

        // OSRM expects longitude first, then latitude.
        $coordinateParts = [];

        for ($stopIndex = $pieceStart; $stopIndex <= $pieceEnd; $stopIndex++) {
            $stopNode = $nodesById[$stopIds[$stopIndex]];

            $coordinateParts[] =
                $stopNode['longitude'] . ',' . $stopNode['latitude'];
        }

        $requestPath =
            '://' . $osrmServer . '/match/v1/driving/' .
            implode(';', $coordinateParts) .
            '?overview=false&steps=true&geometries=geojson' .
            '&gaps=ignore&tidy=false' .
            '&radiuses=' . implode(
                ';',
                array_fill(0, count($coordinateParts), $radiusMeters)
            );

        $response = fetchUrlV11($scheme . $requestPath, $requestTimeoutSeconds);

        // Some Windows PHP installs cannot keep an HTTPS connection to
        // this server. The data is public map geometry, so plain HTTP
        // is an acceptable fallback here.
        if ($response['body'] === null && $scheme === 'https') {
            $retry = fetchUrlV11('http' . $requestPath, $requestTimeoutSeconds);

            if ($retry['body'] !== null) {
                echo "HTTPS failed (" . $response['error'] . "); using HTTP instead.\n";
                $scheme = 'http';
                $response = $retry;
            }
        }

        $requestCount++;

        $result = $response['body'] === null
            ? null
            : json_decode($response['body'], true);

        sleep($secondsBetweenRequests);

        if (!is_array($result) || !isset($result['code'])) {
            // Network problem: give up on this route for now. It is
            // not marked as done, so the next run retries all of it.
            $routeRequestFailed = true;

            echo "Request failed for route $routeId: " . $response['error'] . "\n";

            break;
        }

        $answerCodes[$result['code']] = true;

        /*
         * Reading a map-matching answer:
         *   "tracepoints" has one entry per stop we sent, in the same
         *   order. An entry is null when OSRM could not fit that stop.
         *   Otherwise it says which matching (matchings_index) the
         *   stop belongs to and its position inside it
         *   (waypoint_index). Leg number N of a matching is the drive
         *   from its waypoint N to its waypoint N+1.
         *
         * So a hop has a matched path only when both of its stops
         * were fitted, in the same matching, one right after the
         * other.
         */
        $tracepoints = $result['tracepoints'] ?? [];
        $matchings = $result['matchings'] ?? [];

        for ($hopIndex = $pieceStart; $hopIndex < $pieceEnd; $hopIndex++) {
            $hop = $hops[$hopIndex];
            $fromNode = $nodesById[$hop[0]];
            $toNode = $nodesById[$hop[1]];

            $straightDistance = straightDistanceMetersV11(
                (float) $fromNode['latitude'],
                (float) $fromNode['longitude'],
                (float) $toNode['latitude'],
                (float) $toNode['longitude']
            );

            // Position of this hop's two stops inside this piece.
            $startPoint = $tracepoints[$hopIndex - $pieceStart] ?? null;
            $endPoint = $tracepoints[$hopIndex - $pieceStart + 1] ?? null;

            $leg = null;

            if (
                $result['code'] === 'Ok' &&
                is_array($startPoint) &&
                is_array($endPoint) &&
                $startPoint['matchings_index'] === $endPoint['matchings_index'] &&
                $endPoint['waypoint_index'] === $startPoint['waypoint_index'] + 1
            ) {
                $leg =
                    $matchings[$startPoint['matchings_index']]['legs']
                    [$startPoint['waypoint_index']] ?? null;
            }

            $status = 'no_path';
            $points = [];
            $roadDistance = null;

            if ($leg !== null) {
                $status = 'osrm_matched';
                $points = legToPointsV11($leg);
                $roadDistance = (float) ($leg['distance'] ?? 0);
            } else {
                // No matched path: reuse the first attempt's path for
                // this stop pair, if it had an accepted one.
                $fallback = $fallbackPaths[$hop[0] . '->' . $hop[1]] ?? null;

                if (
                    is_array($fallback) &&
                    ($fallback['status'] ?? '') === 'osrm_estimated' &&
                    !empty($fallback['points'])
                ) {
                    $status = 'osrm_route_fallback';
                    $points = $fallback['points'];
                    $roadDistance = (float) ($fallback['road_distance_m'] ?? 0);
                }
            }

            if (
                $status === 'osrm_matched' &&
                $roadDistance > $straightDistance * $maximumDetourRatio &&
                $roadDistance - $straightDistance > $maximumDetourExtraMeters
            ) {
                $status = 'kept_straight_detour';
            }

            if (count($points) < 2) {
                $status = 'no_path';
                $points = [];
            }

            if ($status === 'osrm_matched') {
                $matchedHopCount++;
            }

            $paths[$routeId . '|' . $hop[0] . '->' . $hop[1]] = [
                'status' => $status,
                'straight_distance_m' => round($straightDistance, 1),
                'road_distance_m' =>
                    $roadDistance === null ? null : round($roadDistance, 1),
                'points' => $points
            ];
        }

        $pieceStart = $pieceEnd;
    }

    if ($routeRequestFailed) {
        $consecutiveFailures++;
        $failedRoutes[$routeId] = true;
        unset($routesDone[$routeId]);

        if ($consecutiveFailures >= $maximumConsecutiveFailures) {
            saveMatchedPathFileV11($outputPath, $metadata, $routesDone, $paths);

            die(
                "\nStopped after $maximumConsecutiveFailures failed routes in a row. " .
                "Wait a few minutes, then run the script again " .
                "(finished routes are kept).\n"
            );
        }

        continue;
    }

    $consecutiveFailures = 0;

    $routesDone[$routeId] =
        implode('+', array_keys($answerCodes)) . ': ' .
        $matchedHopCount . ' of ' . count($hops) . ' hops matched, radius ' .
        $radiusMeters . ' m, ' . $requestCount . ' request(s)';

    // Saving after every route means an interruption never loses
    // finished work.
    saveMatchedPathFileV11($outputPath, $metadata, $routesDone, $paths);

    $doneCount = count(array_intersect_key($routesDone, $routeHops));

    if ($doneCount % 10 === 0 || $doneCount === $totalRoutes) {
        echo "$doneCount / $totalRoutes routes done\n";
    }
}

saveMatchedPathFileV11($outputPath, $metadata, $routesDone, $paths);

// ------------------------------------------------------------
// Summary.
// ------------------------------------------------------------

$summary = [];

foreach ($paths as $path) {
    $summary[$path['status']] = ($summary[$path['status']] ?? 0) + 1;
}

echo "\nFinished. Saved to $outputPath\n";
echo "Hops drawn from map matching: " . ($summary['osrm_matched'] ?? 0) . "\n";
echo "Hops drawn from first-attempt fallback: " . ($summary['osrm_route_fallback'] ?? 0) . "\n";
echo "Hops kept straight (detour too long): " . ($summary['kept_straight_detour'] ?? 0) . "\n";
echo "Hops kept straight (no path): " . ($summary['no_path'] ?? 0) . "\n";
echo "Total hops: " . count($paths) . " of 2225\n";

if (!empty($failedRoutes)) {
    echo
        "Routes whose request failed this run: " . count($failedRoutes) .
        ". Run the script again to retry them.\n";
}
