<?php

declare(strict_types=1);

require_once $router->resolve('components');

$start = microtime(true);
sleep(2);
$elapsed = microtime(true) - $start;

return h('.page',
    nav_links(),
    h('h1', 'Slow route'),
    slow_display($elapsed),
);