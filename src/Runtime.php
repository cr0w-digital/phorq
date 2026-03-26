<?php

declare(strict_types=1);

namespace phorq;

/**
 * Runtime interface.
 *
 * A runtime knows how to:
 *   - accept incoming requests (from globals, Swoole objects, RoadRunner PSR-7 etc.)
 *   - build a phorq\Request from them
 *   - build an appropriate ResponseEmitter
 *   - call the handler once (FPM) or in a loop (Swoole, FrankenPHP, RoadRunner)
 *
 * The handler signature is:
 *   fn(Request $req, ResponseEmitter $emitter): void
 */
interface Runtime
{
    public function run(callable $handler): void;
}
