<?php
/**
 * XML Sitemap Generator for AviationWX.org
 * Generates XML sitemap dynamically using shared URL generation
 * 
 * Usage: https://aviationwx.org/sitemap.xml (via .htaccess rewrite)
 */

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
