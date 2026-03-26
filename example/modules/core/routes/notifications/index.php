<?php

declare(strict_types=1);

require_once $router->resolve('components');

$publisher = $_ENV['PUBLISHER'] ?? getenv('PUBLISHER') ?: '';

return page('Notifications',
    h('p', 'Pub/sub demo — any client subscribed to this page receives messages published from the form below. Open two tabs to see it work.'),

    !$publisher ? h('.setup-notice',
        h('h3', '⚠ No publisher configured'),
        h('p', 'Start with a publisher to enable real-time notifications:'),
        h('pre', "# Redis\nPUBLISHER=redis docker compose up\n\n# Mercure\nPUBLISHER=mercure docker compose up"),
    ) : h('span'),

    h('.notifications-layout',
        h('.notifications-feed',
            h('h2', 'Live feed'),
            !$publisher
                ? h('p.dim', 'Publisher not configured.')
                : h('div#notifications', [
                    'hx-ext'      => 'sse',
                    'sse-connect' => '/notifications/stream',
                    'sse-swap'    => 'notification',
                    'hx-swap'     => 'afterbegin',
                    ], h('p.waiting', 'Waiting for messages…'))
        ),
        h('.notifications-publish',
            h('h2', 'Send a notification'),
            h('form.notify-form', [
                'hx-post'   => '/notifications/publish',
                'hx-target' => '#publish-result',
                'hx-swap'   => 'innerHTML',
            ],
                form_group('Message', 'notify-msg',
                    h('input#notify-msg', ['name' => 'message', 'placeholder' => 'Type a notification…', 'required' => true]),
                ),
                form_group('Level', 'notify-level',
                    h('select#notify-level', ['name' => 'level'],
                        h('option', ['value' => 'info'],    'Info'),
                        h('option', ['value' => 'success'], 'Success'),
                        h('option', ['value' => 'warning'], 'Warning'),
                        h('option', ['value' => 'error'],   'Error'),
                    ),
                ),
                h('button.btn.btn-primary', ['type' => 'submit', 'disabled' => !$publisher], 'Send'),
            ),
            h('div#publish-result'),
        ),
    ),
    demo_meta('Publisher: ', h('code', $publisher ?: 'none'), ' — all subscribers on ', h('code', '/topics/notifications'), ' receive events regardless of which worker handled their request.'),
);