<?php
/**
 * Sitemap URL Generation Library
 * 
 * Provides shared URL generation for both XML and HTML sitemaps,
 * ensuring they stay in sync with a single source of truth.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/public-api/config.php';

/**
 * Strip a trailing stream-type annotation from a webcam name. Config names read
 * like "North Runway Camera (MJPEG)"; the parenthetical repeats the webcam
 * stream type and is dropped for sitemap display.
 *
 * Only recognized stream-type labels are removed. Spatial or operational
 * qualifiers such as "(North)" or "(Tower)" are rounded and preserved, since
 * they disambiguate cameras and belong in the image title.
 *
 * @param string $name Raw webcam name from config
 * @return string Display name without a trailing stream-type annotation
 */
function cleanWebcamName(string $name): string
{
    $cleaned = preg_replace(
        '/\s*\((?:MJPEG|Static JPEG|Static PNG|RTSP|RTSP Auth)\)\s*$/i',
        '',
        $name
    );
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
        
        if (isPublicApiEnabled()) {
            $images = buildSitemapWebcamImages($airport, $airportId, $airportName);
            if ($images !== []) {
                $entry['images'] = $images;
            }
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
 * Build sitemap image entries for one airport's weathercams.
 *
 * Disabled placeholder slots (`enabled: false`, no acquisition inputs) are
 * skipped; their Public API image endpoint would serve a placeholder, not the
 * advertised live webcam. Original config indexes are preserved so image URLs
 * match the Public API webcam index.
 *
 * @param array $airport Airport config entry
 * @param string $airportId Airport ID
 * @param string $airportName Display name used in captions
 * @return array<int, array{loc: string, title: string, caption: string}>
 */
function buildSitemapWebcamImages(array $airport, string $airportId, string $airportName): array
{
    // The Public API rejects IDs over AIRPORT_ID_MAX_LENGTH, so no image URL we
    // build for them would resolve.
    if (strlen($airportId) > AIRPORT_ID_MAX_LENGTH) {
        return [];
    }

    $webcams = $airport['webcams'] ?? [];
    if (!is_array($webcams)) {
        return [];
    }

    $images = [];
    foreach ($webcams as $camIndex => $cam) {
        if (!is_array($cam) || (isset($cam['enabled']) && $cam['enabled'] === false)) {
            continue;
        }

        $camName = cleanWebcamName(
            isset($cam['name']) && is_string($cam['name']) && $cam['name'] !== ''
                ? $cam['name']
                : 'Webcam ' . ($camIndex + 1)
        );

        $images[] = [
            'loc' => publicApiV1Url(
                '/airports/' . $airportId . '/webcams/' . $camIndex . '/image',
                true
            ),
            'title' => $camName,
            'caption' => 'Live ' . $camName . ' webcam at ' . $airportName,
        ];
    }

    return $images;
}

/**
 * Render one Google Image sitemap block for a webcam image.
 *
 * @param array{loc: string, title: string, caption: string} $image
 * @return string Image XML fragment (indented to match sitemap output)
 */
function renderSitemapImageXml(array $image): string
{
    $loc = htmlspecialchars($image['loc']);
    $title = htmlspecialchars($image['title']);
    $caption = htmlspecialchars($image['caption']);

    return "    <image:image>\n"
        . "      <image:loc>{$loc}</image:loc>\n"
        . "      <image:title>{$title}</image:title>\n"
        . "      <image:caption>{$caption}</image:caption>\n"
        . "    </image:image>\n";
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
