<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$ctx = new stdClass();

$router = \phorq\Router::create(
    __DIR__ . '/modules',
    // __DIR__ . '/cache/routes.php',
);

// Configure pub/sub publisher from environment — see docs/pub-sub.md.
$publisher = $_ENV['PUBLISHER'] ?? getenv('PUBLISHER') ?: 'none';

match ($publisher) {
    'redis' => (function () {
        $redis = new Redis();
        $redis->connect(
            $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?: '127.0.0.1',
            (int) ($_ENV['REDIS_PORT'] ?? getenv('REDIS_PORT') ?: 6379),
        );
        \phorq\set_publisher(new \phorq\RedisPublisher($redis));
    })(),

    'mercure' => (function () {
        \phorq\set_publisher(new \phorq\MercurePublisher(
            hubUrl: $_ENV['MERCURE_URL']    ?? getenv('MERCURE_URL')    ?: 'http://localhost:8081/.well-known/mercure',
            secret: $_ENV['MERCURE_SECRET'] ?? getenv('MERCURE_SECRET') ?: 'dev-secret',
        ));
    })(),

    default => null,
};