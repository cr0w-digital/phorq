# ⎇ phorq

## File-based routing for PHP

Your directory structure *is* your URL structure. Dynamic params, catch-alls, middleware, and modules all work out of the box.

## Install

```bash
composer require cr0w/phorq
```

## Quick start

```
modules/
  core/
    middleware.php          # runs on every request
    routes/
      index.php             # GET /
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
    config.php              # { mount: 'blog' }
    routes/
      index.php             # /blog
      [slug].php            # /blog/:slug
```

### Front controller

```php
require __DIR__ . '/vendor/autoload.php';

use phorq\Router;

$router = Router::create(
    __DIR__ . '/modules',
    __DIR__ . '/cache/routes.php'
);

$result = $router->route();

if ($result) {
    echo (string) $result->value;
} else {
    http_response_code(404);
    echo '404 Not Found';
}
```

Pass an optional context as the first argument to thread shared state through middleware and route files:

```php
$result = $router->route($ctx);
```

`$ctx` can be anything — a plain object, an array, a service container. phorq doesn't inspect it.

## Routing conventions

| File / directory | Matches |
|---|---|
| `index.php` | Directory root |
| `about.php` | `/about` |
| `[id].php` | Dynamic segment `/42`, `$id` available |
| `[id]/settings/index.php` | `/42/settings`, `$id` available |
| `[...rest].php` | Catch-all, `$rest` is array of segments |
| `[[...rest]].php` | Optional catch-all file |
| `[[...rest]]/index.php` | Optional catch-all directory |

### Method branching

Handle different HTTP methods inside the route file using `$req`:

```php
<?php // routes/login.php
if ($req->isPost()) {
    // handle form submission
} else {
    // render form
}
```

### Catch-all routes

A **catch-all** (`[...rest].php` or `[...rest]/index.php`) captures one or more remaining path segments into an array variable. It only matches when at least one segment is present — `/docs` alone will not match `docs/[...rest].php`.

An **optional catch-all** (`[[...rest]].php` or `[[...rest]]/index.php`) also matches the bare directory. `/pages` matches with `$rest = []`, and `/pages/a/b` matches with `$rest = ['a', 'b']`.

### Precedence

1. Exact static match (`about.php`)
2. Dynamic param (`[id].php`)
3. Catch-all (`[...rest].php`)

Static directories are walked before dynamic or catch-all directories.

## Modules

Each subdirectory of the modules folder is a module. A module can have:

- **`config.php`** — returns `['mount' => 'prefix']` to set the URL prefix
- **`middleware.php`** — returns a callable (see below)
- **`routes/`** — file-based routes

The `core` module is special:
- Routes under `core/routes/` serve as the fallback when no other module matches.
- Core middleware runs **before** module-specific middleware on every request.
- Module mounts always win. If a module is mounted at `/blog`, a core route at `core/routes/blog/` is unreachable.

## Route files

Every route file receives these variables:

```php
$req    // Request object
$ctx    // whatever was passed to route() — may be null
$router // the Router instance
// + one variable per URL param, e.g. $id, $slug, $rest
```

`$req` is a `phorq\Request` with typed accessors:

```php
$req->method          // 'GET', 'POST', …
$req->path            // 'users/42'
$req->pattern         // '/core/users/[id]'
$req->module          // 'core'
$req->string('email') // trimmed string from input or query
$req->int('page', 1)  // integer with default
$req->bool('active')  // boolean
$req->isPost()        // method checks
$req->isHtmx()        // HX-Request header present
$req->target()        // HX-Target header
$req->header('X-Foo') // arbitrary header
```

Route files can return any value. The front controller decides what to do with it:

```php
<?php // routes/api/data.php
return ['json' => ['ok' => true, 'user' => 'Alice']];
```

```php
<?php // routes/index.php
echo '<h1>Hello</h1>';
```

## Middleware

```php
<?php // modules/core/middleware.php
use phorq\{Request, Router};

return function (callable $next, Request $req, mixed $ctx, Router $router) {
    // before handler
    $result = $next();
    // after handler
    return $result;
};
```

Trailing parameters you don't need can be omitted:

```php
return function (callable $next, Request $req) {
    if (!$req->isSecure()) {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
        exit;
    }
    return $next();
};
```

Middleware stacking order: **core → module → handler**.

## Caching

Pass a cache file path to `Router::create()` and the route map is written once, then loaded from cache on subsequent requests. Delete the file to rebuild.

```php
$router = Router::create(
    $modulesDir,
    __DIR__ . '/cache/routes.php'
);
```

Omit the second argument (or pass `null`) to disable caching during development.

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