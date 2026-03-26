<?php

require_once $router->resolve('components');

title('⎇ examples');

return h('.page',
    h('h1', '⎇ examples'),
    h('p', 'A working demo of file-based routing with HTMX, Datastar, SSE, modules, middleware, catch-alls, and more.'),

    h('h2', 'Hypermedia'),
    h('.index-grid',
        card('/counter/htmx', 'HTMX', 'Counter', 'Partial swaps, triggers, and URL push via HTMX.'),
        card('/counter/datastar', 'Datastar', 'Counter', 'Signal-driven counter with DOM morphing via Datastar.'),
        card('/mixed', 'HTMX + Datastar', 'Mixed', 'HTMX form + Datastar live ticker on the same page.'),
        card('/notifications', 'Pub/sub', 'Notifications', 'Real-time broadcast via Redis or Mercure — open two tabs.'),
        card('/sse/ticker', 'SSE', 'Ticker', 'Live streaming via Server-Sent Events with heartbeats.'),
        card('/contact', 'Method branching', 'Contact', 'GET renders form, POST validates — HTMX swaps error block.'),
    ),

    h('h2', 'Routing'),
    h('.index-grid',
        card('/users', '[id]', 'Users', 'Dynamic params — /users/[id] and /users/[id]/posts/[post_id].'),
        card('/docs/getting-started', '[...path]', 'Docs', 'Catch-all — captures one or more segments into an array.'),
        card('/files', '[[...path]]', 'Files', 'Optional catch-all — matches root and nested paths.'),
    ),

    h('h2', 'Modules & middleware'),
    h('.index-grid',
        card('/blog', 'Module', 'Blog', 'blogging/ module mounted at /blog via config.php.'),
        card('/account', 'Auth middleware', 'Account', 'Protected by module middleware'),
    ),

    h('h2', 'Runtimes'),
    h('.index-grid',
        card('/slow', 'Concurrency', 'Slow route', 'Sleeps 2s — open multiple tabs to compare runtimes.'),
        card('/api/ping', 'JSON', 'API ping', 'JSON response — includes runtime detection.'),
    ),
);