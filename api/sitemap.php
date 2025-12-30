<?php
/**
 * Sitemap Generator for AviationWX.org
 * Generates XML sitemap dynamically from airports.json configuration
 * 
 * Usage: https://aviationwx.org/sitemap.xml (via .htaccess rewrite)
 */

header('Content-Type: application/xml; charset=utf-8');

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/cache-paths.php';

// Load configuration
$config = loadConfig();
if ($config === null || !isset($config['airports'])) {
    http_response_code(500);
    die('<?xml version="1.0" encoding="UTF-8"?><error>Configuration error</error>');
}

// Get base URL (protocol + domain)
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'aviationwx.org';
$baseUrl = $protocol . '://' . $host;

// Determine if we're on the main domain or a subdomain
$isMainDomain = (strpos($host, 'aviationwx.org') !== false && 
                 !preg_match('/^[a-z0-9]{3,4}\.aviationwx\.org$/', $host));

// Start XML output
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
echo '        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\n";
echo '        xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9' . "\n";
echo '        http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">' . "\n";

// Add homepage (only if on main domain)
if ($isMainDomain) {
    $homepageLastmod = date('Y-m-d'); // Use today's date as default
    // Try to get last modification time from airports.json
    $configFile = __DIR__ . '/../config/airports.json';
    if (file_exists($configFile)) {
        $homepageLastmod = date('Y-m-d', filemtime($configFile));
    }
    
    echo "  <url>\n";
    echo "    <loc>{$baseUrl}/</loc>\n";
    echo "    <lastmod>{$homepageLastmod}</lastmod>\n";
    echo "    <changefreq>daily</changefreq>\n";
    echo "    <priority>1.0</priority>\n";
    echo "  </url>\n";
}

// Add each enabled airport page
$enabledAirports = getEnabledAirports($config);
foreach ($enabledAirports as $airportId => $airport) {
    $airportUrl = $protocol . '://' . $airportId . '.aviationwx.org';
    
    // Determine lastmod date
    // Try to get from weather cache file modification time
    $weatherCacheFile = getWeatherCachePath($airportId);
    $lastmod = date('Y-m-d'); // Default to today
    
    if (file_exists($weatherCacheFile)) {
        $lastmod = date('Y-m-d\TH:i:s\Z', filemtime($weatherCacheFile));
    } else {
        // Fall back to airports.json modification time
        $configFile = __DIR__ . '/../config/airports.json';
        if (file_exists($configFile)) {
            $lastmod = date('Y-m-d\TH:i:s\Z', filemtime($configFile));
        }
    }
    
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($airportUrl) . "</loc>\n";
    echo "    <lastmod>{$lastmod}</lastmod>\n";
    echo "    <changefreq>hourly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    echo "  </url>\n";
}

// Add utility pages and subdomains (only if on main domain)
if ($isMainDomain) {
    $pageLastmod = date('Y-m-d');
    
    // Status page
    echo "  <url>\n";
    echo "    <loc>https://status.aviationwx.org</loc>\n";
    echo "    <lastmod>{$pageLastmod}</lastmod>\n";
    echo "    <changefreq>daily</changefreq>\n";
    echo "    <priority>0.3</priority>\n";
    echo "  </url>\n";
    
    // Guides subdomain
    echo "  <url>\n";
    echo "    <loc>https://guides.aviationwx.org</loc>\n";
    echo "    <lastmod>{$pageLastmod}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.6</priority>\n";
    echo "  </url>\n";
    
    // Terms of Service
    echo "  <url>\n";
    echo "    <loc>https://terms.aviationwx.org</loc>\n";
    echo "    <lastmod>{$pageLastmod}</lastmod>\n";
    echo "    <changefreq>yearly</changefreq>\n";
    echo "    <priority>0.2</priority>\n";
    echo "  </url>\n";
    
    // API Documentation
    echo "  <url>\n";
    echo "    <loc>https://api.aviationwx.org</loc>\n";
    echo "    <lastmod>{$pageLastmod}</lastmod>\n";
    echo "    <changefreq>monthly</changefreq>\n";
    echo "    <priority>0.5</priority>\n";
    echo "  </url>\n";
    
    // Embed Generator
    echo "  <url>\n";
    echo "    <loc>https://embed.aviationwx.org</loc>\n";
    echo "    <lastmod>{$pageLastmod}</lastmod>\n";
    echo "    <changefreq>monthly</changefreq>\n";
    echo "    <priority>0.5</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>' . "\n";

