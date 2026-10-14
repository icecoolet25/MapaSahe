<?php
/*
 * MapaSahe database settings.
 *
 * These are the XAMPP defaults (user "root", no password). Change the
 * four values below if your MySQL is set up differently, or when you
 * move the system to a web host.
 */
date_default_timezone_set('Asia/Manila');

$MAPASAHE_DB = [
    'host' => 'localhost',
    'name' => 'mapasahe',
    'user' => 'root',
    'pass' => ''
];

/*
 * Returns the shared database connection, or null when the database
 * cannot be reached. Callers decide what to do with null: the route
 * planner keeps working without logging, the login pages show an error.
 */
function mapasaheDb(): ?PDO
{
    static $pdo = false;

    if ($pdo !== false) {
        return $pdo;
    }

    global $MAPASAHE_DB;

    $dsn = getenv('MAPASAHE_DSN') ?: sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $MAPASAHE_DB['host'],
        $MAPASAHE_DB['name']
    );

    try {
        $pdo = new PDO($dsn, $MAPASAHE_DB['user'], $MAPASAHE_DB['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (Throwable $error) {
        $pdo = null;
    }

    return $pdo;
}
