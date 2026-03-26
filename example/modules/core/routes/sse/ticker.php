<?php

require_once $router->resolve('components');

return h('.page',
    nav_links(),
    h('h1', 'SSE Ticker'),
    h('p',
        'A persistent SSE connection streams tick events every second. ',
        'The HTMX SSE extension swaps the ticker value on each ', h('code', 'tick'), ' event. ',
        'Heartbeats keep the connection alive through proxies.',
    ),
    ticker_display(),
    h('.demo-meta',
        'Note: on the built-in server this connection blocks other requests. ',
        'Use ', h('code', 'frankenphp php-server'), ' or Swoole for concurrent SSE.',
    ),
);