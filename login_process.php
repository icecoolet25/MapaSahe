<?php
require_once __DIR__ . '/auth.php';

mapasaheStartSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: loginMapa.php');
    exit();
}

$db = mapasaheDb();

if ($db === null) {
    header('Location: loginMapa.php?error=' . rawurlencode('Cannot reach the database. Is MySQL running?'));
    exit();
}

$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

$stmt = $db->prepare('SELECT id, username, password, role FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

/*
 * Wrong email and wrong password give the same message, so the form
 * cannot be used to find out which emails have accounts.
 */
if (!$user || !password_verify($password, $user['password'])) {
    mapasaheLogEvent('login_failed', 'Email: ' . $email);
    header('Location: loginMapa.php?error=invalidcredentials');
    exit();
}

session_regenerate_id(true);

$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role'] = $user['role'];

mapasaheLogEvent('login', $user['username'] . ' logged in', (int) $user['id']);

header('Location: ' . ($user['role'] === 'admin' ? 'admin_dashboard.php' : 'user_dashboard.php'));
exit();
