<?php
/**
 * XML Sitemap Generator for AviationWX.org
 * 
 * Serves sitemap only from root domain to establish single authoritative source.
 * Subdomain requests return 404 to prevent duplicate sitemap discovery by search engines.
 * 
 * Usage: https://aviationwx.org/sitemap.xml (via .htaccess rewrite)
 */

// Only serve from root domain to avoid duplicate sitemaps in search engines
$host = isset($_SERVER['HTTP_HOST']) ? strtolower(trim($_SERVER['HTTP_HOST'])) : '';
$isRootDomain = preg_match('/^(www\.)?aviationwx\.org$/i', $host);

if (!$isRootDomain) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n\n";
    echo "Sitemap is only available at https://aviationwx.org/sitemap.xml\n";
    exit;
}

header('Content-Type: application/xml; charset=utf-8');

require_once __DIR__ . '/../lib/sitemap.php';

// Get all sitemap URLs
$urls = getAllSitemapUrls();

if (empty($urls)) {
    http_response_code(500);
    die('<?xml version="1.0" encoding="UTF-8"?><error>Configuration error</error>');
}

// Start XML output
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
echo '        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\n";
echo '        xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9' . "\n";
echo '        http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">' . "\n";

foreach ($urls as $url) {
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($url['loc']) . "</loc>\n";
    echo "    <lastmod>{$url['lastmod']}</lastmod>\n";
    echo "    <changefreq>{$url['changefreq']}</changefreq>\n";
    echo "    <priority>{$url['priority']}</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>' . "\n";
