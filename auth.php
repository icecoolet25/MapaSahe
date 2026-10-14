<?php
/*
 * Shared session, access-control and event-log helpers.
 * Included by every page that needs to know who is logged in.
 */
require_once __DIR__ . '/db_config.php';

function mapasaheStartSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/* The logged-in user as ['id', 'username', 'role'], or null. */
function mapasaheUser(): ?array
{
    mapasaheStartSession();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['user_id'],
        'username' => (string) ($_SESSION['username'] ?? ''),
        'role' => (string) ($_SESSION['role'] ?? 'customer')
    ];
}

function mapasaheRequireLogin(): array
{
    $user = mapasaheUser();

    if ($user === null) {
        header('Location: loginMapa.php?error=' . rawurlencode('Please log in first.'));
        exit();
    }

    return $user;
}

function mapasaheRequireAdmin(): array
{
    $user = mapasaheRequireLogin();

    if ($user['role'] !== 'admin') {
        header('Location: user_dashboard.php');
        exit();
    }

    return $user;
}

/* One token per session, sent with every form that changes something. */
function mapasaheCsrfToken(): string
{
    mapasaheStartSession();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['csrf_token'];
}

function mapasaheCheckCsrf(): bool
{
    mapasaheStartSession();

    $sent = (string) ($_POST['csrf_token'] ?? '');

    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}

function mapasaheEsc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function mapasaheNow(): string
{
    return date('Y-m-d H:i:s');
}

/*
 * Adds one line to the admin history log. Never throws: a logging
 * problem must not break the page that was doing the real work.
 *
 * Types in use: search, search_failed, search_invalid, login,
 * login_failed, register, logout, route_saved, route_removed,
 * password_changed.
 */
function mapasaheLogEvent(string $type, string $details = '', ?int $userId = null): void
{
    $db = mapasaheDb();

    if ($db === null) {
        return;
    }

    try {
        $db->prepare(
            'INSERT INTO events (created_at, type, user_id, details) VALUES (?, ?, ?, ?)'
        )->execute([
            mapasaheNow(),
            $type,
            $userId,
            mb_substr($details, 0, 255)
        ]);
    } catch (Throwable $error) {
        // Logging is best-effort.
    }
}
