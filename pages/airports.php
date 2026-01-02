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

// Prepare airport data for map (JSON)
$airportsForMap = [];
foreach ($airports as $airportId => $airport) {
    if (isset($airport['lat']) && isset($airport['lon'])) {
        $airportsForMap[] = [
            'id' => $airportId,
            'identifier' => getPrimaryIdentifier($airportId, $airport),
            'name' => $airport['name'],
            'lat' => $airport['lat'],
            'lon' => $airport['lon'],
            'url' => 'https://' . $airportId . '.aviationwx.org'
        ];
    }
}
$airportsJson = json_encode($airportsForMap);

// SEO variables
$pageTitle = 'Airport Network Map - AviationWX.org';
$pageDescription = 'View all ' . $totalAirports . ' airports in the AviationWX network on an interactive map. Live webcams and real-time weather data for pilots.';
$pageKeywords = 'airport map, aviation weather network, airport webcams, pilot weather, airport directory';
$canonicalUrl = 'https://aviationwx.org/airports';
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
    
    <link rel="stylesheet" href="/public/css/styles.css">
    <style>
        html {
            scroll-behavior: smooth;
        }
        
        body {
            margin: 0;
            padding: 0;
        }
        
        .page-header {
            background: linear-gradient(135deg, #1a1a1a 0%, #0066cc 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .page-header h1 {
            margin: 0 0 0.5rem 0;
            font-size: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .page-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 1.1rem;
        }
        
        .page-header img {
            width: 48px;
            height: 48px;
        }
        
        #map {
            width: 100%;
            height: calc(66vh - 100px);
            min-height: 300px;
            max-height: 500px;
        }
        
        .map-container {
            border-bottom: 3px solid #0066cc;
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
                height: 50vh;
                min-height: 250px;
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
        
        body.dark-mode .page-header {
            background: linear-gradient(135deg, #0a0a0a 0%, #003d7a 100%);
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
    </style>
</head>
<body>
    <script>
    if (document.documentElement.classList.contains('dark-mode')) {
        document.body.classList.add('dark-mode');
    }
    </script>
    
    <main>
        <header class="page-header">
            <h1>
                <img src="<?= $baseUrl ?>/public/favicons/android-chrome-192x192.png" alt="AviationWX">
                Airport Network
            </h1>
            <p><?= $totalAirports ?> airports with live webcams and real-time weather</p>
        </header>
        
        <div class="map-container">
            <div id="map"></div>
        </div>
        
        <section class="airports-section">
            <h2>All Airports (<?= $totalAirports ?>)</h2>
            
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
                <a href="https://aviationwx.org/airports">Airports</a> • 
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
    
    <script>
    (function() {
        'use strict';
        
        // Airport data from PHP
        var airports = <?= $airportsJson ?>;
        
        if (airports.length === 0) {
            document.getElementById('map').innerHTML = '<p style="padding: 2rem; text-align: center; color: #666;">No airports with coordinates available.</p>';
            return;
        }
        
        // Configure Leaflet default icon path to prevent 404s
        // (We use custom divIcon, but this prevents Leaflet from trying default paths)
        // Note: In Leaflet 1.9.4, we override the _getIconUrl method
        if (L.Icon.Default && L.Icon.Default.prototype) {
            var originalGetIconUrl = L.Icon.Default.prototype._getIconUrl;
            L.Icon.Default.prototype._getIconUrl = function(name) {
                var url = originalGetIconUrl ? originalGetIconUrl.call(this, name) : name;
                // Extract filename and prepend our path
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
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);
        
        // Custom airplane icon
        var airportIcon = L.divIcon({
            className: 'airport-marker',
            html: '<svg viewBox="0 0 24 24" width="28" height="28" style="filter: drop-shadow(1px 1px 2px rgba(0,0,0,0.3));"><path fill="#0066cc" d="M21,16V14L13,9V3.5A1.5,1.5 0 0,0 11.5,2A1.5,1.5 0 0,0 10,3.5V9L2,14V16L10,13.5V19L8,20.5V22L11.5,21L15,22V20.5L13,19V13.5L21,16Z"/></svg>',
            iconSize: [28, 28],
            iconAnchor: [14, 14],
            popupAnchor: [0, -14]
        });
        
        // Add markers for each airport
        var markers = [];
        airports.forEach(function(airport) {
            var marker = L.marker([airport.lat, airport.lon], { icon: airportIcon })
                .bindPopup(
                    '<div class="popup-airport-code">' + airport.identifier + '</div>' +
                    '<div class="popup-airport-name">' + airport.name + '</div>' +
                    '<a href="' + airport.url + '" class="popup-link">View Dashboard →</a>'
                );
            marker.addTo(map);
            markers.push(marker);
        });
        
        // Fit map to show all airports
        if (markers.length > 0) {
            var group = L.featureGroup(markers);
            map.fitBounds(group.getBounds().pad(0.1));
        }
        
        // Limit max zoom when fitting bounds
        if (map.getZoom() > 10) {
            map.setZoom(10);
        }
    })();
    </script>
</body>
</html>

