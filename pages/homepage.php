<?php
// Prevent caching of homepage to ensure fresh data on each visit
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Load SEO utilities and config (for getGitSha function)
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/sentry-js.php';
require_once __DIR__ . '/../lib/seo.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/cloudflare-analytics.php';

// Encode email body for mailto: URLs with readable formatting
// Uses rawurlencode (%20 for spaces) and %0A for newlines to ensure proper display in email clients
function encodeEmailBody($text) {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = explode("\n", $text);
    $encodedLines = array_map('rawurlencode', $lines);
    return implode('%0A', $encodedLines);
}

// Use CONFIG_PATH environment variable if set (for production), otherwise use default path
$envConfigPath = getenv('CONFIG_PATH');
$configFile = ($envConfigPath && file_exists($envConfigPath)) ? $envConfigPath : (__DIR__ . '/../config/airports.json');
$totalAirports = 0;
$totalWebcams = 0;
$totalWeatherStations = 0;

// Get true rolling 24-hour metrics from the metrics system
require_once __DIR__ . '/../lib/metrics.php';
$rolling24h = metrics_get_rolling_hours(24);
// Images processed = variants generated (includes all format/size combinations created)
$imagesProcessed24h = $rolling24h['global']['variants_generated'] ?? 0;

// Fetch Cloudflare Analytics
$cfAnalytics = getCloudflareAnalytics();
$pilotsServedToday = $cfAnalytics['unique_visitors_today'] ?? 0;

if (file_exists($configFile)) {
    $config = json_decode(file_get_contents($configFile), true);
    if (isset($config['airports'])) {
        // Only count enabled airports
        $enabledAirports = getEnabledAirports($config);
        $totalAirports = count($enabledAirports);
        
        // Track unique weather stations
        $uniqueWeatherStations = [];
        
        foreach ($enabledAirports as $airportId => $airport) {
            if (isset($airport['webcams'])) {
                $totalWebcams += count($airport['webcams']);
            }
            
            // Count weather sources from unified weather_sources array
            if (isset($airport['weather_sources']) && is_array($airport['weather_sources'])) {
                foreach ($airport['weather_sources'] as $source) {
                    $type = $source['type'] ?? '';
                    
                    // Build unique identifier based on source type
                    if ($type === 'tempest' && isset($source['station_id'])) {
                        $uniqueWeatherStations['tempest_' . $source['station_id']] = true;
                    } elseif ($type === 'ambient' && isset($source['mac_address'])) {
                        $uniqueWeatherStations['ambient_' . $source['mac_address']] = true;
                    } elseif ($type === 'weatherlink_v2' && isset($source['station_id'])) {
                        $uniqueWeatherStations['weatherlink_' . $source['station_id']] = true;
                    } elseif ($type === 'pwsweather' && isset($source['station_id'])) {
                        $uniqueWeatherStations['pwsweather_' . $source['station_id']] = true;
                    } elseif ($type === 'metar' && isset($source['station_id'])) {
                        $uniqueWeatherStations['metar_' . $source['station_id']] = true;
                    } elseif ($type === 'nws' && isset($source['station_id'])) {
                        $uniqueWeatherStations['nws_' . $source['station_id']] = true;
                    }
                }
            }
        }
        
        $totalWeatherStations = count($uniqueWeatherStations);
    }
}

// Format large numbers for display
function formatMetricNumber($number) {
    if ($number >= 1000000) {
        return number_format($number / 1000000, 1) . 'M';
    } elseif ($number >= 1000) {
        return number_format($number / 1000, 1) . 'K';
    }
    return number_format($number);
}

