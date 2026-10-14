<?php
/*
 * Records one planner search for the admin dashboard.
 *
 * $search = [
 *   'user_id' => int|null,
 *   'status' => 'ok' | 'no_route' | 'invalid',
 *   'origin_id' => string, 'origin_name' => string,
 *   'destination_id' => string, 'destination_name' => string,
 *   'load_ms' => float,    // reading the dataset and building the graph
 *   'total_ms' => float,   // whole request up to the end of the searches
 *   'models' => [
 *     'Dijkstra' => ['found' => bool, 'ms' => float, 'nodes' => int,
 *                    'cost' => float|null, 'edges' => int],
 *     'A*' => [...], 'GBFS' => [...], 'Hybrid' => [...]
 *   ],
 *   'stops' => [['id' => string, 'name' => string,
 *                'role' => 'origin' | 'destination' | 'via'], ...]
 * ]
 *
 * Best-effort: if the database is down or a table is missing, the
 * search still works and simply is not logged.
 */
require_once __DIR__ . '/auth.php';

function mapasaheLogSearch(array $search): void
{
    $db = mapasaheDb();

    if ($db === null) {
        return;
    }

    try {
        $now = mapasaheNow();
        $hybrid = $search['models']['Hybrid'] ?? null;

        $db->beginTransaction();

        $db->prepare(
            'INSERT INTO search_logs
                (created_at, user_id, origin_node_id, origin_name,
                 destination_node_id, destination_name, status,
                 load_ms, total_ms, hybrid_cost_min, hybrid_edges)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $now,
            $search['user_id'],
            mb_substr($search['origin_id'], 0, 64),
            mb_substr($search['origin_name'], 0, 190),
            mb_substr($search['destination_id'], 0, 64),
            mb_substr($search['destination_name'], 0, 190),
            $search['status'],
            round($search['load_ms'], 3),
            round($search['total_ms'], 3),
            ($hybrid && $hybrid['found']) ? $hybrid['cost'] : null,
            ($hybrid && $hybrid['found']) ? $hybrid['edges'] : null
        ]);

        $searchId = (int) $db->lastInsertId();

        $insertRun = $db->prepare(
            'INSERT INTO model_runs
                (search_id, model, found, duration_ms, nodes_explored, cost_min, edge_count)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($search['models'] as $model => $run) {
            $insertRun->execute([
                $searchId,
                $model,
                $run['found'] ? 1 : 0,
                round($run['ms'], 4),
                (int) $run['nodes'],
                $run['found'] ? $run['cost'] : null,
                (int) $run['edges']
            ]);
        }

        $insertStop = $db->prepare(
            'INSERT INTO search_stops (search_id, node_id, stop_name, role) VALUES (?, ?, ?, ?)'
        );

        foreach ($search['stops'] as $stop) {
            $insertStop->execute([
                $searchId,
                mb_substr($stop['id'], 0, 64),
                mb_substr($stop['name'], 0, 190),
                $stop['role']
            ]);
        }

        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        return;
    }

    $trip = $search['origin_name'] . ' → ' . $search['destination_name'];
    $time = number_format($search['total_ms'], 0) . ' ms';

    if ($search['status'] === 'ok') {
        $found = 0;

        foreach ($search['models'] as $run) {
            $found += $run['found'] ? 1 : 0;
        }

        mapasaheLogEvent('search', $trip . ' · ' . $found . ' of 4 routes · ' . $time, $search['user_id']);
    } elseif ($search['status'] === 'no_route') {
        mapasaheLogEvent('search_failed', $trip . ' · no route found · ' . $time, $search['user_id']);
    } else {
        mapasaheLogEvent('search_invalid', 'Rejected: origin or destination missing, unknown, or the same', $search['user_id']);
    }
}
