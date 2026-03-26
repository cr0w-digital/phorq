<?php

declare(strict_types=1);

require_once $router->resolve('components');

$names = [1 => 'Alice', 2 => 'Bob', 3 => 'Carol', 4 => 'Dave', 5 => 'Eve'];
$name  = $names[(int) $id] ?? null;

$posts = [
    1 => ['id' => 1, 'title' => 'Getting started with phorq',        'body' => 'phorq is a file-based hypermedia router for PHP...'],
    2 => ['id' => 2, 'title' => 'File-based routing in practice',     'body' => 'Drop a file in modules/*/routes/ and it is a route...'],
    3 => ['id' => 3, 'title' => 'HTMX and Datastar side by side',     'body' => 'Both work in the same phorq app, each route chooses its approach...'],
];

$post = $posts[(int) $post_id] ?? null;

if ($name === null || $post === null) {
    return error(404);
}

return h('.page',
    nav_links(),
    h('h1', $post['title']),
    h('p.post-meta', 'By ', h('strong', $name)),
    h('p', $post['body']),
    h('a.btn', ['href' => '/users/' . $id . '/posts'], '← Back to posts'),
    h('.demo-meta',
        'Route: ', h('code', '/users/[id]/posts/[post_id]'),
        ' — two dynamic params injected: ',
        h('code', '$id'), ' = ', h('code', $id), ', ',
        h('code', '$post_id'), ' = ', h('code', $post_id),
    ),
);