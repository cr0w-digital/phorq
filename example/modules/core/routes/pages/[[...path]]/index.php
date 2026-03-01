<?php
/**
 * Example: optional catch-all — matches /pages AND /pages/any/sub/path
 * $path is an array of segments (empty array when visiting /pages).
 */
$trail = $path ? implode(' / ', $path) : 'home';
echo "<h1>Pages</h1><p>{$trail}</p>";
