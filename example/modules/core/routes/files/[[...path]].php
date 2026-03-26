<?php

declare(strict_types=1);

require_once $router->resolve('components');

$isRoot  = empty($path);
$current = $isRoot ? 'root' : implode('/', $path);
$parent  = count($path) > 1
    ? '/files/' . implode('/', array_slice($path, 0, -1))
    : ($isRoot ? null : '/files');

$entries = $isRoot
    ? [['name' => 'documents', 'type' => 'dir'], ['name' => 'images', 'type' => 'dir'], ['name' => 'readme.txt', 'type' => 'file']]
    : [['name' => 'example.txt', 'type' => 'file'], ['name' => 'notes.md', 'type' => 'file']];

return page('Files',
    h('p',
        'Optional catch-all — ', h('code', '/files/[[...path]]'), '. ',
        'Matches ', h('code', '/files'), ' and ', h('code', '/files/a/b'), '.',
    ),
    h('p', 'Current: ', h('code', '/' . ($isRoot ? 'files' : 'files/' . $current))),
    $parent ? sub_nav(h('a.btn', ['href' => $parent], '↑ Up')) : h('span'),
    h('ul.file-list', ...array_map(fn($e) =>
        h('li.file-item',
            $e['type'] === 'dir'
                ? h('a', ['href' => '/files/' . ($isRoot ? '' : implode('/', $path) . '/') . $e['name']], '📁 ' . $e['name'])
                : h('span', '📄 ' . $e['name']),
        ),
        $entries,
    )),
    demo_meta(
        'Route: ', h('code', '/files/[[...path]]'),
        ' — ', h('code', '$path'), ' = ', h('code', json_encode($path)),
    ),
);