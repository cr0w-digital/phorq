<?php

declare(strict_types=1);

use phorq\SseEvent;
use function phorq\directives;

/* -------------------------------------------------
 * Core response directives
 *
 * Write imperatively in route files and middleware.
 * The router sweeps them into Result, dispatch interprets them.
 *
 * Primary directives (html, json, redirect, error, sse, subscribe)
 * push to the directive stack AND return the swept array, so they
 * can be used as short-circuit returns in both route files and middleware:
 *
 *   return html($content);
 *   return redirect('/login');
 *   return error(404);
 *
 * Modifier directives (publish, title, meta, trigger, push_url etc.)
 * are void — they annotate the response and are swept alongside
 * the primary directive.
 * ------------------------------------------------- */
 
/**
 * Write an HTML response directive.
 *
 * Pass a phml node, a pre-rendered string, or any renderable value.
 *
 * Example:
 *   return html('<div>Hello</div>');
 *   return html($node, 500);
 */
function html(mixed $content, int $code = 200): array
{
    directives()->push('html', ['content' => $content, 'code' => $code]);
    return directives()->sweep();
}
 
/**
 * Write a JSON response directive.
 *
 * Example:
 *   return json(['status' => 'ok', 'id' => $id]);
 *   return json(['error' => 'Unauthorized'], 401);
 */
function json(mixed $data, int $code = 200): array
{
    directives()->push('json', ['data' => $data, 'code' => $code]);
    return directives()->sweep();
}
 
/**
 * Write a redirect directive.
 *
 * Example:
 *   return redirect('/login');
 *   return redirect('/new-url', 301);
 */
function redirect(string $url, int $code = 302): array
{
    directives()->push('redirect', ['url' => $url, 'code' => $code]);
    return directives()->sweep();
}
 
/**
 * Write an error directive.
 *
 * Content is optional; if absent the response layer resolves an error view.
 *
 * Example:
 *   return error(404);
 *   return error(422, '<div>Validation failed</div>');
 */
function error(int $code, mixed $content = null): array
{
    directives()->push('error', ['content' => $content, 'code' => $code]);
    return directives()->sweep();
}

/**
 * Set the browser page title directly or via HTMX OOB swap.
 *
 * Example:
 *   title('Profile — ' . $user['name']);
 */
function title(string $text): void
{
    directives()->push('title', $text);
}

/**
 * Set page metadata — title and/or meta tags.
 *
 * Only applied on full page requests. Has no effect on HTMX partial responses.
 *
 * Single tag:
 *   meta('description', 'Profile page for Alice.');
 *
 * Multiple tags:
 *   meta([
 *       'title'       => 'Alice — Users',
 *       'description' => 'Profile page for Alice.',
 *       'og:title'    => 'Alice — Users',
 *       'og:image'    => '/images/alice.jpg',
 *   ]);
 */
function meta(string|array $name, string $content = ''): void
{
    if (is_array($name)) {
        foreach ($name as $k => $v) {
            if ($k === 'title') {
                directives()->push('title', $v);
            } else {
                directives()->push('meta', ['name' => $k, 'content' => $v]);
            }
        }
    } else {
        directives()->push('meta', ['name' => $name, 'content' => $content]);
    }
}

/* -------------------------------------------------
 * HTMX directives
 *
 * Supplementary directives that accompany an html response.
 * Write imperatively before returning from route files.
 *
 * HTMX context reads ($req->isHtmx(), $req->target() etc.)
 * are on phorq\htmx\HtmxRequest, available as $req in route files.
 * ------------------------------------------------- */

/**
 * Trigger a custom browser event via HTMX.
 *
 * Timing:
 *   'default' — immediately on response
 *   'swap'    — after content is swapped into the DOM
 *   'settle'  — after swap animations complete
 *
 * Example:
 *   trigger('toast', ['message' => 'Saved!', 'level' => 'success']);
 *   trigger('sidebar:refresh');
 *   trigger('chart-ready', ['data' => $data], 'settle');
 */
function trigger(string|array $event, mixed $data = null, string $timing = 'default'): void
{
    directives()->push('htmx:trigger', compact('event', 'data', 'timing'));
}

