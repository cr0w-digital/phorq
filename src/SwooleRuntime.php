<?php

declare(strict_types=1);

namespace phorq;

/**
 * Swoole HTTP server runtime.
 *
 * Starts a Swoole HTTP server and handles requests concurrently.
 * Each request runs in its own coroutine — directives are isolated
 * automatically via \phorq\directives() coroutine context.
 *
 * Usage:
 *   \phorq\run($router, $ctx, new \phorq\SwooleRuntime(port: 9501));
 *
 * Options can be passed directly to the Swoole server:
 *   \phorq\run($router, $ctx, new \phorq\SwooleRuntime(
 *       host:    '0.0.0.0',
 *       port:    9501,
 *       options: ['worker_num' => 4, 'enable_coroutine' => true],
 *   ));
 */
final class SwooleRuntime implements Runtime
{
    public function __construct(
        private string $host    = '0.0.0.0',
        private int    $port    = 9501,
        private array  $options = [],
    ) {}

    public function run(callable $handler): void
    {
        $http = new \Swoole\Http\Server($this->host, $this->port);

        if ($this->options) {
            $http->set($this->options);
        }

        $http->on('request', function (
            \Swoole\Http\Request  $swooleReq,
            \Swoole\Http\Response $swooleRes,
        ) use ($handler): void {
            $handler(
                self::buildRequest($swooleReq),
                new SwooleEmitter($swooleRes),
            );
        });

        $http->start();
    }

    private static function buildRequest(\Swoole\Http\Request $req): Request
    {
        $method = strtoupper($req->server['request_method'] ?? 'GET');
        $uri    = $req->server['request_uri'] ?? '/';
        $path   = trim((string) parse_url($uri, PHP_URL_PATH), '/');

        $input = match (true) {
            $method === 'GET'  => [],
            !empty($req->post) => $req->post,
            default            => json_decode((string) $req->rawContent(), true) ?? [],
        };

        return new Request(
            method:  $method,
            path:    $path,
            query:   $req->get    ?? [],
            input:   $input,
            headers: $req->header ?? [],
        );
    }
}
