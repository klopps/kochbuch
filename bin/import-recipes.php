#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bulk-imports recipes from JSON files (one file per recipe, same shape as
 * RecipeController::export()'s JSON output - see docs/API.md) into the
 * database, owned by an existing user. Written for importing scraped/
 * exported recipes from another site (see todo.md/done.md) but generic
 * enough for any JSON-based recipe import.
 *
 * Usage: php bin/import-recipes.php <directory-of-json-files> <username>
 *
 * Each JSON file's shape matches RecipeRepository::create()'s $data
 * parameter (name, description, servings, difficulty, ...,  ingredients,
 * steps, tags) plus an optional "image_url" key: if present, the image is
 * downloaded, validated (real file bytes via finfo, not the URL's
 * extension) and attached as the recipe's primary image.
 *
 * Known limitation: "image_url" download uses a plain file_get_contents(),
 * which some CDNs (e.g. chefkoch-cdn.de - confirmed via curl, even with a
 * spoofed User-Agent/Referer) reject with a 403, likely via TLS/browser
 * fingerprinting rather than a simple header check. Where that happens,
 * fetch the image through an authenticated browser session instead (e.g.
 * Playwright's page.evaluate(fetch(...).arrayBuffer()) capturing the
 * result to a file, then base64-decode it locally) and attach it via
 * RecipeRepository::addImage() directly, bypassing this script's own
 * (unreachable-for-that-host) download step.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Kochbuch\Database\Connection;
use Kochbuch\Domain\Recipe\RecipeRepository;

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->load();
}

[, $directory, $username] = $argv + [null, null, null];
if ($directory === null || $username === null) {
    fwrite(STDERR, "Usage: php bin/import-recipes.php <directory-of-json-files> <username>\n");
    exit(1);
}

$pdo = Connection::fromEnv();
$recipes = new RecipeRepository($pdo);

$stmt = $pdo->prepare('SELECT id FROM user WHERE username = ?');
$stmt->execute([$username]);
$userId = $stmt->fetchColumn();
if ($userId === false) {
    fwrite(STDERR, "No such user: $username\n");
    exit(1);
}
$userId = (int) $userId;

$files = glob(rtrim($directory, '/\\') . '/*.json');
sort($files);
if ($files === []) {
    fwrite(STDERR, "No .json files found in $directory\n");
    exit(1);
}

$storageDir = $root . '/storage/recipe-images';
$allowedMimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

foreach ($files as $file) {
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || empty($data['name'])) {
        echo "SKIP  $file (invalid JSON or missing name)\n";
        continue;
    }

    $recipeId = $recipes->create($userId, $data);
    echo "OK    $file -> recipe #$recipeId \"{$data['name']}\"\n";

    if (!empty($data['image_url'])) {
        $bytes = @file_get_contents($data['image_url']);
        if ($bytes === false) {
            echo "      (image download failed: {$data['image_url']})\n";
            continue;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);
        if (!isset($allowedMimeToExt[$mime])) {
            echo "      (image skipped, unsupported type $mime)\n";
            continue;
        }

        $dir = $storageDir . '/' . $recipeId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $allowedMimeToExt[$mime];
        file_put_contents($dir . '/' . $filename, $bytes);

        $imageId = $recipes->addImage($recipeId, $filename);
        echo "      image #$imageId attached\n";
    }
}
