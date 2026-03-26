<?php

declare(strict_types=1);

require_once $router->resolve('components');

$users = [
    ['id' => 1, 'name' => 'Alice',   'role' => 'admin'],
    ['id' => 2, 'name' => 'Bob',     'role' => 'editor'],
    ['id' => 3, 'name' => 'Carol',   'role' => 'viewer'],
    ['id' => 4, 'name' => 'Dave',    'role' => 'editor'],
    ['id' => 5, 'name' => 'Eve',     'role' => 'viewer'],
];

return h('.page',
    nav_links(),
    h('h1', 'Users'),
    h('p', 'Dynamic param demo — click a user to see ', h('code', '/users/[id]'), '.'),
    h('ul.user-list', ...array_map(fn($u) =>
        h('li.user-item',
            h('a', ['href' => '/users/' . $u['id']], $u['name']),
            ' ',
            h('span.role-badge', $u['role']),
            ' ',
            h('a.small-link', ['href' => '/users/' . $u['id'] . '/posts'], 'posts →'),
        ),
        $users,
    )),
);