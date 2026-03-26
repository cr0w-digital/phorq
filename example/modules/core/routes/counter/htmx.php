<?php

require_once $router->resolve('components');

title('HTMX Counter');

session_start();
$count = (int) ($_SESSION['htmx_count'] ?? 0);

if ($req->isPost()) {
    $count = match ($req->string('action', 'increment')) {
        'increment' => $count + 1,
        'decrement' => $count - 1,
        'reset'     => 0,
        default     => $count,
    };
    $_SESSION['htmx_count'] = $count;
    trigger('counter:changed', ['count' => $count]);
    push_url('/counter/htmx');
    return h('.counter-value#counter-value', (string) $count);
}

return page('HTMX Counter',
    h('p', 'State lives on the server (session). Each button triggers a POST — HTMX swaps only the counter value.'),
    counter_htmx($count),
    demo_meta('Try DevTools Network tab — partial responses only. ', h('code', 'HX-Trigger'), ' carries the ', h('code', 'counter:changed'), ' event.'),
);