<?php
/**
 * phorq example — front controller
 *
 * Try it:
 *   php -S localhost:8080 example/public/index.php
 *
 * Then visit:
 *   /              -> core index
 *   /about         -> core about page
 *   /users         -> user list
 *   /users/42      -> user #42
 *   /docs/a/b/c    -> catch-all docs
 *   /blog          -> blog index (separate module)
 *   /blog/my-post  -> blog post with slug param
 */

require __DIR__ . '/../../vendor/autoload.php';

use phorq\Router;
use phorq\Context;

$modulesDir = __DIR__ . '/../modules';

$router = Router::create($modulesDir);
$ctx = new Context();

$result = $router->route($ctx);

if ($result->ok()) {
    echo $result->body;
} else {
    http_response_code(404);
    echo '<h1>404 — Not Found</h1>';
}
