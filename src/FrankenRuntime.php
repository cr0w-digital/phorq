<?php

declare(strict_types=1);

namespace phorq;

/**
 * FrankenPHP runtime.
 *
 * Automatically handles both worker mode and standard PHP-server mode.
 * In worker mode the handler loop runs until the server shuts down.
 * In standard mode behaves identically to FpmRuntime.
 *
 * Usage:
 *   \phorq\run($router, $ctx, new \phorq\FrankenRuntime());
 *
 * Or let run() auto-detect it:
 *   \phorq\run($router, $ctx); // picks FrankenRuntime when frankenphp_handle_request exists
 */
final class FrankenRuntime implements Runtime
{
    public function run(callable $handler): void
    {
        $h = fn() => $handler(Request::fromGlobals(), new FpmEmitter());

        if (function_exists('frankenphp_handle_request')) {
            while (frankenphp_handle_request($h)) {
                gc_collect_cycles();
            }
        } else {
            $h();
        }
    }
}
