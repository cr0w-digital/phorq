<?php

declare(strict_types=1);

namespace phorq;

use Nyholm\Psr7\Factory\Psr17Factory;
use Spiral\RoadRunner\Http\PSR7Worker;

/**
 * Response emitter for RoadRunner.
 *
 * Supports:
 * - normal one-shot responses via PSR7Worker
 * - streaming writes via HttpWorker::respond(..., endOfStream: false)
 *
 * Notes:
 * - Once streaming starts, headers/status are considered committed.
 * - RoadRunner does not appear to expose a client-disconnect check the way
 *   Swoole does, so isConnected() is only a local emitter-state check.
 */
final class RoadRunnerEmitter implements ResponseEmitter
{
    private int $status = 200;

    /** @var array<string, list<string>> */
    private array $headers = [];

    private bool $started = false;
    private bool $closed = false;

    private readonly Psr17Factory $factory;

    public function __construct(
        private readonly PSR7Worker $worker,
    ) {
        $this->factory = new Psr17Factory();
    }

    public function status(int $code): void
    {
        $this->assertNotClosed();
        $this->assertNotStarted(__METHOD__);
        $this->status = $code;
    }

    public function header(string $name, string $value): void
    {
        $this->assertNotClosed();
        $this->assertNotStarted(__METHOD__);

        $this->headers[$name] ??= [];
        $this->headers[$name][] = $value;
    }

    public function body(string $content): void
    {
        $this->assertNotClosed();

        // If streaming already started, finish the existing stream.
        if ($this->started) {
            if ($content !== '') {
                $this->write($content);
            }

            $this->close();
            return;
        }

        $response = $this->factory->createResponse($this->status);

        foreach ($this->headers as $name => $values) {
            foreach ($values as $value) {
                $response = $response->withAddedHeader($name, $value);
            }
        }

        $response = $response->withBody(
            $this->factory->createStream($content)
        );

        $this->worker->respond($response);
        $this->started = true;
        $this->closed = true;
    }

    public function write(string $content): bool
    {
        $this->assertNotClosed();

        try {
            $http = $this->worker->getHttpWorker();

            if (!$this->started) {
                $http->respond(
                    $this->status,
                    $content,
                    $this->headers,
                    false
                );

                $this->started = true;
                return true;
            }

            $http->respond(
                $this->status,
                $content,
                [],
                false
            );

            return true;
        } catch (\Throwable) {
            $this->closed = true;
            return false;
        }
    }

    public function isConnected(): bool
    {
        return !$this->closed;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $http = $this->worker->getHttpWorker();

            if (!$this->started) {
                // Send an empty response and close immediately.
                $http->respond(
                    $this->status,
                    '',
                    $this->headers,
                    true
                );

                $this->started = true;
                $this->closed = true;
                return;
            }

            // Final empty chunk, mark end of stream.
            $http->respond(
                $this->status,
                '',
                [],
                true
            );
        } finally {
            $this->closed = true;
        }
    }

    private function assertNotClosed(): void
    {
        if ($this->closed) {
            throw new \LogicException('Response already closed.');
        }
    }

    private function assertNotStarted(string $method): void
    {
        if ($this->started) {
            throw new \LogicException(sprintf(
                'Cannot call %s() after response streaming has started.',
                $method
            ));
        }
    }
}