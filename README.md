# ⎇ phorq

## File-based routing for PHP

Your directory structure *is* your URL structure. Dynamic params, catch-alls, middleware, and modules all work out of the box.

## Install

```bash
composer require phorq/router
```

## Quick start

```
modules/
  core/
    middleware.php          # runs on every request
    routes/
      index.php             # GET /
      about.php             # GET /about
      users/
        index.php           # GET /users
        index.POST.php      # POST /users
        [id].php            # GET /users/:id
      docs/
        [...rest].php       # GET /docs/*
      pages/
        [[...path]]/
          index.php         # GET /pages and /pages/*
  blogging/
    config.php              # { mount: 'blog' }
    routes/
      index.php             # GET /blog
      [slug].php            # GET /blog/:slug
```

### Front controller

```php
require __DIR__ . '/vendor/autoload.php';

use phorq\{Context, Router};

$router = Router::create(
    __DIR__ . '/modules',
    __DIR__ . '/cache/routes.php'
);

$ctx = new Context();

$result = $router->route($ctx);

if ($result) {
    echo $result->body;
} else {
    http_response_code(404);
    echo '404 Not Found';
}
```

## Routing conventions

| File / directory | Matches |
|---|---|
| `index.php` | Directory root |
| `index.POST.php` | Directory root, POST only |
| `about.php` | `/about` (any method) |
| `about.GET.php` | `/about` GET only |
| `[id].php` | Dynamic segment `/42`, `$id` available |
| `[id]/settings/index.php` | `/42/settings`, `$id` available |
| `[...rest].php` | Catch-all, `$rest` is array of segments |
| `[[...rest]].php` | Optional catch-all file |
| `[[...rest]]/index.php` | Optional catch-all directory |

### Catch-all routes

A **catch-all** (`[...rest].php` or `[...rest]/index.php`) captures one or more remaining path segments into an array variable. It only matches when at least one segment is present. `/docs` alone will **not** match `docs/[...rest].php`.

An **optional catch-all** (`[[...rest]].php` or `[[...rest]]/index.php`) works the same way but also matches the bare directory. `/pages` matches with `$rest = []`, and `/pages/a/b` matches with `$rest = ['a', 'b']`.

### Precedence

1. Exact static match (`about.GET.php` > `about.php`)
2. Dynamic param (`[id].php`)
3. Catch-all (`[...rest].php`)

Static directories are walked before dynamic or catch-all directories.

## Modules

Each subdirectory of the modules folder is a module. A module can have:

- **`config.php`** - returns `['mount' => '/pre']` to set the URL prefix
- **`middleware.php`** - returns a callable `function(callable $next, array $req, Context $ctx, Router $router)`
- **`routes/`** - file-based routes

The `core` module is special:
- Routes under `core/routes/` serve as the fallback when no other module matches.
- Core middleware runs **before** module-specific middleware on every request.
- Module mounts always win. If a module is mounted at `/blog`, a core route at `core/routes/blog/` is unreachable.

## Context

`Context` is a base class with a public `$wrap` array. The router uses it to apply output transformations chosen by route handlers. Middleware can register wrappers at runtime:

```php
$ctx->wrap['json'] = function (mixed $data) {
    header('Content-Type: application/json');
    return json_encode($data);
};
$ctx->wrap['html'] = fn(string $s) => "<html><body>{$s}</body></html>";
```

Simple apps can use `Context` directly. For apps with services, extend it:

```php
class AppContext extends \phorq\Context
{
    public function __construct(
        public Logger $logger,
        public Cache $cache,
    ) {}
}
```

Route handlers select a wrapper by returning a single-key array where the key matches a wrap name:

```php
<?php // routes/api/data.php
$data = ['ok' => true, 'user' => 'Alice'];
return ['json' => $data];
// → passes $data to $ctx->wrap['json']($data)
```

## Middleware

```php
<?php // modules/core/middleware.php
return function (
    callable $next,
    array $req,
    \phorq\Context $ctx,
    \phorq\Router $router
) {
    // before
    $result = $next();
    // after
    return $result;
};
```

Middleware stacking order: **core → module → handler**.

## Caching

Pass a cache file path to `Router::create()` and the route map is written once, then loaded from the cache on subsequent requests. Delete the cache file to rebuild.

```php
$router = Router::create(
    $modulesDir,
    __DIR__ . '/cache/routes.php'
);
```

Pass `null` (or omit the parameter) to disable caching (useful during development).

## Testing

```bash
composer install
composer test
```

## Running the example

```bash
php -S localhost:8080 example/public/index.php
```

Then visit:

- [`/`](http://localhost:8080/)
- [`/about`](http://localhost:8080/about)
- [`/users/42`](http://localhost:8080/users/42)
- [`/docs/a/b`](http://localhost:8080/docs/a/b)
- [`/pages`](http://localhost:8080/pages)
- [`/pages/foo/bar`](http://localhost:8080/pages/foo/bar)
- [`/blog`](http://localhost:8080/blog)
- [`/blog/my-post`](http://localhost:8080/blog/my-post)

## License

MIT
