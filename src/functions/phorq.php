<?php

declare(strict_types=1);

namespace phorq;

/**
 * Start the router.
 *
 * Detects the runtime automatically when none is provided:
 *   - FrankenPHP worker mode — if frankenphp_handle_request() exists
 *   - FPM — otherwise
 *
 * For Swoole and RoadRunner pass the runtime explicitly:
 *   \phorq\run($router, $ctx, new \phorq\SwooleRuntime(port: 9501));
 *   \phorq\run($router, $ctx, new \phorq\RoadRunnerRuntime());
 *
 * Per-request directives reset is handled automatically.
 * No middleware needed for resets when using run().
 */
function run(Router $router, mixed $ctx = null, ?Runtime $runtime = null): void
{
    $runtime ??= function_exists('frankenphp_handle_request')
        ? new FrankenRuntime()
        : new FpmRuntime();
 
    $runtime->run(function (Request $req, ResponseEmitter $emitter) use ($router, $ctx): void {
        directives()->reset();
        $result = $router->route($ctx, $req);
        dispatch($result, fn($n) => $router->resolve($n, $result->module), $req, $emitter);
    });
}

/**
 * Get the current request's Directives instance.
 *
 * Swoole: isolated per coroutine.
 * FrankenPHP: isolated per fiber.
 * FPM: static, safe per process.
 */
function directives(): Directives
{
    // Swoole coroutine
    if (function_exists('Swoole\Coroutine::getCid')
        && \Swoole\Coroutine::getCid() !== -1
    ) {
        $ctx = \Swoole\Coroutine::getContext();
        return $ctx['phorq.directives'] ??= new Directives();
    }

    // FrankenPHP / fiber
    $fiber = \Fiber::getCurrent();
    if ($fiber !== null) {
        static $map;
        $map ??= new \WeakMap();
        return $map[$fiber] ??= new Directives();
    }

    // FPM
    static $directives;
    return $directives ??= new Directives();
}

/**
 * Dispatch a Result to an HTTP response.
 *
 * Reads directives accumulated during the middleware/route chain and
 * sends the appropriate response.
 *
 * Layout rules:
 *   html      — wrapped in layout for full requests; bare for HTMX/Datastar
 *   error     — never wrapped; error view owns its own structure
 *   json      — no layout
 *   redirect  — HX-Redirect for HTMX requests, Location header otherwise
 *   sse       — streams directly, no layout
 *   subscribe — handled by configured publisher
 *
 * @param Result               $result  From Router::route().
 * @param callable             $resolve Module-aware resolver — fn(string): ?string
 * @param Request|null         $req     Current request. Null = build from globals.
 * @param ResponseEmitter|null $emitter Response emitter. Null = FpmEmitter.
 */
function dispatch(
    Result           $result,
    callable         $resolve,
    ?Request         $req     = null,
    ?ResponseEmitter $emitter = null,
): void {
    $emitter ??= new FpmEmitter();
    $req     ??= Request::fromGlobals();

    $directives = $result->directives;

    if ($directives === []) {
        emit_error(['content' => null, 'code' => 404], $resolve, $emitter);
        return;
    }

    // Flush HTMX header directives first — no-op for non-HTMX requests
    flush_htmx_headers($directives, $emitter);

    // Find primary response directive — last one wins
    $primary = null;
    foreach (array_reverse($directives) as $d) {
        if (in_array($d['type'], ['html', 'json', 'redirect', 'error', 'sse', 'subscribe'], true)) {
            $primary = $d;
            break;
        }
    }

    if ($primary === null) {
        emit_error(['content' => null, 'code' => 404], $resolve, $emitter);
        return;
    }

    match ($primary['type']) {
        'html'      => emit_html($primary['payload'], $directives, $resolve, $req, $emitter),
        'json'      => emit_json($primary['payload'], $emitter),
        'redirect'  => emit_redirect($primary['payload'], $req, $emitter),
        'error'     => emit_error($primary['payload'], $resolve, $emitter),
        'sse'       => stream_sse($primary['payload'], $emitter),
        'subscribe' => emit_subscribe($primary['payload'], $emitter),
    };

    // Process publish directives — fire after primary response
    foreach ($directives as $d) {
        if ($d['type'] === 'publish') {
            emit_publish($d['payload']);
        }
    }
}