// SEO variables
$pageTitle = 'AviationWX.org - Live Airport Webcams & Real-time Aviation Weather';
// Optimized meta description - action-oriented, under 160 chars
$pageDescription = 'Make safer go/no-go decisions with free live airport webcams and real-time weather. ' . $totalAirports . ' airports, ' . $totalWebcams . '+ webcams. No login, no ads - built for pilots.';
$pageKeywords = 'live airport webcams, runway conditions, aviation weather, airport webcams, live weather, pilot weather, airport conditions, aviation webcams, real-time weather, airport cameras';
$canonicalUrl = getCanonicalUrl();
$baseUrl = getBaseUrl();
// Prefer WebP for about-photo, fallback to JPG
$aboutPhotoWebp = __DIR__ . '/../public/images/about-photo.webp';
$aboutPhotoJpg = __DIR__ . '/../public/images/about-photo.jpg';
$ogImage = file_exists($aboutPhotoWebp) 
    ? $baseUrl . '/public/images/about-photo.webp'
    : $baseUrl . '/public/images/about-photo.jpg';
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
    <?php
    // Initialize Sentry JavaScript SDK for frontend error tracking
    renderSentryJsInit('homepage');
    ?>
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
    
    // Structured data (Organization schema)
    echo generateStructuredDataScript(generateOrganizationSchema());
    echo "\n    ";
    
    // Structured data (WebSite schema with SearchAction for sitelinks search box)
    echo generateStructuredDataScript(generateWebSiteSchema());
    ?>
    
    <link rel="stylesheet" href="public/css/styles.css">
    <link rel="stylesheet" href="/public/css/navigation.css">
    <style>
        /* Smooth scrolling for anchor links */
        html {
            scroll-behavior: smooth;
            overflow-x: hidden;
        }
        
        body {
            overflow-x: hidden;
            max-width: 100vw;
        }
        
        /* Constrain container to viewport on mobile */
        .container {
            max-width: 100%;
            overflow-x: hidden;
        }
        
        /* Ensure proper anchor positioning */
        section[id] {
            scroll-margin-top: 2rem;
        }
        
        .hero {
            background: linear-gradient(135deg, #1a1a1a 0%, #0066cc 100%);
            color: white;
            padding: 2rem 2rem;
            text-align: center;
            margin: -1rem -1rem 3rem -1rem;
            box-sizing: border-box;
            max-width: calc(100% + 2rem);
        }
        .hero h1 {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
            font-weight: 600;
        }
        .hero p {
            font-size: 1.1rem;
            opacity: 0.95;
            max-width: 800px;
            margin: 0 auto 1rem;
            line-height: 1.6;
        }
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin: 2rem 0;
            width: 100%;
            box-sizing: border-box;
        }
        @media (max-width: 480px) {
            .features {
                grid-template-columns: 1fr;
            }
        }
        
        /* Hero Metrics Mobile Optimization */
        @media (max-width: 640px) {
            .hero > div[style*="gap: 2rem"] {
                gap: 1rem !important; /* Tighter spacing on mobile */
            }
            .hero > div[style*="gap: 2rem"] > div {
                min-width: 70px; /* Prevent cramping while allowing wrapping */
            }
            .hero h1 {
                font-size: 2rem !important; /* Slightly smaller on mobile */
            }
            .hero p {
                font-size: 1rem !important;
            }
        }
        .features-webcam {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin: 2rem 0;
        }
        @media (min-width: 900px) {
            .features-webcam {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        .feature-card {
            background: #f8f9fa;
            padding: 2rem;
            border-radius: 8px;
            border-left: 4px solid #0066cc;
            min-width: 0;
            overflow-wrap: break-word;
            word-wrap: break-word;
            box-sizing: border-box;
            width: 100%;
        }
        .feature-card h3 {
            margin-top: 0;
            color: #0066cc;
        }
        .airports-list {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-top: 2rem;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        @media (max-width: 992px) {
            .airports-list {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 640px) {
            .airports-list {
                grid-template-columns: 1fr;
            }
        }
        .airport-card {
            background: white;
            padding: 1.25rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
            border: 2px solid transparent;
            display: flex;
            flex-direction: column;
            min-width: 0;
            width: 100%;
            box-sizing: border-box;
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
        .flight-condition.vfr {
            background: #d4edda;
            color: #1e7e34; /* Green - VFR: Visibility > 5 miles, Ceiling > 3,000 feet */
        }
        .flight-condition.mvfr {
            background: #cce5ff;
            color: #0066cc; /* Blue - MVFR: Visibility 3-5 miles, Ceiling 1,000-3,000 feet */
        }
        .flight-condition.ifr {
            background: #f8d7da;
            color: #dc3545; /* Red - IFR: Visibility 1-3 miles, Ceiling 500-1,000 feet */
        }
        .flight-condition.lifr {
            background: #ffccff;
            color: #ff00ff; /* Magenta - LIFR: Visibility < 1 mile, Ceiling < 500 feet */
        }
        .flight-condition.unknown {
            background: #e9ecef;
            color: #6c757d;
        }
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin: 2rem 0;
            flex-wrap: wrap;
        }
        .pagination button {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            color: #333;
            transition: all 0.2s;
        }
        .pagination button:hover:not(:disabled) {
            background: #0066cc;
            color: white;
            border-color: #0066cc;
        }
        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .pagination button.active {
            background: #0066cc;
            color: white;
            border-color: #0066cc;
        }
        .pagination-info {
            color: #555;
            font-size: 0.9rem;
            margin: 0 1rem;
        }
        .cta-section {
            background: #f8f9fa;
            padding: 3rem 2rem;
            border-radius: 8px;
            text-align: center;
            margin: 3rem 0;
        }
        .cta-section h2 {
            margin-top: 0;
        }
        .btn-group {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }
        .btn-primary {
            background: #0066cc;
            color: white;
            padding: 1rem 2rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            transition: background 0.2s;
            display: inline-block;
        }
        .btn-primary:hover {
            background: #0052a3;
        }
        .btn-secondary {
            background: white;
            color: #0066cc;
            padding: 1rem 2rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            border: 2px solid #0066cc;
            transition: all 0.2s;
            display: inline-block;
        }
        .btn-secondary:hover {
            background: #0066cc;
            color: white;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
            width: 100%;
            box-sizing: border-box;
        }
        @media (max-width: 480px) {
            .stats {
                grid-template-columns: 1fr;
            }
        }
        .equipment-grid {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 3rem;
            width: 100%;
            box-sizing: border-box;
        }
        @media (max-width: 480px) {
            .equipment-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
        }
        .stat-card {
            text-align: center;
            padding: 1.5rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #0066cc;
        }
        .stat-label {
            font-size: 0.9rem;
            color: #555;
            margin-top: 0.5rem;
        }
        .highlight-box {
            background: #f8f9fa;
            padding: 2rem;
            border-radius: 8px;
            border-left: 5px solid #0066cc;
            margin: 2rem 0;
        }
        .highlight-box h3 {
            margin-top: 0;
            color: #0066cc;
        }
        .user-group-section {
            background: #f8f9fa;
            padding: 2.5rem;
            border-radius: 8px;
            border-left: 5px solid #0066cc;
            margin: 2rem 0;
        }
        .user-group-section h3 {
            margin-top: 0;
            color: #0066cc;
            font-size: 1.5rem;
        }
        .user-groups {
            margin: 3rem 0;
        }
        .user-groups > h2 {
            text-align: center;
            margin-bottom: 2rem;
        }
        .contact-info {
            background: #e9ecef;
            padding: 1.5rem;
            border-radius: 6px;
            margin: 1rem 0;
            text-align: center;
        }
        .contact-info a {
            color: #0066cc;
            text-decoration: none;
            font-weight: 500;
        }
        .contact-info a:hover {
            text-decoration: underline;
        }
        section {
            margin: 3rem 0;
        }
        section h2 {
            color: #333;
            margin-bottom: 1.5rem;
        }
        .about-box {
            background: #f8f9fa;
            padding: 2rem;
            border-radius: 8px;
            margin: 2rem 0;
            border-top: 3px solid #0066cc;
        }
        .about-box p {
            line-height: 1.8;
            color: #555;
        }
        .about-image {
            width: 100%;
            max-width: 600px;
            margin: 0 auto 2rem;
            display: block;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        footer {
            margin-top: 4rem;
            padding-top: 2rem;
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
        ul {
            line-height: 1.8;
        }
        /* Homepage Airport Search Styles */
        .homepage-airport-search-container {
            max-width: 500px;
            margin: 0 auto 2rem;
            position: relative;
        }
        .homepage-airport-search-wrapper {
            position: relative;
        }
        .homepage-airport-search-input {
            width: 100%;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            border: 2px solid #ddd;
            border-radius: 8px;
            background: white;
            color: #333;
            transition: all 0.2s;
            box-sizing: border-box;
        }
        .homepage-airport-search-input:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.15);
        }
        .homepage-airport-search-input::placeholder {
            color: #888;
        }
        .homepage-airport-dropdown {
            position: absolute;
            top: calc(100% + 0.25rem);
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            max-height: 350px;
            overflow-y: auto;
            display: none;
            z-index: 100;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .homepage-airport-dropdown.show {
            display: block;
        }
        .homepage-airport-item {
            display: flex;
            flex-direction: column;
            padding: 0.75rem 1rem;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
            transition: background 0.15s;
            border-bottom: 1px solid #eee;
        }
        .homepage-airport-item:last-child {
            border-bottom: none;
        }
        .homepage-airport-item:hover,
        .homepage-airport-item.selected {
            background: #f0f7ff;
        }
        .homepage-airport-item .airport-identifier {
            font-size: 1.1rem;
            font-weight: 700;
            color: #0066cc;
        }
        .homepage-airport-item .airport-name {
            font-size: 0.9rem;
            color: #555;
            margin-top: 0.15rem;
        }
        .homepage-airport-item.no-results {
            color: #666;
            font-style: italic;
            text-align: center;
            cursor: default;
        }
        .homepage-airport-item.no-results:hover {
            background: transparent;
        }
        code {
            background: #f4f4f4;
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
            font-family: monospace;
            font-size: 0.9em;
            word-break: break-all;
            overflow-wrap: break-word;
            display: inline-block;
            max-width: 100%;
        }
        .feature-card p {
            word-wrap: break-word;
            overflow-wrap: break-word;
            hyphens: auto;
        }
        .feature-card p:last-child {
            word-wrap: break-word;
            overflow-wrap: break-word;
            line-height: 1.6;
        }
        .feature-card code {
            word-break: break-all;
            overflow-wrap: anywhere;
            white-space: normal;
            max-width: 100%;
            box-sizing: border-box;
        }
        .feature-card p code {
            display: inline;
            word-break: break-all;
            overflow-wrap: anywhere;
            max-width: 100%;
        }
        /* Make example paragraphs wrap URLs better */
        .feature-card p {
            overflow-wrap: break-word;
            word-wrap: break-word;
        }
        /* Ensure code in paragraphs can shrink */
        .feature-card p code {
            max-width: calc(100% - 0.8rem);
            min-width: 0;
        }
        
        /* ============================================
           Dark Mode Overrides for Homepage
           Automatically applied based on browser preference
           ============================================ */
        @media (prefers-color-scheme: dark) {
            body {
                background: #121212;
                color: #e0e0e0;
            }
        }
        
        body.dark-mode {
            background: #121212;
            color: #e0e0e0;
        }
        
        body.dark-mode .hero {
            background: linear-gradient(135deg, #0a0a0a 0%, #003d7a 100%);
        }
        
        body.dark-mode .feature-card {
            background: #1e1e1e;
            border-left-color: #4a9eff;
        }
        
        body.dark-mode .feature-card h3 {
            color: #4a9eff;
        }
        
        body.dark-mode .airport-card {
            background: #1e1e1e;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        body.dark-mode .airport-card:hover {
            box-shadow: 0 6px 12px rgba(0,0,0,0.4);
            border-color: #4a9eff;
        }
        
        body.dark-mode .airport-code {
            color: #4a9eff;
        }
        
        body.dark-mode .airport-name,
        body.dark-mode .metric-value {
            color: #e0e0e0;
        }
        
        body.dark-mode .airport-location,
        body.dark-mode .metric-label {
            color: #a0a0a0;
        }
        
        body.dark-mode .airport-metrics {
            border-top-color: #333;
        }
        
        body.dark-mode .flight-condition.vfr {
            background: #1a3a1a;
        }
        
        body.dark-mode .flight-condition.mvfr {
            background: #1a2a3a;
        }
        
        body.dark-mode .flight-condition.ifr {
            background: #3a1a1a;
        }
        
        body.dark-mode .flight-condition.lifr {
            background: #3a1a3a;
        }
        
        body.dark-mode .flight-condition.unknown {
            background: #2a2a2a;
            color: #a0a0a0;
        }
        
        body.dark-mode .pagination button {
            background: #1e1e1e;
            border-color: #333;
            color: #e0e0e0;
        }
        
        body.dark-mode .pagination button:hover:not(:disabled) {
            background: #4a9eff;
            border-color: #4a9eff;
        }
        
        body.dark-mode .pagination button.active {
            background: #4a9eff;
            border-color: #4a9eff;
        }
        
        body.dark-mode .pagination-info {
            color: #a0a0a0;
        }
        
        body.dark-mode .cta-section {
            background: #1e1e1e;
        }
        
        body.dark-mode .stats .stat-card {
            background: #1e1e1e;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        body.dark-mode .stat-number {
            color: #4a9eff;
        }
        
        body.dark-mode .stat-label {
            color: #a0a0a0;
        }
        
        body.dark-mode .highlight-box {
            background: #1e1e1e !important;
            border-left-color: #4a9eff !important;
        }
        
        body.dark-mode .highlight-box[style*="border-left-color: #dc3545"] {
            background: #2a1515 !important;
            border-left-color: #ff6b6b !important;
        }
        
        body.dark-mode .highlight-box p {
            color: #e0e0e0 !important;
        }
        
        body.dark-mode .highlight-box strong {
            color: #ff6b6b !important;
        }
        
        body.dark-mode .user-group-section {
            background: #1e1e1e;
        }
        
        body.dark-mode .user-group-section h3 {
            color: #4a9eff;
        }
        
        body.dark-mode .user-group-section[style*="border-left-color: #28a745"] h3 {
            color: #4ade80;
        }
        
        body.dark-mode .contact-info {
            background: #252525;
        }
        
        body.dark-mode section h2 {
            color: #e0e0e0;
        }
        
        body.dark-mode .about-box {
            background: #1e1e1e;
        }
        
        body.dark-mode .about-box p {
            color: #a0a0a0;
        }
        
        body.dark-mode .about-image {
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
        }
        
        body.dark-mode footer {
            border-top-color: #333;
            color: #a0a0a0;
        }
        
        body.dark-mode footer a {
            color: #4a9eff;
        }
        
        body.dark-mode code {
            background: #2a2a2a;
            color: #ff7eb6;
        }
        
        body.dark-mode ul {
            color: #e0e0e0;
        }
        
        body.dark-mode .homepage-airport-search-input {
            background: #1e1e1e;
            border-color: #333;
            color: #e0e0e0;
        }
        
        body.dark-mode .homepage-airport-search-input::placeholder {
            color: #707070;
        }
        
        body.dark-mode .homepage-airport-search-input:focus {
            border-color: #4a9eff;
            box-shadow: 0 0 0 3px rgba(74, 158, 255, 0.15);
        }
        
        body.dark-mode .homepage-airport-dropdown {
            background: #1e1e1e;
            border-color: #333;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
        }
        
        body.dark-mode .homepage-airport-item {
            border-bottom-color: #333;
        }
        
        body.dark-mode .homepage-airport-item:hover,
        body.dark-mode .homepage-airport-item.selected {
            background: #252525;
        }
        
        body.dark-mode .homepage-airport-item .airport-identifier {
            color: #4a9eff;
        }
        
        body.dark-mode .homepage-airport-item .airport-name {
            color: #a0a0a0;
        }
        
        /* Dark mode for "What We Need/What You Get" boxes */
        body.dark-mode div[style*="background: #f8f9fa"] {
            background: #1e1e1e !important;
        }
        
        body.dark-mode div[style*="background: #f8f9fa"] ul {
            color: #e0e0e0;
        }
        
        body.dark-mode div[style*="background: #f8f9fa"] li {
            color: #e0e0e0;
        }
        
        /* Dark mode for light blue info boxes (#f0f8ff) */
        body.dark-mode div[style*="background: #f0f8ff"] {
            background: #1a2a3a !important;
        }
        
        body.dark-mode div[style*="background: #f0f8ff"] p {
            color: #e0e0e0 !important;
        }
        
        body.dark-mode div[style*="background: #f0f8ff"] ul {
            color: #e0e0e0;
        }
        
        body.dark-mode div[style*="background: #f0f8ff"] li {
            color: #e0e0e0;
        }
        
        body.dark-mode div[style*="background: #f0f8ff"] h3 {
            color: #4a9eff !important;
        }
    </style>
</head>
<body>
    <script>
    // Sync dark-mode class from html to body
    if (document.documentElement.classList.contains('dark-mode')) {
        document.body.classList.add('dark-mode');
    }
    </script>
    <noscript>
        <style>
            html { scroll-behavior: auto; }
            body { margin: 0; padding: 0; }
        </style>
        <div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 0; padding: 1rem; margin: 0 0 1.5rem 0; text-align: center; color: #856404; font-size: 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.2); width: 100%; box-sizing: border-box;">
            <strong>⚠️ JavaScript is required</strong> for this site to function properly. Please enable JavaScript in your browser to view weather data and interactive features.
        </div>
    </noscript>
    <?php require_once __DIR__ . '/../lib/navigation.php'; ?>
    <main>
    <div class="container">
        <!-- Compact Hero Section -->
        <div class="hero">
            <h1 style="font-size: 2.5rem; margin-bottom: 0.75rem;">See the complete picture before you fly.</h1>
            <p style="font-size: 1.1rem; opacity: 0.95; margin-bottom: 1rem; max-width: 800px; margin-left: auto; margin-right: auto;">
                Verified cameras, quality-checked weather, and real-time data help pilots at smaller airports make safer decisions. Free, open source, community-maintained.
            </p>
            
            <!-- Inline Stats Badges -->
            <div style="display: flex; justify-content: center; gap: 2rem; margin: 1.5rem 0; flex-wrap: wrap;">
                <div style="text-align: center;">
                    <span style="font-size: 1.5rem; font-weight: bold; color: white;"><?= $totalAirports ?></span>
                    <span style="display: block; font-size: 0.85rem; opacity: 0.9; margin-top: 0.25rem;">Airports</span>
                </div>
                <div style="text-align: center;">
                    <span style="font-size: 1.5rem; font-weight: bold; color: white;"><?= $totalWebcams ?></span>
                    <span style="display: block; font-size: 0.85rem; opacity: 0.9; margin-top: 0.25rem;">Live Webcams</span>
                </div>
                <div style="text-align: center;">
                    <span style="font-size: 1.5rem; font-weight: bold; color: white;"><?= $totalWeatherStations ?></span>
                    <span style="display: block; font-size: 0.85rem; opacity: 0.9; margin-top: 0.25rem;">Weather Stations</span>
                </div>
                <div style="text-align: center;">
                    <span style="font-size: 1.5rem; font-weight: bold; color: white;"><?= formatMetricNumber($imagesProcessed24h) ?></span>
                    <span style="display: block; font-size: 0.85rem; opacity: 0.9; margin-top: 0.25rem;">Images Processed Today</span>
                </div>
                <div style="text-align: center;">
                    <span style="font-size: 1.5rem; font-weight: bold; color: white;"><?= formatMetricNumber($pilotsServedToday) ?></span>
                    <span style="display: block; font-size: 0.85rem; opacity: 0.9; margin-top: 0.25rem;">Pilots Served Today</span>
                </div>
            </div>
            
            <!-- Primary CTAs -->
            <div style="margin-top: 1.5rem; display: flex; justify-content: center; gap: 1rem; flex-wrap: wrap;">
                <a href="#for-airport-owners" class="btn-primary" style="font-size: 1.05rem; padding: 0.85rem 2rem;">Add Your Airport</a>
                <a href="https://airports.aviationwx.org" class="btn-secondary" style="font-size: 1.05rem; padding: 0.85rem 2rem;">Browse Live Dashboards</a>
            </div>
        </div>

        <!-- Why Data Quality Matters -->
        <section style="margin-top: 4rem;">
            <h2 style="text-align: center;">Why Multiple Data Sources Matter</h2>
            
            <!-- Real-World Problem -->
            <div class="highlight-box" style="border-left-color: #dc3545; background: #fff5f5; margin-bottom: 2rem;">
                <p><strong>Automated weather stations can miss critical details</strong></p>
                <p>
                    A CFI and student approached their home airport with ASOS reporting "7,000 ft ceiling, 10 miles visibility." Actual conditions? Dense fog - they couldn't see the runway and went missed on their approach.
                </p>
                <p style="margin-top: 1rem;">
                    We should never trust any one source of information without cross-referencing it to confirm its accuracy. Visual confirmation, when done right, provides a powerful cross-check for go/no-go decisions.
                </p>
                <p style="margin-top: 1rem; font-size: 0.9rem; font-style: italic;">
                    <a href="https://www.flyingmag.com/how-airport-cameras-save-pilots-from-bad-weather-data/" target="_blank" rel="noopener" style="color: #0066cc;">As Flying Magazine noted</a>: "Visual confirmation plays a critical role when automated systems are 'creatively optimistic.'"
                </p>
            </div>
            
            <!-- Layers of Safety -->
            <div class="feature-card" style="margin-bottom: 2rem;">
                <h3>Layers of Safety for Better ADM</h3>
                <p>No single data source prevents all incidents. Safe flying requires:</p>
                <ul style="margin: 1rem 0 1rem 1.5rem; line-height: 1.8;">
                    <li><strong>Multiple verified sources</strong> - ASOS/AWOS + cameras + local sensors</li>
                    <li><strong>Data quality checks</strong> - Timestamp verification, staleness detection, error frame filtering</li>
                    <li><strong>Visual confirmation</strong> - See actual conditions, not just numbers</li>
                    <li><strong>Accessibility for all airports</strong> - Not just towered or commercial fields</li>
                </ul>
                <p>AviationWX adds these layers to help pilots satisfy CFR 91.103 and make better aeronautical decisions.</p>
            </div>
            
            <!-- Key Benefits -->
            <div class="highlight-box" style="border-left-color: #0066cc; background: #f0f8ff;">
                <p><strong>What AviationWX Provides:</strong></p>
                <ul style="margin: 0.5rem 0 0 1.5rem; line-height: 1.8;">
                    <li>Live cameras verify automated weather reports</li>
                    <li>Quality checks catch stale or inaccurate data</li>
                    <li>Real-time updates from community weather stations</li>
                    <li>Free for pilots, free for airports - always</li>
                </ul>
            </div>
        </section>

        <!-- How It Works (Condensed) -->
        <section id="how-it-works" style="margin-top: 4rem;">
            <h2 style="text-align: center;">Simple, Sustainable, Community-Driven</h2>
            <p style="font-size: 1.05rem; line-height: 1.7; text-align: center; max-width: 900px; margin: 1rem auto;">
                <strong>Contact us</strong> with your airport information → <strong>We build and host</strong> your free dashboard → <strong>Pilots access it</strong> at <code>ICAO.aviationwx.org</code>. We handle all maintenance, integrate with your equipment, or guide your community through new installations.
            </p>
            <p style="margin-top: 1rem; text-align: center;">
                📚 <a href="https://guides.aviationwx.org" style="color: #0066cc; font-weight: 500;">Read detailed setup guides →</a>
            </p>
        </section>

        <section id="for-airport-owners" style="margin-top: 4rem;">
            <h2 style="text-align: center;">Built for Airports of All Sizes</h2>
            <p style="font-size: 1.05rem; text-align: center; margin-bottom: 2rem; color: #555;">Safety tools shouldn't depend on airport size or budget.</p>
            
            <div class="user-group-section" style="border-left-color: #28a745;">
                <h3 style="color: #28a745; text-align: center;">🏢 Add Your Airport - It's Free!</h3>
                
                <p><strong>The Problem:</strong> Smaller community airports often lack the budget for commercial weather services. Pilots want real-time data. Airport managers want to encourage safe operations. Traditional solutions are expensive and proprietary.</p>
                
                <p style="margin-top: 1.5rem;"><strong>Our Approach:</strong></p>
                <ul style="margin: 0.75rem 0 0 1.5rem; line-height: 1.8;">
                    <li><strong>Free dashboards</strong> hosted at <code>ICAO.aviationwx.org</code></li>
                    <li><strong>Works with equipment you own</strong> or helps you source affordable options</li>
                    <li><strong>Open-source code</strong> means the solution outlasts any single maintainer</li>
                    <li><strong>Community installations</strong> keep costs low and control local</li>
                    <li><strong>Data quality checks</strong> ensure accuracy (many systems skip this)</li>
                    <li><strong>SEO benefits</strong> - we link to your organization's website</li>
                </ul>
                
                <div class="features" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); margin-top: 2rem; gap: 1.5rem;">
                    <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; border-left: 3px solid #28a745;">
                        <h4 style="margin-top: 0; color: #28a745;">What We Need</h4>
                        <ul style="margin: 0.5rem 0 0 1.25rem; line-height: 1.7; font-size: 0.95rem;">
                            <li>Permission to partner with your airport</li>
                            <li>Existing cameras/sensors we can integrate, OR</li>
                            <li>Guidance for community-led installations (we provide recommendations)</li>
                        </ul>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; border-left: 3px solid #28a745;">
                        <h4 style="margin-top: 0; color: #28a745;">What You Get</h4>
                        <ul style="margin: 0.5rem 0 0 1.25rem; line-height: 1.7; font-size: 0.95rem;">
                            <li>Free weather dashboard with data integrity checks</li>
                            <li>We handle all hosting, software updates, maintenance</li>
                            <li>Equipment ownership stays with your airport</li>
                            <li>Setup guides and ongoing support</li>
                        </ul>
                    </div>
                </div>
                
                <?php
                $ownerEmailSubject = rawurlencode("Request to add airport to AviationWX.org");
                $ownerEmailBody = encodeEmailBody("Hello AviationWX.org team,

I'm interested in adding my airport to the AviationWX.org network.

Airport Code: [Please provide]
Airport Name: [Please provide]
Your Name: [Please provide]
Your Role: [e.g., Airport manager, Pilot, Community volunteer]

Do you have existing equipment?
- [ ] Yes - webcam(s) and/or weather station already installed
- [ ] No - starting fresh, will need equipment recommendations

Brief description of your situation:
[Please describe - existing setup, or what you're hoping to achieve]

I've reviewed the installation guides at guides.aviationwx.org:
- [ ] Yes
- [ ] Not yet

Best regards,
[Your name]");
                ?>
                <p style="margin-top: 2rem; text-align: center;">
                    <a href="mailto:contact@aviationwx.org?subject=<?= $ownerEmailSubject ?>&body=<?= $ownerEmailBody ?>" class="btn-primary" style="background: #28a745; border-color: #28a745; font-size: 1.05rem; padding: 0.85rem 2rem;">
                        📧 Get Started - Send Airport Information
                    </a>
                </p>
                <p style="margin-top: 0.5rem; font-size: 0.9rem; color: #666; text-align: center;">
                    Opens your email client with a template
                </p>
                <p style="margin-top: 1rem; text-align: center;">
                    <a href="https://guides.aviationwx.org" style="color: #0066cc; text-decoration: none; font-size: 0.95rem; font-weight: 500;">
                        📚 Read Setup & Equipment Guides →
                    </a>
                </p>
            </div>
        </section>

        <!-- For Pilots & CFIs -->
        <section style="margin-top: 4rem;">
            <h2 style="text-align: center;">Better Data for Better Decisions</h2>
            
            <div class="features" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
                <!-- For Pilots -->
                <div class="user-group-section" style="border-left-color: #0066cc;">
                    <h3 style="color: #0066cc;"><img src="<?= $baseUrl ?>/public/favicons/android-chrome-192x192.png" alt="" style="vertical-align: middle; margin-right: 0.5rem; width: 24px; height: 24px; background: transparent;"> For Pilots</h3>
                    <p><strong>Use AviationWX dashboards to:</strong></p>
                    <ul style="margin: 0.75rem 0 0 1.5rem; line-height: 1.7; font-size: 0.95rem;">
                        <li>Verify ASOS/AWOS reports with live cameras before departure</li>
                        <li>Build a complete picture with multiple data sources</li>
                        <li>Check diversion airports in real-time during flight</li>
                        <li>Satisfy CFR 91.103 requirements (all available information)</li>
                        <li>See actual visibility conditions, not just reported numbers</li>
                    </ul>
                    <p style="margin-top: 1rem; font-size: 0.95rem;">
                        Our data quality checks catch stale timestamps, incomplete uploads, and sensor errors - helping you trust what you see.
                    </p>
                </div>
                
                <!-- For CFIs -->
                <div class="user-group-section" style="border-left-color: #0066cc;">
                    <h3 style="color: #0066cc;">📚 For CFIs & Flight Schools</h3>
                    <p style="font-style: italic; margin-bottom: 1rem;">
                        "CFIs love this tool - it helps teach student pilots how to make smart weather decisions." <br/>
                        <span style="font-size: 0.9rem;">- Flying Magazine</span>
                    </p>
                    <p><strong>Use AviationWX to demonstrate:</strong></p>
                    <ul style="margin: 0.75rem 0 0 1.5rem; line-height: 1.7; font-size: 0.95rem;">
                        <li>How to verify automated weather</li>
                        <li>The importance of multiple data sources in ADM</li>
                        <li>Real-world go/no-go decision-making</li>
                        <li>The difference between reported and actual conditions</li>
                    </ul>
                    <p style="margin-top: 1rem; font-size: 0.95rem; font-weight: 500;">
                        Share AviationWX with fellow pilots and airport owners to help grow the safety network. The more airports participate, the safer GA becomes.
                    </p>
                </div>
            </div>
        </section>

        <!-- Data Quality & Verification -->
        <section style="margin-top: 4rem;">
            <h2 style="text-align: center;">Verified Data You Can Trust</h2>
            <p style="font-size: 1.05rem; text-align: center; margin-bottom: 2rem; color: #555;">Built-in quality checks that commercial systems often skip</p>
            
            <div class="features" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
                <div class="feature-card">
                    <h3>🔍 Timestamp Validation</h3>
                    <p>Rejects stale data from delayed uploads or stuck sensors. Shows data age clearly.</p>
                </div>
                
                <div class="feature-card">
                    <h3>📸 Camera Quality Checks</h3>
                    <p>Detects incomplete uploads, corrupt images, and error frames before display.</p>
                </div>
                
                <div class="feature-card">
                    <h3>🌡️ Sensor Verification</h3>
                    <p>Confirms weather station connectivity and validates reasonable sensor values.</p>
                </div>
                
                <div class="feature-card">
                    <h3>⏱️ Staleness Detection</h3>
                    <p>Warns when information is outdated instead of silently showing old data.</p>
                </div>
                
                <div class="feature-card">
                    <h3>🔄 Multi-Source Comparison</h3>
                    <p>Cross-checks METAR, local sensors, and cameras for consistency.</p>
                </div>
                
                <div class="feature-card">
                    <h3>🔓 Transparent Failures</h3>
                    <p>When data fails checks, we show it clearly instead of hiding problems.</p>
                </div>
            </div>
            
            <div class="highlight-box" style="border-left-color: #0066cc; background: #f0f8ff; margin-top: 2rem;">
                <p><strong>Why This Matters:</strong></p>
                <p>Bad data leads to bad decisions. Our open-source approach means the community can audit, improve, and trust the verification logic. Transparency helps pilots make informed decisions.</p>
            </div>
        </section>

        <script>
        (function() {
            'use strict';
            
            function changePage(page) {
                const url = new URL(window.location);
                url.searchParams.set('page', page);
                window.location.href = url.toString();
            }
            
            // Expose to global scope for onclick handlers
            window.changePage = changePage;
        })();
        </script>

        <!-- Participating Airports -->
        <section id="participating-airports" style="margin-top: 4rem;">
            <h2 style="text-align: center;">Live Airport Dashboards</h2>
            <p style="font-size: 1.05rem; text-align: center; margin-bottom: 1rem;">
                Explore live weather dashboards at participating airports. Each dashboard includes verified cameras, real-time weather, and data quality checks.
            </p>
            <p style="text-align: center; margin-bottom: 2rem;">
                🗺️ <a href="https://airports.aviationwx.org" style="color: #0066cc; font-weight: 500; font-size: 1.05rem;">View All Airports on Interactive Map →</a>
            </p>
            
            <?php if ($totalAirports > 0 && file_exists($configFile)): ?>
            <?php
            $envConfigPath = getenv('CONFIG_PATH');
            $configFileForList = ($envConfigPath && file_exists($envConfigPath)) ? $envConfigPath : (__DIR__ . '/../config/airports.json');
            $config = json_decode(file_get_contents($configFileForList), true);
            // Only show listed airports (excludes unlisted airports from display)
            $airports = getListedAirports($config ?? []);
            $airportsPerPage = 9;
            $currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $totalPages = max(1, ceil(count($airports) / $airportsPerPage));
            $currentPage = min($currentPage, $totalPages);
            $startIndex = ($currentPage - 1) * $airportsPerPage;
            $airportsOnPage = array_slice($airports, $startIndex, $airportsPerPage, true);
                
                // Always reads fresh from disk (no PHP-level caching) to ensure up-to-date data
                function getAirportWeather($airportId) {
                    $cacheFile = getWeatherCachePath($airportId);
                    if (file_exists($cacheFile)) {
                        // Clear opcache to ensure fresh read
                        if (function_exists('opcache_invalidate')) {
                            @opcache_invalidate($cacheFile, true);
                        }
                        $cacheData = json_decode(file_get_contents($cacheFile), true);
                        // Cache file stores weather data directly (not wrapped in 'weather' key)
                        if ($cacheData && is_array($cacheData)) {
                            $cacheData['_cache_file_mtime'] = filemtime($cacheFile);
                            return $cacheData;
                        }
                    }
                    return null;
                }
                
                function formatRelativeTime($timestamp) {
                    if (!$timestamp || $timestamp <= 0) return 'Unknown';
                    $diff = time() - $timestamp;
                    if ($diff < 60) return 'Just now';
                    if ($diff < 3600) return floor($diff / 60) . 'm ago';
                    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
                    return floor($diff / 86400) . 'd ago';
                }
                
                // Uses observation timestamps from weather sources (not file modification time)
                function getNewestDataTimestamp($weather) {
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
                    
                    // Prefer observation times over fetch times for accuracy
                    if (isset($weather['obs_time_primary']) && $weather['obs_time_primary'] > 0) {
                        $timestamps[] = $weather['obs_time_primary'];
                    }
                    if (isset($weather['obs_time_metar']) && $weather['obs_time_metar'] > 0) {
                        $timestamps[] = $weather['obs_time_metar'];
                    }
                    
                    // Fallback to general last_updated if no source-specific timestamps
                    if (empty($timestamps) && isset($weather['last_updated']) && $weather['last_updated'] > 0) {
                        $timestamps[] = $weather['last_updated'];
                    }
                    
                    return !empty($timestamps) ? max($timestamps) : null;
                }
                
                // Load weather utilities once before the loop
                require_once __DIR__ . '/../lib/weather/utils.php';
                ?>
                <div class="airports-list">
                    <?php foreach ($airportsOnPage as $airportId => $airport): 
                        $url = 'https://' . $airportId . '.aviationwx.org';
                        $hasMetar = isMetarEnabled($airport);
                        // Fetch weather if airport has any weather sources configured
                        $hasAnyWeather = hasWeatherSources($airport);
                        $weather = $hasAnyWeather ? getAirportWeather($airportId) : [];
                        $flightCategory = $weather['flight_category'] ?? null;
                        $temperature = $weather['temperature_f'] ?? $weather['temperature'] ?? null;
                        if ($temperature !== null && $temperature < 50 && !isset($weather['temperature_f'])) {
                            $temperature = ($temperature * 9/5) + 32;
                        }
                        $windSpeed = $weather['wind_speed'] ?? null;
                        $windDirection = $weather['wind_direction'] ?? null;
                        $newestTimestamp = getNewestDataTimestamp($weather);
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
                                    <div class="metric-label">Current Temp</div>
                                    <div class="metric-value">
                                        <?= $temperature !== null ? htmlspecialchars(round($temperature)) . '°F' : '--' ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($hasMetar): ?>
                                <div class="metric">
                                    <div class="metric-label">Temperature</div>
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
                                        <?= htmlspecialchars(formatRelativeTime($newestTimestamp)) ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if (count($airports) > $airportsPerPage): ?>
                <div class="pagination">
                    <button onclick="changePage(<?= $currentPage - 1 ?>)" <?= $currentPage <= 1 ? 'disabled' : '' ?>>
                        Previous
                    </button>
                    <span class="pagination-info">
                        Page <?= $currentPage ?> of <?= $totalPages ?>
                    </span>
                    <?php
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $currentPage + 2);
                    
                    if ($startPage > 1) {
                        echo '<button onclick="changePage(1)">1</button>';
                        if ($startPage > 2) echo '<span>...</span>';
                    }
                    
                    for ($i = $startPage; $i <= $endPage; $i++) {
                        $active = $i === $currentPage ? 'active' : '';
                        echo '<button class="' . $active . '" onclick="changePage(' . $i . ')">' . $i . '</button>';
                    }
                    
                    if ($endPage < $totalPages) {
                        if ($endPage < $totalPages - 1) echo '<span>...</span>';
                        echo '<button onclick="changePage(' . $totalPages . ')">' . $totalPages . '</button>';
                    }
                    ?>
                    <button onclick="changePage(<?= $currentPage + 1 ?>)" <?= $currentPage >= $totalPages ? 'disabled' : '' ?>>
                        Next
                    </button>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <p style="text-align: center; color: #555; padding: 2rem;">No airports currently configured.</p>
                <?php endif; ?>
        </section>

        <!-- Supported Equipment (Condensed) -->
        <section style="margin-top: 4rem;">
            <h2 style="text-align: center;">Works With What You Have</h2>
            <p style="font-size: 1.05rem; text-align: center; margin-bottom: 2rem; color: #555;">
                We integrate with existing equipment or help you choose affordable options
            </p>
            
            <div class="features" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
                <div class="feature-card">
                    <h3 style="color: #0066cc;">🌡️ Weather Stations</h3>
                    <p><strong>Tempest, Ambient Weather, WeatherLink, PWSWeather.com, METAR</strong></p>
                    <p style="margin-top: 0.5rem; font-size: 0.95rem; color: #666;">Live data from personal weather stations or official observations</p>
                </div>
                
                <div class="feature-card">
                    <h3 style="color: #0066cc;">📹 Cameras</h3>
                    <p><strong>Reolink, Axis, Hikvision, Dahua, Amcrest</strong></p>
                    <p style="margin-top: 0.5rem; font-size: 0.95rem; color: #666;">FTP push or RTSP pull protocols supported</p>
                </div>
                
                <div class="feature-card">
                    <h3 style="color: #0066cc;">🔧 Integration</h3>
                    <p><strong>FTP, RTSP, API</strong></p>
                    <p style="margin-top: 0.5rem; font-size: 0.95rem; color: #666;">If your equipment isn't listed, we can likely add support</p>
                </div>
            </div>
            
            <p style="text-align: center; margin-top: 2rem;">
                <strong>Open-source means adaptability.</strong> Need support for new equipment? The community can add it.
            </p>
            <p style="text-align: center; margin-top: 1rem;">
                🔧 <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener" style="color: #0066cc; font-weight: 500;">View Technical Details & Compatibility →</a>
            </p>
        </section>

        <!-- Open Source for the Long Term -->
        <section id="about-the-project" style="margin-top: 4rem;">
            <h2 style="text-align: center;">Built to Last, Built to Adapt</h2>
            
            <div class="about-box" style="background: #f0f8ff; border-left-color: #0066cc;">
                <h3 style="color: #0066cc; margin-top: 0;">Why Open Source Matters</h3>
                <p>Proprietary weather services come and go. Subscription models make smaller airports choose between cost and safety. Open-source infrastructure means:</p>
                <ul style="margin: 1rem 0 0 2rem; line-height: 1.8;">
                    <li>The code outlasts any single maintainer</li>
                    <li>Communities can self-host if needed</li>
                    <li>Anyone can audit data quality logic</li>
                    <li>Solutions adapt as technology and aviation needs evolve</li>
                    <li>No vendor lock-in or surprise price changes</li>
                </ul>
            </div>
            
            <picture>
                <?php
                $aboutPhotoWebpPath = __DIR__ . '/../public/images/about-photo.webp';
                $aboutPhotoJpgPath = __DIR__ . '/../public/images/about-photo.jpg';
                if (file_exists($aboutPhotoWebpPath)): ?>
                    <source srcset="/public/images/about-photo.webp" type="image/webp">
                <?php endif; ?>
                <img src="/public/images/about-photo.jpg" alt="AviationWX - Built for pilots, by pilots" class="about-image">
            </picture>
            
            <div class="about-box">
                <h3 style="color: #0066cc;">About the Project</h3>
                <p>
                    <strong>AviationWX.org</strong> is maintained by <strong>Alex Witherspoon</strong>, a pilot dedicated to helping fellow aviators make safer flight decisions through better, more accessible weather information.
                </p>
                <p style="margin-top: 1rem;">
                    Some of the best flying can take us into small airports or grass strips that don't always come with infrastructure to help us make smart calls. While I've seen many great implementations, we often have to re-invent the wheel to display that information for pilots to use. I was particularly inspired by <a href="http://www.twinoakswx.com" target="_blank" rel="noopener">Twin Oaks Airpark's dashboard</a>, and wanted to make something this simple available for all airports.
                </p>
                <p style="margin-top: 1rem;">
                    This service is provided <strong>free of charge</strong> to the aviation community, including all upkeep and maintenance costs. The project is entirely open source, ensuring that if Alex is unable to continue the effort, others can step in to maintain and improve it.
                </p>
                <p style="margin-top: 1rem;">
                    Built for pilots, airport operators, and the entire aviation community.
                </p>
            </div>
            
            <div class="about-box" style="margin-top: 2rem; border-top: 3px solid #0066cc;">
                <h3 style="color: #0066cc; margin-top: 0;">💻 Open Source Projects</h3>
                <p>AviationWX is fully open source and welcomes contributions from the community.</p>
                
                <div class="features" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); margin-top: 1.5rem; gap: 1.5rem;">
                    <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; border-left: 3px solid #0066cc;">
                        <h4 style="margin-top: 0; color: #0066cc;">🛬 AviationWX Platform</h4>
                        <p style="font-size: 0.95rem;">Weather dashboard system with data quality checks, multi-source integration, and responsive design.</p>
                        <div style="margin-top: 1rem;">
                            <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener" style="color: #0066cc; font-weight: 500; font-size: 0.9rem;">View on GitHub →</a> | 
                            <a href="https://github.com/alexwitherspoon/aviationwx.org/blob/main/CONTRIBUTING.md" target="_blank" rel="noopener" style="color: #0066cc; font-weight: 500; font-size: 0.9rem;">Contributing Guidelines →</a>
                        </div>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; border-left: 3px solid #0066cc;">
                        <h4 style="margin-top: 0; color: #0066cc;">📷 AviationWX Bridge</h4>
                        <p style="font-size: 0.95rem;">Camera integration tool for Axis, Reolink, and other RTSP-capable cameras to work with the platform.</p>
                        <div style="margin-top: 1rem;">
                            <a href="https://github.com/alexwitherspoon/aviationwx.org-bridge" target="_blank" rel="noopener" style="color: #0066cc; font-weight: 500; font-size: 0.9rem;">View on GitHub →</a>
                        </div>
                    </div>
                </div>
                
                <p style="margin-top: 1.5rem; font-size: 0.95rem; color: #666; text-align: center;">
                    Deep technical documentation lives in the GitHub repositories.
                </p>
            </div>
            
            <div class="about-box" style="margin-top: 2rem; border-top: 3px solid #28a745;">
                <h3 style="color: #28a745; margin-top: 0;">Support the Project (Optional)</h3>
                <p>
                    If you'd like to support this project financially, that's wonderful and greatly appreciated! However, <strong>donations are completely optional</strong> - AviationWX will always remain free to use for everyone in the aviation community.
                </p>
                <p style="margin-top: 1rem;">
                    Donations help cover hosting costs for all airport dashboards, server infrastructure for weather data processing, ongoing development of new features and equipment support, and maintenance and data integrity improvements.
                </p>
                <p style="margin-top: 1rem;">
                    Every contribution keeps this service free for the entire GA community. You can sponsor this project through <a href="https://github.com/sponsors/alexwitherspoon" target="_blank" rel="noopener" style="color: #28a745; font-weight: 500;">GitHub Sponsors</a>.
                </p>
                <div style="text-align: center; margin-top: 1.5rem;">
                    <a href="https://github.com/sponsors/alexwitherspoon" class="btn-primary" target="_blank" rel="noopener" style="background: #28a745; border-color: #28a745;">
                        Support on GitHub Sponsors
                    </a>
                </div>
            </div>
            
            <div class="about-box" style="margin-top: 2rem; border-top: 3px solid #0066cc;" id="contact">
                <h3 style="color: #0066cc; margin-top: 0;">Get in Touch</h3>
                <p>We'd love to hear from you! Whether you've found a bug, have a feature suggestion, want to contribute, or just want to say hello.</p>
                <ul style="margin: 1rem 0 0 2rem;">
                    <li><strong>Email:</strong> <a href="mailto:contact@aviationwx.org">contact@aviationwx.org</a></li>
                    <li><strong>GitHub Issues:</strong> <a href="https://github.com/alexwitherspoon/aviationwx.org/issues" target="_blank" rel="noopener">Report bugs or request features</a></li>
                    <li><strong>Contribute:</strong> <a href="https://github.com/alexwitherspoon/aviationwx.org/blob/main/CONTRIBUTING.md" target="_blank" rel="noopener">Contributing guidelines</a></li>
                </ul>
            </div>
        </section>

        <footer class="footer">
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
    </div>
    </main>
</body>
</html>
