<?php
/**
 * Embed Widget Renderer
 * 
 * Renders embeddable weather widgets for airports.
 * Supports multiple styles: badge, card, webcam, multi, full
 * Matches the airport dashboard look and feel.
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/seo.php';
require_once __DIR__ . '/../lib/weather/utils.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/webcam-metadata.php';

// Get embed parameters
$embedAirportId = $_GET['embed_airport'] ?? $_GET['airport'] ?? '';
$style = $_GET['style'] ?? 'badge';
$theme = $_GET['theme'] ?? 'light';
$webcamIndex = isset($_GET['webcam']) ? intval($_GET['webcam']) : 0;
$target = $_GET['target'] ?? '_blank';
$format = $_GET['format'] ?? 'html'; // html or png

// Parse cams parameter for multi-cam widgets (comma-separated indices)
$cams = [0, 1, 2, 3]; // Default camera indices
if (isset($_GET['cams'])) {
    $camsParsed = array_map('intval', explode(',', $_GET['cams']));
    // Merge with defaults to ensure we always have 4 values
    for ($i = 0; $i < 4; $i++) {
        if (isset($camsParsed[$i])) {
            $cams[$i] = $camsParsed[$i];
        }
    }
}

// Unit preferences
$tempUnit = $_GET['temp'] ?? 'F';  // F or C
$distUnit = $_GET['dist'] ?? 'ft'; // ft or m
$windUnit = $_GET['wind'] ?? 'kt'; // kt, mph, or kmh
$baroUnit = $_GET['baro'] ?? 'inHg'; // inHg, hPa, or mmHg

// Validate units
if (!in_array($tempUnit, ['F', 'C'])) $tempUnit = 'F';
if (!in_array($distUnit, ['ft', 'm'])) $distUnit = 'ft';
if (!in_array($windUnit, ['kt', 'mph', 'kmh'])) $windUnit = 'kt';
if (!in_array($baroUnit, ['inHg', 'hPa', 'mmHg'])) $baroUnit = 'inHg';

// Unit conversion functions
function convertTemp($value, $unit) {
    if ($value === null) return null;
    if ($unit === 'C') {
        return ($value - 32) * 5 / 9;
    }
    return $value; // Already in F
}

function formatTemp($value, $unit) {
    if ($value === null) return '--';
    $converted = convertTemp($value, $unit);
    return round($converted) . '°' . $unit;
}

function convertDist($value, $unit) {
    if ($value === null) return null;
    if ($unit === 'm') {
        return $value * 0.3048; // ft to m
    }
    return $value; // Already in ft
}

function formatDist($value, $unit, $format = 'standard') {
    if ($value === null) return '--';
    $converted = convertDist($value, $unit);
    if ($format === 'comma') {
        return number_format($converted) . ' ' . $unit;
    }
    return round($converted) . ' ' . $unit;
}

function convertWindSpeed($value, $unit) {
    if ($value === null) return null;
    if ($unit === 'mph') {
        return $value * 1.15078; // kt to mph
    } elseif ($unit === 'kmh') {
        return $value * 1.852; // kt to km/h
    }
    return $value; // Already in kt
}

function formatWindSpeed($value, $unit) {
    if ($value === null) return '--';
    $converted = convertWindSpeed($value, $unit);
    $unitLabel = $unit === 'kmh' ? 'km/h' : $unit;
    return round($converted) . ' ' . $unitLabel;
}

function convertPressure($value, $unit) {
    if ($value === null) return null;
    // Input is in inHg
    if ($unit === 'hPa') {
        return $value * 33.8639; // inHg to hPa
    } elseif ($unit === 'mmHg') {
        return $value * 25.4; // inHg to mmHg
    }
    return $value; // Already in inHg
}

function formatPressure($value, $unit) {
    if ($value === null) return '--';
    $converted = convertPressure($value, $unit);
    if ($unit === 'hPa') {
        return round($converted) . ' hPa';
    } elseif ($unit === 'mmHg') {
        return round($converted) . ' mmHg';
    }
    return number_format($converted, 2) . '"Hg';
}

function formatRainfall($value, $distUnit) {
    if ($value === null) return '--';
    if ($distUnit === 'm') {
        // Convert inches to cm
        return number_format($value * 2.54, 2) . ' cm';
    }
    return number_format($value, 2) . ' in';
}

// Validate style
$validStyles = ['card', 'webcam', 'dual', 'multi', 'full', 'full-single', 'full-dual', 'full-multi'];
if (!in_array($style, $validStyles)) {
    $style = 'card';
}

// Validate theme
if (!in_array($theme, ['dark', 'light', 'auto'])) {
    $theme = 'auto'; // Default to auto for best user experience
}

// Validate target
if (!in_array($target, ['_blank', '_self', '_parent', '_top'])) {
    $target = '_blank';
}

// Load configuration and find airport
$config = loadConfig();
$airport = null;
$airportId = null;

if (!empty($embedAirportId) && $config && isset($config['airports'])) {
    // Try direct lookup first
    if (isset($config['airports'][$embedAirportId])) {
        $airport = $config['airports'][$embedAirportId];
        $airportId = $embedAirportId;
    } else {
        // Try lookup by identifier
        $result = findAirportByIdentifier($embedAirportId, $config);
        if ($result !== null && isset($result['airport']) && isset($result['airportId'])) {
            $airport = $result['airport'];
            $airportId = $result['airportId'];
        }
    }
}

// Check if airport is enabled
if ($airport && !isAirportEnabled($airport)) {
    $airport = null;
    $airportId = null;
}

// Get weather data if airport found
$weather = null;
if ($airport && $airportId) {
    $weatherCacheFile = getWeatherCachePath($airportId);
    if (file_exists($weatherCacheFile)) {
        $weatherData = json_decode(file_get_contents($weatherCacheFile), true);
        if (is_array($weatherData)) {
            $weather = $weatherData;
        }
    }
}

// Get primary identifier
$primaryIdentifier = $airport ? getPrimaryIdentifier($airportId, $airport) : 'N/A';
$airportName = $airport['name'] ?? 'Unknown Airport';
$airportTimezone = $airport['timezone'] ?? 'America/Los_Angeles';

// Get dashboard URL
$baseDomain = getBaseDomain();
$dashboardUrl = $airport ? 'https://' . $airportId . '.' . $baseDomain : 'https://' . $baseDomain;

// Get webcam info
$hasWebcams = $airport && isset($airport['webcams']) && count($airport['webcams']) > 0;
$webcamCount = $hasWebcams ? count($airport['webcams']) : 0;

// Get runway info for wind visualization
$runways = [];
if ($airport && isset($airport['runways']) && is_array($airport['runways'])) {
    $runways = $airport['runways'];
}

// Get webcam metadata for dynamic aspect ratios and variant support
$webcamMetadata = [];
if ($hasWebcams && $airportId) {
    for ($i = 0; $i < $webcamCount; $i++) {
        $meta = getWebcamMetadata($airportId, $i);
        if ($meta) {
            $webcamMetadata[$i] = $meta;
        } else {
            // Default to 16:9 if no metadata
            $webcamMetadata[$i] = [
                'width' => 1920,
                'height' => 1080,
                'aspect_ratio' => 1.777,
                'timestamp' => 0
            ];
        }
    }
}

// Helper function to build webcam URL with variant support
function buildEmbedWebcamUrl($dashboardUrl, $airportId, $camIndex, $format = 'jpg', $size = null) {
    $url = $dashboardUrl . '/webcam.php?id=' . urlencode($airportId) . '&cam=' . $camIndex . '&fmt=' . $format;
    if ($size !== null) {
        $url .= '&size=' . $size;
    }
    return $url;
}

// Helper function to build srcset for embed webcam
function buildEmbedWebcamSrcset($dashboardUrl, $airportId, $camIndex, $meta, $format = 'jpg') {
    if (!$meta || !isset($meta['timestamp']) || $meta['timestamp'] <= 0) {
        // Fallback to simple URL if no metadata
        return buildEmbedWebcamUrl($dashboardUrl, $airportId, $camIndex, $format, 'original');
    }
    
    $availableVariants = getAvailableVariants($airportId, $camIndex, $meta['timestamp']);
    if (empty($availableVariants)) {
        return buildEmbedWebcamUrl($dashboardUrl, $airportId, $camIndex, $format, 'original');
    }
    
    $srcsetParts = [];
    $aspectRatio = $meta['aspect_ratio'] ?? 1.777;
    $originalWidth = $meta['width'] ?? 1920;
    
    // Collect available variants for this format
    $variantList = [];
    foreach ($availableVariants as $variant => $formats) {
        if (in_array($format, $formats)) {
            if ($variant === 'original') {
                $variantList[] = 'original';
            } elseif (is_numeric($variant)) {
                $variantList[] = (int)$variant;
            }
        }
    }
    
    // Sort numeric variants descending, original last
    $numericVariants = array_filter($variantList, 'is_numeric');
    rsort($numericVariants);
    if (in_array('original', $variantList)) {
        $numericVariants[] = 'original';
    }
    
    foreach ($numericVariants as $variant) {
        $url = buildEmbedWebcamUrl($dashboardUrl, $airportId, $camIndex, $format, $variant);
        if ($variant === 'original') {
            $srcsetParts[] = $url . ' ' . $originalWidth . 'w';
        } else {
            $variantWidth = (int)round($aspectRatio * (int)$variant);
            if ($variantWidth > 3840) {
                $variantWidth = 3840;
            }
            $srcsetParts[] = $url . ' ' . $variantWidth . 'w';
        }
    }
    
    return implode(', ', $srcsetParts);
}

// Build webcam URL if needed (for single webcam style)
$webcamUrl = null;
$webcamSrcset = null;
if ($hasWebcams && $webcamIndex < $webcamCount) {
    $meta = $webcamMetadata[$webcamIndex] ?? null;
    $enabledFormats = getEnabledWebcamFormats();
    $webcamUrl = buildEmbedWebcamUrl($dashboardUrl, $airportId, $webcamIndex, 'jpg', 'original');
    $webcamSrcset = buildEmbedWebcamSrcset($dashboardUrl, $airportId, $webcamIndex, $meta, 'jpg');
}

// Detect if airport has METAR data
// METAR provides: flight_category, visibility, ceiling
$hasMetar = $airport && isMetarEnabled($airport);

// Also check if we actually have METAR-derived data in the weather cache
$hasMetarData = $hasMetar && isset($weather['flight_category']) && $weather['flight_category'] !== null;

// Extract weather values
$flightCategory = $weather['flight_category'] ?? null;
$temperature = $weather['temperature_f'] ?? $weather['temperature'] ?? null;
if ($temperature !== null && !isset($weather['temperature_f']) && $temperature < 50) {
    // Convert C to F if needed
    $temperature = ($temperature * 9/5) + 32;
}
$windSpeed = $weather['wind_speed'] ?? null;
$windDirection = $weather['wind_direction'] ?? null;
$gustSpeed = $weather['gust_speed'] ?? null;
$peakGustToday = $weather['peak_gust_today'] ?? null;
$peakGustTime = $weather['peak_gust_time'] ?? null;
$visibility = $weather['visibility'] ?? null;
$pressure = $weather['pressure'] ?? null;
$densityAltitude = $weather['density_altitude'] ?? null;
$pressureAltitude = $weather['pressure_altitude'] ?? null;
$dewpoint = $weather['dewpoint_f'] ?? null;
$dewpointSpread = $weather['dewpoint_spread'] ?? null;
$humidity = $weather['humidity'] ?? null;
$tempHighToday = $weather['temp_high_today'] ?? null;
$tempLowToday = $weather['temp_low_today'] ?? null;
$ceiling = $weather['ceiling'] ?? null;
$cloudCover = $weather['cloud_cover'] ?? null;
$rainfallToday = $weather['precip_accum'] ?? null;
$lastUpdated = $weather['last_updated'] ?? $weather['obs_time_primary'] ?? null;

// Get weather source type for display
$weatherSourceType = null;
if ($airport && isset($airport['weather_source']['type'])) {
    $weatherSourceType = $airport['weather_source']['type'];
}

// Build list of active data sources for display
$activeSources = [];
if ($weatherSourceType && $weatherSourceType !== 'metar') {
    switch ($weatherSourceType) {
        case 'tempest':
            $activeSources[] = 'Tempest';
            break;
        case 'ambient':
            $activeSources[] = 'Ambient';
            break;
        case 'weatherlink':
            $activeSources[] = 'Davis';
            break;
        case 'pwsweather':
            $activeSources[] = 'PWS';
            break;
        case 'synopticdata':
            $activeSources[] = 'Synoptic';
            break;
        default:
            $activeSources[] = ucfirst($weatherSourceType);
    }
}
if ($hasMetar) {
    $activeSources[] = 'NWS';  // National Weather Service (METAR source)
}

// Format sources for display
$sourceDisplay = implode(' + ', $activeSources);
$sourceDisplayShort = count($activeSources) > 1 
    ? strtoupper(substr($activeSources[0], 0, 3)) . '+NWS'
    : (count($activeSources) === 1 ? strtoupper($activeSources[0]) : 'LIVE');

// Format sources for footer attribution (& separated)
$sourceAttribution = !empty($activeSources) ? ' & ' . implode(' & ', $activeSources) : '';

// Format relative time
function formatRelativeTimeEmbed($timestamp) {
    if (!$timestamp || $timestamp <= 0) return 'Unknown';
    $diff = time() - $timestamp;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

// Format timestamp as 12-hour local time for embeds
function formatLocalTimeEmbed($timestamp, $timezone = 'America/Los_Angeles') {
    if (!$timestamp || $timestamp <= 0) return 'Unknown';
    try {
        $dt = new DateTime('@' . $timestamp);
        $tz = new DateTimeZone($timezone);
        $dt->setTimezone($tz);
        // Format: "5:30 PM PST" 
        return $dt->format('g:i A T');
    } catch (Exception $e) {
        return 'Unknown';
    }
}

// Flight category colors
function getFlightCategoryColors($category, $theme) {
    $isDark = ($theme === 'dark');
    $useAuto = ($theme === 'auto');
    
    switch (strtoupper($category ?? '')) {
        case 'VFR':
            return ['bg' => '#28a745', 'text' => '#fff'];
        case 'MVFR':
            return ['bg' => '#0066cc', 'text' => '#fff'];
        case 'IFR':
            return ['bg' => '#dc3545', 'text' => '#fff'];
        case 'LIFR':
            return ['bg' => '#ff00ff', 'text' => '#fff'];
        default:
            // For auto theme, use CSS variable; for static themes, use fixed color
            if ($useAuto) {
                return ['bg' => 'var(--unknown-bg)', 'text' => '#fff'];
            }
            return ['bg' => $isDark ? '#444' : '#888', 'text' => '#fff'];
    }
}

$categoryColors = getFlightCategoryColors($flightCategory, $theme);

// Determine if we need dynamic theming based on user preference
$useAutoTheme = ($theme === 'auto');
$isDark = ($theme === 'dark');

// For static themes (light/dark), set colors via PHP
// For auto theme, we'll use CSS variables with media queries
if (!$useAutoTheme) {
    $bgColor = $isDark ? '#1a1a1a' : '#ffffff';
    $cardBg = $isDark ? '#242424' : '#f8f9fa';
    $textColor = $isDark ? '#e0e0e0' : '#333333';
    $mutedColor = $isDark ? '#888888' : '#666666';
    $borderColor = $isDark ? '#333333' : '#dddddd';
}
$accentColor = '#0066cc';

// Set no-cache headers for embeds
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Frame-Options: ALLOWALL');

// Set 404 if airport not found (check before output)
if (empty($embedAirportId) || !$airport) {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($primaryIdentifier) ?> Weather - AviationWX</title>
    <style>
        <?php if ($useAutoTheme): ?>
        /* Auto theme: Use CSS variables that respond to system preference */
        :root {
            /* Light mode colors (default) */
            --bg-color: #ffffff;
            --card-bg: #f8f9fa;
            --text-color: #333333;
            --muted-color: #666666;
            --border-color: #dddddd;
            --accent-color: #0066cc;
            --footer-bg: rgba(0,0,0,0.05);
            --webcam-placeholder-bg: #ddd;
            --unknown-bg: #888;
            --no-metar-bg: #666;
            --peak-item-bg: rgba(255,150,0,0.15);
            --fog-warning-bg: rgba(255,100,100,0.15);
            --wind-compass-bg: #f0f0f0;
        }
        
        @media (prefers-color-scheme: dark) {
            :root {
                /* Dark mode colors (auto-detected) */
                --bg-color: #1a1a1a;
                --card-bg: #242424;
                --text-color: #e0e0e0;
                --muted-color: #888888;
                --border-color: #333333;
                --accent-color: #0066cc;
                --footer-bg: rgba(0,0,0,0.3);
                --webcam-placeholder-bg: #333;
                --unknown-bg: #444;
                --no-metar-bg: #444;
                --peak-item-bg: rgba(255,150,0,0.2);
                --fog-warning-bg: rgba(255,100,100,0.2);
                --wind-compass-bg: #1a1a1a;
            }
        }
        <?php else: ?>
        /* Static theme: Use CSS variables with fixed values */
        :root {
            --bg-color: <?= $bgColor ?>;
            --card-bg: <?= $cardBg ?>;
            --text-color: <?= $textColor ?>;
            --muted-color: <?= $mutedColor ?>;
            --border-color: <?= $borderColor ?>;
            --accent-color: <?= $accentColor ?>;
            --footer-bg: <?= $isDark ? 'rgba(0,0,0,0.3)' : 'rgba(0,0,0,0.05)' ?>;
            --webcam-placeholder-bg: <?= $isDark ? '#333' : '#ddd' ?>;
            --unknown-bg: <?= $isDark ? '#444' : '#888' ?>;
            --no-metar-bg: <?= $isDark ? '#444' : '#666' ?>;
            --peak-item-bg: <?= $isDark ? 'rgba(255,150,0,0.2)' : 'rgba(255,150,0,0.15)' ?>;
            --fog-warning-bg: <?= $isDark ? 'rgba(255,100,100,0.2)' : 'rgba(255,100,100,0.15)' ?>;
            --wind-compass-bg: <?= $isDark ? '#1a1a1a' : '#f0f0f0' ?>;
        }
        <?php endif; ?>
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html, body {
            width: 100%;
            height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: var(--bg-color);
            color: var(--text-color);
            line-height: 1.4;
            overflow: hidden;
        }
        
        a {
            color: inherit;
            text-decoration: none;
        }
        
        .embed-container {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        /* Flight category badge */
        .flight-category {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            background: <?= $categoryColors['bg'] ?>;
            color: <?= $categoryColors['text'] ?>;
        }
        
        .flight-category.unknown {
            background: var(--unknown-bg);
        }
        
        /* Unified footer */
        .embed-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.3rem 0.5rem;
            background: var(--footer-bg);
            font-size: 0.7rem;
            color: var(--muted-color);
            gap: 0.5rem;
            flex-shrink: 0;
        }
        
        .embed-footer .footer-left {
            flex: 1;
            text-align: left;
        }
        
        .embed-footer .footer-center {
            flex: 1;
            text-align: center;
            color: var(--accent-color);
            font-weight: 500;
        }
        
        .embed-footer .footer-right {
            flex: 1;
            text-align: right;
        }
        
        
        
        /* ========================
           MINI AIRPORT CARD STYLE (300x275)
           ======================== */
        .style-card {
            height: 100%;
            display: flex;
            flex-direction: column;
            cursor: pointer;
        }
        
        .style-card .card-header {
            background: var(--card-bg);
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .style-card .airport-info h2 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.1rem;
        }
        
        .style-card .airport-info .airport-meta {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .style-card .airport-info .identifier {
            font-size: 0.8rem;
            color: var(--accent-color);
            font-weight: 600;
        }
        
        .style-card .airport-info .webcam-count {
            font-size: 0.7rem;
            color: var(--muted-color);
        }
        
        .style-card .card-body {
            flex: 1;
            padding: 0.5rem 0.75rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 0;
        }
        
        .style-card .weather-row {
            background: var(--card-bg);
            padding: 0.75rem 0.5rem;
            display: flex;
            justify-content: space-around;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
        }
        
        .style-card .weather-row:last-child {
            border-bottom: none;
        }
        
        .style-card .weather-row .item {
            text-align: center;
        }
        
        .style-card .weather-row .label {
            font-size: 0.65rem;
            color: var(--muted-color);
            text-transform: uppercase;
        }
        
        .style-card .weather-row .value {
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .style-card .weather-row .wind-mini {
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .style-card .weather-row .wind-mini canvas {
            display: block;
        }
        
        
        /* ========================
           WEBCAM STYLE (400x320)
           ======================== */
        .style-webcam {
            height: 100%;
            display: flex;
            flex-direction: column;
            cursor: pointer;
        }
        
        .style-webcam .webcam-container {
            flex: 1;
            position: relative;
            background: #000;
            overflow: hidden;
            <?php if ($webcamUrl && isset($webcamMetadata[$webcamIndex])): 
                $meta = $webcamMetadata[$webcamIndex];
                $aspectRatio = $meta['aspect_ratio'] ?? 1.777;
            ?>
            aspect-ratio: <?= $aspectRatio ?>;
            <?php else: ?>
            aspect-ratio: 16/9;
            <?php endif; ?>
        }
        
        .style-webcam .webcam-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .style-webcam .overlay-info {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(transparent, rgba(0,0,0,0.8));
            padding: 1.5rem 0.75rem 0.5rem;
            color: white;
        }
        
        .style-webcam .overlay-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .style-webcam .overlay-left {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .style-webcam .overlay-left .code {
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        .style-webcam .weather-bar {
            background: var(--card-bg);
            padding: 0.5rem 0.75rem;
            display: flex;
            justify-content: space-around;
            border-top: 1px solid var(--border-color);
        }
        
        .style-webcam .weather-bar .item {
            text-align: center;
            font-size: 0.85rem;
        }
        
        .style-webcam .weather-bar .item .label {
            font-size: 0.65rem;
            color: var(--muted-color);
            text-transform: uppercase;
        }
        
        .style-webcam .weather-bar .item .value {
            font-weight: 600;
        }
        
        .style-webcam .weather-bar .wind-mini {
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .style-webcam .weather-bar .wind-mini canvas {
            display: block;
        }
        
        /* ========================
           DUAL CAMERA STYLE (600x300)
           ======================== */
        .style-dual {
            height: 100%;
            display: flex;
            flex-direction: column;
            cursor: pointer;
        }
        
        .style-dual .dual-header {
            background: var(--card-bg);
            padding: 0.5rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
        }
        
        .style-dual .dual-header h2 {
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .style-dual .dual-header .code {
            color: var(--accent-color);
        }
        
        .style-dual .dual-header .cam-count {
            font-size: 0.7rem;
            font-weight: normal;
            color: var(--muted-color);
            margin-left: 0.5rem;
        }
        
        .style-dual .dual-webcam-grid {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2px;
            background: var(--border-color);
        }
        
        .style-dual .dual-webcam-cell {
            position: relative;
            background: #000;
            overflow: hidden;
        }
        
        <?php if ($hasWebcams && $airportId): 
            // Set dynamic aspect ratios for dual webcam cells
            for ($slot = 0; $slot < 2; $slot++):
                $camIdx = $cams[$slot] ?? $slot;
                if ($camIdx < $webcamCount && isset($webcamMetadata[$camIdx])):
                    $meta = $webcamMetadata[$camIdx];
                    $aspectRatio = $meta['aspect_ratio'] ?? 1.777;
        ?>
        .style-dual .dual-webcam-cell:nth-child(<?= $slot + 1 ?>) {
            aspect-ratio: <?= $aspectRatio ?>;
        }
        <?php 
                endif;
            endfor;
        endif; ?>
        
        .style-dual .dual-webcam-cell img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .style-dual .dual-webcam-cell .cam-label {
            position: absolute;
            bottom: 0.25rem;
            left: 0.25rem;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 0.15rem 0.4rem;
            font-size: 0.7rem;
            border-radius: 3px;
        }
        
        .style-dual .dual-webcam-cell.no-cam {
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--wind-compass-bg);
        }
        
        .style-dual .dual-webcam-cell .no-webcam-placeholder {
            color: var(--muted-color);
            font-size: 0.85rem;
        }
        
        .style-dual .dual-weather-bar {
            background: var(--card-bg);
            padding: 0.5rem 1rem;
            display: flex;
            justify-content: space-around;
            align-items: center;
            border-top: 1px solid var(--border-color);
        }
        
        .style-dual .dual-weather-bar .item {
            text-align: center;
        }
        
        .style-dual .dual-weather-bar .label {
            font-size: 0.65rem;
            color: var(--muted-color);
            text-transform: uppercase;
        }
        
        .style-dual .dual-weather-bar .value {
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .style-dual .dual-weather-bar .wind-mini {
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .style-dual .dual-weather-bar .wind-mini canvas {
            display: block;
        }
        
        /* ========================
           4 CAMERA GRID STYLE (600x600)
           ======================== */
        .style-multi {
            height: 100%;
            display: flex;
            flex-direction: column;
            cursor: pointer;
        }
        
        .style-multi .multi-header {
            background: var(--card-bg);
            padding: 0.5rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
        }
        
        .style-multi .multi-header h2 {
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .style-multi .multi-header .code {
            color: var(--accent-color);
        }
        
        .style-multi .multi-header .cam-count {
            font-size: 0.7rem;
            font-weight: normal;
            color: var(--muted-color);
            margin-left: 0.5rem;
        }
        
        .style-multi .webcam-grid {
            display: grid;
            gap: 2px;
            background: var(--border-color);
        }
        
        .style-multi .webcam-grid.cams-1 {
            grid-template-columns: 1fr;
        }
        
        .style-multi .webcam-grid.cams-2 {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .style-multi .webcam-grid.cams-3 {
            grid-template-columns: repeat(3, 1fr);
        }
        
        .style-multi .webcam-grid.cams-4 {
            flex: 1;
            grid-template-columns: repeat(2, 1fr);
            grid-template-rows: repeat(2, 1fr);
        }
        
        /* Dynamic aspect ratios will be set inline per cell based on metadata */
        .style-multi .webcam-grid.cams-4 .webcam-cell {
            aspect-ratio: auto;
        }
        
        .style-multi .webcam-cell {
            position: relative;
            background: #000;
            overflow: hidden;
        }
        
        .style-multi .webcam-cell img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .style-multi .webcam-cell .cam-label {
            position: absolute;
            bottom: 0.25rem;
            left: 0.25rem;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 0.15rem 0.4rem;
            font-size: 0.65rem;
            border-radius: 3px;
        }
        
        .style-multi .weather-summary {
            background: var(--card-bg);
            padding: 0.5rem 1rem;
            display: flex;
            justify-content: space-around;
            align-items: center;
            border-top: 1px solid var(--border-color);
        }
        
        .style-multi .weather-summary .item {
            text-align: center;
        }
        
        .style-multi .weather-summary .label {
            font-size: 0.65rem;
            color: var(--muted-color);
            text-transform: uppercase;
        }
        
        .style-multi .weather-summary .value {
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .style-multi .weather-summary .wind-mini {
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .style-multi .weather-summary .wind-mini canvas {
            display: block;
        }
        
        /* ========================
           FULL WIDGET STYLE (800x500)
           ======================== */
        .style-full {
            height: 100%;
            display: flex;
            flex-direction: column;
            cursor: pointer;
        }
        
        .style-full .full-header {
            background: var(--card-bg);
            padding: 0.75rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
        }
        
        .style-full .airport-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .style-full .airport-title h2 {
            font-size: 1.2rem;
        }
        
        .style-full .airport-title .code {
            color: var(--accent-color);
            font-weight: 700;
        }
        
        .style-full .airport-title .cam-count {
            font-size: 0.75rem;
            font-weight: normal;
            color: var(--muted-color);
            margin-left: 0.5rem;
        }
        
        .style-full .full-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 0;
        }
        
        .style-full .webcam-section {
            position: relative;
            background: #000;
            flex: 1;
            min-height: 100px;
        }
        
        .style-full .webcam-section img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .style-full .data-row {
            display: flex;
            background: <?= $bgColor ?>;
            border-top: 1px solid var(--border-color);
            flex-shrink: 0;
        }
        
        .style-full .wind-section {
            display: flex;
            background: var(--card-bg);
            border-right: 1px solid var(--border-color);
        }
        
        .style-full .wind-viz-container {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 0.5rem;
            min-width: 110px;
        }
        
        .style-full .wind-section .wind-details {
            padding: 0.4rem 0.5rem;
            border-left: 1px solid var(--border-color);
            min-width: 100px;
        }
        
        .style-full .wind-section .wind-details .column-header {
            font-size: 0.7rem;
            font-weight: 600;
            color: <?= $theme === 'dark' ? '#4dabf7' : '#0066cc' ?>;
            margin-bottom: 0.3rem;
            white-space: nowrap;
        }
        
        .style-full .wind-section .wind-details .metric-item {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            padding: 0.15rem 0;
        }
        
        .style-full .wind-section .wind-details .metric-item .label {
            color: <?= $theme === 'dark' ? '#aaa' : '#666' ?>;
        }
        
        .style-full .wind-section .wind-details .metric-item .value {
            font-weight: 600;
            color: var(--text-color);
        }
        
        .style-full .wind-section .wind-details .peak-item .value {
            color: <?= $theme === 'dark' ? '#ffa94d' : '#e67700' ?>;
        }
        
        .style-full .wind-section .wind-details .peak-time-item {
            font-size: 0.65rem;
            opacity: 0.8;
            margin-top: -0.1rem;
        }
        
        .style-full .wind-section .wind-details .peak-time-item .value {
            color: <?= $theme === 'dark' ? '#ffa94d' : '#e67700' ?>;
            font-weight: 500;
        }
        
        .style-full .wind-viz-container canvas {
            display: block;
        }
        
        .style-full .wind-summary {
            text-align: center;
            margin-top: 0.25rem;
        }
        
        .style-full .wind-summary .wind-value {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .style-full .metrics-section {
            flex: 1;
            background: <?= $bgColor ?>;
            padding: 0.4rem 0.5rem;
            display: flex;
            overflow: hidden;
        }
        
        .style-full .metrics-columns {
            display: flex;
            gap: 0.5rem;
            flex: 1;
        }
        
        .style-full .metric-column {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            background: var(--card-bg);
            border-radius: 6px;
            padding: 0.4rem;
        }
        
        .style-full .column-header {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--accent-color);
            padding-bottom: 0.25rem;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 0.15rem;
        }
        
        .style-full .metric-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.15rem 0;
        }
        
        .style-full .metric-item.peak-item {
            background: var(--peak-item-bg);
            border-radius: 3px;
            padding: 0.15rem 0.25rem;
            margin: 0 -0.25rem;
        }
        
        .style-full .metric-item.fog-warning {
            background: var(--fog-warning-bg);
            border-radius: 3px;
            padding: 0.15rem 0.25rem;
            margin: 0 -0.25rem;
        }
        
        .style-full .metric-item .label {
            font-size: 0.6rem;
            color: var(--muted-color);
        }
        
        .style-full .metric-item .value {
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        
        /* No data placeholder */
        .no-data {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--muted-color);
            text-align: center;
            padding: 1rem;
        }
        
        .no-data .icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .no-webcam-placeholder {
            background: var(--webcam-placeholder-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--muted-color);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<?php if (!$airport): ?>
    <?php http_response_code(404); ?>
    <div class="no-data">
        <div class="icon">✈️</div>
        <p>Airport not found</p>
        <p style="font-size: 0.8rem; margin-top: 0.5rem;">
            <a href="https://<?= htmlspecialchars($baseDomain) ?>" target="<?= htmlspecialchars($target) ?>" style="color: var(--accent-color);">
                View all airports →
            </a>
        </p>
    </div>
<?php else: ?>
    <a href="<?= htmlspecialchars($dashboardUrl) ?>" target="<?= htmlspecialchars($target) ?>" rel="noopener" class="embed-container theme-<?= htmlspecialchars($theme) ?>">
    
    <?php if ($style === 'card'): ?>
        <!-- CARD STYLE -->
        <div class="style-card">
            <div class="card-header">
                <div class="airport-info">
                    <h2><?= htmlspecialchars($airportName) ?></h2>
                    <div class="airport-meta">
                        <span class="identifier"><?= htmlspecialchars($primaryIdentifier) ?></span>
                        <?php if ($webcamCount > 0): ?>
                        <span class="webcam-count">📷 <?= $webcamCount ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($hasMetarData): ?>
                    <span class="flight-category">
                        <?= htmlspecialchars($flightCategory) ?>
                    </span>
                <?php else: ?>
                    <!-- No METAR: show weather source indicator -->
                    <span class="flight-category" style="background: var(--no-metar-bg); font-size: 0.7rem;">
                        <?= htmlspecialchars($sourceDisplayShort) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="weather-row">
                    <div class="item wind-mini">
                        <canvas id="card-wind-canvas" width="60" height="60"></canvas>
                    </div>
                    <div class="item wind-block">
                        <div class="label">💨 Wind</div>
                        <div class="value">
                            <?php 
                            $windDir = $windDirection !== null ? round($windDirection) . '°' : '--';
                            $windSpd = $windSpeed !== null ? round(convertWindSpeed($windSpeed, $windUnit)) : '--';
                            $gustVal = ($gustSpeed !== null && $gustSpeed > 0) ? 'G' . round(convertWindSpeed($gustSpeed, $windUnit)) : '';
                            $windUnitLabel = $windUnit === 'kmh' ? 'km/h' : $windUnit;
                            ?>
                            <?= $windDir ?>@<?= $windSpd ?><?= $gustVal ?><?= $windUnitLabel ?>
                        </div>
                    </div>
                    <div class="item">
                        <div class="label">🌡️ Temp</div>
                        <div class="value"><?= formatTemp($temperature, $tempUnit) ?></div>
                    </div>
                </div>
                <div class="weather-row">
                    <div class="item">
                        <div class="label">📊 Altim</div>
                        <div class="value"><?= formatPressure($pressure, $baroUnit) ?></div>
                    </div>
                    <div class="item">
                        <div class="label">📈 DA</div>
                        <div class="value"><?= formatDist($densityAltitude, $distUnit, 'comma') ?></div>
                    </div>
                    <?php if ($hasMetarData && $visibility !== null): ?>
                    <div class="item">
                        <div class="label">👁️ Vis</div>
                        <div class="value"><?= $visibility >= 10 ? '10+' : round($visibility, 1) ?> SM</div>
                    </div>
                    <?php else: ?>
                    <div class="item">
                        <div class="label">💧 Humid</div>
                        <div class="value"><?= $humidity !== null ? round($humidity) . '%' : '--' ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Mini Wind Viz Script for Card -->
            <script>
            (function() {
                var canvas = document.getElementById('card-wind-canvas');
                if (!canvas) return;
                var ctx = canvas.getContext('2d');
                var cx = 30, cy = 30, r = 24;
                
                var runways = <?= json_encode($runways) ?>;
                var windSpeed = <?= $windSpeed !== null ? round($windSpeed) : 'null' ?>;
                var windDir = <?= ($windDirection !== null && is_numeric($windDirection)) ? round($windDirection) : 'null' ?>;
                var isVRB = <?= $windDirection === 'VRB' ? 'true' : 'false' ?>;
                <?php if ($useAutoTheme): ?>
                var isDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                <?php else: ?>
                var isDark = <?= $isDark ? 'true' : 'false' ?>;
                <?php endif; ?>
                
                // Draw circle
                ctx.strokeStyle = isDark ? '#555' : '#ccc';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.arc(cx, cy, r, 0, 2 * Math.PI);
                ctx.stroke();
                
                // Draw primary runway
                if (runways.length > 0) {
                    var h1 = runways[0].heading_1 || 0;
                    var angle = (h1 * Math.PI) / 180;
                    ctx.strokeStyle = isDark ? '#666' : '#999';
                    ctx.lineWidth = 4;
                    ctx.lineCap = 'round';
                    ctx.beginPath();
                    ctx.moveTo(cx - Math.sin(angle) * 18, cy + Math.cos(angle) * 18);
                    ctx.lineTo(cx + Math.sin(angle) * 18, cy - Math.cos(angle) * 18);
                    ctx.stroke();
                }
                
                // Draw wind arrow (only if wind >= 3 knots - calm otherwise)
                var CALM_WIND_THRESHOLD = 3;
                if (windSpeed !== null && windSpeed >= CALM_WIND_THRESHOLD && windDir !== null && !isVRB) {
                    var windAngle = ((windDir + 180) % 360) * Math.PI / 180;
                    var arrowLen = Math.min(windSpeed * 1.5, 18);
                    var endX = cx + Math.sin(windAngle) * arrowLen;
                    var endY = cy - Math.cos(windAngle) * arrowLen;
                    
                    ctx.strokeStyle = '#dc3545';
                    ctx.lineWidth = 2;
                    ctx.beginPath();
                    ctx.moveTo(cx, cy);
                    ctx.lineTo(endX, endY);
                    ctx.stroke();
                    
                    var headAngle = Math.atan2(endY - cy, endX - cx);
                    ctx.fillStyle = '#dc3545';
                    ctx.beginPath();
                    ctx.moveTo(endX, endY);
                    ctx.lineTo(endX - 6 * Math.cos(headAngle - Math.PI / 6), endY - 6 * Math.sin(headAngle - Math.PI / 6));
                    ctx.lineTo(endX - 6 * Math.cos(headAngle + Math.PI / 6), endY - 6 * Math.sin(headAngle + Math.PI / 6));
                    ctx.closePath();
                    ctx.fill();
                }
            })();
            </script>
        </div>
        <div class="embed-footer">
            <div class="footer-left">Last Updated: <?= htmlspecialchars(formatLocalTimeEmbed($lastUpdated, $airportTimezone)) ?></div>
            <div class="footer-center">View Dashboard</div>
            <div class="footer-right">Powered by AviationWX<?= htmlspecialchars($sourceAttribution) ?></div>
        </div>
        
    <?php elseif ($style === 'webcam'): ?>
        <!-- WEBCAM STYLE -->
        <div class="style-webcam">
            <div class="webcam-container">
                <?php if ($webcamUrl): 
                    $meta = $webcamMetadata[$webcamIndex] ?? null;
                    $enabledFormats = getEnabledWebcamFormats();
                    $baseUrl = $dashboardUrl . '/webcam.php?id=' . urlencode($airportId) . '&cam=' . $webcamIndex;
                    $timestamp = $meta['timestamp'] ?? 0;
                    if ($timestamp > 0) {
                        $baseUrl .= '&ts=' . $timestamp;
                    }
                ?>
                    <picture>
                        <?php if (in_array('webp', $enabledFormats) && $meta): ?>
                        <source srcset="<?= htmlspecialchars(buildEmbedWebcamSrcset($dashboardUrl, $airportId, $webcamIndex, $meta, 'webp')) ?>" type="image/webp" sizes="400px">
                        <?php endif; ?>
                        <img src="<?= htmlspecialchars($webcamUrl) ?>" 
                             <?php if ($webcamSrcset): ?>srcset="<?= htmlspecialchars($webcamSrcset) ?>" sizes="400px"<?php endif; ?>
                             alt="<?= htmlspecialchars($primaryIdentifier) ?> Webcam" 
                             class="webcam-image"
                             <?php if ($meta): ?>width="<?= $meta['width'] ?>" height="<?= $meta['height'] ?>"<?php endif; ?>>
                    </picture>
                <?php else: ?>
                    <div class="no-webcam-placeholder">No webcam available</div>
                <?php endif; ?>
                <div class="overlay-info">
                    <div class="overlay-row">
                        <div class="overlay-left">
                            <span class="code"><?= htmlspecialchars($primaryIdentifier) ?></span>
                            <?php if ($hasMetarData): ?>
                                <span class="flight-category">
                                    <?= htmlspecialchars($flightCategory) ?>
                                </span>
                            <?php elseif ($gustSpeed !== null && $gustSpeed > 0): ?>
                                <span class="flight-category" style="background: rgba(100,100,100,0.8);">
                                    G<?= round($gustSpeed) ?>kt
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($webcamCount > 1): ?>
                        <span style="font-size: 0.75rem; opacity: 0.9;">📷 <?= $webcamIndex + 1 ?> of <?= $webcamCount ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="weather-bar">
                <div class="item wind-mini">
                    <canvas id="webcam-wind-canvas" width="50" height="50"></canvas>
                </div>
                <!-- Compact Wind Block -->
                <div class="item wind-item">
                    <div class="label">💨 Wind</div>
                    <div class="value">
                        <?php 
                        $windDir = $windDirection !== null ? round($windDirection) . '°' : '--';
                        $windSpd = $windSpeed !== null ? round(convertWindSpeed($windSpeed, $windUnit)) : '--';
                        $gustVal = ($gustSpeed !== null && $gustSpeed > 0) ? 'G' . round(convertWindSpeed($gustSpeed, $windUnit)) : '';
                        $windUnitLabel = $windUnit === 'kmh' ? 'km/h' : $windUnit;
                        ?>
                        <?= $windDir ?>@<?= $windSpd ?><?= $gustVal ?><?= $windUnitLabel ?>
                    </div>
                </div>
                <div class="item">
                    <div class="label">🌡️ Temp</div>
                    <div class="value"><?= formatTemp($temperature, $tempUnit) ?></div>
                </div>
                <div class="item">
                    <div class="label">📊 Altim</div>
                    <div class="value"><?= formatPressure($pressure, $baroUnit) ?></div>
                </div>
            </div>
            
            <!-- Mini Wind Viz Script for Webcam -->
            <script>
            (function() {
                var canvas = document.getElementById('webcam-wind-canvas');
                if (!canvas) return;
                var ctx = canvas.getContext('2d');
                var cx = 25, cy = 25, r = 20;
                
                var runways = <?= json_encode($runways) ?>;
                var windSpeed = <?= $windSpeed !== null ? round($windSpeed) : 'null' ?>;
                var windDir = <?= ($windDirection !== null && is_numeric($windDirection)) ? round($windDirection) : 'null' ?>;
                var isVRB = <?= $windDirection === 'VRB' ? 'true' : 'false' ?>;
                <?php if ($useAutoTheme): ?>
                var isDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                <?php else: ?>
                var isDark = <?= $isDark ? 'true' : 'false' ?>;
                <?php endif; ?>
                
                // Draw circle
                ctx.strokeStyle = isDark ? '#555' : '#ccc';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.arc(cx, cy, r, 0, 2 * Math.PI);
                ctx.stroke();
                
                // Draw primary runway
                if (runways.length > 0) {
                    var h1 = runways[0].heading_1 || 0;
                    var angle = (h1 * Math.PI) / 180;
                    ctx.strokeStyle = isDark ? '#666' : '#999';
                    ctx.lineWidth = 3;
                    ctx.lineCap = 'round';
                    ctx.beginPath();
                    ctx.moveTo(cx - Math.sin(angle) * 14, cy + Math.cos(angle) * 14);
                    ctx.lineTo(cx + Math.sin(angle) * 14, cy - Math.cos(angle) * 14);
                    ctx.stroke();
                }
                
                // Draw wind arrow (only if wind >= 3 knots - calm otherwise)
                var CALM_WIND_THRESHOLD = 3;
                if (windSpeed !== null && windSpeed >= CALM_WIND_THRESHOLD && windDir !== null && !isVRB) {
                    var windAngle = ((windDir + 180) % 360) * Math.PI / 180;
                    var arrowLen = Math.min(windSpeed * 1.5, 15);
                    var endX = cx + Math.sin(windAngle) * arrowLen;
                    var endY = cy - Math.cos(windAngle) * arrowLen;
                    
                    ctx.strokeStyle = '#dc3545';
                    ctx.lineWidth = 2;
                    ctx.beginPath();
                    ctx.moveTo(cx, cy);
                    ctx.lineTo(endX, endY);
                    ctx.stroke();
                    
                    var headAngle = Math.atan2(endY - cy, endX - cx);
                    ctx.fillStyle = '#dc3545';
                    ctx.beginPath();
                    ctx.moveTo(endX, endY);
                    ctx.lineTo(endX - 5 * Math.cos(headAngle - Math.PI / 6), endY - 5 * Math.sin(headAngle - Math.PI / 6));
                    ctx.lineTo(endX - 5 * Math.cos(headAngle + Math.PI / 6), endY - 5 * Math.sin(headAngle + Math.PI / 6));
                    ctx.closePath();
                    ctx.fill();
                }
            })();
            </script>
            <div class="embed-footer">
                <div class="footer-left">Last Updated: <?= htmlspecialchars(formatLocalTimeEmbed($lastUpdated, $airportTimezone)) ?></div>
                <div class="footer-center">View Dashboard</div>
                <div class="footer-right">Powered by AviationWX<?= htmlspecialchars($sourceAttribution) ?></div>
            </div>
        </div>
        
    <?php elseif ($style === 'dual'): ?>
        <!-- DUAL CAMERA STYLE -->
        <div class="style-dual">
            <div class="dual-header">
                <h2>
                    <span class="code"><?= htmlspecialchars($primaryIdentifier) ?></span>
                    <span><?= htmlspecialchars($airportName) ?></span>
                    <?php if ($webcamCount > 0): ?>
                    <span class="cam-count">📷 <?= $webcamCount ?></span>
                    <?php endif; ?>
                </h2>
                <?php if ($hasMetarData): ?>
                    <span class="flight-category">
                        <?= htmlspecialchars($flightCategory) ?>
                    </span>
                <?php else: ?>
                    <span class="flight-category" style="background: var(--no-metar-bg); font-size: 0.7rem;">
                        <?php if ($gustSpeed !== null && $gustSpeed > 0): ?>
                            G<?= round($gustSpeed) ?>kt
                        <?php else: ?>
                            <?= htmlspecialchars($sourceDisplayShort) ?>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="dual-webcam-grid">
                <?php 
                if ($webcamCount > 0): 
                    // Use selected camera indices from $cams array
                    for ($slot = 0; $slot < 2; $slot++): 
                        $camIdx = $cams[$slot] ?? $slot;
                        // Ensure camera index is valid
                        if ($camIdx >= $webcamCount) $camIdx = $slot < $webcamCount ? $slot : 0;
                        $meta = $webcamMetadata[$camIdx] ?? null;
                        $camUrl = buildEmbedWebcamUrl($dashboardUrl, $airportId, $camIdx, 'jpg', 'original');
                        $camSrcset = buildEmbedWebcamSrcset($dashboardUrl, $airportId, $camIdx, $meta, 'jpg');
                        $camName = $airport['webcams'][$camIdx]['name'] ?? 'Camera ' . ($camIdx + 1);
                        $enabledFormats = getEnabledWebcamFormats();
                        $timestamp = $meta['timestamp'] ?? 0;
                        $baseUrl = $dashboardUrl . '/webcam.php?id=' . urlencode($airportId) . '&cam=' . $camIdx;
                        if ($timestamp > 0) {
                            $baseUrl .= '&ts=' . $timestamp;
                        }
                ?>
                <div class="dual-webcam-cell">
                    <picture>
                        <?php if (in_array('webp', $enabledFormats) && $meta): ?>
                        <source srcset="<?= htmlspecialchars(buildEmbedWebcamSrcset($dashboardUrl, $airportId, $camIdx, $meta, 'webp')) ?>" type="image/webp" sizes="300px">
                        <?php endif; ?>
                        <img src="<?= htmlspecialchars($camUrl) ?>" 
                             <?php if ($camSrcset): ?>srcset="<?= htmlspecialchars($camSrcset) ?>" sizes="300px"<?php endif; ?>
                             alt="<?= htmlspecialchars($camName) ?>"
                             <?php if ($meta): ?>width="<?= $meta['width'] ?>" height="<?= $meta['height'] ?>"<?php endif; ?>>
                    </picture>
                    <span class="cam-label"><?= htmlspecialchars($camName) ?></span>
                </div>
                <?php 
                    endfor; 
                else: 
                ?>
                <div class="dual-webcam-cell no-cam">
                    <div class="no-webcam-placeholder">No webcams available</div>
                </div>
                <div class="dual-webcam-cell no-cam">
                    <div class="no-webcam-placeholder">No webcams available</div>
                </div>
                <?php endif; ?>
            </div>
            <div class="dual-weather-bar">
                <div class="item wind-mini">
                    <canvas id="dual-wind-canvas" width="50" height="50"></canvas>
                </div>
                <!-- Compact Wind Block -->
                <div class="item wind-block">
                    <div class="label">💨 Wind</div>
                    <div class="value">
                        <?php 
                        $windDir = $windDirection !== null ? round($windDirection) . '°' : '--';
                        $windSpd = $windSpeed !== null ? round(convertWindSpeed($windSpeed, $windUnit)) : '--';
                        $gustVal = ($gustSpeed !== null && $gustSpeed > 0) ? 'G' . round(convertWindSpeed($gustSpeed, $windUnit)) : '';
                        $windUnitLabel = $windUnit === 'kmh' ? 'km/h' : $windUnit;
                        ?>
                        <?= $windDir ?>@<?= $windSpd ?><?= $gustVal ?><?= $windUnitLabel ?>
                    </div>
                </div>
                <div class="item">
                    <div class="label">🌡️ Temp</div>
                    <div class="value"><?= formatTemp($temperature, $tempUnit) ?></div>
                </div>
                <div class="item">
                    <div class="label">📊 Altim</div>
                    <div class="value"><?= formatPressure($pressure, $baroUnit) ?></div>
                </div>
                <div class="item">
                    <div class="label">📈 DA</div>
                    <div class="value"><?= formatDist($densityAltitude, $distUnit, 'comma') ?></div>
                </div>
            </div>
            
            <!-- Mini Wind Viz Script for Dual -->
            <script>
            (function() {
                var canvas = document.getElementById('dual-wind-canvas');
                if (!canvas) return;
                var ctx = canvas.getContext('2d');
                var cx = 25, cy = 25, r = 20;
                
                var runways = <?= json_encode($runways) ?>;
                var windSpeed = <?= $windSpeed !== null ? round($windSpeed) : 'null' ?>;
                var windDir = <?= ($windDirection !== null && is_numeric($windDirection)) ? round($windDirection) : 'null' ?>;
                var isVRB = <?= $windDirection === 'VRB' ? 'true' : 'false' ?>;
                <?php if ($useAutoTheme): ?>
                var isDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                <?php else: ?>
                var isDark = <?= $isDark ? 'true' : 'false' ?>;
                <?php endif; ?>
                
                // Draw circle
                ctx.strokeStyle = isDark ? '#555' : '#ccc';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.arc(cx, cy, r, 0, 2 * Math.PI);
                ctx.stroke();
                
                // Draw primary runway
                if (runways.length > 0) {
                    var h1 = runways[0].heading_1 || 0;
                    var angle = (h1 * Math.PI) / 180;
                    ctx.strokeStyle = isDark ? '#666' : '#999';
                    ctx.lineWidth = 3;
                    ctx.lineCap = 'round';
                    ctx.beginPath();
                    ctx.moveTo(cx - Math.sin(angle) * 14, cy + Math.cos(angle) * 14);
                    ctx.lineTo(cx + Math.sin(angle) * 14, cy - Math.cos(angle) * 14);
                    ctx.stroke();
                }
                
                // Draw wind arrow (only if wind >= 3 knots - calm otherwise)
                var CALM_WIND_THRESHOLD = 3;
                if (windSpeed !== null && windSpeed >= CALM_WIND_THRESHOLD && windDir !== null && !isVRB) {
                    var windAngle = ((windDir + 180) % 360) * Math.PI / 180;
                    var arrowLen = Math.min(windSpeed * 1.5, 15);
                    var endX = cx + Math.sin(windAngle) * arrowLen;
                    var endY = cy - Math.cos(windAngle) * arrowLen;
                    
                    ctx.strokeStyle = '#dc3545';
                    ctx.lineWidth = 2;
                    ctx.beginPath();
                    ctx.moveTo(cx, cy);
                    ctx.lineTo(endX, endY);
                    ctx.stroke();
                    
                    var headAngle = Math.atan2(endY - cy, endX - cx);
                    ctx.fillStyle = '#dc3545';
                    ctx.beginPath();
                    ctx.moveTo(endX, endY);
                    ctx.lineTo(endX - 5 * Math.cos(headAngle - Math.PI / 6), endY - 5 * Math.sin(headAngle - Math.PI / 6));
                    ctx.lineTo(endX - 5 * Math.cos(headAngle + Math.PI / 6), endY - 5 * Math.sin(headAngle + Math.PI / 6));
                    ctx.closePath();
                    ctx.fill();
                }
            })();
            </script>
            <div class="embed-footer">
                <div class="footer-left">Last Updated: <?= htmlspecialchars(formatLocalTimeEmbed($lastUpdated, $airportTimezone)) ?></div>
                <div class="footer-center">View Dashboard</div>
                <div class="footer-right">Powered by AviationWX<?= htmlspecialchars($sourceAttribution) ?></div>
            </div>
        </div>
        
    <?php elseif ($style === 'multi'): ?>
        <!-- MULTI-WEBCAM STYLE -->
        <div class="style-multi">
            <div class="multi-header">
                <h2>
                    <span class="code"><?= htmlspecialchars($primaryIdentifier) ?></span>
                    <span><?= htmlspecialchars($airportName) ?></span>
                    <?php if ($webcamCount > 0): ?>
                    <span class="cam-count">📷 <?= $webcamCount ?></span>
                    <?php endif; ?>
                </h2>
                <?php if ($hasMetarData): ?>
                    <span class="flight-category">
                        <?= htmlspecialchars($flightCategory) ?>
                    </span>
                <?php else: ?>
                    <span class="flight-category" style="background: var(--no-metar-bg); font-size: 0.7rem;">
                        <?php if ($gustSpeed !== null && $gustSpeed > 0): ?>
                            G<?= round($gustSpeed) ?>kt
                        <?php else: ?>
                            <?= htmlspecialchars($sourceDisplayShort) ?>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php 
            $displayCamCount = min($webcamCount, 4);
            $gridClass = 'cams-' . max(1, $displayCamCount);
            ?>
            <div class="webcam-grid <?= $gridClass ?>">
                <?php if ($webcamCount > 0): ?>
                    <?php for ($slot = 0; $slot < $displayCamCount; $slot++): 
                        // Use selected camera index from $cams array
                        $camIdx = $cams[$slot] ?? $slot;
                        // Ensure camera index is valid
                        if ($camIdx >= $webcamCount) $camIdx = $slot < $webcamCount ? $slot : 0;
                        $meta = $webcamMetadata[$camIdx] ?? null;
                        $camUrl = buildEmbedWebcamUrl($dashboardUrl, $airportId, $camIdx, 'jpg', 'original');
                        $camSrcset = buildEmbedWebcamSrcset($dashboardUrl, $airportId, $camIdx, $meta, 'jpg');
                        $camName = $airport['webcams'][$camIdx]['name'] ?? 'Camera ' . ($camIdx + 1);
                        $enabledFormats = getEnabledWebcamFormats();
                        $aspectRatio = $meta ? $meta['aspect_ratio'] : 1.777;
                        $timestamp = $meta['timestamp'] ?? 0;
                        $baseUrl = $dashboardUrl . '/webcam.php?id=' . urlencode($airportId) . '&cam=' . $camIdx;
                        if ($timestamp > 0) {
                            $baseUrl .= '&ts=' . $timestamp;
                        }
                    ?>
                    <div class="webcam-cell" style="aspect-ratio: <?= $aspectRatio ?>;">
                        <picture>
                            <?php if (in_array('webp', $enabledFormats) && $meta): ?>
                            <source srcset="<?= htmlspecialchars(buildEmbedWebcamSrcset($dashboardUrl, $airportId, $camIdx, $meta, 'webp')) ?>" type="image/webp" sizes="300px">
                            <?php endif; ?>
                            <img src="<?= htmlspecialchars($camUrl) ?>" 
                                 <?php if ($camSrcset): ?>srcset="<?= htmlspecialchars($camSrcset) ?>" sizes="300px"<?php endif; ?>
                                 alt="<?= htmlspecialchars($camName) ?>"
                                 <?php if ($meta): ?>width="<?= $meta['width'] ?>" height="<?= $meta['height'] ?>"<?php endif; ?>>
                        </picture>
                        <span class="cam-label"><?= htmlspecialchars($camName) ?></span>
                    </div>
                    <?php endfor; ?>
                <?php else: ?>
                    <div class="webcam-cell no-cams">
                        <div class="no-webcam-placeholder">No webcams available</div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="weather-summary">
                <div class="item wind-mini">
                    <canvas id="wind-mini-canvas" width="50" height="50"></canvas>
                </div>
                <!-- Compact Wind Block -->
                <div class="item wind-block">
                    <div class="label">💨 Wind</div>
                    <div class="value">
                        <?php 
                        $windDir = $windDirection !== null ? round($windDirection) . '°' : '--';
                        $windSpd = $windSpeed !== null ? round(convertWindSpeed($windSpeed, $windUnit)) : '--';
                        $gustVal = ($gustSpeed !== null && $gustSpeed > 0) ? 'G' . round(convertWindSpeed($gustSpeed, $windUnit)) : '';
                        $windUnitLabel = $windUnit === 'kmh' ? 'km/h' : $windUnit;
                        ?>
                        <?= $windDir ?>@<?= $windSpd ?><?= $gustVal ?><?= $windUnitLabel ?>
                    </div>
                </div>
                <div class="item">
                    <div class="label">🌡️ Temp</div>
                    <div class="value"><?= formatTemp($temperature, $tempUnit) ?></div>
                </div>
                <?php if ($hasMetarData && $visibility !== null): ?>
                <div class="item">
                    <div class="label">👁️ Vis</div>
                    <div class="value"><?= $visibility >= 10 ? '10+' : round($visibility, 1) ?> SM</div>
                </div>
                <?php endif; ?>
                <div class="item">
                    <div class="label">📊 Altim</div>
                    <div class="value"><?= formatPressure($pressure, $baroUnit) ?></div>
                </div>
                <div class="item">
                    <div class="label">📈 DA</div>
                    <div class="value"><?= formatDist($densityAltitude, $distUnit, 'comma') ?></div>
                </div>
            </div>
            
            <!-- Mini Wind Viz Script -->
            <script>
            (function() {
                var canvas = document.getElementById('wind-mini-canvas');
                if (!canvas) return;
                var ctx = canvas.getContext('2d');
                var cx = 25, cy = 25, r = 20;
                
                var runways = <?= json_encode($runways) ?>;
                var windSpeed = <?= $windSpeed !== null ? round($windSpeed) : 'null' ?>;
                var windDir = <?= ($windDirection !== null && is_numeric($windDirection)) ? round($windDirection) : 'null' ?>;
                var isVRB = <?= $windDirection === 'VRB' ? 'true' : 'false' ?>;
                <?php if ($useAutoTheme): ?>
                var isDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                <?php else: ?>
                var isDark = <?= $isDark ? 'true' : 'false' ?>;
                <?php endif; ?>
                
                // Draw circle
                ctx.strokeStyle = isDark ? '#555' : '#ccc';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.arc(cx, cy, r, 0, 2 * Math.PI);
                ctx.stroke();
                
                // Draw primary runway
                if (runways.length > 0) {
                    var h1 = runways[0].heading_1 || 0;
                    var angle = (h1 * Math.PI) / 180;
                    ctx.strokeStyle = isDark ? '#666' : '#999';
                    ctx.lineWidth = 3;
                    ctx.lineCap = 'round';
                    ctx.beginPath();
                    ctx.moveTo(cx - Math.sin(angle) * 14, cy + Math.cos(angle) * 14);
                    ctx.lineTo(cx + Math.sin(angle) * 14, cy - Math.cos(angle) * 14);
                    ctx.stroke();
                }
                
                // Draw wind arrow (only if wind >= 3 knots - calm otherwise)
                var CALM_WIND_THRESHOLD = 3;
                if (windSpeed !== null && windSpeed >= CALM_WIND_THRESHOLD && windDir !== null && !isVRB) {
                    var windAngle = ((windDir + 180) % 360) * Math.PI / 180;
                    var arrowLen = Math.min(windSpeed * 1.5, 15);
                    var endX = cx + Math.sin(windAngle) * arrowLen;
                    var endY = cy - Math.cos(windAngle) * arrowLen;
                    
                    ctx.strokeStyle = '#dc3545';
                    ctx.lineWidth = 2;
                    ctx.beginPath();
                    ctx.moveTo(cx, cy);
                    ctx.lineTo(endX, endY);
                    ctx.stroke();
                    
                    var headAngle = Math.atan2(endY - cy, endX - cx);
                    ctx.fillStyle = '#dc3545';
                    ctx.beginPath();
                    ctx.moveTo(endX, endY);
                    ctx.lineTo(endX - 5 * Math.cos(headAngle - Math.PI / 6), endY - 5 * Math.sin(headAngle - Math.PI / 6));
                    ctx.lineTo(endX - 5 * Math.cos(headAngle + Math.PI / 6), endY - 5 * Math.sin(headAngle + Math.PI / 6));
                    ctx.closePath();
                    ctx.fill();
                }
            })();
            </script>
            <div class="embed-footer">
                <div class="footer-left">Last Updated: <?= htmlspecialchars(formatLocalTimeEmbed($lastUpdated, $airportTimezone)) ?></div>
                <div class="footer-center">View Dashboard</div>
                <div class="footer-right">Powered by AviationWX<?= htmlspecialchars($sourceAttribution) ?></div>
            </div>
        </div>
        
    <?php elseif ($style === 'full'): ?>
        <!-- FULL WIDGET STYLE -->
        <div class="style-full">
            <div class="full-header">
                <div class="airport-title">
                    <h2>
                        <span class="code"><?= htmlspecialchars($primaryIdentifier) ?></span>
                        <?= htmlspecialchars($airportName) ?>
                        <?php if ($webcamCount > 0): ?>
                        <span class="cam-count">📷 <?= $webcamCount ?></span>
                        <?php endif; ?>
                    </h2>
                    <?php if ($hasMetarData): ?>
                        <span class="flight-category">
                            <?= htmlspecialchars($flightCategory) ?>
                        </span>
                    <?php elseif ($gustSpeed !== null && $gustSpeed > 0): ?>
                        <span class="flight-category" style="background: var(--no-metar-bg); font-size: 0.7rem;">
                            G<?= round($gustSpeed) ?>kt
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="full-body">
                <div class="webcam-section">
                    <?php if ($webcamUrl): 
                        $meta = $webcamMetadata[$webcamIndex] ?? null;
                        $enabledFormats = getEnabledWebcamFormats();
                        $timestamp = $meta['timestamp'] ?? 0;
                        $baseUrl = $dashboardUrl . '/webcam.php?id=' . urlencode($airportId) . '&cam=' . $webcamIndex;
                        if ($timestamp > 0) {
                            $baseUrl .= '&ts=' . $timestamp;
                        }
                    ?>
                        <picture>
                            <?php if (in_array('webp', $enabledFormats) && $meta): ?>
                            <source srcset="<?= htmlspecialchars(buildEmbedWebcamSrcset($dashboardUrl, $airportId, $webcamIndex, $meta, 'webp')) ?>" type="image/webp" sizes="800px">
                            <?php endif; ?>
                            <img src="<?= htmlspecialchars($webcamUrl) ?>" 
                                 <?php if ($webcamSrcset): ?>srcset="<?= htmlspecialchars($webcamSrcset) ?>" sizes="800px"<?php endif; ?>
                                 alt="<?= htmlspecialchars($primaryIdentifier) ?> Webcam"
                                 <?php if ($meta): ?>width="<?= $meta['width'] ?>" height="<?= $meta['height'] ?>"<?php endif; ?>>
                        </picture>
                    <?php else: ?>
                        <div class="no-webcam-placeholder" style="height: 100%;">No webcam available</div>
                    <?php endif; ?>
                </div>
                <div class="data-row">
                    <!-- Wind Section (Compass + Wind Column) -->
                    <div class="wind-section">
                        <div class="wind-viz-container">
                            <canvas id="wind-canvas" width="100" height="100"></canvas>
                            <div class="wind-summary">
                                <?php 
                                $windDir = $windDirection !== null ? (is_numeric($windDirection) ? round($windDirection) . '°' : $windDirection) : '--';
                                $windSpd = $windSpeed !== null ? round(convertWindSpeed($windSpeed, $windUnit)) : '--';
                                $gustVal = ($gustSpeed !== null && $gustSpeed > 0) ? 'G' . round(convertWindSpeed($gustSpeed, $windUnit)) : '';
                                $windUnitLabel = $windUnit === 'kmh' ? 'km/h' : $windUnit;
                                ?>
                                <span class="wind-value"><?= $windDir ?>@<?= $windSpd ?><?= $gustVal ?><?= $windUnitLabel ?></span>
                            </div>
                        </div>
                        <!-- Wind Column next to compass -->
                        <div class="metric-column wind-details">
                            <div class="column-header">💨 Wind</div>
                            <div class="metric-item">
                                <span class="label">Direction</span>
                                <span class="value"><?= $windDirection !== null ? (is_numeric($windDirection) ? round($windDirection) . '°' : $windDirection) : '--' ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="label">Speed</span>
                                <span class="value"><?= formatWindSpeed($windSpeed, $windUnit) ?></span>
                            </div>
                            <?php if ($gustSpeed !== null && $gustSpeed > 0): ?>
                            <div class="metric-item">
                                <span class="label">Gusting</span>
                                <span class="value"><?= formatWindSpeed($gustSpeed, $windUnit) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($peakGustToday !== null && $peakGustToday > 0): ?>
                            <div class="metric-item peak-item">
                                <span class="label">Peak Gust</span>
                                <span class="value"><?= formatWindSpeed($peakGustToday, $windUnit) ?></span>
                            </div>
                            <?php if ($peakGustTime !== null): ?>
                            <div class="metric-item peak-time-item">
                                <span class="label">@ Time</span>
                                <span class="value peak-time"><?php
                                    try {
                                        $tz = new DateTimeZone($airportTimezone);
                                        $dt = new DateTime('@' . $peakGustTime);
                                        $dt->setTimezone($tz);
                                        echo $dt->format('g:ia');
                                    } catch (Exception $e) {
                                        echo date('g:ia', $peakGustTime);
                                    }
                                ?></span>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Grouped Metrics by Column -->
                    <div class="metrics-section">
                    <div class="metrics-columns">
                        <!-- Temperature Column -->
                        <div class="metric-column">
                            <div class="column-header">🌡️ Temperature</div>
                            <div class="metric-item">
                                <span class="label">Temp</span>
                                <span class="value"><?= formatTemp($temperature, $tempUnit) ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="label">Dewpt</span>
                                <span class="value"><?= formatTemp($dewpoint, $tempUnit) ?></span>
                            </div>
                            <?php if ($dewpointSpread !== null): ?>
                            <div class="metric-item <?= $dewpointSpread <= 3 ? 'fog-warning' : '' ?>">
                                <span class="label">Spread</span>
                                <span class="value"><?php 
                                    // Spread is always the same in F or C (just smaller numbers in C)
                                    if ($tempUnit === 'C') {
                                        echo round($dewpointSpread * 5 / 9) . '°C';
                                    } else {
                                        echo round($dewpointSpread) . '°F';
                                    }
                                ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="metric-item">
                                <span class="label">Today's Hi/Lo</span>
                                <span class="value"><?php
                                    $hi = $tempHighToday !== null ? round(convertTemp($tempHighToday, $tempUnit)) : '--';
                                    $lo = $tempLowToday !== null ? round(convertTemp($tempLowToday, $tempUnit)) : '--';
                                    echo $hi . '/' . $lo . '°' . $tempUnit;
                                ?></span>
                            </div>
                        </div>
                        <!-- Sky/Visibility Column -->
                        <div class="metric-column">
                            <div class="column-header">👁️ Conditions</div>
                            <?php if ($hasMetarData && $visibility !== null): ?>
                            <div class="metric-item">
                                <span class="label">Visibility</span>
                                <span class="value"><?= $visibility >= 10 ? '10+' : round($visibility, 1) ?> SM</span>
                            </div>
                            <?php endif; ?>
                            <?php if ($hasMetarData && $ceiling !== null): ?>
                            <div class="metric-item">
                                <span class="label">Ceiling</span>
                                <span class="value"><?= $ceiling >= 99999 ? 'UNL' : formatDist($ceiling, $distUnit, 'comma') ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="metric-item">
                                <span class="label">Humidity</span>
                                <span class="value"><?= $humidity !== null ? round($humidity) . ' %' : '--' ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="label">Rain Today</span>
                                <span class="value"><?= formatRainfall($rainfallToday, $distUnit) ?></span>
                            </div>
                        </div>
                        <!-- Pressure/Altitude Column -->
                        <div class="metric-column">
                            <div class="column-header">📊 Altitude</div>
                            <div class="metric-item">
                                <span class="label">Altimeter</span>
                                <span class="value"><?= formatPressure($pressure, $baroUnit) ?></span>
                            </div>
                            <?php if ($pressureAltitude !== null): ?>
                            <div class="metric-item">
                                <span class="label">Press Alt</span>
                                <span class="value"><?= formatDist($pressureAltitude, $distUnit, 'comma') ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="metric-item">
                                <span class="label">Density Alt</span>
                                <span class="value"><?= formatDist($densityAltitude, $distUnit, 'comma') ?></span>
                            </div>
                        </div>
                    </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="embed-footer">
            <div class="footer-left">Last Updated: <?= htmlspecialchars(formatLocalTimeEmbed($lastUpdated, $airportTimezone)) ?></div>
            <div class="footer-center">View Dashboard</div>
            <div class="footer-right">Powered by AviationWX<?= htmlspecialchars($sourceAttribution) ?></div>
        </div>
        
        <!-- Wind Visualization Script -->
        <script>
        (function() {
            var canvas = document.getElementById('wind-canvas');
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            var cx = canvas.width / 2, cy = canvas.height / 2, r = Math.min(canvas.width, canvas.height) / 2 - 12;
            
            var runways = <?= json_encode($runways) ?>;
            var windSpeed = <?= $windSpeed !== null ? round($windSpeed) : 'null' ?>;
            var windDir = <?= ($windDirection !== null && is_numeric($windDirection)) ? round($windDirection) : 'null' ?>;
            var isVRB = <?= $windDirection === 'VRB' ? 'true' : 'false' ?>;
            var isDark = <?= $isDark ? 'true' : 'false' ?>;
            
            // Draw outer circle
            ctx.strokeStyle = isDark ? '#555' : '#ccc';
            ctx.lineWidth = 1.5;
            ctx.beginPath();
            ctx.arc(cx, cy, r, 0, 2 * Math.PI);
            ctx.stroke();
            
            // Draw runways
            runways.forEach(function(rw) {
                var h1 = rw.heading_1 || 0;
                var angle = (h1 * Math.PI) / 180;
                var rwLen = r * 0.7;
                
                ctx.strokeStyle = isDark ? '#666' : '#999';
                ctx.lineWidth = 6;
                ctx.lineCap = 'round';
                ctx.beginPath();
                ctx.moveTo(cx - Math.sin(angle) * rwLen, cy + Math.cos(angle) * rwLen);
                ctx.lineTo(cx + Math.sin(angle) * rwLen, cy - Math.cos(angle) * rwLen);
                ctx.stroke();
                
                // Runway labels
                var label1 = String(Math.round(h1 / 10)).padStart(2, '0');
                var label2 = String(Math.round(((h1 + 180) % 360) / 10)).padStart(2, '0');
                ctx.font = 'bold 9px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = isDark ? '#888' : '#666';
                ctx.fillText(label1, cx - Math.sin(angle) * (rwLen + 10), cy + Math.cos(angle) * (rwLen + 10));
                ctx.fillText(label2, cx + Math.sin(angle) * (rwLen + 10), cy - Math.cos(angle) * (rwLen + 10));
            });
            
            // Draw wind arrow
            var CALM_WIND_THRESHOLD = 3; // Winds below 3 knots are considered calm in aviation
            if (windSpeed !== null && windSpeed >= CALM_WIND_THRESHOLD && windDir !== null && !isVRB) {
                var windAngle = ((windDir + 180) % 360) * Math.PI / 180; // Convert FROM to TOWARD
                var arrowLen = Math.min(windSpeed * 3, r - 15);
                var endX = cx + Math.sin(windAngle) * arrowLen;
                var endY = cy - Math.cos(windAngle) * arrowLen;
                
                // Arrow glow
                ctx.fillStyle = 'rgba(220, 53, 69, 0.15)';
                ctx.beginPath();
                ctx.arc(cx, cy, Math.max(12, windSpeed * 2), 0, 2 * Math.PI);
                ctx.fill();
                
                // Arrow line
                ctx.strokeStyle = '#dc3545';
                ctx.lineWidth = 3;
                ctx.lineCap = 'round';
                ctx.beginPath();
                ctx.moveTo(cx, cy);
                ctx.lineTo(endX, endY);
                ctx.stroke();
                
                // Arrow head
                var headAngle = Math.atan2(endY - cy, endX - cx);
                ctx.fillStyle = '#dc3545';
                ctx.beginPath();
                ctx.moveTo(endX, endY);
                ctx.lineTo(endX - 8 * Math.cos(headAngle - Math.PI / 6), endY - 8 * Math.sin(headAngle - Math.PI / 6));
                ctx.lineTo(endX - 8 * Math.cos(headAngle + Math.PI / 6), endY - 8 * Math.sin(headAngle + Math.PI / 6));
                ctx.closePath();
                ctx.fill();
            } else if (isVRB && windSpeed >= CALM_WIND_THRESHOLD) {
                ctx.font = 'bold 14px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillStyle = '#dc3545';
                ctx.fillText('VRB', cx, cy + 4);
            } else if (windSpeed === null || windSpeed < CALM_WIND_THRESHOLD) {
                ctx.font = 'bold 12px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillStyle = isDark ? '#666' : '#999';
                ctx.fillText('CALM', cx, cy + 4);
            }
            
            // Cardinal directions
            ctx.font = 'bold 10px sans-serif';
            ctx.fillStyle = isDark ? '#888' : '#666';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ['N', 'E', 'S', 'W'].forEach(function(d, i) {
                var ang = (i * 90 * Math.PI) / 180;
                ctx.fillText(d, cx + Math.sin(ang) * (r + 8), cy - Math.cos(ang) * (r + 8));
            });
        })();
        </script>
        
    <?php elseif ($style === 'full-single'): ?>
        <!-- FULL SINGLE WEBCAM STYLE -->
        <div class="style-full">
            <div class="full-header">
                <div class="airport-title">
                    <h2>
                        <span class="code"><?= htmlspecialchars($primaryIdentifier) ?></span>
                        <?= htmlspecialchars($airportName) ?>
                        <?php if ($webcamCount > 0): ?>
                        <span class="cam-count">📷 <?= $webcamCount ?></span>
                        <?php endif; ?>
                    </h2>
                    <?php if ($hasMetarData): ?>
                        <span class="flight-category">
                            <?= htmlspecialchars($flightCategory) ?>
                        </span>
                    <?php elseif ($gustSpeed !== null && $gustSpeed > 0): ?>
                        <span class="flight-category" style="background: var(--no-metar-bg); font-size: 0.7rem;">
                            G<?= round($gustSpeed) ?>kt
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="full-body">
                <div class="webcam-section">
                    <?php if ($webcamUrl): 
                        $meta = $webcamMetadata[$webcamIndex] ?? null;
                        $enabledFormats = getEnabledWebcamFormats();
                        $timestamp = $meta['timestamp'] ?? 0;
                        $baseUrl = $dashboardUrl . '/webcam.php?id=' . urlencode($airportId) . '&cam=' . $webcamIndex;
                        if ($timestamp > 0) {
                            $baseUrl .= '&ts=' . $timestamp;
                        }
                    ?>
                        <picture>
                            <?php if (in_array('webp', $enabledFormats) && $meta): ?>
                            <source srcset="<?= htmlspecialchars(buildEmbedWebcamSrcset($dashboardUrl, $airportId, $webcamIndex, $meta, 'webp')) ?>" type="image/webp" sizes="400px">
                            <?php endif; ?>
                            <img src="<?= htmlspecialchars($webcamUrl) ?>" 
                                 <?php if ($webcamSrcset): ?>srcset="<?= htmlspecialchars($webcamSrcset) ?>" sizes="400px"<?php endif; ?>
                                 alt="<?= htmlspecialchars($primaryIdentifier) ?> Webcam"
                                 <?php if ($meta): ?>width="<?= $meta['width'] ?>" height="<?= $meta['height'] ?>"<?php endif; ?>>
                        </picture>
                    <?php else: ?>
                        <div class="no-webcam-placeholder" style="height: 100%;">No webcam available</div>
                    <?php endif; ?>
                </div>
                <div class="data-row">
                    <!-- Wind Section (Compass + Wind Column) -->
                    <div class="wind-section">
                        <div class="wind-viz-container">
                            <canvas id="wind-canvas-full-single" width="100" height="100"></canvas>
                            <div class="wind-summary">
                                <?php 
                                $windDir = $windDirection !== null ? (is_numeric($windDirection) ? round($windDirection) . '°' : $windDirection) : '--';
                                $windSpd = $windSpeed !== null ? round(convertWindSpeed($windSpeed, $windUnit)) : '--';
                                $gustVal = ($gustSpeed !== null && $gustSpeed > 0) ? 'G' . round(convertWindSpeed($gustSpeed, $windUnit)) : '';
                                $windUnitLabel = $windUnit === 'kmh' ? 'km/h' : $windUnit;
                                ?>
                                <span class="wind-value"><?= $windDir ?>@<?= $windSpd ?><?= $gustVal ?><?= $windUnitLabel ?></span>
                            </div>
                        </div>
                        <!-- Wind Column next to compass -->
                        <div class="metric-column wind-details">
                            <div class="column-header">💨 Wind</div>
                            <div class="metric-item">
                                <span class="label">Direction</span>
                                <span class="value"><?= $windDirection !== null ? (is_numeric($windDirection) ? round($windDirection) . '°' : $windDirection) : '--' ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="label">Speed</span>
                                <span class="value"><?= formatWindSpeed($windSpeed, $windUnit) ?></span>
                            </div>
                            <?php if ($gustSpeed !== null && $gustSpeed > 0): ?>
                            <div class="metric-item">
                                <span class="label">Gusting</span>
                                <span class="value"><?= formatWindSpeed($gustSpeed, $windUnit) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($peakGustToday !== null && $peakGustToday > 0): ?>
                            <div class="metric-item peak-item">
                                <span class="label">Peak Gust</span>
                                <span class="value"><?= formatWindSpeed($peakGustToday, $windUnit) ?></span>
                            </div>
                            <?php if ($peakGustTime !== null): ?>
                            <div class="metric-item peak-time-item">
                                <span class="label">@ Time</span>
                                <span class="value peak-time"><?php
                                    try {
                                        $tz = new DateTimeZone($airportTimezone);
                                        $dt = new DateTime('@' . $peakGustTime);
                                        $dt->setTimezone($tz);
                                        echo $dt->format('g:ia');
                                    } catch (Exception $e) {
                                        echo date('g:ia', $peakGustTime);
                                    }
                                ?></span>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Grouped Metrics by Column -->
                    <div class="metrics-section">
                    <div class="metrics-columns">
                        <!-- Temperature Column -->
                        <div class="metric-column">
                            <div class="column-header">🌡️ Temperature</div>
                            <div class="metric-item">
                                <span class="label">Temp</span>
                                <span class="value"><?= formatTemp($temperature, $tempUnit) ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="label">Dewpt</span>
                                <span class="value"><?= formatTemp($dewpoint, $tempUnit) ?></span>
                            </div>
                            <?php if ($dewpointSpread !== null): ?>
                            <div class="metric-item <?= $dewpointSpread <= 3 ? 'fog-warning' : '' ?>">
                                <span class="label">Spread</span>
                                <span class="value"><?php 
                                    // Spread is always the same in F or C (just smaller numbers in C)
                                    if ($tempUnit === 'C') {
                                        echo round($dewpointSpread * 5 / 9) . '°C';
                                    } else {
                                        echo round($dewpointSpread) . '°F';
                                    }
                                ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="metric-item">
                                <span class="label">Today's Hi/Lo</span>
                                <span class="value"><?php
                                    $hi = $tempHighToday !== null ? round(convertTemp($tempHighToday, $tempUnit)) : '--';
                                    $lo = $tempLowToday !== null ? round(convertTemp($tempLowToday, $tempUnit)) : '--';
                                    echo $hi . '/' . $lo . '°' . $tempUnit;
                                ?></span>
                            </div>
                        </div>
                        <!-- Sky/Visibility Column -->
                        <div class="metric-column">
                            <div class="column-header">👁️ Conditions</div>
                            <?php if ($hasMetarData && $visibility !== null): ?>
                            <div class="metric-item">
                                <span class="label">Visibility</span>
                                <span class="value"><?= $visibility >= 10 ? '10+' : round($visibility, 1) ?> SM</span>
                            </div>
                            <?php endif; ?>
                            <?php if ($hasMetarData && $ceiling !== null): ?>
                            <div class="metric-item">
                                <span class="label">Ceiling</span>
                                <span class="value"><?= $ceiling >= 99999 ? 'UNL' : formatDist($ceiling, $distUnit, 'comma') ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="metric-item">
                                <span class="label">Humidity</span>
                                <span class="value"><?= $humidity !== null ? round($humidity) . ' %' : '--' ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="label">Rain Today</span>
                                <span class="value"><?= formatRainfall($rainfallToday, $distUnit) ?></span>
                            </div>
                        </div>
                        <!-- Pressure/Altitude Column -->
                        <div class="metric-column">
                            <div class="column-header">📊 Altitude</div>
                            <div class="metric-item">
                                <span class="label">Altimeter</span>
                                <span class="value"><?= formatPressure($pressure, $baroUnit) ?></span>
                            </div>
                            <?php if ($pressureAltitude !== null): ?>
                            <div class="metric-item">
                                <span class="label">Press Alt</span>
                                <span class="value"><?= formatDist($pressureAltitude, $distUnit, 'comma') ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="metric-item">
                                <span class="label">Density Alt</span>
                                <span class="value"><?= formatDist($densityAltitude, $distUnit, 'comma') ?></span>
                            </div>
                        </div>
                    </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="embed-footer">
            <div class="footer-left">Last Updated: <?= htmlspecialchars(formatLocalTimeEmbed($lastUpdated, $airportTimezone)) ?></div>
            <div class="footer-center">View Dashboard</div>
            <div class="footer-right">Powered by AviationWX<?= htmlspecialchars($sourceAttribution) ?></div>
        </div>
        
        <!-- Wind Visualization Script -->
        <script>
        (function() {
            var canvas = document.getElementById('wind-canvas-full-single');
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            var cx = canvas.width / 2, cy = canvas.height / 2, r = Math.min(canvas.width, canvas.height) / 2 - 12;
            
            var runways = <?= json_encode($runways) ?>;
            var windSpeed = <?= $windSpeed !== null ? round($windSpeed) : 'null' ?>;
            var windDir = <?= ($windDirection !== null && is_numeric($windDirection)) ? round($windDirection) : 'null' ?>;
            var isVRB = <?= $windDirection === 'VRB' ? 'true' : 'false' ?>;
            var isDark = <?= $isDark ? 'true' : 'false' ?>;
            
            // Draw outer circle
            ctx.strokeStyle = isDark ? '#555' : '#ccc';
            ctx.lineWidth = 1.5;
            ctx.beginPath();
            ctx.arc(cx, cy, r, 0, 2 * Math.PI);
            ctx.stroke();
            
            // Draw runways
            runways.forEach(function(rw) {
                var h1 = rw.heading_1 || 0;
                var angle = (h1 * Math.PI) / 180;
                var rwLen = r * 0.7;
                
                ctx.strokeStyle = isDark ? '#666' : '#999';
                ctx.lineWidth = 6;
                ctx.lineCap = 'round';
                ctx.beginPath();
                ctx.moveTo(cx - Math.sin(angle) * rwLen, cy + Math.cos(angle) * rwLen);
                ctx.lineTo(cx + Math.sin(angle) * rwLen, cy - Math.cos(angle) * rwLen);
                ctx.stroke();
                
                // Runway labels
                var label1 = String(Math.round(h1 / 10)).padStart(2, '0');
                var label2 = String(Math.round(((h1 + 180) % 360) / 10)).padStart(2, '0');
                ctx.font = 'bold 9px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = isDark ? '#888' : '#666';
                ctx.fillText(label1, cx - Math.sin(angle) * (rwLen + 10), cy + Math.cos(angle) * (rwLen + 10));
                ctx.fillText(label2, cx + Math.sin(angle) * (rwLen + 10), cy - Math.cos(angle) * (rwLen + 10));
            });
            
            // Draw wind arrow
            var CALM_WIND_THRESHOLD = 3; // Winds below 3 knots are considered calm in aviation
            if (windSpeed !== null && windSpeed >= CALM_WIND_THRESHOLD && windDir !== null && !isVRB) {
                var windAngle = ((windDir + 180) % 360) * Math.PI / 180; // Convert FROM to TOWARD
                var arrowLen = Math.min(windSpeed * 3, r - 15);
                var endX = cx + Math.sin(windAngle) * arrowLen;
                var endY = cy - Math.cos(windAngle) * arrowLen;
                
                // Arrow glow
                ctx.fillStyle = 'rgba(220, 53, 69, 0.15)';
                ctx.beginPath();
                ctx.arc(cx, cy, Math.max(12, windSpeed * 2), 0, 2 * Math.PI);
                ctx.fill();
                
                // Arrow line
                ctx.strokeStyle = '#dc3545';
                ctx.lineWidth = 3;
                ctx.lineCap = 'round';
                ctx.beginPath();
                ctx.moveTo(cx, cy);
                ctx.lineTo(endX, endY);
                ctx.stroke();
                
                // Arrow head
                var headAngle = Math.atan2(endY - cy, endX - cx);
                ctx.fillStyle = '#dc3545';
                ctx.beginPath();
                ctx.moveTo(endX, endY);
                ctx.lineTo(endX - 8 * Math.cos(headAngle - Math.PI / 6), endY - 8 * Math.sin(headAngle - Math.PI / 6));
                ctx.lineTo(endX - 8 * Math.cos(headAngle + Math.PI / 6), endY - 8 * Math.sin(headAngle + Math.PI / 6));
                ctx.closePath();
                ctx.fill();
            } else if (isVRB && windSpeed >= CALM_WIND_THRESHOLD) {
                ctx.font = 'bold 14px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillStyle = '#dc3545';
                ctx.fillText('VRB', cx, cy + 4);
            } else if (windSpeed === null || windSpeed < CALM_WIND_THRESHOLD) {
                ctx.font = 'bold 12px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillStyle = isDark ? '#666' : '#999';
                ctx.fillText('CALM', cx, cy + 4);
            }
            
            // Cardinal directions
            ctx.font = 'bold 10px sans-serif';
            ctx.fillStyle = isDark ? '#888' : '#666';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ['N', 'E', 'S', 'W'].forEach(function(d, i) {
                var ang = (i * 90 * Math.PI) / 180;
                ctx.fillText(d, cx + Math.sin(ang) * (r + 8), cy - Math.cos(ang) * (r + 8));
            });
        })();
        </script>
        
    <?php elseif ($style === 'full-dual'): ?>
        <!-- FULL DUAL CAMERA STYLE -->
        <div class="style-full">
            <div class="full-header">
                <div class="airport-title">
                    <h2>
                        <span class="code"><?= htmlspecialchars($primaryIdentifier) ?></span>
                        <?= htmlspecialchars($airportName) ?>
                        <?php if ($webcamCount > 0): ?>
                        <span class="cam-count">📷 <?= $webcamCount ?></span>
                        <?php endif; ?>
                    </h2>
                    <?php if ($hasMetarData): ?>
                        <span class="flight-category">
                            <?= htmlspecialchars($flightCategory) ?>
                        </span>
                    <?php elseif ($gustSpeed !== null && $gustSpeed > 0): ?>
                        <span class="flight-category" style="background: var(--no-metar-bg); font-size: 0.7rem;">
                            G<?= round($gustSpeed) ?>kt
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="full-body">
                <!-- Dual Webcam Grid Section -->
                <div class="webcam-section" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 2px; background: var(--border-color);">
                    <?php 
                    if ($webcamCount > 0): 
                        // Use selected camera indices from $cams array
                        for ($slot = 0; $slot < 2; $slot++): 
                            $camIdx = $cams[$slot] ?? $slot;
                            // Ensure camera index is valid
                            if ($camIdx >= $webcamCount) $camIdx = $slot < $webcamCount ? $slot : 0;
                            $meta = $webcamMetadata[$camIdx] ?? null;
                            $camUrl = buildEmbedWebcamUrl($dashboardUrl, $airportId, $camIdx, 'jpg', 'original');
                            $camSrcset = buildEmbedWebcamSrcset($dashboardUrl, $airportId, $camIdx, $meta, 'jpg');
                            $camName = $airport['webcams'][$camIdx]['name'] ?? 'Camera ' . ($camIdx + 1);
                            $enabledFormats = getEnabledWebcamFormats();
                            $timestamp = $meta['timestamp'] ?? 0;
                            $baseUrl = $dashboardUrl . '/webcam.php?id=' . urlencode($airportId) . '&cam=' . $camIdx;
                            if ($timestamp > 0) {
                                $baseUrl .= '&ts=' . $timestamp;
                            }
                    ?>
                    <div style="position: relative; background: #000; overflow: hidden;">
                        <picture>
                            <?php if (in_array('webp', $enabledFormats) && $meta): ?>
                            <source srcset="<?= htmlspecialchars(buildEmbedWebcamSrcset($dashboardUrl, $airportId, $camIdx, $meta, 'webp')) ?>" type="image/webp" sizes="300px">
                            <?php endif; ?>
                            <img src="<?= htmlspecialchars($camUrl) ?>" 
                                 <?php if ($camSrcset): ?>srcset="<?= htmlspecialchars($camSrcset) ?>" sizes="300px"<?php endif; ?>
                                 alt="<?= htmlspecialchars($camName) ?>"
                                 style="width: 100%; height: 100%; object-fit: cover;"
                                 <?php if ($meta): ?>width="<?= $meta['width'] ?>" height="<?= $meta['height'] ?>"<?php endif; ?>>
                        </picture>
                        <span style="position: absolute; bottom: 0.25rem; left: 0.25rem; background: rgba(0,0,0,0.7); color: white; padding: 0.15rem 0.4rem; font-size: 0.7rem; border-radius: 3px;"><?= htmlspecialchars($camName) ?></span>
                    </div>
                    <?php 
                        endfor; 
                    else: 
                    ?>
                    <div style="display: flex; align-items: center; justify-content: center; background: var(--wind-compass-bg);">
                        <div class="no-webcam-placeholder">No webcams available</div>
                    </div>
                    <div style="display: flex; align-items: center; justify-content: center; background: var(--wind-compass-bg);">
                        <div class="no-webcam-placeholder">No webcams available</div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="data-row">
                    <!-- Wind Section (Compass + Wind Column) -->
                    <div class="wind-section">
                        <div class="wind-viz-container">
                            <canvas id="wind-canvas-full-dual" width="100" height="100"></canvas>
                            <div class="wind-summary">
                                <?php 
                                $windDir = $windDirection !== null ? (is_numeric($windDirection) ? round($windDirection) . '°' : $windDirection) : '--';
                                $windSpd = $windSpeed !== null ? round(convertWindSpeed($windSpeed, $windUnit)) : '--';
                                $gustVal = ($gustSpeed !== null && $gustSpeed > 0) ? 'G' . round(convertWindSpeed($gustSpeed, $windUnit)) : '';
                                $windUnitLabel = $windUnit === 'kmh' ? 'km/h' : $windUnit;
                                ?>
                                <span class="wind-value"><?= $windDir ?>@<?= $windSpd ?><?= $gustVal ?><?= $windUnitLabel ?></span>
                            </div>
                        </div>
                        <!-- Wind Column next to compass -->
                        <div class="metric-column wind-details">
                            <div class="column-header">💨 Wind</div>
                            <div class="metric-item">
                                <span class="label">Direction</span>
                                <span class="value"><?= $windDirection !== null ? (is_numeric($windDirection) ? round($windDirection) . '°' : $windDirection) : '--' ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="label">Speed</span>
                                <span class="value"><?= formatWindSpeed($windSpeed, $windUnit) ?></span>
                            </div>
                            <?php if ($gustSpeed !== null && $gustSpeed > 0): ?>
                            <div class="metric-item">
                                <span class="label">Gusting</span>
                                <span class="value"><?= formatWindSpeed($gustSpeed, $windUnit) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($peakGustToday !== null && $peakGustToday > 0): ?>
                            <div class="metric-item peak-item">
                                <span class="label">Peak Gust</span>
                                <span class="value"><?= formatWindSpeed($peakGustToday, $windUnit) ?></span>
                            </div>
                            <?php if ($peakGustTime !== null): ?>
                            <div class="metric-item peak-time-item">
                                <span class="label">@ Time</span>
                                <span class="value peak-time"><?php
                                    try {
                                        $tz = new DateTimeZone($airportTimezone);
                                        $dt = new DateTime('@' . $peakGustTime);
                                        $dt->setTimezone($tz);
                                        echo $dt->format('g:ia');
                                    } catch (Exception $e) {
                                        echo date('g:ia', $peakGustTime);
                                    }
                                ?></span>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Grouped Metrics by Column -->
                    <div class="metrics-section">
                    <div class="metrics-columns">
                        <!-- Temperature Column -->
                        <div class="metric-column">
                            <div class="column-header">🌡️ Temperature</div>
                            <div class="metric-item">
                                <span class="label">Temp</span>
                                <span class="value"><?= formatTemp($temperature, $tempUnit) ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="label">Dewpt</span>
                                <span class="value"><?= formatTemp($dewpoint, $tempUnit) ?></span>
                            </div>
                            <?php if ($dewpointSpread !== null): ?>
                            <div class="metric-item <?= $dewpointSpread <= 3 ? 'fog-warning' : '' ?>">
                                <span class="label">Spread</span>
                                <span class="value"><?php 
                                    // Spread is always the same in F or C (just smaller numbers in C)
                                    if ($tempUnit === 'C') {
                                        echo round($dewpointSpread * 5 / 9) . '°C';
                                    } else {
                                        echo round($dewpointSpread) . '°F';
                                    }
                                ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="metric-item">
                                <span class="label">Today's Hi/Lo</span>
                                <span class="value"><?php
                                    $hi = $tempHighToday !== null ? round(convertTemp($tempHighToday, $tempUnit)) : '--';
                                    $lo = $tempLowToday !== null ? round(convertTemp($tempLowToday, $tempUnit)) : '--';
                                    echo $hi . '/' . $lo . '°' . $tempUnit;
                                ?></span>
                            </div>
                        </div>
                        <!-- Sky/Visibility Column -->
                        <div class="metric-column">
                            <div class="column-header">👁️ Conditions</div>
                            <?php if ($hasMetarData && $visibility !== null): ?>
                            <div class="metric-item">
                                <span class="label">Visibility</span>
                                <span class="value"><?= $visibility >= 10 ? '10+' : round($visibility, 1) ?> SM</span>
                            </div>
                            <?php endif; ?>
                            <?php if ($hasMetarData && $ceiling !== null): ?>
                            <div class="metric-item">
                                <span class="label">Ceiling</span>
                                <span class="value"><?= $ceiling >= 99999 ? 'UNL' : formatDist($ceiling, $distUnit, 'comma') ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="metric-item">
                                <span class="label">Humidity</span>
                                <span class="value"><?= $humidity !== null ? round($humidity) . ' %' : '--' ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="label">Rain Today</span>
                                <span class="value"><?= formatRainfall($rainfallToday, $distUnit) ?></span>
                            </div>
                        </div>
                        <!-- Pressure/Altitude Column -->
                        <div class="metric-column">
                            <div class="column-header">📊 Altitude</div>
                            <div class="metric-item">
                                <span class="label">Altimeter</span>
                                <span class="value"><?= formatPressure($pressure, $baroUnit) ?></span>
                            </div>
                            <?php if ($pressureAltitude !== null): ?>
                            <div class="metric-item">
                                <span class="label">Press Alt</span>
                                <span class="value"><?= formatDist($pressureAltitude, $distUnit, 'comma') ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="metric-item">
                                <span class="label">Density Alt</span>
                                <span class="value"><?= formatDist($densityAltitude, $distUnit, 'comma') ?></span>
                            </div>
                        </div>
                    </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="embed-footer">
            <div class="footer-left">Last Updated: <?= htmlspecialchars(formatLocalTimeEmbed($lastUpdated, $airportTimezone)) ?></div>
            <div class="footer-center">View Dashboard</div>
            <div class="footer-right">Powered by AviationWX<?= htmlspecialchars($sourceAttribution) ?></div>
        </div>
        
        <!-- Wind Visualization Script -->
        <script>
        (function() {
            var canvas = document.getElementById('wind-canvas-full-dual');
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            var cx = canvas.width / 2, cy = canvas.height / 2, r = Math.min(canvas.width, canvas.height) / 2 - 12;
            
            var runways = <?= json_encode($runways) ?>;
            var windSpeed = <?= $windSpeed !== null ? round($windSpeed) : 'null' ?>;
            var windDir = <?= ($windDirection !== null && is_numeric($windDirection)) ? round($windDirection) : 'null' ?>;
            var isVRB = <?= $windDirection === 'VRB' ? 'true' : 'false' ?>;
            var isDark = <?= $isDark ? 'true' : 'false' ?>;
            
            // Draw outer circle
            ctx.strokeStyle = isDark ? '#555' : '#ccc';
            ctx.lineWidth = 1.5;
            ctx.beginPath();
            ctx.arc(cx, cy, r, 0, 2 * Math.PI);
            ctx.stroke();
            
            // Draw runways
            runways.forEach(function(rw) {
                var h1 = rw.heading_1 || 0;
                var angle = (h1 * Math.PI) / 180;
                var rwLen = r * 0.7;
                
                ctx.strokeStyle = isDark ? '#666' : '#999';
                ctx.lineWidth = 6;
                ctx.lineCap = 'round';
                ctx.beginPath();
                ctx.moveTo(cx - Math.sin(angle) * rwLen, cy + Math.cos(angle) * rwLen);
                ctx.lineTo(cx + Math.sin(angle) * rwLen, cy - Math.cos(angle) * rwLen);
                ctx.stroke();
                
                // Runway labels
                var label1 = String(Math.round(h1 / 10)).padStart(2, '0');
                var label2 = String(Math.round(((h1 + 180) % 360) / 10)).padStart(2, '0');
                ctx.font = 'bold 9px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = isDark ? '#888' : '#666';
                ctx.fillText(label1, cx - Math.sin(angle) * (rwLen + 10), cy + Math.cos(angle) * (rwLen + 10));
                ctx.fillText(label2, cx + Math.sin(angle) * (rwLen + 10), cy - Math.cos(angle) * (rwLen + 10));
            });
            
            // Draw wind arrow
            var CALM_WIND_THRESHOLD = 3; // Winds below 3 knots are considered calm in aviation
            if (windSpeed !== null && windSpeed >= CALM_WIND_THRESHOLD && windDir !== null && !isVRB) {
                var windAngle = ((windDir + 180) % 360) * Math.PI / 180; // Convert FROM to TOWARD
                var arrowLen = Math.min(windSpeed * 3, r - 15);
                var endX = cx + Math.sin(windAngle) * arrowLen;
                var endY = cy - Math.cos(windAngle) * arrowLen;
                
                // Arrow glow
                ctx.fillStyle = 'rgba(220, 53, 69, 0.15)';
                ctx.beginPath();
                ctx.arc(cx, cy, Math.max(12, windSpeed * 2), 0, 2 * Math.PI);
                ctx.fill();
                
                // Arrow line
                ctx.strokeStyle = '#dc3545';
                ctx.lineWidth = 3;
                ctx.lineCap = 'round';
                ctx.beginPath();
                ctx.moveTo(cx, cy);
                ctx.lineTo(endX, endY);
                ctx.stroke();
                
                // Arrow head
                var headAngle = Math.atan2(endY - cy, endX - cx);
                ctx.fillStyle = '#dc3545';
                ctx.beginPath();
                ctx.moveTo(endX, endY);
                ctx.lineTo(endX - 8 * Math.cos(headAngle - Math.PI / 6), endY - 8 * Math.sin(headAngle - Math.PI / 6));
                ctx.lineTo(endX - 8 * Math.cos(headAngle + Math.PI / 6), endY - 8 * Math.sin(headAngle + Math.PI / 6));
                ctx.closePath();
                ctx.fill();
            } else if (isVRB && windSpeed >= CALM_WIND_THRESHOLD) {
                ctx.font = 'bold 14px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillStyle = '#dc3545';
                ctx.fillText('VRB', cx, cy + 4);
            } else if (windSpeed === null || windSpeed < CALM_WIND_THRESHOLD) {
                ctx.font = 'bold 12px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillStyle = isDark ? '#666' : '#999';
                ctx.fillText('CALM', cx, cy + 4);
            }
            
            // Cardinal directions
            ctx.font = 'bold 10px sans-serif';
            ctx.fillStyle = isDark ? '#888' : '#666';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ['N', 'E', 'S', 'W'].forEach(function(d, i) {
                var ang = (i * 90 * Math.PI) / 180;
                ctx.fillText(d, cx + Math.sin(ang) * (r + 8), cy - Math.cos(ang) * (r + 8));
            });
        })();
        </script>
        
    <?php elseif ($style === 'full-multi'): ?>
        <!-- FULL 4 CAMERA GRID STYLE -->
        <div class="style-full">
            <div class="full-header">
                <div class="airport-title">
                    <h2>
                        <span class="code"><?= htmlspecialchars($primaryIdentifier) ?></span>
                        <?= htmlspecialchars($airportName) ?>
                        <?php if ($webcamCount > 0): ?>
                        <span class="cam-count">📷 <?= $webcamCount ?></span>
                        <?php endif; ?>
                    </h2>
                    <?php if ($hasMetarData): ?>
                        <span class="flight-category">
                            <?= htmlspecialchars($flightCategory) ?>
                        </span>
                    <?php elseif ($gustSpeed !== null && $gustSpeed > 0): ?>
                        <span class="flight-category" style="background: var(--no-metar-bg); font-size: 0.7rem;">
                            G<?= round($gustSpeed) ?>kt
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="full-body">
                <!-- 4 Webcam Grid Section -->
                <div class="webcam-section" style="display: grid; grid-template-columns: repeat(2, 1fr); grid-template-rows: repeat(2, 1fr); gap: 2px; background: var(--border-color);">
                    <?php 
                    if ($webcamCount > 0): 
                        $displayCamCount = min($webcamCount, 4);
                        for ($slot = 0; $slot < 4; $slot++): 
                            // Use selected camera index from $cams array
                            $camIdx = $cams[$slot] ?? $slot;
                            // Ensure camera index is valid
                            if ($camIdx >= $webcamCount) $camIdx = $slot < $webcamCount ? $slot : 0;
                            $meta = $webcamMetadata[$camIdx] ?? null;
                            $camUrl = buildEmbedWebcamUrl($dashboardUrl, $airportId, $camIdx, 'jpg', 'original');
                            $camSrcset = buildEmbedWebcamSrcset($dashboardUrl, $airportId, $camIdx, $meta, 'jpg');
                            $camName = $airport['webcams'][$camIdx]['name'] ?? 'Camera ' . ($camIdx + 1);
                            $enabledFormats = getEnabledWebcamFormats();
                            $aspectRatio = $meta ? $meta['aspect_ratio'] : 1.777;
                            $timestamp = $meta['timestamp'] ?? 0;
                            $baseUrl = $dashboardUrl . '/webcam.php?id=' . urlencode($airportId) . '&cam=' . $camIdx;
                            if ($timestamp > 0) {
                                $baseUrl .= '&ts=' . $timestamp;
                            }
                    ?>
                    <div style="position: relative; background: #000; overflow: hidden; aspect-ratio: <?= $aspectRatio ?>;">
                        <picture>
                            <?php if (in_array('webp', $enabledFormats) && $meta): ?>
                            <source srcset="<?= htmlspecialchars(buildEmbedWebcamSrcset($dashboardUrl, $airportId, $camIdx, $meta, 'webp')) ?>" type="image/webp" sizes="300px">
                            <?php endif; ?>
                            <img src="<?= htmlspecialchars($camUrl) ?>" 
                                 <?php if ($camSrcset): ?>srcset="<?= htmlspecialchars($camSrcset) ?>" sizes="300px"<?php endif; ?>
                                 alt="<?= htmlspecialchars($camName) ?>"
                                 style="width: 100%; height: 100%; object-fit: cover;"
                                 <?php if ($meta): ?>width="<?= $meta['width'] ?>" height="<?= $meta['height'] ?>"<?php endif; ?>>
                        </picture>
                        <span style="position: absolute; bottom: 0.25rem; left: 0.25rem; background: rgba(0,0,0,0.7); color: white; padding: 0.15rem 0.4rem; font-size: 0.65rem; border-radius: 3px;"><?= htmlspecialchars($camName) ?></span>
                    </div>
                    <?php 
                        endfor; 
                    else: 
                    ?>
                    <div style="display: flex; align-items: center; justify-content: center; background: var(--wind-compass-bg);">
                        <div class="no-webcam-placeholder">No webcams available</div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="data-row">
                    <!-- Wind Section (Compass + Wind Column) -->
                    <div class="wind-section">
                        <div class="wind-viz-container">
                            <canvas id="wind-canvas-full-multi" width="100" height="100"></canvas>
                            <div class="wind-summary">
                                <?php 
                                $windDir = $windDirection !== null ? (is_numeric($windDirection) ? round($windDirection) . '°' : $windDirection) : '--';
                                $windSpd = $windSpeed !== null ? round(convertWindSpeed($windSpeed, $windUnit)) : '--';
                                $gustVal = ($gustSpeed !== null && $gustSpeed > 0) ? 'G' . round(convertWindSpeed($gustSpeed, $windUnit)) : '';
                                $windUnitLabel = $windUnit === 'kmh' ? 'km/h' : $windUnit;
                                ?>
                                <span class="wind-value"><?= $windDir ?>@<?= $windSpd ?><?= $gustVal ?><?= $windUnitLabel ?></span>
                            </div>
                        </div>
                        <!-- Wind Column next to compass -->
                        <div class="metric-column wind-details">
                            <div class="column-header">💨 Wind</div>
                            <div class="metric-item">
                                <span class="label">Direction</span>
                                <span class="value"><?= $windDirection !== null ? (is_numeric($windDirection) ? round($windDirection) . '°' : $windDirection) : '--' ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="label">Speed</span>
                                <span class="value"><?= formatWindSpeed($windSpeed, $windUnit) ?></span>
                            </div>
                            <?php if ($gustSpeed !== null && $gustSpeed > 0): ?>
                            <div class="metric-item">
                                <span class="label">Gusting</span>
                                <span class="value"><?= formatWindSpeed($gustSpeed, $windUnit) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($peakGustToday !== null && $peakGustToday > 0): ?>
                            <div class="metric-item peak-item">
                                <span class="label">Peak Gust</span>
                                <span class="value"><?= formatWindSpeed($peakGustToday, $windUnit) ?></span>
                            </div>
                            <?php if ($peakGustTime !== null): ?>
                            <div class="metric-item peak-time-item">
                                <span class="label">@ Time</span>
                                <span class="value peak-time"><?php
                                    try {
                                        $tz = new DateTimeZone($airportTimezone);
                                        $dt = new DateTime('@' . $peakGustTime);
                                        $dt->setTimezone($tz);
                                        echo $dt->format('g:ia');
                                    } catch (Exception $e) {
                                        echo date('g:ia', $peakGustTime);
                                    }
                                ?></span>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Grouped Metrics by Column -->
                    <div class="metrics-section">
                    <div class="metrics-columns">
                        <!-- Temperature Column -->
                        <div class="metric-column">
                            <div class="column-header">🌡️ Temperature</div>
                            <div class="metric-item">
                                <span class="label">Temp</span>
                                <span class="value"><?= formatTemp($temperature, $tempUnit) ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="label">Dewpt</span>
                                <span class="value"><?= formatTemp($dewpoint, $tempUnit) ?></span>
                            </div>
                            <?php if ($dewpointSpread !== null): ?>
                            <div class="metric-item <?= $dewpointSpread <= 3 ? 'fog-warning' : '' ?>">
                                <span class="label">Spread</span>
                                <span class="value"><?php 
                                    // Spread is always the same in F or C (just smaller numbers in C)
                                    if ($tempUnit === 'C') {
                                        echo round($dewpointSpread * 5 / 9) . '°C';
                                    } else {
                                        echo round($dewpointSpread) . '°F';
                                    }
                                ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="metric-item">
                                <span class="label">Today's Hi/Lo</span>
                                <span class="value"><?php
                                    $hi = $tempHighToday !== null ? round(convertTemp($tempHighToday, $tempUnit)) : '--';
                                    $lo = $tempLowToday !== null ? round(convertTemp($tempLowToday, $tempUnit)) : '--';
                                    echo $hi . '/' . $lo . '°' . $tempUnit;
                                ?></span>
                            </div>
                        </div>
                        <!-- Sky/Visibility Column -->
                        <div class="metric-column">
                            <div class="column-header">👁️ Conditions</div>
                            <?php if ($hasMetarData && $visibility !== null): ?>
                            <div class="metric-item">
                                <span class="label">Visibility</span>
                                <span class="value"><?= $visibility >= 10 ? '10+' : round($visibility, 1) ?> SM</span>
                            </div>
                            <?php endif; ?>
                            <?php if ($hasMetarData && $ceiling !== null): ?>
                            <div class="metric-item">
                                <span class="label">Ceiling</span>
                                <span class="value"><?= $ceiling >= 99999 ? 'UNL' : formatDist($ceiling, $distUnit, 'comma') ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="metric-item">
                                <span class="label">Humidity</span>
                                <span class="value"><?= $humidity !== null ? round($humidity) . ' %' : '--' ?></span>
                            </div>
                            <div class="metric-item">
                                <span class="label">Rain Today</span>
                                <span class="value"><?= formatRainfall($rainfallToday, $distUnit) ?></span>
                            </div>
                        </div>
                        <!-- Pressure/Altitude Column -->
                        <div class="metric-column">
                            <div class="column-header">📊 Altitude</div>
                            <div class="metric-item">
                                <span class="label">Altimeter</span>
                                <span class="value"><?= formatPressure($pressure, $baroUnit) ?></span>
                            </div>
                            <?php if ($pressureAltitude !== null): ?>
                            <div class="metric-item">
                                <span class="label">Press Alt</span>
                                <span class="value"><?= formatDist($pressureAltitude, $distUnit, 'comma') ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="metric-item">
                                <span class="label">Density Alt</span>
                                <span class="value"><?= formatDist($densityAltitude, $distUnit, 'comma') ?></span>
                            </div>
                        </div>
                    </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="embed-footer">
            <div class="footer-left">Last Updated: <?= htmlspecialchars(formatLocalTimeEmbed($lastUpdated, $airportTimezone)) ?></div>
            <div class="footer-center">View Dashboard</div>
            <div class="footer-right">Powered by AviationWX<?= htmlspecialchars($sourceAttribution) ?></div>
        </div>
        
        <!-- Wind Visualization Script -->
        <script>
        (function() {
            var canvas = document.getElementById('wind-canvas-full-multi');
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            var cx = canvas.width / 2, cy = canvas.height / 2, r = Math.min(canvas.width, canvas.height) / 2 - 12;
            
            var runways = <?= json_encode($runways) ?>;
            var windSpeed = <?= $windSpeed !== null ? round($windSpeed) : 'null' ?>;
            var windDir = <?= ($windDirection !== null && is_numeric($windDirection)) ? round($windDirection) : 'null' ?>;
            var isVRB = <?= $windDirection === 'VRB' ? 'true' : 'false' ?>;
            var isDark = <?= $isDark ? 'true' : 'false' ?>;
            
            // Draw outer circle
            ctx.strokeStyle = isDark ? '#555' : '#ccc';
            ctx.lineWidth = 1.5;
            ctx.beginPath();
            ctx.arc(cx, cy, r, 0, 2 * Math.PI);
            ctx.stroke();
            
            // Draw runways
            runways.forEach(function(rw) {
                var h1 = rw.heading_1 || 0;
                var angle = (h1 * Math.PI) / 180;
                var rwLen = r * 0.7;
                
                ctx.strokeStyle = isDark ? '#666' : '#999';
                ctx.lineWidth = 6;
                ctx.lineCap = 'round';
                ctx.beginPath();
                ctx.moveTo(cx - Math.sin(angle) * rwLen, cy + Math.cos(angle) * rwLen);
                ctx.lineTo(cx + Math.sin(angle) * rwLen, cy - Math.cos(angle) * rwLen);
                ctx.stroke();
                
                // Runway labels
                var label1 = String(Math.round(h1 / 10)).padStart(2, '0');
                var label2 = String(Math.round(((h1 + 180) % 360) / 10)).padStart(2, '0');
                ctx.font = 'bold 9px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = isDark ? '#888' : '#666';
                ctx.fillText(label1, cx - Math.sin(angle) * (rwLen + 10), cy + Math.cos(angle) * (rwLen + 10));
                ctx.fillText(label2, cx + Math.sin(angle) * (rwLen + 10), cy - Math.cos(angle) * (rwLen + 10));
            });
            
            // Draw wind arrow
            var CALM_WIND_THRESHOLD = 3; // Winds below 3 knots are considered calm in aviation
            if (windSpeed !== null && windSpeed >= CALM_WIND_THRESHOLD && windDir !== null && !isVRB) {
                var windAngle = ((windDir + 180) % 360) * Math.PI / 180; // Convert FROM to TOWARD
                var arrowLen = Math.min(windSpeed * 3, r - 15);
                var endX = cx + Math.sin(windAngle) * arrowLen;
                var endY = cy - Math.cos(windAngle) * arrowLen;
                
                // Arrow glow
                ctx.fillStyle = 'rgba(220, 53, 69, 0.15)';
                ctx.beginPath();
                ctx.arc(cx, cy, Math.max(12, windSpeed * 2), 0, 2 * Math.PI);
                ctx.fill();
                
                // Arrow line
                ctx.strokeStyle = '#dc3545';
                ctx.lineWidth = 3;
                ctx.lineCap = 'round';
                ctx.beginPath();
                ctx.moveTo(cx, cy);
                ctx.lineTo(endX, endY);
                ctx.stroke();
                
                // Arrow head
                var headAngle = Math.atan2(endY - cy, endX - cx);
                ctx.fillStyle = '#dc3545';
                ctx.beginPath();
                ctx.moveTo(endX, endY);
                ctx.lineTo(endX - 8 * Math.cos(headAngle - Math.PI / 6), endY - 8 * Math.sin(headAngle - Math.PI / 6));
                ctx.lineTo(endX - 8 * Math.cos(headAngle + Math.PI / 6), endY - 8 * Math.sin(headAngle + Math.PI / 6));
                ctx.closePath();
                ctx.fill();
            } else if (isVRB && windSpeed >= CALM_WIND_THRESHOLD) {
                ctx.font = 'bold 14px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillStyle = '#dc3545';
                ctx.fillText('VRB', cx, cy + 4);
            } else if (windSpeed === null || windSpeed < CALM_WIND_THRESHOLD) {
                ctx.font = 'bold 12px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillStyle = isDark ? '#666' : '#999';
                ctx.fillText('CALM', cx, cy + 4);
            }
            
            // Cardinal directions
            ctx.font = 'bold 10px sans-serif';
            ctx.fillStyle = isDark ? '#888' : '#666';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ['N', 'E', 'S', 'W'].forEach(function(d, i) {
                var ang = (i * 90 * Math.PI) / 180;
                ctx.fillText(d, cx + Math.sin(ang) * (r + 8), cy - Math.cos(ang) * (r + 8));
            });
        })();
        </script>
        
    <?php endif; ?>
    
    </a>
<?php endif; ?>
</body>
</html>

