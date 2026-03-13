<?php
/**
 * Example: /users
 */
if ($req->isPost()) {
    header('Content-Type: application/json');
    return json_encode(['created' => true, 'input' => $req->input]);
}

echo '<h1>Users</h1><ul><li>Alice</li><li>Bob</li></ul>';