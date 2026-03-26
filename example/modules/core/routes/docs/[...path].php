<?php

declare(strict_types=1);

require_once $router->resolve('components');

// $path is an array of URL segments — e.g. /docs/getting-started/installation
// gives $path = ['getting-started', 'installation']

$breadcrumbs = array_merge([['label' => 'Docs', 'href' => '/docs/intro']], array_map(
    fn($seg, $i) => [
        'label' => ucwords(str_replace('-', ' ', $seg)),
        'href'  => '/docs/' . implode('/', array_slice($path, 0, $i + 1)),
    ],
    $path,
    array_keys($path),
));

return h('.page',
    nav_links(),
    h('h1', 'Docs'),
    h('p', 'Catch-all route demo — ', h('code', '/docs/[...path]'), ' captures one or more segments.'),
    h('nav.breadcrumbs', ...array_map(
        fn($crumb) => h('span.crumb',
            h('a', ['href' => $crumb['href']], $crumb['label']),
        ),
        $breadcrumbs,
    )),
    h('p', 'You are viewing: ', h('code', '/' . implode('/', $path))),
    h('p.small', 'Try navigating to ', h('code', '/docs/getting-started/installation'), ' or ', h('code', '/docs/api/request')),
    h('.demo-meta',
        'Route: ', h('code', '/docs/[...path]'),
        ' — ', h('code', '$path'), ' = ', h('code', json_encode($path)),
    ),
);