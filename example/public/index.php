<?php

// Serve static files directly when using the built-in server.
if (php_sapi_name() === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) return false;
}

// FPM / FrankenPHP entry point.
// Run: php -S localhost:8080 public/index.php
//      frankenphp php-server
//      docker compose up
require __DIR__ . '/../boot.php';
\phorq\run($router, $ctx);