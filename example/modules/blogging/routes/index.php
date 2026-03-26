<?php

require_once $resolve('components');

title('Blog');

$posts = [
    ['slug' => 'hello-phorq',        'title' => 'Hello phorq',                    'date' => '2026-03-01', 'excerpt' => 'phorq is a file-based hypermedia router for PHP.',         'tags' => ['routing', 'php']],
    ['slug' => 'file-based-routing', 'title' => 'File-based routing in practice', 'date' => '2026-03-10', 'excerpt' => 'The directory tree is the route map.',                      'tags' => ['routing', 'architecture']],
    ['slug' => 'htmx-vs-datastar',   'title' => 'HTMX vs Datastar',               'date' => '2026-03-15', 'excerpt' => 'Both work in the same phorq app. Choose per route.',        'tags' => ['htmx', 'datastar', 'hypermedia']],
];

return page('Blog',
    h('p',
        'Module demo — ', h('code', 'modules/blogging/'), ' is mounted at ',
        h('code', '/blog'), ' via ', h('code', 'config.php'), '. ',
        'This module has its own ', h('code', 'components.php'), ' with blog-specific helpers.',
    ),
    h('p', h('a', ['href' => 'blog/static'], 'View static example')),
    h('.blog-grid', ...array_map('blog_post_card', $posts)),
    demo_meta(
        'Module: ', h('code', 'blogging'), ' mounted at ', h('code', '/blog'),
        ' — ', h('code', 'resolve(\'components\')'), ' → ',
        h('code', 'modules/blogging/components.php'),
        ' (loads core first, adds ', h('code', 'blog_post_card()'), ' etc.)',
    ),
);