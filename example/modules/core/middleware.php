<?php
/**
 * Core middleware — runs on every request.
 * Adds an X-Powered-By header.
 */
return function (callable $next, array $req, \phorq\Context $ctx, \phorq\Router $router) {
    header('X-Powered-By: phorq');
    return $next();
};
