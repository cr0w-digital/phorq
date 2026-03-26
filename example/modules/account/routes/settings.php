<?php

declare(strict_types=1);

require_once $router->resolve('components');

return page('Settings',
    h('p', 'Another protected route in the ', h('code', 'account'), ' module.'),
    h('p', 'The same middleware protects every route here without repeating auth logic.'),
    sub_nav(
        h('a.btn', ['href' => '/account/settings'], 'Settings'),
        h('a.btn', ['href' => '/logout'], 'Logout'),
    ),
    demo_meta('Route: ', h('code', '/account/settings'), ' — protected by module middleware.'),
);