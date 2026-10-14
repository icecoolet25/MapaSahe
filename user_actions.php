<?php
/*
 * Handles the forms on the user dashboard and the planner's
 * "Save this trip" button. Every action is a POST with the session
 * token and ends in a redirect back to the dashboard.
 *
 *   action=save_route       origin_node_id, origin_name,
 *                           destination_node_id, destination_name
 *   action=remove_route     route_id
 *   action=change_password  current_password, new_password
 */
require_once __DIR__ . '/auth.php';

$user = mapasaheRequireLogin();

function backToDashboard(string $key, string $value): void
{
    header('Location: user_dashboard.php?' . $key . '=' . rawurlencode($value));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !mapasaheCheckCsrf()) {
    backToDashboard('error', 'That form expired. Please try again.');
}

$db = mapasaheDb();

if ($db === null) {
    backToDashboard('error', 'Cannot reach the database.');
}

$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'save_route') {
        $originId = trim((string) ($_POST['origin_node_id'] ?? ''));
        $destinationId = trim((string) ($_POST['destination_node_id'] ?? ''));
        $originName = trim((string) ($_POST['origin_name'] ?? ''));
        $destinationName = trim((string) ($_POST['destination_name'] ?? ''));

        if ($originId === '' || $destinationId === '' || $originName === '' || $destinationName === '') {
            backToDashboard('error', 'That trip could not be saved.');
        }

        $stmt = $db->prepare(
            'SELECT id FROM saved_routes
             WHERE user_id = ? AND origin_node_id = ? AND destination_node_id = ?'
        );
        $stmt->execute([$user['id'], $originId, $destinationId]);

        if ($stmt->fetch()) {
            backToDashboard('notice', 'That trip is already in your saved routes.');
        }

        $db->prepare(
            'INSERT INTO saved_routes
                (user_id, origin_node_id, origin_name, destination_node_id, destination_name, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $user['id'],
            mb_substr($originId, 0, 64),
            mb_substr($originName, 0, 190),
            mb_substr($destinationId, 0, 64),
            mb_substr($destinationName, 0, 190),
            mapasaheNow()
        ]);

        mapasaheLogEvent('route_saved', $originName . ' → ' . $destinationName, $user['id']);
        backToDashboard('notice', 'Trip saved.');
    }

    if ($action === 'remove_route') {
        // The user_id check means a user can only remove their own trips.
        $stmt = $db->prepare('DELETE FROM saved_routes WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($_POST['route_id'] ?? 0), $user['id']]);

        if ($stmt->rowCount() > 0) {
            mapasaheLogEvent('route_removed', 'Removed a saved trip', $user['id']);
        }

        backToDashboard('notice', 'Trip removed.');
    }

    if ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');

        if (strlen($new) < 8) {
            backToDashboard('error', 'The new password needs at least 8 characters.');
        }

        $stmt = $db->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password'])) {
            backToDashboard('error', 'Your current password is not correct.');
        }

        $db->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);

        mapasaheLogEvent('password_changed', $user['username'] . ' changed their password', $user['id']);
        backToDashboard('notice', 'Password changed.');
    }
} catch (Throwable $error) {
    backToDashboard('error', 'Something went wrong. Please try again.');
}

backToDashboard('error', 'Unknown action.');
