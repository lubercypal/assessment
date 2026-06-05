<?php

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbName = app_config('db_name');
    $dbSocket = app_config('db_socket');
    $dbPort = app_config('db_port');

    if ($dbSocket) {
        $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $dbSocket, $dbName);
    } else {
        $dsn = sprintf(
            'mysql:host=%s%s;dbname=%s;charset=utf8mb4',
            app_config('db_host'),
            $dbPort ? ';port=' . (int) $dbPort : '',
            $dbName
        );
    }

    $pdo = new PDO($dsn, app_config('db_user'), app_config('db_pass'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
    ]);

    return $pdo;
}
