<?php

declare(strict_types=1);

return json([
    'ok'      => true,
    'time'    => date('c'),
    'path'    => $req->path,
    'method'  => $req->method,
    'runtime' => match (true) {
        defined('SWOOLE_VERSION')                     => 'swoole',
        function_exists('frankenphp_handle_request')  => 'frankenphp-worker',
        isset($_SERVER['FRANKENPHP_WORKER'])          => 'frankenphp',
        default                                       => 'fpm',
    },
]);