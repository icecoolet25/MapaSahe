<?php
/*
 * JSON for the admin dashboard. Admins only.
 *
 *   admin_api.php?view=summary&range=today|7d|30d|all
 *     -> { range, generated_at, kpis, attention[], models[], stages[],
 *          timeline[], paths[], stops[], event_types[] }
 *
 *   admin_api.php?view=history&type=&from=YYYY-MM-DD&to=YYYY-MM-DD
 *                &before_id=&limit=50
 *     -> { events: [{ id, at, type, user, details }], has_more }
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

$user = mapasaheUser();

if ($user === null || $user['role'] !== 'admin') {
    respond(['error' => 'Admins only.'], 403);
}

// Let other requests from this browser run while the numbers are computed.
session_write_close();

$db = mapasaheDb();

if ($db === null) {
    respond(['error' => 'Cannot reach the database. Is MySQL running?'], 503);
}

function average(array $values): ?float
{
    return count($values) > 0 ? array_sum($values) / count($values) : null;
}

/* Nearest-rank percentile: 50 = median, 95 = "slow but not freak" case. */
function percentile(array $values, float $percent): ?float
{
    if (count($values) === 0) {
        return null;
    }

    sort($values);

    $rank = (int) ceil($percent / 100 * count($values));

    return $values[max(0, min(count($values) - 1, $rank - 1))];
}

function rounded(?float $value, int $places = 2): ?float
{
    return $value === null ? null : round($value, $places);
}

$view = (string) ($_GET['view'] ?? 'summary');

