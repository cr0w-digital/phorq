<?php

declare(strict_types=1);

return function (callable $next, \phorq\Request $req, mixed $ctx): array {
    // Good place for auth checks, logging etc.
    return $next();
};