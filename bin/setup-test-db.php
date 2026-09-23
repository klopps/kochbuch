#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Creates (or refreshes) a dedicated `<DB_DATABASE>_test` database by
 * cloning the *structure* (no data) of the database this .env points at -
 * never the other way around, and this script never writes to the source
 * database.
 *
 * Run this once, and again any time the schema changes outside of a normal
 * `bin/migrate.php` run against the test DB picking it up.
 *
 * Usage: php bin/setup-test-db.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? '3306';
$sourceDb = $_ENV['DB_DATABASE'] ?? 'kochbuch';
$testDb = $sourceDb . '_test';
$username = $_ENV['DB_USERNAME'] ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';
$charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

if ($testDb === $sourceDb) {
    fwrite(STDERR, "Refusing to run: the test database name would be identical to DB_DATABASE ($sourceDb).\n");
    exit(1);
}

$server = new PDO("mysql:host=$host;port=$port;charset=$charset", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

echo "Recreating `$testDb` from `$sourceDb`'s current structure...\n";
$server->exec("DROP DATABASE IF EXISTS `$testDb`");
$server->exec("CREATE DATABASE `$testDb` CHARACTER SET $charset");

$source = new PDO("mysql:host=$host;port=$port;dbname=$sourceDb;charset=$charset", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$test = new PDO("mysql:host=$host;port=$port;dbname=$testDb;charset=$charset", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$tables = $source->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

$test->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) {
    $create = $source->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
    $test->exec($create['Create Table']);
    echo "  created table $table\n";
}
$test->exec('SET FOREIGN_KEY_CHECKS=1');

if (in_array('schema_migrations', $tables, true)) {
    $files = glob($root . '/database/migrations/*.sql');
    sort($files);
    $stmt = $test->prepare('INSERT IGNORE INTO schema_migrations (migration, applied_at) VALUES (?, NOW())');
    foreach ($files as $file) {
        $stmt->execute([basename($file)]);
    }
    echo 'Marked ' . count($files) . " migration file(s) as already applied.\n";
}

echo "Done. `$testDb` now mirrors `$sourceDb`'s structure (no data).\n";
