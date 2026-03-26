<?php

declare(strict_types=1);

namespace phorq;

/**
 * Default emitter for PHP-FPM and FrankenPHP.
 */
final class FpmEmitter implements ResponseEmitter
{
    public function status(int $code): void
    {
        http_response_code($code);
    }

    public function header(string $name, string $value): void
    {
        header($name . ': ' . $value);
    }

    public function body(string $content): void
    {
        echo $content;
    }

    public function write(string $content): bool
    {
        echo $content;

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();

        return !connection_aborted();
    }

    public function isConnected(): bool
    {
        return !connection_aborted();
    }

    public function close(): void
    {
        // FPM closes the connection naturally at end of script
    }
}