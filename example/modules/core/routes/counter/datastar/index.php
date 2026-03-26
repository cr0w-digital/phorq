<?php

require_once $router->resolve('components');

title('Datastar Counter');

$counter_local = fn (int $count): array =>
    h('.counter', ['data-signals:_c' => $count],
        h('.counter-value', ['data-text' => '$_c'], $count),
        h('.counter-controls',
            h('button.btn', ['data-on:click' => '$_c--'], '−'),
            h('button.btn.btn-primary', ['data-on:click' => '$_c++'], '+'),
            h('button.btn', ['data-on:click' => '$_c=0'], 'Reset'),
        ),
        h('p.counter-note',
            h('strong', 'Local'), ' — client-only signal, never sent to server.',
        ),
    );

$counter_remote = fn (int $count): array =>
    h('.counter', ['data-signals:count' => $count],
        h('.counter-value', ['data-text' => '$count'], (string) $count),
        h('.counter-controls',
            h('button.btn', ['data-on:click' => "@post('/counter/datastar/decrement')"], '−'),
            h('button.btn.btn-primary', ['data-on:click' => "@post('/counter/datastar/increment')"], '+'),
            h('button.btn', ['data-on:click' => "@post('/counter/datastar/reset')"], 'Reset'),
        ),
        h('p.counter-note',
            h('strong', 'Remote'), ' — POSTs signals to server, JSON response patches ', h('code', '$count'), ' automatically.',
        ),
    );

$count = 0;

return page('Datastar Counter',
    h('p', 'Local signals update only in the browser. Remote signals POST to the server and patch back via JSON response.'),
    h('.counter-grid',
        $counter_local($count),
        $counter_remote($count),
    ),
    demo_meta(
        'Try DevTools Network — remote buttons show a plain ', h('code', 'application/json'), ' response. ',
    ),
);