<?php
/**
 * Sitemap URL Generation Library
 * 
 * Provides shared URL generation for both XML and HTML sitemaps,
 * ensuring they stay in sync with a single source of truth.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cache-paths.php';

/**
 * Strip the trailing stream-type annotation from a webcam name. Config names read
 * like "North Runway Camera (MJPEG)"; the parenthetical repeats the webcam `type`
 * and is dropped for sitemap display.
 *
 * @param string $name Raw webcam name from config
 * @return string Display name without the trailing annotation
 */
function cleanWebcamName(string $name): string
{
    $cleaned = preg_replace('/\s*\([^()]*\)\s*$/', '', $name);
    return $cleaned !== '' ? $cleaned : $name;
}

/**
 * Get all sitemap URLs grouped by category
 * 
 * @return array<string, array<int, array{
 *   loc: string,
 *   title: string,
 *   lastmod: string,
 *   changefreq: string,
 *   priority: string,
 *   images?: array<int, array{loc: string, title: string, caption: string}>
 * }>> URLs grouped by category (main, airports, guides, tools, legal)
 */
function getSitemapUrls(): array
{
    $config = loadConfig();
    if ($config === null || !isset($config['airports'])) {
        return [];
    }
    
    $urls = [
        'main' => [],
        'airports' => [],
        'guides' => [],
        'tools' => [],
        'legal' => []
    ];
    
    $today = date('Y-m-d');
    
    $configFile = getConfigFilePath();
    $configLastmod = ($configFile !== null && file_exists($configFile))
        ? date('Y-m-d', (int) filemtime($configFile))
        : $today;
    
    // Main pages
    $urls['main'][] = [
        'loc' => 'https://aviationwx.org/',
        'title' => 'Homepage',
        'lastmod' => $configLastmod,
        'changefreq' => 'daily',
        'priority' => '1.0'
    ];
    
    $urls['main'][] = [
        'loc' => 'https://airports.aviationwx.org/',
        'title' => 'Airport Directory',
        'lastmod' => $today,
        'changefreq' => 'daily',
        'priority' => '0.8'
    ];
    
    // Airport pages (only listed airports - excludes unlisted from SEO)
    $listedAirports = getListedAirports($config);
    foreach ($listedAirports as $airportId => $airport) {
        $primaryIdentifier = getPrimaryIdentifier($airportId, $airport);
        $airportUrl = 'https://' . strtolower($primaryIdentifier) . '.aviationwx.org/';
        
        $weatherCacheFile = getWeatherCachePath($airportId);
        if (file_exists($weatherCacheFile)) {
            $lastmod = date('Y-m-d\TH:i:s\Z', filemtime($weatherCacheFile));
        } else {
            $lastmod = $configLastmod . 'T00:00:00Z';
        }
        
        $airportName = $airport['name'] ?? strtoupper($airportId);
        
        $entry = [
            'loc' => $airportUrl,
            'title' => strtoupper($primaryIdentifier) . ' - ' . $airportName,
            'lastmod' => $lastmod,
            'changefreq' => 'hourly',
            'priority' => '0.8'
        ];
        
        $images = [];
        if (!empty($airport['webcams']) && is_array($airport['webcams'])) {
            foreach ($airport['webcams'] as $camIndex => $cam) {
                $camName = cleanWebcamName(
                    isset($cam['name']) && is_string($cam['name']) && $cam['name'] !== ''
                        ? $cam['name']
                        : 'Webcam ' . ($camIndex + 1)
                );
                $images[] = [
                    'loc' => $airportUrl . 'webcam.php?id=' . urlencode($airportId) . '&cam=' . $camIndex . '&fmt=jpg',
                    'title' => $camName,
                    'caption' => 'Live ' . $camName . ' webcam at ' . $airportName,
                ];
            }
        }
        if ($images !== []) {
            $entry['images'] = $images;
        }
        
        $urls['airports'][] = $entry;
    }
    
    // Guides
    $guidesDir = __DIR__ . '/../guides';
    
    $readmePath = $guidesDir . '/README.md';
    if (file_exists($readmePath)) {
        $urls['guides'][] = [
            'loc' => 'https://guides.aviationwx.org/',
            'title' => 'Guides Index',
            'lastmod' => date('Y-m-d', filemtime($readmePath)),
            'changefreq' => 'weekly',
            'priority' => '0.7'
        ];
    }
    
    if (is_dir($guidesDir)) {
        $files = scandir($guidesDir);
        foreach ($files as $file) {
            if (preg_match('/^(\d+)-(.+)\.md$/i', $file)) {
                $guideSlug = preg_replace('/\.md$/i', '', $file);
                $filePath = $guidesDir . '/' . $file;
                
                $titlePart = preg_replace('/^\d+-/', '', $guideSlug);
                $guideTitle = ucwords(str_replace('-', ' ', $titlePart));
                
                $urls['guides'][] = [
                    'loc' => 'https://guides.aviationwx.org/' . $guideSlug,
                    'title' => $guideTitle,
                    'lastmod' => date('Y-m-d', filemtime($filePath)),
                    'changefreq' => 'monthly',
                    'priority' => '0.6'
                ];
            }
        }
    }
    
    // Tools & Resources
    $urls['tools'][] = [
        'loc' => 'https://embed.aviationwx.org/',
        'title' => 'Embed Widget Generator',
        'lastmod' => $today,
        'changefreq' => 'monthly',
        'priority' => '0.5'
    ];
    
    $urls['tools'][] = [
        'loc' => 'https://api.aviationwx.org/',
        'title' => 'API Documentation',
        'lastmod' => $today,
        'changefreq' => 'monthly',
        'priority' => '0.5'
    ];
    
    $urls['tools'][] = [
        'loc' => 'https://status.aviationwx.org/',
        'title' => 'System Status',
        'lastmod' => $today,
        'changefreq' => 'daily',
        'priority' => '0.3'
    ];
    
    $urls['tools'][] = [
        'loc' => 'https://aviationwx.org/sitemap',
        'title' => 'Site Map',
        'lastmod' => $today,
        'changefreq' => 'daily',
        'priority' => '0.3'
    ];
    
    // Legal
    $urls['legal'][] = [
        'loc' => 'https://terms.aviationwx.org/',
        'title' => 'Terms of Service',
        'lastmod' => $today,
        'changefreq' => 'yearly',
        'priority' => '0.2'
    ];
    
    return $urls;
}

/**
 * Get flat list of all sitemap URLs
 * 
 * @return array<int, array{
 *   loc: string,
 *   title: string,
 *   lastmod: string,
 *   changefreq: string,
 *   priority: string,
 *   images?: array<int, array{loc: string, title: string, caption: string}>
 * }> Flat array of all URL entries
 */
function getAllSitemapUrls(): array
{
    $grouped = getSitemapUrls();
    $all = [];
    
    foreach ($grouped as $urls) {
        foreach ($urls as $url) {
            $all[] = $url;
        }
    }
    
    return $all;
}

/**
 * Get display labels for sitemap categories
 * 
 * @return array<string, string> Category key => display label
 */
function getSitemapCategoryLabels(): array
{
    return [
        'main' => 'Main',
        'airports' => 'Airports',
        'guides' => 'Guides',
        'tools' => 'Tools & Resources',
        'legal' => 'Legal'
    ];
}
