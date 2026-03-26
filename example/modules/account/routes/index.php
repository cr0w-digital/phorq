<?php

declare(strict_types=1);

require_once $router->resolve('components');

return page('Account dashboard',
    h('p', 'This route is protected by ', h('code', 'modules/account/middleware.php'), '.'),
    h('p', 'The middleware short-circuits with ', h('code', 'return redirect(\'/login?from=...\')'), ' when not authenticated.'),
    sub_nav(h('a.btn', ['href' => '/account/settings'], 'Settings →')),
    demo_meta('Module: ', h('code', 'account'), ' — middleware runs before every route in this module. Try visiting without ', h('code', '?auth=1'), '.'),
);