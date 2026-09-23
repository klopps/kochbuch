<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap (see phpunit.xml's bootstrap attribute). Loads the
 * real .env for DB host/credentials, then forces the database name to the
 * dedicated `<name>_test` database (see bin/setup-test-db.php) - tests
 * must never be able to reach the real database, even if a test has a bug.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$testDatabase = ($_ENV['DB_DATABASE'] ?? 'kochbuch') . '_test';
$_ENV['DB_DATABASE'] = $testDatabase;
$_SERVER['DB_DATABASE'] = $testDatabase;
putenv('DB_DATABASE=' . $testDatabase);

if (empty($_ENV['JWT_SECRET'])) {
    $_ENV['JWT_SECRET'] = 'test-secret-do-not-use-in-production';
}
