<?php
// Internal forwarder for .md guide variants (nginx internal location).
// Restores the original request URI (path + query) so index.php routes the
// request as a guide, which lets guides.php redirect to the canonical URL.
$orig = $_GET['orig_uri'] ?? null;
unset($_GET['orig_uri']);
// nginx appends &$args even when empty, leaving a trailing "" key behind.
unset($_GET['']);
if (is_string($orig) && $orig !== '') {
    $qs = !empty($_GET) ? http_build_query($_GET) : '';
    $_SERVER['REQUEST_URI'] = $orig . ($qs !== '' ? '?' . $qs : '');
}
require_once __DIR__ . '/index.php';
