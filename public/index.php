<?php

declare(strict_types=1);

// php -S's router script is invoked for every request, including static
// assets (js/css/fonts/images) - unlike Apache/nginx, it never serves an
// existing file on its own unless the router explicitly opts out by
// returning false. Only relevant to the PHP_SAPI==='cli-server' dev server
// from `php -S localhost:8000 -t public public/index.php`; Apache/XAMPP
// deployments serve static files directly and never reach this.
if (PHP_SAPI === 'cli-server') {
    $path = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Kochbuch\App;

App::create(dirname(__DIR__))->run();
