<?php
/**
 * Example: POST /users — create a user
 */
header('Content-Type: application/json');
echo json_encode(['created' => true, 'input' => $input ?? []]);
