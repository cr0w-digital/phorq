<?php

declare(strict_types=1);

require_once $router->resolve('components');

$users = [
    1 => ['id' => 1, 'name' => 'Alice', 'role' => 'admin',  'bio' => 'Loves PHP and hiking.'],
    2 => ['id' => 2, 'name' => 'Bob',   'role' => 'editor', 'bio' => 'Writer and coffee enthusiast.'],
    3 => ['id' => 3, 'name' => 'Carol', 'role' => 'viewer', 'bio' => 'Reader of all things.'],
    4 => ['id' => 4, 'name' => 'Dave',  'role' => 'editor', 'bio' => 'Edits words, breaks things.'],
    5 => ['id' => 5, 'name' => 'Eve',   'role' => 'viewer', 'bio' => 'Quietly watching.'],
];

// $id is injected from the URL — /users/42
$user = $users[(int) $id] ?? null;

if ($user === null) {
    return error(404);
}

return h('.page',
    nav_links(),
    h('h1', $user['name']),
    h('p', h('span.role-badge', $user['role'])),
    h('p', $user['bio']),
    h('nav.sub-nav',
        h('a.btn', ['href' => '/users/' . $user['id'] . '/posts'], 'View posts →'),
        ' ',
        h('a.btn', ['href' => '/users'], '← All users'),
    ),
    h('.demo-meta', 'Route: ', h('code', '/users/[id]'), ' — ', h('code', '$id'), ' = ', h('code', $id)),
);