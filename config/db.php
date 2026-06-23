<?php
require_once __DIR__ . '/env.php';

define('DB_HOST',   env('DB_HOST',   '127.0.0.1'));
define('DB_PORT',   env('DB_PORT',   '5432'));
define('DB_NAME',   env('DB_NAME',   'comunhao'));
define('DB_SCHEMA', env('DB_SCHEMA', 'material'));
define('DB_USER',   env('DB_USER',   'postgres'));
define('DB_PASS',   env('DB_PASS',   ''));

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        DB_HOST, DB_PORT, DB_NAME
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec('SET search_path = ' . DB_SCHEMA);

    return $pdo;
}
