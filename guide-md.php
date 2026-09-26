<?php
// Internal forwarder for .md guide variants (nginx internal location).
// Restores the original request URI (carried by nginx as X-Original-URI) so
// index.php routes the request as a guide and guides.php canonicalizes it.
$originalUri = $_SERVER['HTTP_X_ORIGINAL_URI'] ?? ($_SERVER['REQUEST_URI'] ?? '/');
$_SERVER['REQUEST_URI'] = $originalUri;

$query = parse_url($originalUri, PHP_URL_QUERY);
if (is_string($query) && $query !== '') {
    parse_str($query, $_GET);
}

require_once __DIR__ . '/index.php';