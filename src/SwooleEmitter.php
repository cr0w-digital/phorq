<?php

declare(strict_types=1);

namespace phorq;

/**
 * Response emitter for Swoole.
 *
 * Pass to \phuse\dispatch() when using phorq\SwooleAdapter.
 *
 * Example:
 *
 *   $http->on('request', function($swooleReq, $swooleRes) use ($router, $ctx) {
 *       \phorq\SwooleAdapter::handle(
 *           $swooleReq, $swooleRes, $router, $ctx,
 *           function(\phorq\Result $result, \phorq\Request $req) use ($router, $swooleRes) {
 *               \phuse\dispatch(
 *                   $result,
 *                   fn($name) => $router->resolve($name, $result->module),
 *                   $req,
 *                   new \phorq\SwooleEmitter($swooleRes),
 *               );
 *           }
 *       );
 *   });
 */
final class SwooleEmitter implements ResponseEmitter
{
    public function __construct(private \Swoole\Http\Response $response) {}

    public function status(int $code): void
    {
        $this->response->status($code);
    }

    public function header(string $name, string $value): void
    {
        $this->response->header($name, $value);
    }

    public function body(string $content): void
    {
        $this->response->end($content);
    }

    public function write(string $content): bool
    {
        return $this->response->write($content);
    }

    public function isConnected(): bool
    {
        return $this->response->isWritable();
    }

    public function close(): void
    {
        $this->response->end();
    }
}