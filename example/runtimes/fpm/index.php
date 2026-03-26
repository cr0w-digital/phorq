<?php

// FPM / FrankenPHP entry point.
// Run: php -S localhost:8080 public/index.php
//      frankenphp php-server
//      docker compose up

require __DIR__ . '/../../boot.php';

\phorq\run($router, $ctx);