<?php

declare(strict_types=1);

namespace phorq;

/**
 * Abstracts the HTTP response output layer.
 *
 * Implementations handle the runtime-specific mechanics of sending
 * status codes, headers, and body content — allowing dispatch and
 * stream_sse to remain runtime-agnostic.
 *
 * Three methods cover request/response:    status, header, body
 * Three methods cover streaming (SSE):     write, isConnected, close
 */
interface ResponseEmitter
{
    /**
     * Set the HTTP response status code.
     */
    public function status(int $code): void;

    /**
     * Send a response header.
     */
    public function header(string $name, string $value): void;

    /**
     * Send the full response body and close the connection.
     */
    public function body(string $content): void;

    /**
     * Write a chunk without closing — used for SSE streaming.
     * Returns false if the connection was lost.
     */
    public function write(string $content): bool;

    /**
     * True while the client connection is open.
     */
    public function isConnected(): bool;

    /**
     * Close the connection after streaming is complete.
     */
    public function close(): void;
}