try {
    // ── History log ───────────────────────────────────────────
    if ($view === 'history') {
        $where = ['1 = 1'];
        $params = [];

        $type = (string) ($_GET['type'] ?? '');
        $from = (string) ($_GET['from'] ?? '');
        $to = (string) ($_GET['to'] ?? '');
        $beforeId = (int) ($_GET['before_id'] ?? 0);
        $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));

        if ($type !== '') {
            $where[] = 'e.type = ?';
            $params[] = $type;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'e.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'e.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        if ($beforeId > 0) {
            $where[] = 'e.id < ?';
            $params[] = $beforeId;
        }

        // One extra row tells us whether there is another page.
        $stmt = $db->prepare(
            'SELECT e.id, e.created_at, e.type, e.details, u.username
             FROM events e LEFT JOIN users u ON u.id = e.user_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY e.id DESC LIMIT ' . ($limit + 1)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        respond([
            'events' => array_map(function ($row) {
                return [
                    'id' => (int) $row['id'],
                    'at' => $row['created_at'],
                    'type' => $row['type'],
                    'user' => $row['username'] ?? 'Guest',
                    'details' => $row['details']
                ];
            }, $rows),
            'has_more' => $hasMore
        ]);
    }

    // ── Summary ───────────────────────────────────────────────
    $ranges = [
        'today' => date('Y-m-d 00:00:00'),
        '7d' => date('Y-m-d H:i:s', time() - 7 * 86400),
        '30d' => date('Y-m-d H:i:s', time() - 30 * 86400),
        'all' => '1970-01-01 00:00:00'
    ];

    $range = (string) ($_GET['range'] ?? '7d');

    if (!isset($ranges[$range])) {
        $range = '7d';
    }

    $since = $ranges[$range];

    // Searches in range, newest first. "invalid" ones never reached the
    // models, so they are counted separately and left out of timings.
    $stmt = $db->prepare(
        'SELECT id, created_at, status, load_ms, total_ms
         FROM search_logs WHERE created_at >= ? ORDER BY id DESC LIMIT 5000'
    );
    $stmt->execute([$since]);
    $allSearches = $stmt->fetchAll();

    $searches = [];
    $invalidCount = 0;

    foreach ($allSearches as $row) {
        if ($row['status'] === 'invalid') {
            $invalidCount++;
        } else {
            $searches[] = $row;
        }
    }

    $totalTimes = array_map('floatval', array_column($searches, 'total_ms'));
    $loadTimes = array_map('floatval', array_column($searches, 'load_ms'));
    $okCount = count(array_filter($searches, function ($row) {
        return $row['status'] === 'ok';
    }));

    $recentTimes = array_slice($totalTimes, 0, 20);

    // Model runs in range.
    $stmt = $db->prepare(
        "SELECT m.model, m.found, m.duration_ms, m.nodes_explored, m.cost_min
         FROM model_runs m JOIN search_logs s ON s.id = m.search_id
         WHERE s.created_at >= ? AND s.status <> 'invalid'
         ORDER BY m.id DESC LIMIT 20000"
    );
    $stmt->execute([$since]);

    $byModel = [];

    foreach ($stmt->fetchAll() as $row) {
        $byModel[$row['model']][] = $row;
    }

    $modelInfo = [
        'Dijkstra' => [
            'color' => '#0047AB',
            'big_o' => 'O((V + E) log V)',
            'note' => 'Expands outward from the start in cost order. Always returns the lowest-cost path, and visits the most stops doing it.'
        ],
        'A*' => [
            'color' => '#1E90FF',
            'big_o' => 'O((V + E) log V) worst case',
            'note' => 'Same guarantee as Dijkstra, but the straight-line distance to the destination lets it skip stops that lead away.'
        ],
        'GBFS' => [
            'color' => '#00BFFF',
            'big_o' => 'O((V + E) log V) worst case',
            'note' => 'Follows the stop that looks closest to the destination. Usually visits the fewest stops, with no guarantee the path is the cheapest.'
        ],
        'Hybrid' => [
            'color' => '#FF7518',
            'big_o' => 'O(P) on top of the three searches',
            'note' => 'Stitches the three results together. Its own time is only the stitching; its real cost is the three searches added up.'
        ]
    ];

    $models = [];
    $fastest = null;

    foreach ($modelInfo as $name => $info) {
        $runs = $byModel[$name] ?? [];
        $times = array_map('floatval', array_column($runs, 'duration_ms'));
        $found = array_values(array_filter($runs, function ($run) {
            return (int) $run['found'] === 1;
        }));

        $entry = [
            'model' => $name,
            'color' => $info['color'],
            'big_o' => $info['big_o'],
            'note' => $info['note'],
            'runs' => count($runs),
            'found' => count($found),
            'success_rate' => count($runs) > 0 ? round(100 * count($found) / count($runs), 1) : null,
            'error_rate' => count($runs) > 0 ? round(100 * (count($runs) - count($found)) / count($runs), 1) : null,
            'avg_ms' => rounded(average($times), 3),
            'median_ms' => rounded(percentile($times, 50), 3),
            'p95_ms' => rounded(percentile($times, 95), 3),
            'recent_avg_ms' => rounded(average(array_slice($times, 0, 20)), 3),
            'avg_nodes' => rounded(average(array_map('floatval', array_column($found, 'nodes_explored'))), 1),
            'avg_cost' => rounded(average(array_map('floatval', array_column($found, 'cost_min'))), 2),
            'relative_cost' => null
        ];

        if ($name !== 'Hybrid' && $entry['avg_ms'] !== null && $entry['avg_ms'] > 0) {
            $fastest = $fastest === null ? $entry['avg_ms'] : min($fastest, $entry['avg_ms']);
        }

        $models[] = $entry;
    }

    // Relative cost: 1.0x is the quickest of the three models.
    foreach ($models as &$entry) {
        if ($entry['model'] !== 'Hybrid' && $fastest !== null && $entry['avg_ms'] !== null) {
            $entry['relative_cost'] = round($entry['avg_ms'] / $fastest, 2);
        }
    }

    unset($entry);

    // Where a request's time goes, stage by stage.
    $stages = [[
        'stage' => 'Load network',
        'detail' => 'Read the dataset and build the graph',
        'color' => '#64748B',
        'avg_ms' => rounded(average($loadTimes), 3)
    ]];

    foreach ($models as $entry) {
        $stages[] = [
            'stage' => $entry['model'] === 'Hybrid' ? 'Hybrid stitching' : $entry['model'] . ' search',
            'detail' => $entry['big_o'],
            'color' => $entry['color'],
            'avg_ms' => $entry['avg_ms']
        ];
    }

    $timeline = array_map(function ($row) {
        return [
            'id' => (int) $row['id'],
            'at' => $row['created_at'],
            'total_ms' => round((float) $row['total_ms'], 2),
            'status' => $row['status']
        ];
    }, array_reverse(array_slice($searches, 0, 60)));

    // Most searched origin -> destination pairs.
    $stmt = $db->prepare(
        "SELECT origin_name, destination_name,
                COUNT(*) AS uses,
                AVG(total_ms) AS avg_ms,
                SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) AS ok_count
         FROM search_logs
         WHERE created_at >= ? AND status <> 'invalid'
         GROUP BY origin_node_id, destination_node_id, origin_name, destination_name
         ORDER BY uses DESC, origin_name ASC LIMIT 10"
    );
    $stmt->execute([$since]);

    $paths = array_map(function ($row) {
        return [
            'origin' => $row['origin_name'],
            'destination' => $row['destination_name'],
            'uses' => (int) $row['uses'],
            'avg_ms' => round((float) $row['avg_ms'], 2),
            'success_rate' => round(100 * (int) $row['ok_count'] / max(1, (int) $row['uses']), 1)
        ];
    }, $stmt->fetchAll());

    // Stop usage.
    $stmt = $db->prepare(
        "SELECT st.node_id, st.stop_name,
                SUM(CASE WHEN st.role = 'origin' THEN 1 ELSE 0 END) AS as_origin,
                SUM(CASE WHEN st.role = 'destination' THEN 1 ELSE 0 END) AS as_destination,
                SUM(CASE WHEN st.role = 'via' THEN 1 ELSE 0 END) AS as_via,
                COUNT(*) AS uses,
                AVG(s.total_ms) AS avg_ms,
                SUM(CASE WHEN st.role <> 'via' AND s.status <> 'ok' THEN 1 ELSE 0 END) AS end_failures
         FROM search_stops st JOIN search_logs s ON s.id = st.search_id
         WHERE s.created_at >= ?
         GROUP BY st.node_id, st.stop_name
         ORDER BY uses DESC, st.stop_name ASC LIMIT 100"
    );
    $stmt->execute([$since]);

    $stops = array_map(function ($row) {
        $ends = (int) $row['as_origin'] + (int) $row['as_destination'];

        return [
            'node_id' => $row['node_id'],
            'name' => $row['stop_name'],
            'as_origin' => (int) $row['as_origin'],
            'as_destination' => (int) $row['as_destination'],
            'as_via' => (int) $row['as_via'],
            'uses' => (int) $row['uses'],
            'avg_ms' => round((float) $row['avg_ms'], 2),
            // Share of searches starting or ending here that found no route.
            'fail_rate' => $ends > 0 ? round(100 * (int) $row['end_failures'] / $ends, 1) : null
        ];
    }, $stmt->fetchAll());

    $stmt = $db->prepare(
        'SELECT COUNT(DISTINCT st.node_id)
         FROM search_stops st JOIN search_logs s ON s.id = st.search_id
         WHERE s.created_at >= ?'
    );
    $stmt->execute([$since]);
    $stopsUsed = (int) $stmt->fetchColumn();

    // Size of the network, straight from the dataset file.
    $stopsTotal = null;
    $routesTotal = null;
    $datasetPath = __DIR__ . '/data/mapasahe_graph_v2.json';

    if (is_readable($datasetPath)) {
        $dataset = json_decode((string) file_get_contents($datasetPath), true);

        if (is_array($dataset)) {
            $stopsTotal = count($dataset['nodes'] ?? []);
            $routesTotal = count($dataset['routes'] ?? []);
        }

        unset($dataset);
    }

    $users = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM events WHERE type = 'login_failed' AND created_at >= ?");
    $stmt->execute([$since]);
    $failedLogins = (int) $stmt->fetchColumn();

    $eventTypes = $db->query('SELECT DISTINCT type FROM events ORDER BY type')->fetchAll(PDO::FETCH_COLUMN);

    // ── What needs attention ──────────────────────────────────
    $attention = [];
    $avgTotal = average($totalTimes);
    $avgRecent = average($recentTimes);

    if (count($searches) === 0) {
        $attention[] = [
            'level' => 'info',
            'text' => 'No searches recorded in this period yet. Run one in the planner and it will appear here.'
        ];
    }

    // The hybrid needs all three, so it is left out here: its misses
    // always follow from a model's miss.
    $missesByModel = [];

    foreach ($models as $entry) {
        if ($entry['model'] !== 'Hybrid' && $entry['runs'] > 0) {
            $missesByModel[$entry['model']] = $entry['runs'] - $entry['found'];
        }
    }

    if (count($missesByModel) > 0 && max($missesByModel) > 0) {
        if (count(array_unique($missesByModel)) === 1) {
            // Same count for every model: almost always stops that
            // simply are not connected, not a problem with one model.
            $missed = reset($missesByModel);

            $attention[] = [
                'level' => 'warn',
                'text' => 'No route found in ' . $missed . ' of ' . count($searches)
                    . ' searches. All three models came up empty, so those stops are probably not connected in the dataset.'
            ];
        } else {
            foreach ($missesByModel as $name => $missed) {
                if ($missed > 0) {
                    $attention[] = [
                        'level' => 'warn',
                        'text' => $name . ' found no path in ' . $missed . ' of ' . count($searches)
                            . ' searches. The models disagree, so compare the rows below.'
                    ];
                }
            }
        }
    }

    if (count($totalTimes) >= 40 && $avgRecent > 1.5 * $avgTotal) {
        $attention[] = [
            'level' => 'warn',
            'text' => 'Slowdown: the last 20 searches averaged ' . round($avgRecent) . ' ms, against '
                . round($avgTotal) . ' ms for the whole period.'
        ];
    }

    if ($failedLogins >= 5) {
        $attention[] = [
            'level' => 'warn',
            'text' => $failedLogins . ' failed login attempts in this period.'
        ];
    }

    if ($invalidCount > 0) {
        $attention[] = [
            'level' => 'info',
            'text' => $invalidCount . ' search ' . ($invalidCount === 1 ? 'form was' : 'forms were')
                . ' rejected before reaching the models (missing or identical stops).'
        ];
    }

    if (count($searches) > 0 && count(array_filter($attention, function ($item) {
        return $item['level'] === 'warn';
    })) === 0) {
        array_unshift($attention, [
            'level' => 'ok',
            'text' => 'All three models are returning routes and response times are steady.'
        ]);
    }

    respond([
        'range' => $range,
        'generated_at' => mapasaheNow(),
        'kpis' => [
            'searches' => count($searches),
            'success_rate' => count($searches) > 0 ? round(100 * $okCount / count($searches), 1) : null,
            'avg_ms' => rounded($avgTotal, 1),
            'median_ms' => rounded(percentile($totalTimes, 50), 1),
            'p95_ms' => rounded(percentile($totalTimes, 95), 1),
            'recent_avg_ms' => rounded($avgRecent, 1),
            'fastest_ms' => count($totalTimes) > 0 ? round(min($totalTimes), 1) : null,
            'slowest_ms' => count($totalTimes) > 0 ? round(max($totalTimes), 1) : null,
            'users' => $users,
            'stops_total' => $stopsTotal,
            'routes_total' => $routesTotal,
            'stops_used' => $stopsUsed,
            'failed_logins' => $failedLogins,
            'invalid_searches' => $invalidCount
        ],
        'attention' => $attention,
        'models' => $models,
        'stages' => $stages,
        'timeline' => $timeline,
        'paths' => $paths,
        'stops' => $stops,
        'event_types' => $eventTypes
    ]);
} catch (Throwable $error) {
    respond([
        'error' => 'The dashboard tables are missing or out of date. Import mapasahe.sql in phpMyAdmin.'
    ], 500);
}
