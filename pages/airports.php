<?php
/**
 * Airport Directory Page
 * Shows an interactive map of all airports and a list with weather stats
 */

// Note: config.php is already loaded by index.php
require_once __DIR__ . '/../lib/seo.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/weather/utils.php';

// Load configuration and get enabled airports
$config = loadConfig();
$airports = getEnabledAirports($config);

// Sort airports alphabetically by name
uasort($airports, function($a, $b) {
    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
});

$totalAirports = count($airports);

// Helper function to get airport weather (same as homepage)
function getAirportWeatherForDirectory($airportId) {
    $cacheFile = getWeatherCachePath($airportId);
    if (file_exists($cacheFile)) {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($cacheFile, true);
        }
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        if ($cacheData && is_array($cacheData)) {
            $cacheData['_cache_file_mtime'] = filemtime($cacheFile);
            return $cacheData;
        }
    }
    return null;
}

// Helper function to format relative time (same as homepage)
function formatRelativeTimeForDirectory($timestamp) {
    if (!$timestamp || $timestamp <= 0) return 'Unknown';
    $diff = time() - $timestamp;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

// Helper to get newest data timestamp (same as homepage)
function getNewestDataTimestampForDirectory($weather) {
    if (!$weather) return null;
    $timestamps = [];
    
    if (isset($weather['temperature_f']) || isset($weather['temperature'])) {
        if (isset($weather['last_updated_primary']) && $weather['last_updated_primary'] > 0) {
            $timestamps[] = $weather['last_updated_primary'];
        }
    }
    
    if (isset($weather['wind_speed']) && $weather['wind_speed'] !== null) {
        if (isset($weather['last_updated_primary']) && $weather['last_updated_primary'] > 0) {
            $timestamps[] = $weather['last_updated_primary'];
        }
    }
    
    if (isset($weather['flight_category']) && $weather['flight_category'] !== null) {
        if (isset($weather['last_updated_metar']) && $weather['last_updated_metar'] > 0) {
            $timestamps[] = $weather['last_updated_metar'];
        }
    }
    
    if (isset($weather['obs_time_primary']) && $weather['obs_time_primary'] > 0) {
        $timestamps[] = $weather['obs_time_primary'];
    }
    if (isset($weather['obs_time_metar']) && $weather['obs_time_metar'] > 0) {
        $timestamps[] = $weather['obs_time_metar'];
    }
    
    if (empty($timestamps) && isset($weather['last_updated']) && $weather['last_updated'] > 0) {
        $timestamps[] = $weather['last_updated'];
    }
    
    return !empty($timestamps) ? max($timestamps) : null;
}

// Prepare airport data for map (JSON) - include weather data
$airportsForMap = [];
foreach ($airports as $airportId => $airport) {
    if (isset($airport['lat']) && isset($airport['lon'])) {
        // Get weather data for flight category
        $hasMetar = isMetarEnabled($airport);
        $hasWeatherSource = isset($airport['weather_source']) && !empty($airport['weather_source']);
        $hasAnyWeather = $hasWeatherSource || $hasMetar;
        $weather = $hasAnyWeather ? getAirportWeatherForDirectory($airportId) : [];
        $flightCategory = $hasMetar ? ($weather['flight_category'] ?? null) : null;
        
        $airportsForMap[] = [
            'id' => $airportId,
            'identifier' => getPrimaryIdentifier($airportId, $airport),
            'name' => $airport['name'],
            'lat' => $airport['lat'],
            'lon' => $airport['lon'],
            'url' => 'https://' . $airportId . '.aviationwx.org',
            'flightCategory' => $flightCategory // null if no METAR data
        ];
    }
}
$airportsJson = json_encode($airportsForMap);

// SEO variables
$pageTitle = 'Airport Network Map - AviationWX.org';
$pageDescription = 'View all ' . $totalAirports . ' airports in the AviationWX network on an interactive map. Live webcams and real-time weather data for pilots.';
$pageKeywords = 'airport map, aviation weather network, airport webcams, pilot weather, airport directory';
$canonicalUrl = 'https://airports.aviationwx.org';
$baseUrl = getBaseUrl();
$ogImage = $baseUrl . '/public/images/about-photo.webp';

// Breadcrumbs
$breadcrumbs = generateBreadcrumbSchema([
    ['name' => 'Home', 'url' => 'https://aviationwx.org'],
    ['name' => 'Airport Network']
]);
?>
<!DOCTYPE html>
<html lang="en">
<script>
// Apply dark mode immediately based on browser preference to prevent flash
(function() {
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.classList.add('dark-mode');
    }
})();
</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    
    <?php
    // Favicon and icon tags
    echo generateFaviconTags();
    echo "\n    ";
    
    // Enhanced meta tags
    echo generateEnhancedMetaTags($pageDescription, $pageKeywords);
    echo "\n    ";
    
    // Canonical URL
    echo generateCanonicalTag($canonicalUrl);
    echo "\n    ";
    
    // Open Graph and Twitter Card tags
    echo generateSocialMetaTags($pageTitle, $pageDescription, $canonicalUrl, $ogImage);
    echo "\n    ";
    
    // Breadcrumb structured data
    echo generateStructuredDataScript($breadcrumbs);
    ?>
    
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="/public/css/leaflet.css">
    <!-- Leaflet MarkerCluster CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
    
    <link rel="stylesheet" href="/public/css/styles.css">
    <link rel="stylesheet" href="/public/css/navigation.css">
    <style>
        html {
            scroll-behavior: smooth;
        }
        
        body {
            margin: 0;
            padding: 0;
        }
        
        #map {
            width: 100%;
            height: calc(85vh - 60px);
            min-height: 500px;
            max-height: 1200px;
        }
        
        .map-container {
            border-bottom: 3px solid #0066cc;
            position: relative;
        }
        
        /* Map Control Buttons */
        .map-controls {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .map-control-btn {
            background: white;
            border: 2px solid rgba(0,0,0,0.2);
            border-radius: 4px;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 1px 5px rgba(0,0,0,0.2);
            font-size: 1.2rem;
            transition: all 0.2s;
        }
        
        .map-control-btn:hover {
            background: #f8f9fa;
            transform: scale(1.05);
        }
        
        .map-control-btn.active {
            background: #0066cc;
            color: white;
            border-color: #0066cc;
        }
        
        /* Flight Category Legend */
        .flight-legend {
            position: absolute;
            bottom: 30px;
            left: 10px;
            z-index: 1000;
            background: white;
            padding: 10px 12px;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            font-size: 0.85rem;
        }
        
        .flight-legend h4 {
            margin: 0 0 8px 0;
            font-size: 0.9rem;
            font-weight: 600;
            color: #333;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }
        
        .legend-item:last-child {
            margin-bottom: 0;
        }
        
        .legend-color {
            width: 20px;
            height: 14px;
            border-radius: 3px;
        }
        
        .legend-color.vfr { background: #4ade80; }
        .legend-color.mvfr { background: #3b82f6; }
        .legend-color.ifr { background: #ef4444; }
        .legend-color.lifr { background: #d946ef; }
        
        /* Jump to Map Button */
        .jump-to-map {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1001;
            background: #0066cc;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,102,204,0.4);
            display: none;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .jump-to-map:hover {
            background: #0052a3;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,102,204,0.5);
        }
        
        .jump-to-map.visible {
            display: flex;
        }
        
        /* Full-screen mode */
        .map-container.fullscreen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: 9999;
            border-bottom: none;
        }
        
        .map-container.fullscreen #map {
            height: 100vh;
            max-height: none;
        }
        
        /* Weather radar controls */
        .radar-controls {
            position: absolute;
            top: 10px;
            left: 60px;
            z-index: 1000;
            background: white;
            padding: 8px 12px;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            display: none;
        }
        
        .radar-controls.visible {
            display: block;
        }
        
        .radar-controls label {
            font-size: 0.85rem;
            color: #333;
            font-weight: 500;
        }
        
        .radar-opacity-slider {
            width: 120px;
            margin-left: 8px;
        }
        
        .airports-section {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .airports-section h2 {
            margin: 0 0 1.5rem 0;
            color: #333;
            font-size: 1.5rem;
        }
        
        .airports-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
        }
        
        @media (max-width: 992px) {
            .airports-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 640px) {
            .airports-grid {
                grid-template-columns: 1fr;
            }
            
            #map {
                height: 60vh;
                min-height: 300px;
                max-height: 600px;
            }
            
            .airports-section {
                padding: 1rem;
            }
        }
        
        .airport-card {
            background: white;
            padding: 1.25rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
            border: 2px solid transparent;
        }
        
        .airport-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
            border-color: #0066cc;
        }
        
        .airport-card a {
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .airport-code {
            font-size: 1.75rem;
            font-weight: bold;
            color: #0066cc;
            margin-bottom: 0.4rem;
        }
        
        .airport-name {
            font-size: 0.95rem;
            color: #333;
            margin-bottom: 0.2rem;
            font-weight: 500;
        }
        
        .airport-location {
            font-size: 0.85rem;
            color: #555;
            margin-bottom: 0.75rem;
            line-height: 1.4;
        }
        
        .airport-metrics {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: auto;
            padding-top: 0.75rem;
            border-top: 1px solid #e9ecef;
        }
        
        .metric {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-width: 70px;
        }
        
        .metric-label {
            font-size: 0.75rem;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }
        
        .metric-value {
            font-size: 1rem;
            font-weight: 600;
            color: #333;
        }
        
        .flight-condition {
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
            letter-spacing: 0.5px;
        }
        
        .flight-condition.vfr { background: #d4edda; color: #1e7e34; }
        .flight-condition.mvfr { background: #cce5ff; color: #0066cc; }
        .flight-condition.ifr { background: #f8d7da; color: #dc3545; }
        .flight-condition.lifr { background: #ffccff; color: #ff00ff; }
        .flight-condition.unknown { background: #e9ecef; color: #6c757d; }
        
        footer {
            margin-top: 0;
            padding: 2rem;
            border-top: 1px solid #ddd;
            text-align: center;
            color: #555;
            font-size: 0.9rem;
        }
        
        footer a {
            color: #0066cc;
            text-decoration: none;
        }
        
        footer a:hover {
            text-decoration: underline;
        }
        
        /* Add Your Airport Section */
        .add-airport-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 3rem 2rem;
            margin-top: 3rem;
        }
        
        .add-airport-content {
            max-width: 900px;
            margin: 0 auto;
            text-align: center;
        }
        
        .add-airport-content h2 {
            color: #333;
            font-size: 1.75rem;
            margin: 0 0 1rem 0;
        }
        
        .add-airport-content > p {
            color: #555;
            font-size: 1.1rem;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        
        .add-airport-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
            align-items: stretch; /* Ensure cards have equal height */
        }
        
        @media (max-width: 640px) {
            .add-airport-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .add-airport-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: left;
            display: flex;
            flex-direction: column; /* Stack content vertically */
        }
        
        .add-airport-card.highlight {
            border: 2px solid #28a745;
        }
        
        .add-airport-card h3 {
            margin: 0 0 0.75rem 0;
            font-size: 1.2rem;
            color: #333;
        }
        
        .add-airport-card p {
            color: #555;
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 1rem;
            flex-grow: 1; /* Allow paragraph to grow and push button down */
        }
        
        .add-airport-card .btn {
            display: inline-block;
            padding: 0.6rem 1.25rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s;
            margin-top: auto; /* Push button to bottom of card */
            align-self: flex-start; /* Keep button left-aligned */
        }
        
        .add-airport-card .btn-primary {
            background: #28a745;
            color: white;
        }
        
        .add-airport-card .btn-primary:hover {
            background: #218838;
        }
        
        .add-airport-card .btn-secondary {
            background: white;
            color: #0066cc;
            border: 2px solid #0066cc;
        }
        
        .add-airport-card .btn-secondary:hover {
            background: #0066cc;
            color: white;
        }
        
        .add-airport-footer {
            margin-top: 1rem;
        }
        
        .add-airport-footer a {
            color: #0066cc;
            text-decoration: none;
            font-size: 0.95rem;
        }
        
        .add-airport-footer a:hover {
            text-decoration: underline;
        }
        
        /* Leaflet popup styling */
        .leaflet-popup-content {
            margin: 10px 15px;
        }
        
        .popup-airport-code {
            font-size: 1.2rem;
            font-weight: bold;
            color: #0066cc;
            margin-bottom: 0.25rem;
        }
        
        .popup-airport-name {
            font-size: 0.9rem;
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .popup-link {
            display: inline-block;
            background: #0066cc;
            color: #ffffff !important;
            padding: 0.4rem 0.8rem;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .popup-link:hover {
            background: #0052a3;
            color: #ffffff !important;
        }
        
        /* Dark mode */
        @media (prefers-color-scheme: dark) {
            body { background: #121212; color: #e0e0e0; }
        }
        
        body.dark-mode {
            background: #121212;
            color: #e0e0e0;
        }
        
        body.dark-mode .airports-section h2 {
            color: #e0e0e0;
        }
        
        body.dark-mode .airport-card {
            background: #1e1e1e;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        body.dark-mode .airport-card:hover {
            box-shadow: 0 6px 12px rgba(0,0,0,0.4);
            border-color: #4a9eff;
        }
        
        body.dark-mode .airport-code { color: #4a9eff; }
        body.dark-mode .airport-name { color: #e0e0e0; }
        body.dark-mode .airport-location { color: #a0a0a0; }
        body.dark-mode .metric-label { color: #a0a0a0; }
        body.dark-mode .metric-value { color: #e0e0e0; }
        body.dark-mode .airport-metrics { border-top-color: #333; }
        
        body.dark-mode .flight-condition.vfr { background: #1a3a1a; }
        body.dark-mode .flight-condition.mvfr { background: #1a2a3a; }
        body.dark-mode .flight-condition.ifr { background: #3a1a1a; }
        body.dark-mode .flight-condition.lifr { background: #3a1a3a; }
        body.dark-mode .flight-condition.unknown { background: #2a2a2a; color: #a0a0a0; }
        
        body.dark-mode footer {
            border-top-color: #333;
            color: #a0a0a0;
        }
        
        body.dark-mode footer a { color: #4a9eff; }
        
        /* Dark mode add-airport section */
        body.dark-mode .add-airport-section {
            background: linear-gradient(135deg, #1a1a1a 0%, #252525 100%);
        }
        
        body.dark-mode .add-airport-content h2 { color: #e0e0e0; }
        body.dark-mode .add-airport-content > p { color: #a0a0a0; }
        
        body.dark-mode .add-airport-card {
            background: #1e1e1e;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        body.dark-mode .add-airport-card.highlight {
            border-color: #4ade80;
        }
        
        body.dark-mode .add-airport-card h3 { color: #e0e0e0; }
        body.dark-mode .add-airport-card p { color: #a0a0a0; }
        
        body.dark-mode .add-airport-card .btn-primary {
            background: #4ade80;
            color: #1a1a1a;
        }
        
        body.dark-mode .add-airport-card .btn-primary:hover {
            background: #22c55e;
        }
        
        body.dark-mode .add-airport-card .btn-secondary {
            background: #1e1e1e;
            color: #4a9eff;
            border-color: #4a9eff;
        }
        
        body.dark-mode .add-airport-card .btn-secondary:hover {
            background: #4a9eff;
            color: white;
        }
        
        body.dark-mode .add-airport-footer a { color: #4a9eff; }
        
        /* Dark mode popup styling - inverse contrast */
        body.dark-mode .popup-link {
            background: #ffffff;
            color: #0066cc !important;
        }
        
        body.dark-mode .popup-link:hover {
            background: #e0e0e0;
            color: #0052a3 !important;
        }
        
        /* Dark mode map tiles handled by filter */
        body.dark-mode .map-container {
            border-bottom-color: #4a9eff;
        }
        
        /* Dark mode map controls */
        body.dark-mode .map-control-btn {
            background: #2a2a2a;
            border-color: #444;
            color: #e0e0e0;
        }
        
        body.dark-mode .map-control-btn:hover {
            background: #333;
        }
        
        body.dark-mode .map-control-btn.active {
            background: #4a9eff;
            border-color: #4a9eff;
            color: white;
        }
        
        body.dark-mode .flight-legend {
            background: #2a2a2a;
            color: #e0e0e0;
        }
        
        body.dark-mode .flight-legend h4 {
            color: #e0e0e0;
        }
        
        body.dark-mode .jump-to-map {
            background: #4a9eff;
        }
        
        body.dark-mode .jump-to-map:hover {
            background: #3b8cee;
        }
        
        body.dark-mode .radar-controls {
            background: #2a2a2a;
            color: #e0e0e0;
        }
        
        body.dark-mode .radar-controls label {
            color: #e0e0e0;
        }
    </style>
</head>
<body>
    <script>
    if (document.documentElement.classList.contains('dark-mode')) {
        document.body.classList.add('dark-mode');
    }
    </script>
    
    <?php require_once __DIR__ . '/../lib/navigation.php'; ?>
    
    <main>
        <div class="map-container">
            <!-- Map Control Buttons -->
            <div class="map-controls">
                <button id="fullscreen-btn" class="map-control-btn" title="Toggle Fullscreen" aria-label="Toggle fullscreen map">
                    ⛶
                </button>
                <button id="radar-btn" class="map-control-btn" title="Toggle Precipitation Radar" aria-label="Toggle precipitation radar overlay">
                    🌧️
                </button>
                <button id="clouds-btn" class="map-control-btn" title="Toggle Cloud Cover" aria-label="Toggle cloud cover overlay">
                    ☁️
                </button>
            </div>
            
            <!-- Weather Layer Controls -->
            <div id="weather-controls" class="radar-controls">
                <div id="precip-control" style="display: none; margin-bottom: 0.5rem;">
                    <label style="display: block;">
                        Precip Opacity:
                        <input type="range" id="radar-opacity" class="radar-opacity-slider" min="0" max="100" value="70">
                    </label>
                </div>
                <div id="clouds-control" style="display: none;">
                    <label style="display: block;">
                        Clouds Opacity:
                        <input type="range" id="clouds-opacity" class="radar-opacity-slider" min="0" max="100" value="60">
                    </label>
                </div>
            </div>
            
            <!-- Flight Category Legend -->
            <div class="flight-legend">
                <h4>Flight Categories</h4>
                <div class="legend-item">
                    <div class="legend-color vfr"></div>
                    <span>VFR (>5 mi, >3000 ft)</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color mvfr"></div>
                    <span>MVFR (3-5 mi, 1000-3000 ft)</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color ifr"></div>
                    <span>IFR (1-3 mi, 500-1000 ft)</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color lifr"></div>
                    <span>LIFR (<1 mi, <500 ft)</span>
                </div>
            </div>
            
            <div id="map"></div>
        </div>
        
        <!-- Jump to Map Button -->
        <button id="jump-to-map" class="jump-to-map">
            📍 <span>Back to Map</span>
        </button>
        
        <section class="airports-section">
            <div class="airports-grid">
                <?php foreach ($airports as $airportId => $airport): 
                    $url = 'https://' . $airportId . '.aviationwx.org';
                    $hasMetar = isMetarEnabled($airport);
                    $hasWeatherSource = isset($airport['weather_source']) && !empty($airport['weather_source']);
                    $hasAnyWeather = $hasWeatherSource || $hasMetar;
                    $weather = $hasAnyWeather ? getAirportWeatherForDirectory($airportId) : [];
                    $flightCategory = $weather['flight_category'] ?? null;
                    $temperature = $weather['temperature_f'] ?? $weather['temperature'] ?? null;
                    if ($temperature !== null && $temperature < 50 && !isset($weather['temperature_f'])) {
                        $temperature = ($temperature * 9/5) + 32;
                    }
                    $windSpeed = $weather['wind_speed'] ?? null;
                    $windDirection = $weather['wind_direction'] ?? null;
                    $newestTimestamp = getNewestDataTimestampForDirectory($weather);
                ?>
                <div class="airport-card">
                    <a href="<?= htmlspecialchars($url) ?>">
                        <div class="airport-code"><?= htmlspecialchars(getPrimaryIdentifier($airportId, $airport)) ?></div>
                        <div class="airport-name"><?= htmlspecialchars($airport['name']) ?></div>
                        <div class="airport-location"><?= htmlspecialchars($airport['address']) ?></div>
                        
                        <?php if ($hasAnyWeather): ?>
                        <div class="airport-metrics">
                            <?php if ($hasMetar): ?>
                            <div class="metric">
                                <div class="metric-label">Condition</div>
                                <?php if ($flightCategory): 
                                    $conditionClass = strtolower($flightCategory);
                                ?>
                                    <span class="flight-condition <?= htmlspecialchars($conditionClass) ?>">
                                        <?= htmlspecialchars($flightCategory) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="flight-condition unknown">--</span>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="metric">
                                <div class="metric-label">Wind</div>
                                <div class="metric-value">
                                    <?= $windSpeed !== null ? htmlspecialchars(round($windSpeed)) . ' kts' : '--' ?>
                                </div>
                            </div>
                            
                            <div class="metric">
                                <div class="metric-label">Direction</div>
                                <div class="metric-value">
                                    <?php 
                                    if ($windDirection === 'VRB' || $windDirection === 'vrb'):
                                        echo 'VRB';
                                    elseif ($windDirection !== null && is_numeric($windDirection)):
                                        echo htmlspecialchars(round($windDirection)) . '°';
                                    else:
                                        echo '--';
                                    endif;
                                    ?>
                                </div>
                            </div>
                            
                            <div class="metric">
                                <div class="metric-label">Temp</div>
                                <div class="metric-value">
                                    <?= $temperature !== null ? htmlspecialchars(round($temperature)) . '°F' : '--' ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($hasMetar): ?>
                            <div class="metric">
                                <div class="metric-label">Temp</div>
                                <div class="metric-value">
                                    <?= $temperature !== null ? htmlspecialchars(round($temperature)) . '°F' : '--' ?>
                                </div>
                            </div>
                            
                            <div class="metric">
                                <div class="metric-label">Wind</div>
                                <div class="metric-value">
                                    <?= $windSpeed !== null ? htmlspecialchars(round($windSpeed)) . ' kts' : '--' ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($newestTimestamp): ?>
                            <div class="metric" style="flex-basis: 100%; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #e9ecef;">
                                <div class="metric-label" style="font-size: 0.7rem;">Last Updated</div>
                                <div class="metric-value" style="font-size: 0.8rem; color: #555; font-weight: 500;">
                                    <?= htmlspecialchars(formatRelativeTimeForDirectory($newestTimestamp)) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        
        <!-- Add Your Airport Section -->
        <section class="add-airport-section">
            <div class="add-airport-content">
                <h2>Don't See Your Airport?</h2>
                <p>We're always looking to add more airports to the network. AviationWX.org is <strong>completely free</strong> for airports and pilots - no fees, no subscriptions, no ads.</p>
                
                <div class="add-airport-grid">
                    <div class="add-airport-card">
                        <h3>✈️ For Pilots</h3>
                        <p>Know an airport that should be here? Help us connect with the right people - airport managers, advocacy organizations, or local flying clubs.</p>
                        <a href="https://guides.aviationwx.org/12-submit-an-airport-to-aviationwx" class="btn btn-secondary">Learn How to Help</a>
                    </div>
                    
                    <div class="add-airport-card highlight">
                        <h3>🏢 For Airport Owners</h3>
                        <p>We integrate with your existing webcams and weather stations, or guide your community through new installations. You own the equipment, we host the dashboard.</p>
                        <a href="mailto:contact@aviationwx.org?subject=Add%20my%20airport%20to%20AviationWX" class="btn btn-primary">Get Started</a>
                    </div>
                </div>
                
                <p class="add-airport-footer">
                    <a href="https://guides.aviationwx.org">📚 Read the full setup guides</a>
                </p>
            </div>
        </section>
        
        <footer>
            <p>
                &copy; <?= date('Y') ?> <a href="https://aviationwx.org">AviationWX.org</a> • 
                <a href="https://airports.aviationwx.org">Airports</a> • 
                <a href="https://guides.aviationwx.org">Guides</a> • 
                <a href="https://aviationwx.org#about-the-project">Built for pilots, by pilots</a> • 
                <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener">Open Source<?php $gitSha = getGitSha(); echo $gitSha ? ' - ' . htmlspecialchars($gitSha) : ''; ?></a> • 
                <a href="https://terms.aviationwx.org">Terms of Service</a> • 
                <a href="https://api.aviationwx.org">API</a> • 
                <a href="https://status.aviationwx.org">Status</a>
            </p>
        </footer>
    </main>
    
    <!-- Leaflet JS -->
    <script src="/public/js/leaflet.js"></script>
    <!-- Leaflet MarkerCluster -->
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    
    <script>
    (function() {
        'use strict';
        
        // Airport data from PHP
        var airports = <?= $airportsJson ?>;
        
        if (airports.length === 0) {
            document.getElementById('map').innerHTML = '<p style="padding: 2rem; text-align: center; color: #666;">No airports with coordinates available.</p>';
            return;
        }
        
        // Configure Leaflet default icon path
        if (L.Icon.Default && L.Icon.Default.prototype) {
            var originalGetIconUrl = L.Icon.Default.prototype._getIconUrl;
            L.Icon.Default.prototype._getIconUrl = function(name) {
                var url = originalGetIconUrl ? originalGetIconUrl.call(this, name) : name;
                var filename = url.split('/').pop();
                return '/public/images/leaflet/' + filename;
            };
        }
        
        // Initialize map
        var map = L.map('map', {
            scrollWheelZoom: true,
            zoomControl: true
        });
        
        // Add OpenStreetMap tiles
        var baseLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);
        
        // Weather Radar Layer (RainViewer API)
        var radarLayer = null;
        var radarTimestamp = null;
        
        // Cloud Cover Layer (OpenWeatherMap)
        var cloudsLayer = null;
        
        function addRadarLayer() {
            // Fetch latest radar timestamp from RainViewer
            fetch('https://api.rainviewer.com/public/weather-maps.json')
                .then(function(response) { 
                    if (!response.ok) {
                        throw new Error('Radar API response not OK');
                    }
                    return response.json(); 
                })
                .then(function(data) {
                    if (data.radar && data.radar.past && data.radar.past.length > 0) {
                        // Get most recent radar frame
                        radarTimestamp = data.radar.past[data.radar.past.length - 1].time;
                        
                        // RainViewer tile URL format
                        // size/smoothing_quality/color_scheme
                        var radarUrl = 'https://tilecache.rainviewer.com/v2/radar/' + radarTimestamp + '/256/{z}/{x}/{y}/6/1_1.png';
                        
                        radarLayer = L.tileLayer(radarUrl, {
                            opacity: 0.7,
                            attribution: 'Radar © <a href="https://www.rainviewer.com">RainViewer</a>',
                            zIndex: 500,
                            maxZoom: 19
                        });
                        
                        radarLayer.addTo(map);
                        document.getElementById('precip-control').style.display = 'block';
                        updateWeatherControlsVisibility();
                        
                        console.log('Radar layer added with timestamp:', radarTimestamp);
                    } else {
                        console.warn('No radar data available in API response');
                    }
                })
                .catch(function(err) {
                    console.error('Failed to load radar data:', err);
                    alert('Precipitation radar temporarily unavailable. Please try again later.');
                });
        }
        
        function removeRadarLayer() {
            if (radarLayer) {
                map.removeLayer(radarLayer);
                radarLayer = null;
                document.getElementById('precip-control').style.display = 'none';
                updateWeatherControlsVisibility();
            }
        }
        
        function addCloudsLayer() {
            // OpenWeatherMap Clouds layer
            // API key not required for tile access (free tier)
            var cloudsUrl = 'https://tile.openweathermap.org/map/clouds_new/{z}/{x}/{y}.png?appid=439d4b804bc8187953eb36d2a8c26a02';
            
            cloudsLayer = L.tileLayer(cloudsUrl, {
                opacity: 0.6,
                attribution: 'Clouds © <a href="https://openweathermap.org">OpenWeatherMap</a>',
                zIndex: 400,
                maxZoom: 19
            });
            
            cloudsLayer.addTo(map);
            document.getElementById('clouds-control').style.display = 'block';
            updateWeatherControlsVisibility();
            
            console.log('Cloud layer added');
        }
        
        function removeCloudsLayer() {
            if (cloudsLayer) {
                map.removeLayer(cloudsLayer);
                cloudsLayer = null;
                document.getElementById('clouds-control').style.display = 'none';
                updateWeatherControlsVisibility();
            }
        }
        
        function updateWeatherControlsVisibility() {
            var weatherControls = document.getElementById('weather-controls');
            var precipControl = document.getElementById('precip-control');
            var cloudsControl = document.getElementById('clouds-control');
            
            // Show weather controls panel if any layer is active
            if (precipControl.style.display === 'block' || cloudsControl.style.display === 'block') {
                weatherControls.classList.add('visible');
            } else {
                weatherControls.classList.remove('visible');
            }
        }
        
        // Custom airplane icon (function to create colored icons based on flight category)
        function createAirportIcon(flightCategory) {
            var color = '#666'; // Default gray for no data
            
            if (flightCategory) {
                switch(flightCategory.toUpperCase()) {
                    case 'VFR':
                        color = '#4ade80'; // Green
                        break;
                    case 'MVFR':
                        color = '#3b82f6'; // Blue
                        break;
                    case 'IFR':
                        color = '#ef4444'; // Red
                        break;
                    case 'LIFR':
                        color = '#d946ef'; // Magenta
                        break;
                }
            }
            
            return L.divIcon({
                className: 'airport-marker',
                html: '<svg viewBox="0 0 24 24" width="28" height="28" style="filter: drop-shadow(1px 1px 2px rgba(0,0,0,0.4));"><path fill="' + color + '" d="M21,16V14L13,9V3.5A1.5,1.5 0 0,0 11.5,2A1.5,1.5 0 0,0 10,3.5V9L2,14V16L10,13.5V19L8,20.5V22L11.5,21L15,22V20.5L13,19V13.5L21,16Z"/></svg>',
                iconSize: [28, 28],
                iconAnchor: [14, 14],
                popupAnchor: [0, -14]
            });
        }
        
        // Create marker cluster group
        var markers = L.markerClusterGroup({
            maxClusterRadius: 60,
            spiderfyOnMaxZoom: true,
            showCoverageOnHover: false,
            zoomToBoundsOnClick: true,
            iconCreateFunction: function(cluster) {
                var count = cluster.getChildCount();
                var size = count < 10 ? 'small' : count < 50 ? 'medium' : 'large';
                return L.divIcon({
                    html: '<div style="background: #0066cc; color: white; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px; box-shadow: 0 2px 6px rgba(0,0,0,0.3);">' + count + '</div>',
                    className: 'marker-cluster',
                    iconSize: [40, 40]
                });
            }
        });
        
        // Add markers for each airport
        var allMarkers = [];
        airports.forEach(function(airport) {
            // Create icon with flight category color
            var icon = createAirportIcon(airport.flightCategory);
            
            var marker = L.marker([airport.lat, airport.lon], { icon: icon })
                .bindPopup(
                    '<div class="popup-airport-code">' + airport.identifier + '</div>' +
                    '<div class="popup-airport-name">' + airport.name + '</div>' +
                    (airport.flightCategory ? '<div style="margin: 0.5rem 0; padding: 0.25rem 0.5rem; background: ' + getFlightCategoryBgColor(airport.flightCategory) + '; color: ' + getFlightCategoryTextColor(airport.flightCategory) + '; border-radius: 4px; font-size: 0.85rem; font-weight: 600; text-align: center; text-transform: uppercase;">' + airport.flightCategory + '</div>' : '') +
                    '<a href="' + airport.url + '" class="popup-link">View Dashboard →</a>'
                );
            
            marker._airportData = airport; // Store for search
            markers.addLayer(marker);
            allMarkers.push(marker);
        });
        
        // Helper functions for popup styling
        function getFlightCategoryBgColor(cat) {
            switch(cat.toUpperCase()) {
                case 'VFR': return '#d4edda';
                case 'MVFR': return '#cce5ff';
                case 'IFR': return '#f8d7da';
                case 'LIFR': return '#ffccff';
                default: return '#e9ecef';
            }
        }
        
        function getFlightCategoryTextColor(cat) {
            switch(cat.toUpperCase()) {
                case 'VFR': return '#1e7e34';
                case 'MVFR': return '#0066cc';
                case 'IFR': return '#dc3545';
                case 'LIFR': return '#ff00ff';
                default: return '#6c757d';
            }
        }
        
        map.addLayer(markers);
        
        // Fit map to show all airports
        if (allMarkers.length > 0) {
            var group = L.featureGroup(allMarkers);
            map.fitBounds(group.getBounds().pad(0.1));
        }
        
        // Limit max zoom when fitting bounds
        if (map.getZoom() > 10) {
            map.setZoom(10);
        }
        
        // ========================================================================
        // FEATURE: Fullscreen Toggle
        // ========================================================================
        var fullscreenBtn = document.getElementById('fullscreen-btn');
        var mapContainer = document.querySelector('.map-container');
        var isFullscreen = false;
        
        fullscreenBtn.addEventListener('click', function() {
            isFullscreen = !isFullscreen;
            
            if (isFullscreen) {
                mapContainer.classList.add('fullscreen');
                fullscreenBtn.classList.add('active');
                fullscreenBtn.innerHTML = '✕';
                fullscreenBtn.title = 'Exit Fullscreen';
            } else {
                mapContainer.classList.remove('fullscreen');
                fullscreenBtn.classList.remove('active');
                fullscreenBtn.innerHTML = '⛶';
                fullscreenBtn.title = 'Toggle Fullscreen';
            }
            
            // Invalidate map size after transition
            setTimeout(function() {
                map.invalidateSize();
            }, 300);
        });
        
        // Exit fullscreen with ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isFullscreen) {
                fullscreenBtn.click();
            }
        });
        
        // ========================================================================
        // FEATURE: Weather Layer Toggles
        // ========================================================================
        var radarBtn = document.getElementById('radar-btn');
        var cloudsBtn = document.getElementById('clouds-btn');
        var radarEnabled = false;
        var cloudsEnabled = false;
        
        radarBtn.addEventListener('click', function() {
            radarEnabled = !radarEnabled;
            
            if (radarEnabled) {
                radarBtn.classList.add('active');
                addRadarLayer();
            } else {
                radarBtn.classList.remove('active');
                removeRadarLayer();
            }
        });
        
        cloudsBtn.addEventListener('click', function() {
            cloudsEnabled = !cloudsEnabled;
            
            if (cloudsEnabled) {
                cloudsBtn.classList.add('active');
                addCloudsLayer();
            } else {
                cloudsBtn.classList.remove('active');
                removeCloudsLayer();
            }
        });
        
        // Precipitation opacity slider
        var radarOpacitySlider = document.getElementById('radar-opacity');
        radarOpacitySlider.addEventListener('input', function() {
            if (radarLayer) {
                radarLayer.setOpacity(this.value / 100);
            }
        });
        
        // Cloud cover opacity slider
        var cloudsOpacitySlider = document.getElementById('clouds-opacity');
        cloudsOpacitySlider.addEventListener('input', function() {
            if (cloudsLayer) {
                cloudsLayer.setOpacity(this.value / 100);
            }
        });
        
        // ========================================================================
        // FEATURE: Jump to Map Button
        // ========================================================================
        var jumpBtn = document.getElementById('jump-to-map');
        
        window.addEventListener('scroll', function() {
            // Show button when scrolled past map
            var mapBottom = mapContainer.offsetTop + mapContainer.offsetHeight;
            if (window.scrollY > mapBottom) {
                jumpBtn.classList.add('visible');
            } else {
                jumpBtn.classList.remove('visible');
            }
        });
        
        jumpBtn.addEventListener('click', function() {
            mapContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        
        // ========================================================================
        // FEATURE: Search Integration (highlights marker on map)
        // ========================================================================
        var searchInput = document.getElementById('site-nav-airport-search');
        if (searchInput) {
            var highlightedMarker = null;
            
            // Listen for custom event from navigation.php search
            document.addEventListener('airportSearchSelect', function(e) {
                var airportId = e.detail.airportId;
                
                // Find matching marker
                allMarkers.forEach(function(marker) {
                    if (marker._airportData && marker._airportData.id === airportId) {
                        // Zoom to marker
                        map.setView(marker.getLatLng(), 12);
                        
                        // Open popup with highlight
                        marker.openPopup();
                        
                        // Store for cleanup
                        highlightedMarker = marker;
                        
                        // Scroll to map
                        mapContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            });
        }
    })();
    </script>
</body>
</html>

