<?php
require_once __DIR__ . '/auth.php';

$user = mapasaheUser();

// Logging out is a POST with the session token, so a link on another
// site cannot log someone out.
if ($user !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && mapasaheCheckCsrf()) {
    mapasaheLogEvent('logout', $user['username'] . ' logged out', $user['id']);

    $_SESSION = [];
    session_destroy();
}

header('Location: system1_3.php');
exit();
