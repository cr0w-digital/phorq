<?php

declare(strict_types=1);

require_once $router->resolve('components');

$names = [1 => 'Alice', 2 => 'Bob', 3 => 'Carol', 4 => 'Dave', 5 => 'Eve'];
$name  = $names[(int) $id] ?? null;

if ($name === null) {
    return error(404);
}

$posts = [
    ['id' => 1, 'title' => 'Getting started with phorq'],
    ['id' => 2, 'title' => 'File-based routing in practice'],
    ['id' => 3, 'title' => 'HTMX and Datastar side by side'],
];

return h('.page',
    nav_links(),
    h('h1', $name . '\'s posts'),
    h('p', 'Nested param demo — route is ', h('code', '/users/[id]/posts'), '.'),
    h('ul.post-list', ...array_map(fn($p) =>
        h('li',
            h('a', ['href' => '/users/' . $id . '/posts/' . $p['id']], $p['title']),
        ),
        $posts,
    )),
    h('a.btn', ['href' => '/users/' . $id], '← Back to ' . $name),
    h('.demo-meta',
        'Route: ', h('code', '/users/[id]/posts'),
        ' — ', h('code', '$id'), ' = ', h('code', $id),
    ),
);