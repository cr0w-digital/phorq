<?php

declare(strict_types=1);

namespace phorq;

/**
 * Standard PHP-FPM runtime.
 *
 * Handles a single request from superglobals and exits.
 * The default runtime when nothing else is detected.
 */
final class FpmRuntime implements Runtime
{
    public function run(callable $handler): void
    {
        $handler(Request::fromGlobals(), new FpmEmitter());
    }
}
