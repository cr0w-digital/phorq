<?php
/**
 * Example: GET /docs/* — catch-all route
 * $rest is an array of remaining path segments.
 */
$page = implode('/', $rest ?? []);
echo "<h1>Docs</h1><p>Page: {$page}</p>";
