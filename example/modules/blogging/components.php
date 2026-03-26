<?php

declare(strict_types=1);

// Load core components first — blog components build on top of them
require_once $router->resolve('components', 'core');

/**
 * Blog-specific component functions.
 * Core components (nav_links, page, demo_meta, card etc.) available globally.
 */

function blog_hero(string $title, string $date, string $excerpt): array
{
    return h('.blog-hero',
        h('p.blog-hero-date', $date),
        h('h1.blog-hero-title', $title),
        h('p.blog-hero-excerpt', $excerpt),
    );
}

function blog_post_card(array $post): array
{
    return h('a.blog-card', ['href' => '/blog/' . $post['slug']],
        h('p.blog-card-date', $post['date']),
        h('h3.blog-card-title', $post['title']),
        h('p.blog-card-excerpt', $post['excerpt'] ?? ''),
        h('span.blog-card-read', 'Read →'),
    );
}

function blog_byline(string $author, string $date): array
{
    return h('.blog-byline',
        h('span.blog-byline-author', $author),
        h('span.blog-byline-sep', '·'),
        h('span.blog-byline-date', $date),
    );
}

function blog_tag(string $tag): array
{
    return h('span.blog-tag', $tag);
}