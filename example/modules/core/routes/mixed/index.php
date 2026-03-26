<?php

declare(strict_types=1);

require_once $router->resolve('components');

title('Mixed: HTMX + Datastar');

if ($req->isPost() && $req->isHtmx()) {
    $name = $req->string('name');

    if (!$name) {
        return h('.order-result#order-result',
            form_errors(['Name is required.']),
        );
    }

    trigger('order:placed', ['name' => $name]);

    return h('.order-result#order-result',
        h('p.success', '✓ Order placed for ' . e($name) . '!'),
        h('p.small', 'The Datastar ticker above updated independently.'),
    );
}

return page('Mixed: HTMX + Datastar',
    h('p',
        'HTMX and Datastar coexist in the same page. ',
        'The form submits via HTMX. The live ticker uses Datastar signals. ',
        'Each handles what it is best at.',
    ),
    h('.mixed-grid',
        h('.mixed-panel',
            h('h2', 'Live ticker'),
            h('p.small', 'Datastar — signal-driven, updates every second via SSE.'),
            h('button.btn', ['data-on:click' => "@get('/mixed/tick')"], 'Start ticker'),
            h('.ds-ticker', [
                'data-signals:tick' => '0',
                'data-signals:ts'   => '"--:--:--"',
                'data-on:load'      => "@get('/mixed/tick')",
            ],
                h('.ticker-value', ['data-text' => '$ts'], '--:--:--'),
                h('p.small', 'Tick: ', h('span', ['data-text' => '$tick'], '0')),
            ),
        ),
        h('.mixed-panel',
            h('h2', 'Place order'),
            h('p.small', 'HTMX — form submission with partial swap and event trigger.'),
            h('form', [
                'hx-post'   => '/mixed',
                'hx-target' => '#order-result',
                'hx-swap'   => 'outerHTML',
            ],
                form_group('Your name', 'order-name',
                    h('input#order-name', ['name' => 'name', 'placeholder' => 'Enter name']),
                ),
                h('button.btn.btn-primary', ['type' => 'submit'], 'Place order'),
            ),
            h('.order-result#order-result'),
        ),
    ),
    demo_meta(
        'Both libraries loaded in the same layout. ',
        'HTMX handles the form, Datastar handles the ticker. ',
        'No conflict — they target different elements.',
    ),
);