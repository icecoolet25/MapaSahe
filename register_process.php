<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: registerMapa.php');
    exit();
}

$db = mapasaheDb();

if ($db === null) {
    header('Location: registerMapa.php?error=dberror');
    exit();
}

$username = trim((string) ($_POST['username'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

// Everyone who signs up is a regular user. Admins are set in the database.
$role = 'customer';

if ($username === '' || $email === '' || $password === '') {
    header('Location: registerMapa.php?error=emptyfields');
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: registerMapa.php?error=invalidemail');
    exit();
}

try {
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);

    if ($stmt->fetch()) {
        header('Location: registerMapa.php?error=emailalreadyexists');
        exit();
    }

    $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);

    if ($stmt->fetch()) {
        header('Location: registerMapa.php?error=usertaken');
        exit();
    }

    $db->prepare(
        'INSERT INTO users (username, email, password, role, created_at) VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $username,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $role,
        mapasaheNow()
    ]);

    mapasaheLogEvent('register', $username . ' created an account', (int) $db->lastInsertId());

    header('Location: registerMapa.php?success=registered');
} catch (Throwable $error) {
    header('Location: registerMapa.php?error=failed');
}

exit();
