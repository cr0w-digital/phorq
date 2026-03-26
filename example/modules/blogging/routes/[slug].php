<?php

require_once $resolve('components');

$posts = [
    'hello-phorq'        => ['title' => 'Hello phorq',                    'date' => '2026-03-01', 'author' => 'Alice', 'excerpt' => 'phorq is a file-based hypermedia router for PHP.',  'body' => 'phorq is a file-based hypermedia router for PHP. Drop a file in modules/*/routes/ and it is a route. No registration, no controllers, no config. Works with HTMX and Datastar out of the box.', 'tags' => ['routing', 'php']],
    'file-based-routing' => ['title' => 'File-based routing in practice', 'date' => '2026-03-10', 'author' => 'Bob',   'excerpt' => 'The directory tree is the route map.',               'body' => 'The directory tree is the route map. Dynamic segments, catch-alls, optional catch-alls, and modules all expressed as file and directory names. phorq scans once at boot and caches as a plain PHP array.', 'tags' => ['routing', 'architecture']],
    'htmx-vs-datastar'   => ['title' => 'HTMX vs Datastar',               'date' => '2026-03-15', 'author' => 'Carol', 'excerpt' => 'Both work in the same phorq app.',                   'body' => 'Both work in the same phorq app. HTMX uses partial swaps and server-managed state. Datastar uses signals and DOM morphing. Choose per route — they coexist without conflict.', 'tags' => ['htmx', 'datastar', 'hypermedia']],
];

$post = $posts[$slug] ?? null;
if ($post === null) return error(404);

return h('.page',
    nav_links(),
    blog_hero($post['title'], $post['date'], $post['excerpt']),
    blog_byline($post['author'], $post['date']),
    h('.blog-tags', ...array_map('blog_tag', $post['tags'])),
    h('.blog-body', h('p', $post['body'])),
    sub_nav(h('a.btn', ['href' => '/blog'], '← Blog')),
    demo_meta(
        'Route: ', h('code', '/blog/[slug]'), ' — ', h('code', '$slug'), ' = ', h('code', $slug), '. ',
        'Uses ', h('code', 'blog_hero()'), ', ', h('code', 'blog_byline()'),
        ' from ', h('code', 'modules/blogging/components.php'), '.',
    ),
);