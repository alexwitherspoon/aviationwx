<?php
/**
 * Social card image (serve only).
 *
 * Streams the rendered airport directory map card for Open Graph and Twitter
 * cards. Rendering happens on a schedule in scripts/render-map-card.php, never
 * on request. When no card has been rendered yet the endpoint redirects to the
 * static brand logo.
 */

require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/seo.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

$file = CACHE_SOCIAL_MAP_CARD_FILE;

if (!is_file($file)) {
    header('Location: ' . getDefaultSocialCardImageUrl(), true, 302);
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . (string) filesize($file));

if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}

readfile($file);
