<?php

use phorq\{Request, Router};

/**
 * Core middleware — runs on every request.
 * Adds an X-Powered-By header.
 */
return function (callable $next, Request $req, mixed $ctx, Router $router) {
    header('X-Powered-By: phorq');
    return $next();
};
