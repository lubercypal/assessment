<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
$config = require $root . '/config/env.php';

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']),
    $config['db_user'],
    $config['db_pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$mode = $argv[1] ?? 'check';

if ($mode === 'check') {
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo 'Connected. Tables found: ' . count($tables) . PHP_EOL;
    foreach ($tables as $table) {
        echo '- ' . $table . PHP_EOL;
    }
    exit(0);
}

if ($mode !== 'import') {
    fwrite(STDERR, "Usage: php scripts/import_database.php [check|import]\n");
    exit(1);
}

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if ($tables) {
    fwrite(STDERR, "Database is not empty. Refusing to import over existing tables.\n");
    fwrite(STDERR, "Existing tables: " . implode(', ', $tables) . "\n");
    exit(1);
}

foreach (['database/schema.sql', 'database/seed.sql'] as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        throw new RuntimeException("Missing file: {$file}");
    }

    $pdo->exec(file_get_contents($path));
    echo "Imported {$file}\n";
}

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo 'Done. Tables found: ' . count($tables) . PHP_EOL;
