# ⎇ phorq

File-based PHP router, hypermedia first.

## Install

```bash
composer require cr0w/phorq
```

## Quick start

```
modules/
  core/
    middleware.php
    routes/
      index.php             # /
      about.php             # /about
      users/
        index.php           # /users
        [id].php            # /users/:id
      docs/
        [...rest].php       # /docs/*
      pages/
        [[...path]]/
          index.php         # /pages and /pages/*
  blogging/
    config.php              # ['mount' => 'blog']
    routes/
      index.php             # /blog
      [slug].php            # /blog/:slug
```

### Front controller

```php
require __DIR__ . '/vendor/autoload.php';

$router = \phorq\Router::create(__DIR__ . '/modules');

\phorq\run($router, $ctx);
```

`run()` detects the runtime automatically — FrankenPHP worker mode, FrankenPHP `php-server`, or standard FPM. Pass a runtime explicitly for Swoole or RoadRunner (see [Long-running runtimes](#long-running-runtimes)).

Pass an object as `$ctx` to thread shared state through middleware and route files. phorq does not inspect it — any object works, from a plain `stdClass` to a full service container. Arrays won't work since PHP copies them on assignment, meaning mutations in middleware won't be visible downstream.

phorq is a thin routing layer — existing PHP code using `$_GET`, `$_POST`, `$_SESSION`, `header()`, and other superglobals works unchanged inside route files. Incremental adoption is straightforward.

## Route files

Every route file receives:

```php
$req    // phorq\Request
$ctx    // whatever was passed to route()
$router // the Router instance
// + one variable per URL param: $id, $slug, $rest etc.
```

Route files output HTML however they like. The router captures output and return values and wraps them as `html` directives automatically:

```php
<?php // modules/core/routes/users/[id].php

// echo — captured via output buffering
echo '<div class="card">' . htmlspecialchars($user['name']) . '</div>';

// return string — implicit html directive
return '<div class="card">' . htmlspecialchars($user['name']) . '</div>';

// return phml node — implicit html directive
return h('.card', h('h2', $user['name']));
```

All three are equivalent. Call `html($content, $code)` explicitly only when you need a non-200 status code.

Short-circuit by returning a directive:

```php
<?php
if (!$ctx->auth) {
    return redirect('/login');
}

return h('.dashboard', ...);
```

`redirect()`, `error()`, and `json()` return a directives array — returning them works the same way in route files and middleware. You can also call without returning and use a bare `return`:

```php
redirect('/login');
return;
```

`header()` works too but bypasses the directives system entirely — the response layer won't see it and dispatch behaviour won't apply. Use it as an escape hatch when you need direct header control.

## Request

`phorq\Request` carries the current request with typed accessors:

```php
$req->method           // 'GET', 'POST', …
$req->path             // 'users/42'
$req->pattern          // '/core/users/[id]'
$req->module           // 'core'
$req->string('email')  // trimmed string from input or query
$req->int('page', 1)   // integer with default
$req->float('amount')
$req->bool('active')
$req->has('key')
$req->input('payload') // raw value from request body
$req->query('sort')    // raw value from query string
$req->header('X-Foo')
$req->isGet()
$req->isPost()
$req->isPut()
$req->isPatch()
$req->isDelete()
$req->isJson()
$req->isSecure()
```

### HTMX

```php
$req->isHtmx()         // HX-Request header present
$req->isBoosted()      // HX-Boosted header present
$req->target()         // HX-Target value
$req->trigger()        // HX-Trigger value
$req->triggerName()    // HX-Trigger-Name value
```

### Datastar

```php
$req->isDatastar()           // Datastar-Request header present
$req->signals()              // all signals sent with the request
$req->signal('count', 0)     // single signal with default
$req->signal('user.name')    // dot notation for nested signals
```

### Method branching

```php
<?php // routes/login.php

if ($req->isPost()) {
    // handle submission
} else {
    return h('.login-form', ...);
}
```

## Routing conventions

| File / directory | Matches |
|---|---|
| `index.php` | Directory root |
| `about.php` | `/about` |
| `[id].php` | `/42` — `$id` available |
| `[id]/settings/index.php` | `/42/settings` — `$id` available |
| `[...rest].php` | `/docs/a/b/c` — `$rest = ['a','b','c']` |
| `[[...rest]].php` | `/pages` or `/pages/a/b` — `$rest = []` or `['a','b']` |
| `[[...rest]]/index.php` | Same as above, directory form |

**Precedence:** exact static → dynamic param → catch-all.

A catch-all (`[...rest]`) requires at least one segment. An optional catch-all (`[[...rest]]`) also matches the bare directory.

## Modules

Each subdirectory under `modules/` is a module:

```
modules/
  core/        # fallback module — serves unmatched paths
  account/     # mounted at /account by default
  blogging/    # config.php can set a custom mount
```

A module can have:

- **`config.php`** — returns `['mount' => 'blog']` to set a custom URL prefix
- **`middleware.php`** — returns a callable, runs on every request to that module
- **`routes/`** — file-based route tree

The `core` module is special — its routes serve as the fallback when no other module matches, and its middleware runs before every module's middleware.

Module mounts always win over core routes. A module mounted at `/blog` makes `core/routes/blog/` unreachable.

## Middleware

```php
<?php // modules/core/middleware.php

return function (callable $next, \phorq\Request $req, mixed $ctx, \phorq\Router $router): array {
    // before
    $directives = $next(); // returns directives accumulated by inner chain
    // inspect or modify $directives here
    return $directives;
};
```

Trailing parameters can be omitted. Middleware must return the result of `$next()` to pass directives up the chain. Short-circuiting directives — `redirect()`, `error()`, `json()` — return the swept directives array so middleware can return them directly:

```php
return function (callable $next, \phorq\Request $req): array {
    if (!$req->isSecure()) {
        return redirect('https://' . $_SERVER['HTTP_HOST'] . $req->path, 301);
    }
    return $next();
};
```

Middleware stacking order: **global → core → module → handler**.

Register global middleware on the router before calling `route()`:

```php
$router->use(new SessionMiddleware());
$router->use(function(callable $next): array {
    \phorq\directives()->reset();
    return $next();
});
```

Middleware can freely add directives after `$next()` without worrying about what happened inside. If the inner chain short-circuited with a `redirect()` or `error()`, dispatch picks the primary directive and ignores anything supplementary. Outer middleware never needs to inspect the directives array to know whether a short-circuit occurred.

## Directives

Route files write to a per-request directives stack via `\phorq\directives()`. When the route handler finishes, the accumulated directives are swept and returned up through the middleware chain as the return value of `$next()`. Middleware can inspect or modify them before passing them on.

phorq ships four core directive functions:

```php
html($content, $code)      // HTML response
json($data, $code)         // JSON response — returns swept directives
redirect($url, $code)      // Redirect — returns swept directives
error($code, $content)     // Error response — returns swept directives
```

### SSE

```php
sse(function(): \Generator {
    while (true) {
        yield event('tick', ['time' => time()]);
        sleep(1);
    }
}, heartbeat: 30);
```

### HTMX directives

```php
trigger('toast', ['message' => 'Saved!']);
push_url('/users/' . $id);
title($user['name']);
oob('#notifications', h('.badge', $count));
```

`\phorq\dispatch()` handles layout wrapping for full requests and bare fragments for HTMX and Datastar requests automatically.

### Datastar directives

```php
sse(function() use ($req): \Generator {
    $count = $req->signal('count', 0) + 1;
    yield \phorq\datastar\elements(h('#counter', $count));
    yield \phorq\datastar\signals(['count' => $count]);
});
```

For persistent real-time streams, combine with pub/sub:

```php
subscribe('/topics/counter');
```

### Pub/sub

```php
// publish to a topic — fire and forget
publish('/topics/prices', event('price', ['btc' => getPrice()]));

// subscribe client to a topic — transport handled by configured publisher
subscribe('/topics/prices');
```

Configure a publisher at bootstrap:

```php
\phorq\set_publisher(new \phorq\MercurePublisher($hubUrl, $secret));
// or
\phorq\set_publisher(new \phorq\RedisPublisher($redis));
```

## Module resolution

`$router->resolve()` finds a file in the current module with fallback to core:

```php
$router->resolve('layout');      // modules/{module}/layout.php → modules/core/layout.php
$router->resolve('errors/404');  // modules/{module}/errors/404.php → core fallback
```

Useful for layout files, error views, and shared helpers that modules can override.

## Caching

Pass a cache file path to `Router::create()`. The route map is scanned once and cached as a PHP file. Delete the file to rebuild.

```php
$router = \phorq\Router::create(
    __DIR__ . '/modules',
    __DIR__ . '/cache/routes.php',
);
```

Omit the second argument to disable caching.

## Built-in server

For local development, PHP's built-in server works out of the box:

```bash
php -S localhost:8080 public/index.php
```

The built-in server is single-threaded — SSE connections will block all other requests while open. For SSE development, use FrankenPHP locally:

```bash
frankenphp php-server
```

## Long-running runtimes

`\phorq\run()` handles FPM and FrankenPHP automatically. For Swoole and RoadRunner pass a runtime explicitly. Per-request directive resets are handled by `run()` — no middleware needed.

### FrankenPHP

FrankenPHP's `php-server` command serves PHP files directly with no configuration — useful for local development including SSE and Datastar:

```bash
frankenphp php-server
```

For worker mode (recommended for production), add a `Caddyfile`:

```
{
    frankenphp
}

localhost {
    root * public/
    php_server {
        worker public/index.php 4  # number of workers
    }
}
```

`run()` detects worker mode automatically — the same `public/index.php` works for both.

SSE is fully supported in both modes. In worker mode each SSE connection occupies a worker for the duration of the stream — size your worker count to handle concurrent SSE connections plus regular requests. A rough starting point: `num_cpus * 2 + expected_concurrent_sse_connections`.

### Swoole

```php
require __DIR__ . '/vendor/autoload.php';

$router = \phorq\Router::create(__DIR__ . '/modules');

\phorq\run($router, $ctx, new \phorq\SwooleRuntime(
    host: '0.0.0.0',
    port: 9501,
));
```

Options are passed directly to the Swoole server:

```php
\phorq\run($router, $ctx, new \phorq\SwooleRuntime(
    host:    '0.0.0.0',
    port:    9501,
    options: ['worker_num' => 4, 'enable_coroutine' => true],
));
```

### RoadRunner

Requires `spiral/roadrunner-http` and `nyholm/psr7`:

```bash
composer require spiral/roadrunner-http nyholm/psr7
```

```php
// public/index.php
require __DIR__ . '/../vendor/autoload.php';

$router = \phorq\Router::create(__DIR__ . '/../modules');

\phorq\run($router, $ctx, new \phorq\RoadRunnerRuntime());
```

`.rr.yaml`:

```yaml
server:
  command: "php public/index.php"
http:
  address: "0.0.0.0:8080"
  pool:
    num_workers: 4
```

## License

MIT