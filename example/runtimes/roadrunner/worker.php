<?php
// RoadRunner entry point.
// Run: rr serve -c examples/roadrunner/.rr.yaml

require __DIR__ . '/../../boot.php';

\phorq\run($router, $ctx, new \phorq\RoadRunnerRuntime());