<?php

declare(strict_types=1);

return function (callable $next, \phorq\Request $req, mixed $ctx): array {
    session_start();
    $authed = !empty($_SESSION['authed']);

    if (!$authed) {
        return redirect('/login?from=' . urlencode('/' . $req->path));
    }

    return $next();
};