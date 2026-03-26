<?php
// Swoole entry point.
// Run: php examples/swoole/server.php

require __DIR__ . '/../../boot.php';

\phorq\run($router, $ctx, new \phorq\SwooleRuntime(
    host:    '0.0.0.0',
    port:    9501,
    options: ['worker_num' => swoole_cpu_num(), 'enable_coroutine' => true],
));