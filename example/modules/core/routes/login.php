<?php

declare(strict_types=1);

require_once $router->resolve('components');

$from = $req->query('from', '/account');

if ($req->isPost()) {
    session_start();
    $_SESSION['authed'] = true;
    return redirect($from);
}

return page('Login',
    h('p', 'Redirected here by ', h('code', 'account'), ' module middleware.'),
    h('form', ['method' => 'POST', 'action' => '/login?from=' . urlencode($from)],
        h('input', ['type' => 'hidden', 'name' => 'from', 'value' => $from]),
        h('p', h('em', 'Click Login to simulate authentication (no password needed).')),
        h('button.btn.btn-primary', ['type' => 'submit'], 'Login'),
    ),
    demo_meta('Redirected from: ', h('code', $from), ' — after login, returns to the original destination.'),
);