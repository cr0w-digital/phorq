<?php

declare(strict_types=1);

namespace phorq;

/**
 * RoadRunner HTTP server runtime.
 *
 * Requires the spiral/roadrunner-http package:
 *   composer require spiral/roadrunner-http nyholm/psr7
 *
 * RoadRunner uses PSR-7 requests and responses. This runtime bridges
 * them to phorq's Request and ResponseEmitter.
 *
 * Usage:
 *   \phorq\run($router, $ctx, new \phorq\RoadRunnerRuntime());
 *
 * .rr.yaml:
 *   server:
 *     command: "php public/index.php"
 *   http:
 *     address: "0.0.0.0:8080"
 *     pool:
 *       num_workers: 4
 */
final class RoadRunnerRuntime implements Runtime
{
    public function run(callable $handler): void
    {
        $worker  = \Spiral\RoadRunner\Worker::create();
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $psr7    = new \Spiral\RoadRunner\Http\PSR7Worker($worker, $factory, $factory, $factory);

        while ($request = $psr7->waitRequest()) {
            try {
                $phorqReq = self::buildRequest($request);
                $emitter  = new RoadRunnerEmitter($psr7);

                $handler($phorqReq, $emitter);
            } catch (\Throwable $e) {
                $psr7->getWorker()->error((string) $e);
            }
        }
    }

    private static function buildRequest(\Psr\Http\Message\ServerRequestInterface $req): Request
    {
        $method = strtoupper($req->getMethod());
        $path   = trim($req->getUri()->getPath(), '/');

        $input = match (true) {
            $method === 'GET' => [],
            !empty($req->getParsedBody()) => (array) $req->getParsedBody(),
            default => json_decode((string) $req->getBody(), true) ?? [],
        };

        $headers = [];
        foreach ($req->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return new Request(
            method:  $method,
            path:    $path,
            query:   $req->getQueryParams(),
            input:   $input,
            headers: $headers,
        );
    }
}