/* -------------------------------------------------
 * Emitters
 * ------------------------------------------------- */

/**
 * @internal
 */
function emit_json(array $payload, ResponseEmitter $emitter): void
{
    $emitter->status($payload['code'] ?? 200);
    $emitter->header('Content-Type', 'application/json');
    $emitter->body(json_encode($payload['data']));
}

/**
 * @internal
 *
 * Redirects using HX-Redirect for HTMX requests, Location header otherwise.
 */
function emit_redirect(array $payload, Request $req, ResponseEmitter $emitter): void
{
    $url  = $payload['url'];
    $code = $payload['code'] ?? 302;

    if ($req->isHtmx()) {
        $emitter->header('HX-Redirect', $url);
    } else {
        $emitter->status($code);
        $emitter->header('Location', $url);
    }
}

/**
 * @internal
 *
 * Wraps in layout for full requests, bare for HTMX/Datastar.
 * Appends HTMX OOB/title extras to body when present.
 *
 * Layout file has $content and $title in scope:
 *
 *   <title><?= htmlspecialchars($title) ?></title>
 *   <?= $content ?>
 */
function emit_html(array $payload, array $directives, callable $resolve, Request $req, ResponseEmitter $emitter): void
{
    $emitter->status($payload['code'] ?? 200);
 
    $content = render_value($payload['content']);
    $extra   = flush_htmx_body($directives);
 
    if ($req->isHtmx() || $req->isDatastar()) {
        $emitter->body($content . $extra);
        return;
    }
 
    $layoutPath = $resolve('layout');
 
    if ($layoutPath === null) {
        $emitter->body($content . $extra);
        return;
    }
 
    // Extract title and meta directives for layout scope
    $title = '';
    $meta  = [];
    foreach ($directives as $d) {
        if ($d['type'] === 'title') {
            $title = $d['payload'];
        } elseif ($d['type'] === 'meta') {
            $meta[$d['payload']['name']] = $d['payload']['content'];
        }
    }
 
    ob_start();
    require $layoutPath;
    $emitter->body(ob_get_clean() . $extra);
}
 
/**
 * @internal
 *
 * Error directives are never wrapped in layout.
 * Resolution order:
 *   1. Content in directive
 *   2. resolve('errors/$code')
 *   3. resolve('errors/default')
 *   4. Plain text fallback
 */
function emit_error(array $payload, callable $resolve, ResponseEmitter $emitter): void
{
    $code    = $payload['code'] ?? 500;
    $content = $payload['content'];
 
    $emitter->status($code);
 
    if ($content !== null) {
        $emitter->body(render_value($content));
        return;
    }
 
    $viewPath = $resolve('errors/' . $code) ?? $resolve('errors/default');
 
    if ($viewPath !== null) {
        ob_start();
        require $viewPath;
        $emitter->body(ob_get_clean());
        return;
    }
 
    $emitter->body($code . ' ' . default_error_message($code));
}

/**
 * @internal
 */
function emit_subscribe(array $payload, ResponseEmitter $emitter): void
{
    $publisher = get_publisher();

    if ($publisher === null) {
        emit_error(['content' => null, 'code' => 500], fn() => null, $emitter);
        trigger_error('phorq: subscribe() called but no publisher registered. Call \phorq\set_publisher() at bootstrap.', E_USER_WARNING);
        return;
    }

    $publisher->subscribe($payload['topic'], $emitter);
}

/**
 * @internal
 */
function emit_publish(array $payload): void
{
    $publisher = get_publisher();

    if ($publisher === null) {
        trigger_error('phorq: publish() called but no publisher registered. Call \phorq\set_publisher() at bootstrap.', E_USER_WARNING);
        return;
    }

    $publisher->publish($payload['topic'], $payload['event']);
}

/* -------------------------------------------------
 * HTMX helpers
 * ------------------------------------------------- */

/**
 * Flush HTMX header directives to the emitter.
 * No-op when no htmx: directives are present.
 *
 * @internal
 */