/**
 * Trigger an event after content is swapped into the DOM.
 *
 * Example:
 *   on_swap('init-chart', ['data' => $chartData]);
 */
function on_swap(string|array $event, mixed $data = null): void
{
    trigger($event, $data, 'swap');
}

/**
 * Trigger an event after swap animations complete.
 *
 * Example:
 *   on_settle('modal-opened');
 */
function on_settle(string|array $event, mixed $data = null): void
{
    trigger($event, $data, 'settle');
}

/**
 * Push a new URL into the browser history.
 *
 * Example:
 *   push_url('/users/' . $id);
 */
function push_url(string $url): void
{
    directives()->push('htmx:push_url', $url);
}

/**
 * Replace the current URL without adding to history.
 *
 * Example:
 *   replace_url('/search?q=' . urlencode($query));
 */
function replace_url(string $url): void
{
    directives()->push('htmx:replace_url', $url);
}

/**
 * Queue an out-of-band swap — updates an element outside the main target.
 *
 * Example:
 *   oob('#notifications', \phml\render(h('span.badge', $count)));
 */
function oob(string $selector, string $html, string $swap = 'innerHTML'): void
{
    directives()->push('htmx:oob', compact('selector', 'html', 'swap'));
}

/**
 * Retarget the HTMX swap to a different element.
 *
 * Example:
 *   return swap('#errors', h('.error-list', ...$errorNodes));
 *   return swap('#sidebar', $sidebarNode, 'outerHTML');
 */
function swap(string $selector, mixed $content, string $mode = 'innerHTML', int $code = 200): array
{
    directives()->push('htmx:retarget', ['selector' => $selector, 'mode' => $mode]);
    directives()->push('html', ['content' => $content, 'code' => $code]);
    return directives()->sweep();
}

/**
 * Server-Sent Events support.
 *
 * Usage in a route file:
 *
 *   sse(function(): \Generator {
 *       while (true) {
 *           yield event('price', ['value' => getPrice()]);
 *           sleep(1);
 *       }
 *   });
 *
 * Heartbeats are sent automatically every 15 seconds by default.
 * The connection closes cleanly when the client disconnects.
 */

/**
 * Write an SSE directive.
 *
 * @param callable(): \Generator $source  Generator that yields SseEvent instances.
 * @param int                    $heartbeat Seconds between heartbeat comments. 0 to disable.
 * @param int                    $retry     Client reconnect delay in ms. 0 to omit.
 */
function sse(callable $source, int $heartbeat = 15, int $retry = 0): array
{
    directives()->push('sse', [
        'source'    => $source,
        'heartbeat' => $heartbeat,
        'retry'     => $retry,
    ]);
    return directives()->sweep();
}

/**
 * Publish an event to a topic via the configured publisher.
 *
 * Fire and forget — does not hold a connection open.
 * Requires a publisher to be registered via set_publisher().
 *
 * Example:
 *   publish('/topics/prices', event('price', ['btc' => getPrice()]));
 *   return json(['ok' => true]);
 */
function publish(string $topic, \phorq\SseEvent $event): void
{
    directives()->push('publish', ['topic' => $topic, 'event' => $event]);
}

/**
 * Subscribe the current client to a topic via the configured publisher.
 *
 * Example:
 *   return subscribe('/topics/prices');
 */
function subscribe(string $topic): array
{
    directives()->push('subscribe', ['topic' => $topic]);
    return directives()->sweep();
}

/**
 * Build a named or unnamed SSE event.
 *
 * @param string|null $name Event name (sets the `event:` field). Null for unnamed.
 * @param mixed       $data Data payload — arrays are JSON-encoded.
 * @param string|null $id   Event ID for client reconnection tracking.
 */
function event(?string $name, mixed $data, ?string $id = null): SseEvent
{
    return new SseEvent($name, $data, $id);
}

/**
 * Build an SSE comment.
 *
 * Comments are ignored by the browser event listener but keep the
 * connection alive through proxies. Used automatically for heartbeats,
 * but can be yielded directly for any purpose.
 */
function comment(string $text): SseEvent
{
    return SseEvent::comment($text);
}
