<?php

declare(strict_types=1);

/**
 * Shared phml component helpers.
 * Included by route files via require_once $router->resolve('components').
 */

// ── Layout helpers ────────────────────────────────────────

function nav_links(): array
{
    return h('nav.nav',
        h('a.nav-link', ['href' => '/'], 'Home'),
    );
}

/**
 * Wraps a page with nav and heading.
 */
function page(string $title, mixed ...$content): array
{
    return h('.page',
        nav_links(),
        h('h1', $title),
        ...$content,
    );
}

/**
 * Demo metadata footer — shows route pattern, variables, notes.
 */
function demo_meta(mixed ...$items): array
{
    return h('.demo-meta', ...$items);
}

// ── Cards ─────────────────────────────────────────────────

function card(string $href, string $tag, string $title, string $desc): array
{
    return h('a.card', ['href' => $href],
        h('span.tag', $tag),
        h('h3', $title),
        h('p', $desc),
    );
}

// ── Forms ─────────────────────────────────────────────────

function form_group(string $label, string $forId, array $input, ?string $error = null): array
{
    return h('.form-group',
        h('label', ['for' => $forId], $label),
        $input,
        $error ? h('p.form-error', $error) : h('span'),
    );
}

function form_errors(array $errors = [], string $id = 'form-errors'): array
{
    return h('.form-errors', ['id' => $id],
        ...array_map(fn($e) => h('p.form-error', $e), $errors),
    );
}

// ── Navigation ────────────────────────────────────────────

/**
 * Breadcrumb trail.
 * $crumbs: array of ['label' => '...', 'href' => '...']
 * Last crumb rendered without a link.
 */
function breadcrumbs(array $crumbs): array
{
    $last  = array_pop($crumbs);
    $items = array_map(
        fn($c) => h('span.crumb', h('a', ['href' => $c['href']], $c['label'])),
        $crumbs,
    );
    $items[] = h('span.crumb', $last['label']);

    return h('nav.breadcrumbs', ...$items);
}

function sub_nav(mixed ...$items): array
{
    return h('nav.sub-nav', ...$items);
}

// ── Badges ────────────────────────────────────────────────

function role_badge(string $role): array
{
    return h('span.role-badge', $role);
}

function tag_badge(string $tag): array
{
    return h('span.tag', $tag);
}

// ── Route-specific components ─────────────────────────────

function counter_htmx(int $count): array
{
    return h('.counter',
        h('.counter-value#counter-value', (string) $count),
        h('.counter-controls',
            h('button.btn', [
                'hx-post'   => '/counter/htmx',
                'hx-target' => '#counter-value',
                'hx-swap'   => 'outerHTML',
                'hx-vals'   => json_encode(['action' => 'decrement']),
            ], '−'),
            h('button.btn.btn-primary', [
                'hx-post'   => '/counter/htmx',
                'hx-target' => '#counter-value',
                'hx-swap'   => 'outerHTML',
                'hx-vals'   => json_encode(['action' => 'increment']),
            ], '+'),
            h('button.btn', [
                'hx-post'    => '/counter/htmx',
                'hx-target'  => '#counter-value',
                'hx-swap'    => 'outerHTML',
                'hx-vals'    => json_encode(['action' => 'reset']),
                'hx-confirm' => 'Reset counter?',
            ], 'Reset'),
        ),
        h('p.counter-note',
            'Powered by ', h('strong', 'HTMX'),
            ' — server handles state, partial swap updates the value.',
        ),
    );
}

function ticker_display(): array
{
    return h('.ticker', ['hx-ext' => 'sse', 'sse-connect' => '/sse/stream'],
        h('.ticker-value', ['sse-swap' => 'tick'], 'Connecting…'),
        h('p.ticker-note', 'Raw SSE stream with heartbeats. Open two tabs to see concurrent connections.'),
    );
}

function slow_display(float $elapsed): array
{
    return h('.slow',
        h('p', 'Responded in ', h('strong', number_format($elapsed, 2) . 's'), '.'),
        h('p.slow-note',
            'On FPM this route blocks the process for 2 seconds. ',
            'On Swoole or FrankenPHP worker mode, other requests are served concurrently. ',
            'Open this route in multiple tabs simultaneously to observe the difference.',
        ),
        h('a.btn', ['href' => '/slow'], 'Request again'),
    );
}