function flush_htmx_headers(array $directives, ResponseEmitter $emitter): void
{
    $triggers = ['default' => [], 'swap' => [], 'settle' => []];

    foreach ($directives as $d) {
        match ($d['type']) {
            'htmx:trigger'     => $triggers[$d['payload']['timing']][] = $d['payload'],
            'htmx:push_url'    => $emitter->header('HX-Push-Url',    $d['payload']),
            'htmx:replace_url' => $emitter->header('HX-Replace-Url', $d['payload']),
            'htmx:retarget'    => $emitter->header('HX-Retarget',    $d['payload']['selector']),
            default            => null,
        };

        if ($d['type'] === 'htmx:retarget' && $d['payload']['mode'] !== 'innerHTML') {
            $emitter->header('HX-Reswap', $d['payload']['mode']);
        }
    }

    foreach (['default' => 'HX-Trigger', 'swap' => 'HX-Trigger-After-Swap', 'settle' => 'HX-Trigger-After-Settle'] as $timing => $header) {
        if (!$triggers[$timing]) continue;

        $map = [];
        foreach ($triggers[$timing] as $t) {
            $event = $t['event'];
            $data  = $t['data'];

            if (is_array($event)) {
                foreach ($event as $k => $v) $map[$k] = $v;
            } elseif ($data !== null) {
                $map[$event] = $data;
            } else {
                $map[$event] = true;
            }
        }

        $emitter->header($header, count($map) === 1 && reset($map) === true
            ? array_key_first($map)
            : json_encode($map)
        );
    }
}

/**
 * Build HTMX OOB/title body extras from directives.
 *
 * @internal
 */
function flush_htmx_body(array $directives): string
{
    $extra = '';
 
    foreach ($directives as $d) {
        match ($d['type']) {
            'title' => $extra .= '<title hx-swap-oob="true">' . htmlspecialchars($d['payload'], ENT_QUOTES, 'UTF-8') . '</title>',
            'htmx:oob'   => $extra .= '<div id="' . ltrim($d['payload']['selector'], '#') . '" hx-swap-oob="' . $d['payload']['swap'] . '">' . $d['payload']['html'] . '</div>',
            default      => null,
        };
    }
 
    return $extra;
}

/* -------------------------------------------------
 * Helpers
 * ------------------------------------------------- */

/**
 * Resolve a name to a callable via the resolver.
 *
 * @internal
 */
function resolve_callable(callable $resolve, string $name): ?callable
{
    $path = $resolve($name);
    if ($path === null) return null;
    $value = require $path;
    return is_callable($value) ? $value : null;
}

/**
 * Render a value to a string — handles phml nodes, strings, and callables.
 *
 * @internal
 */
function render_value(mixed $value): string
{
    if (is_string($value)) return $value;
    if (is_callable($value)) return render_value($value());
    if (is_array($value)) return \phml\render($value);
    return '';
}

/**
 * @internal
 */
function default_error_message(int $code): string
{
    return match ($code) {
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        409 => 'Conflict',
        422 => 'Unprocessable Entity',
        500 => 'Internal Server Error',
        default => 'Error',
    };
}

/**
 * Stream an SSE response.
 *
 * Called by dispatch when it encounters an 'sse' directive.
 * Handles headers, buffering, heartbeats, and disconnect detection.
 *
 * @internal
 */
function stream_sse(array $payload, ResponseEmitter $emitter): void
{
    $source    = $payload['source'];
    $heartbeat = $payload['heartbeat'];
    $retry     = $payload['retry'];

    $emitter->status(200);
    $emitter->header('Content-Type',      'text/event-stream');
    $emitter->header('Cache-Control',     'no-cache');
    $emitter->header('X-Accel-Buffering', 'no');

    // FPM — disable output buffering for true streaming
    if ($emitter instanceof FpmEmitter) {
        while (ob_get_level() > 0) ob_end_flush();
        ob_implicit_flush(true);
        set_time_limit(0);
        ignore_user_abort(false);
    }

    if ($retry > 0) {
        $emitter->write('retry: ' . $retry . "\n\n");
    }

    $generator     = $source();
    $lastHeartbeat = time();

    while ($emitter->isConnected()) {
        if ($generator->valid()) {
            $ev = $generator->current();

            if ($ev instanceof SseEvent) {
                if (!$emitter->write($ev->encode())) break;
            }

            $generator->next();
        } else {
            break;
        }

        if ($heartbeat > 0 && (time() - $lastHeartbeat) >= $heartbeat) {
            if (!$emitter->write(comment('heartbeat')->encode())) break;
            $lastHeartbeat = time();
        }
    }

    $emitter->close();
}
