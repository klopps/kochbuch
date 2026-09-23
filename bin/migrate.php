#!/usr/bin/env php
<?php

declare(strict_types=1);

// Tiny migration runner: applies database/migrations/*.sql in filename order,
// tracking what already ran in a `schema_migrations` table.

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $_ENV['DB_HOST'] ?? 'localhost',
    $_ENV['DB_PORT'] ?? '3306',
    $_ENV['DB_DATABASE'] ?? 'kochbuch',
    $_ENV['DB_CHARSET'] ?? 'utf8mb4'
);

$pdo = new PDO($dsn, $_ENV['DB_USERNAME'] ?? 'root', $_ENV['DB_PASSWORD'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = glob($root . '/database/migrations/*.sql');
sort($files);

$ranAny = false;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    echo "Applying $name ...\n";
    $sql = file_get_contents($file);
    $pdo->exec($sql);

    $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration, applied_at) VALUES (?, NOW())');
    $stmt->execute([$name]);
    $ranAny = true;
}

echo $ranAny ? "Done.\n" : "Nothing to do, schema is up to date.\n";
