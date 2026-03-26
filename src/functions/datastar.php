<?php

declare(strict_types=1);

namespace phorq\datastar;

/**
 * Datastar support for phorq.
 *
 * Datastar is a hypermedia framework that uses SSE for real-time DOM
 * patching and signal syncing. This module provides SSE event builders
 * for use inside sse() generators:
 *
 *   - elements() — patches DOM elements via morphing
 *   - signals()  — updates frontend signals
 *   - script() — executes JS in the browser
 *
 * Signals from the frontend are available directly on $req:
 *
 *   $count = $req->signal('count', 0);
 *   $req->isDatastar();
 *   $req->signals();
 *
 * Usage in a route file:
 *
 *   sse(function() use ($req): \Generator {
 *       $count = $req->signal('count', 0) + 1;
 *       yield elements(h('#counter', $count));
 *       yield signals(['count' => $count]);
 *   });
 */

/* -------------------------------------------------
 * SSE event builders
 * ------------------------------------------------- */

/**
 * Patch elements into the DOM.
 *
 * The element must have a stable id — Datastar morphs by id.
 * Pass a phml node, array of nodes, or HTML string.
 *
 * @param string|array       $content  phml node or HTML string
 * @param string|null        $selector CSS selector to target (optional — defaults to id matching)
 * @param string             $mode     morph (default), inner, outer, prepend, append, before, after, remove
 * @param bool               $useViewTransition
 *
 * Example:
 *   yield elements(h('#counter', $count));
 *   yield elements('<div id="counter">' . $count . '</div>');
 *   yield elements(h('#results', ...$items), mode: 'append');
 */
function elements(
    string|array $content,
    ?string      $selector          = null,
    string       $mode              = 'morph',
    bool         $useViewTransition = false,
): \phorq\SseEvent {
    $html = is_array($content) ? \phml\render($content) : $content;

    $data = '';
    if ($selector !== null)    $data .= "selector {$selector}\n";
    if ($mode !== 'morph')     $data .= "mode {$mode}\n";
    if ($useViewTransition)    $data .= "useViewTransition true\n";

    // Each line of HTML gets its own data: elements prefix
    foreach (explode("\n", trim($html)) as $line) {
        $data .= "elements {$line}\n";
    }

    return new \phorq\SseEvent(
        name: 'datastar-patch-elements',
        data: rtrim($data),
        raw:  true, // tell SseEvent encoder to use raw data lines
    );
}

/**
 * Patch signals on the frontend.
 *
 * Merges the given signals into the frontend signal store.
 * Existing signals not in the patch are left unchanged.
 *
 * @param array $signals   Key-value pairs of signal names and values
 * @param bool  $onlyIfMissing  Only set signals that don't already exist
 *
 * Example:
 *   yield signals(['count' => $count, 'loading' => false]);
 */
function signals(array $signals, bool $onlyIfMissing = false): \phorq\SseEvent
{
    $data = 'signals ' . json_encode($signals);
    if ($onlyIfMissing) $data .= "\nonlyIfMissing true";

    return new \phorq\SseEvent(
        name: 'datastar-patch-signals',
        data: $data,
        raw:  true,
    );
}

/**
 * Execute JavaScript in the browser.
 *
 * Useful for triggering browser APIs, focusing elements, etc.
 * Use sparingly — prefer DOM patching and signal updates.
 *
 * Example:
 *   yield script("document.getElementById('modal').showModal()");
 */
function script(string $script): \phorq\SseEvent
{
    $lines = explode("\n", trim($script));
    $data  = implode("\n", array_map(fn($l) => "script {$l}", $lines));

    return new \phorq\SseEvent(
        name: 'datastar-execute-script',
        data: $data,
        raw:  true,
    );
}