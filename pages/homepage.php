<?php
// Prevent caching of homepage to ensure fresh data on each visit
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Load SEO utilities and config (for getGitSha function)
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/seo.php';
require_once __DIR__ . '/../lib/cache-paths.php';

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
            
            // Count primary weather sources (non-METAR types with unique identifiers)
            if (isset($airport['weather_source']) && is_array($airport['weather_source'])) {
                $source = $airport['weather_source'];
                $type = $source['type'] ?? '';
                
                // Build unique identifier based on source type
                if ($type === 'tempest' && isset($source['station_id'])) {
                    $uniqueWeatherStations['tempest_' . $source['station_id']] = true;
                } elseif ($type === 'ambient' && isset($source['mac_address'])) {
                    $uniqueWeatherStations['ambient_' . $source['mac_address']] = true;
                } elseif ($type === 'weatherlink' && isset($source['station_id'])) {
                    $uniqueWeatherStations['weatherlink_' . $source['station_id']] = true;
                } elseif ($type === 'pwsweather' && isset($source['station_id'])) {
                    $uniqueWeatherStations['pwsweather_' . $source['station_id']] = true;
                }
                // Note: type 'metar' uses metar_station field, counted below
            }
            
            // Count unique METAR stations
            if (isset($airport['metar_station']) && !empty($airport['metar_station'])) {
                $uniqueWeatherStations['metar_' . $airport['metar_station']] = true;
            }
        }
        
        $totalWeatherStations = count($uniqueWeatherStations);
    }
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
            padding: 4rem 2rem;
            text-align: center;
            margin: -1rem -1rem 3rem -1rem;
            box-sizing: border-box;
            max-width: calc(100% + 2rem);
        }
        .hero h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .hero p {
            font-size: 1.2rem;
            opacity: 0.95;
            max-width: 700px;
            margin: 0 auto 1rem;
        }
        .hero .subtitle {
            font-size: 0.95rem;
            opacity: 0.85;
            font-style: italic;
            margin-top: 0.5rem;
        }
        .hero .volunteer-note {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.2);
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
        <div class="hero">
            <h1><img src="<?= $baseUrl ?>/public/favicons/android-chrome-192x192.png" alt="AviationWX" style="vertical-align: middle; margin-right: 0.5rem; width: 76px; height: 76px; background: transparent;"> AviationWX.org</h1>
            <p style="font-size: 1.4rem; font-weight: 500; margin-bottom: 1rem;">
                Reduce general aviation incidents, promote safety, and ensure accessible solutions for smaller airports and aviators.
            </p>
            <p style="font-size: 1.1rem; opacity: 0.95; margin-bottom: 2rem;">
                Free weather dashboards with real-time webcams and weather data. We host the dashboard and integrate with your existing cameras and sensors, or guide your community through new installations.
            </p>
            <div class="btn-group" style="margin-top: 1.5rem;">
                <a href="#for-airport-owners" class="btn-primary" style="font-size: 1.1rem; padding: 1rem 2.5rem;">Add Your Airport</a>
                <a href="#participating-airports" class="btn-secondary" style="font-size: 1.1rem; padding: 1rem 2.5rem;">View Airports</a>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?= $totalAirports ?></div>
                <div class="stat-label">Participating Airports</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $totalWebcams ?></div>
                <div class="stat-label">Live Webcams</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $totalWeatherStations ?></div>
                <div class="stat-label">Weather Stations</div>
            </div>
        </div>

        <section>
            <h2>Why This Matters</h2>
            <div class="highlight-box" style="border-left-color: #dc3545; background: #fff5f5;">
                <p><strong>Safety First</strong></p>
                <p>
                    Weather-related factors contribute to a significant portion of general aviation accidents. Real-time webcams and weather data help pilots see actual visibility conditions and make safer go/no-go decisions. CFIs use these tools to teach student pilots how to make smart weather decisions.
                </p>
                
                <p style="margin-top: 1.5rem;"><strong>Economic Impact</strong></p>
                <p>
                    Tools like this encourage airport use, bringing more pilots to your airport and supporting local economic activity.
                </p>
                
                <p style="margin-top: 1.5rem;"><strong>Community & Culture</strong></p>
                <p>
                    Better weather information brings positive attention to the general aviation community, supports airport organizations, and strengthens aviation culture.
                </p>
                
                <p style="margin-top: 1.5rem;">
                    <strong>It's completely free</strong> - for the airport, for pilots, always. No fees, no subscriptions, no ads.
                </p>
            </div>
        </section>

        <section id="how-it-works">
            <h2>How It Works</h2>
            <div class="features" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                <div class="feature-card">
                    <h3>1. Contact Us</h3>
                    <p>Reach out via email with your airport information. We'll discuss your existing equipment or what's needed for new setup.</p>
                </div>
                <div class="feature-card">
                    <h3>2. We Build Your Dashboard</h3>
                    <p>We create and host your weather dashboard. We integrate with your existing equipment or guide your community through new sensor installations.</p>
                </div>
                <div class="feature-card">
                    <h3>3. Your Airport Goes Live</h3>
                    <p>Pilots can start using your dashboard immediately at <code>ICAO.aviationwx.org</code>. We handle all ongoing maintenance.</p>
                </div>
            </div>
        </section>

        <section id="for-airport-owners">
            <h2>For Airport Owners, Operators & Organizations</h2>
            <div class="user-group-section" style="border-left-color: #28a745;">
                <h3 style="color: #28a745; text-align: center;">🏢 Add Your Airport - It's Free & Easy!</h3>
                <p><strong>Webcams and weather stations are useful, but can be expensive and a pain to operate.</strong> Some profit-driven services make it hard to bring this safety net to smaller airports in a sustainable way. METAR and other systems are good, but often aren't timely enough.</p>
                
                <p style="margin-top: 1rem;"><strong>Safety at your airport saves lives, saves property, and makes your airport more useful to a broader range of aviators.</strong></p>
                
                <p style="margin-top: 1.5rem;"><strong>What we need:</strong></p>
                <ul>
                    <li>Permission to partner with the AviationWX.org project</li>
                    <li>Existing webcam and weather equipment we can integrate with, or local equipment installed by your community (we provide recommendations and guidance)</li>
                </ul>
                
                <p style="margin-top: 1.5rem;"><strong>What you get:</strong></p>
                <ul>
                    <li>Free weather dashboard at <code>ICAO.aviationwx.org</code></li>
                    <li>We handle all dashboard hosting and software maintenance</li>
                    <li>Equipment recommendations and installation guidance</li>
                    <li>Equipment ownership stays with the airport</li>
                    <li>SEO benefits - we link to your organization's website to help drive traffic</li>
                </ul>
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
                <p style="margin-top: 1.5rem; text-align: center;">
                    <a href="mailto:contact@aviationwx.org?subject=<?= $ownerEmailSubject ?>&body=<?= $ownerEmailBody ?>" class="btn-primary" style="background: #28a745; border-color: #28a745; font-size: 1.1rem; padding: 1rem 2rem;">
                        📧 Get Started - Send Setup Information
                    </a>
                </p>
                <p style="margin-top: 0.5rem; font-size: 0.9rem; color: #666; text-align: center;">
                    Opens your email client with a template you can fill out with your airport's information
                </p>
                <p style="margin-top: 1rem; text-align: center;">
                    <a href="https://guides.aviationwx.org" style="color: #0066cc; text-decoration: none; font-size: 0.95rem;">
                        📚 Read setup guides and documentation →
                    </a>
                </p>
            </div>
        </section>

        <section>
            <h2>What You Get</h2>
            <p>Each airport dashboard provides real-time, localized weather data designed for pilots making flight decisions:</p>
            
            <div class="features">
                <div class="feature-card">
                    <h3>🌡️ Real-Time Weather</h3>
                    <p>Live data from on-site weather stations (Tempest, Ambient Weather, WeatherLink, PWSWeather.com) or METAR observations.</p>
                </div>
                
                <div class="feature-card">
                    <h3>📹 Multiple Webcams</h3>
                    <p>Visual conditions with strategically positioned webcams showing current airport conditions.</p>
                </div>
                
                <div class="feature-card">
                    <h3>🎬 Time-lapse History</h3>
                    <p>Review recent conditions with the webcam history player. Shareable URLs and kiosk mode for airport signage displays.</p>
                </div>
                
                <div class="feature-card">
                    <h3>🧭 Wind Visualization</h3>
                    <p>Interactive runway wind diagram with wind speed, direction, and crosswind components.</p>
                </div>
                
                <div class="feature-card">
                    <h3><img src="<?= $baseUrl ?>/public/favicons/android-chrome-192x192.png" alt="" style="vertical-align: middle; margin-right: 0.5rem; width: 24px; height: 24px; background: transparent;"> Aviation Metrics</h3>
                    <p>Density altitude, pressure altitude, VFR/IFR status, and other critical pilot information.</p>
                </div>
                
                <div class="feature-card">
                    <h3>📱 Mobile & Desktop</h3>
                    <p>Lightweight, quick-loading website optimized for mobile and desktop. Fast access when you need it most.</p>
                </div>
                
                <div class="feature-card">
                    <h3>🔗 Embed Generator</h3>
                    <p>Add weather widgets to your website, WordPress, or Google Sites. <a href="https://embed.aviationwx.org" style="color: #0066cc;">Create Embed →</a></p>
                </div>
                
                <div class="feature-card">
                    <h3>📡 Public API</h3>
                    <p>Access weather, webcams, and 24-hour history programmatically. <a href="https://api.aviationwx.org" style="color: #0066cc;">API Documentation →</a></p>
                </div>
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
        
        <?php
        // Prepare all airports for homepage search
        $homepageSearchAirports = [];
        if (isset($enabledAirports) && is_array($enabledAirports)) {
            foreach ($enabledAirports as $searchAirportId => $searchAirport) {
                $searchPrimaryIdentifier = getPrimaryIdentifier($searchAirportId, $searchAirport);
                $homepageSearchAirports[] = [
                    'id' => $searchAirportId,
                    'name' => $searchAirport['name'] ?? '',
                    'identifier' => $searchPrimaryIdentifier,
                    'icao' => $searchAirport['icao'] ?? '',
                    'iata' => $searchAirport['iata'] ?? '',
                    'faa' => $searchAirport['faa'] ?? ''
                ];
            }
        }
        ?>
        <script>
        (function() {
            'use strict';
            
            // Airport data for search
            var HOMEPAGE_AIRPORTS = <?= json_encode($homepageSearchAirports) ?>;
            var BASE_DOMAIN = <?= json_encode(getBaseDomain()) ?>;
            
            function initHomepageSearch() {
                var searchInput = document.getElementById('homepage-airport-search');
                var dropdown = document.getElementById('homepage-airport-dropdown');
                var selectedIndex = -1;
                var searchTimeout = null;
                
                if (!searchInput || !dropdown) return;
            
            // Navigate to airport subdomain
            function navigateToAirport(airportId) {
                var protocol = window.location.protocol;
                var newUrl = protocol + '//' + airportId.toLowerCase() + '.' + BASE_DOMAIN;
                window.location.href = newUrl;
            }
            
            // Search airports
            function searchAirports(query) {
                if (!query || query.length < 2) {
                    return [];
                }
                
                var queryLower = query.toLowerCase().trim();
                var results = [];
                
                for (var i = 0; i < HOMEPAGE_AIRPORTS.length; i++) {
                    var airport = HOMEPAGE_AIRPORTS[i];
                    var nameMatch = airport.name.toLowerCase().indexOf(queryLower) !== -1;
                    var icaoMatch = airport.icao && airport.icao.toLowerCase().indexOf(queryLower) !== -1;
                    var iataMatch = airport.iata && airport.iata.toLowerCase().indexOf(queryLower) !== -1;
                    var faaMatch = airport.faa && airport.faa.toLowerCase().indexOf(queryLower) !== -1;
                    var identifierMatch = airport.identifier.toLowerCase().indexOf(queryLower) !== -1;
                    
                    if (nameMatch || icaoMatch || iataMatch || faaMatch || identifierMatch) {
                        results.push(airport);
                    }
                }
                
                // Sort: exact matches first, then by name
                results.sort(function(a, b) {
                    var aExact = a.identifier.toLowerCase() === queryLower || 
                                (a.icao && a.icao.toLowerCase() === queryLower) ||
                                (a.iata && a.iata.toLowerCase() === queryLower);
                    var bExact = b.identifier.toLowerCase() === queryLower || 
                                (b.icao && b.icao.toLowerCase() === queryLower) ||
                                (b.iata && b.iata.toLowerCase() === queryLower);
                    
                    if (aExact && !bExact) return -1;
                    if (!aExact && bExact) return 1;
                    
                    return a.name.localeCompare(b.name);
                });
                
                return results.slice(0, 10);
            }
            
            // Populate dropdown with results
            function populateDropdown(results) {
                dropdown.innerHTML = '';
                
                if (results.length === 0) {
                    var noResults = document.createElement('div');
                    noResults.className = 'homepage-airport-item no-results';
                    noResults.textContent = 'No airports found';
                    dropdown.appendChild(noResults);
                } else {
                    for (var i = 0; i < results.length; i++) {
                        (function(index) {
                            var airport = results[index];
                            var item = document.createElement('a');
                            item.href = '#';
                            item.className = 'homepage-airport-item';
                            item.dataset.airportId = airport.id;
                            item.dataset.index = index;
                            
                            var identifier = document.createElement('span');
                            identifier.className = 'airport-identifier';
                            identifier.textContent = airport.identifier;
                            
                            var name = document.createElement('span');
                            name.className = 'airport-name';
                            name.textContent = airport.name;
                            
                            item.appendChild(identifier);
                            item.appendChild(name);
                            
                            item.addEventListener('click', function(e) {
                                e.preventDefault();
                                navigateToAirport(airport.id);
                            });
                            
                            item.addEventListener('mouseenter', function() {
                                selectedIndex = index;
                                updateSelection();
                            });
                            
                            dropdown.appendChild(item);
                        })(i);
                    }
                }
                
                dropdown.classList.add('show');
                selectedIndex = -1;
            }
            
            function updateSelection() {
                var items = dropdown.querySelectorAll('.homepage-airport-item');
                for (var i = 0; i < items.length; i++) {
                    if (i === selectedIndex) {
                        items[i].classList.add('selected');
                    } else {
                        items[i].classList.remove('selected');
                    }
                }
            }
            
            function performSearch(query) {
                if (!query || query.length < 2) {
                    dropdown.classList.remove('show');
                    return;
                }
                
                var results = searchAirports(query);
                populateDropdown(results);
            }
            
            // Event handlers
            searchInput.addEventListener('input', function(e) {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    performSearch(e.target.value);
                }, 200);
            });
            
            searchInput.addEventListener('focus', function() {
                if (searchInput.value.length >= 2) {
                    performSearch(searchInput.value);
                }
            });
            
            searchInput.addEventListener('keydown', function(e) {
                var items = dropdown.querySelectorAll('.homepage-airport-item:not(.no-results)');
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (items.length > 0) {
                        selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                        updateSelection();
                        items[selectedIndex].scrollIntoView({ block: 'nearest' });
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (items.length > 0) {
                        selectedIndex = Math.max(selectedIndex - 1, 0);
                        updateSelection();
                        items[selectedIndex].scrollIntoView({ block: 'nearest' });
                    }
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (selectedIndex >= 0 && selectedIndex < items.length) {
                        var airportId = items[selectedIndex].dataset.airportId;
                        if (airportId) {
                            navigateToAirport(airportId);
                        }
                    } else if (items.length === 1) {
                        var airportId = items[0].dataset.airportId;
                        if (airportId) {
                            navigateToAirport(airportId);
                        }
                    }
                } else if (e.key === 'Escape') {
                    dropdown.classList.remove('show');
                    searchInput.blur();
                }
            });
            
                // Close dropdown when clicking outside
                document.addEventListener('click', function(e) {
                    if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                        dropdown.classList.remove('show');
                    }
                });
            }
            
            // Initialize when DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initHomepageSearch);
            } else {
                initHomepageSearch();
            }
        })();
        </script>

        <!-- For Pilots -->
        <section>
            <h2>For Pilots</h2>
            <div class="user-group-section">
                <h3 style="text-align: center;"><img src="<?= $baseUrl ?>/public/favicons/android-chrome-192x192.png" alt="" style="vertical-align: middle; margin-right: 0.5rem; width: 24px; height: 24px; background: transparent;"> Make Better Flight Decisions</h3>
                <p>Use AviationWX to make better-informed flight decisions with real-time weather data and visual conditions. See actual visibility conditions before departure or approach, and make informed go/no-go decisions with up-to-date information.</p>
                <p style="margin-top: 1rem;"><strong>CFIs love this tool</strong> - it helps teach student pilots how to make smart weather decisions. Real-time webcams and weather data provide the timely, accurate information that helps prevent incidents.</p>
                <p style="margin-top: 1rem; font-weight: 500;">Share this service with fellow pilots and airport owners to help grow the aviation weather network!</p>
            </div>
            
            <div id="participating-airports" style="margin-top: 3rem;">
                <h2 style="text-align: center; margin-bottom: 1.5rem;">Participating Airports</h2>
                
                <?php if ($totalAirports > 0 && file_exists($configFile)): ?>
                <!-- Airport Search -->
                <div class="homepage-airport-search-container">
                    <div class="homepage-airport-search-wrapper">
                        <input type="text" 
                               id="homepage-airport-search" 
                               class="homepage-airport-search-input" 
                               placeholder="Search airports by name or identifier..." 
                               autocomplete="off"
                               aria-label="Search airports">
                        <div id="homepage-airport-dropdown" class="homepage-airport-dropdown">
                            <!-- Content populated by JavaScript -->
                        </div>
                    </div>
                </div>
                <?php
                $envConfigPath = getenv('CONFIG_PATH');
                $configFileForList = ($envConfigPath && file_exists($envConfigPath)) ? $envConfigPath : (__DIR__ . '/../config/airports.json');
                $config = json_decode(file_get_contents($configFileForList), true);
                // Only show enabled airports
                $airports = getEnabledAirports($config ?? []);
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
                        $hasWeatherSource = isset($airport['weather_source']) && !empty($airport['weather_source']);
                        // Fetch weather if airport has either a primary weather source OR METAR
                        $hasAnyWeather = $hasWeatherSource || $hasMetar;
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
            </div>
        </section>

        <section>
            <h2>Supported Equipment and Systems</h2>
            <p style="text-align: center; color: #555; margin-bottom: 2rem;">We work with existing equipment, help you pick equipment or can provide new equipment. We can integrate with a broad range of equipment, and if it isn't supported today we can probably add support quickly. All formats are automatically processed and optimized.</p>
            
            <div class="equipment-grid">
                <div>
                    <h3 style="color: #0066cc; margin-bottom: 1rem;">🌡️ Weather Sources</h3>
                    <ul style="margin: 0 0 0 2rem; line-height: 1.8;">
                        <li>Tempest Weather</li>
                        <li>Ambient Weather</li>
                        <li>Davis WeatherLink</li>
                        <li>PWSWeather.com</li>
                        <li>METAR observations</li>
                    </ul>
                </div>
                
                <div>
                    <h3 style="color: #0066cc; margin-bottom: 1rem;">📹 Webcam Connectivity Types</h3>
                    <ul style="margin: 0 0 0 2rem; line-height: 1.8;">
                        <li>MJPEG streams</li>
                        <li>RTSP/RTSPS streams</li>
                        <li>Static images (JPEG/PNG)</li>
                        <li>Push uploads via SFTP/FTP/FTPS</li>
                    </ul>
                </div>
                
                <div>
                    <h3 style="color: #0066cc; margin-bottom: 1rem;">⚡ Infrastructure</h3>
                    <ul style="margin: 0 0 0 2rem; line-height: 1.8;">
                        <li>Hardline Power</li>
                        <li>POE (Power over Ethernet)</li>
                        <li>Solar powered</li>
                        <li>WiFi internet</li>
                        <li>Hardline internet</li>
                        <li>Cellular</li>
                        <li>Satellite</li>
                    </ul>
                </div>
            </div>
            
            <p style="text-align: center; margin-top: 2rem;">
                <a href="https://github.com/alexwitherspoon/aviationwx.org" class="btn-secondary" target="_blank" rel="noopener">View Technical Details on GitHub</a>
            </p>
        </section>

        <!-- About the Project -->
        <section id="about-the-project">
            <h2>About the Project</h2>
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
                <p>
                    <strong>AviationWX.org</strong> is a volunteer effort by <strong>Alex Witherspoon</strong>, a pilot dedicated to helping fellow aviators make safer flight decisions through better, more timely weather information.
                </p>
                <p style="margin-top: 1rem;">
                    Some of the best flying can take us into small airports or grass strips that don't always come with infrastructure to help us make smart calls. While I've seen many great implementations, we often have to re-invent the wheel to display that information for pilots to use. I was particularly inspired by <a href="http://www.twinoakswx.com" target="_blank" rel="noopener">Twin Oaks Airpark's dashboard</a>, and wanted to make something this simple available for all airports. No app, no usernames, no fees, no ads, this is a safety oriented service for pilots that will work on mobile or desktop. This service can interface with many different camera systems, and weather systems and make it available online for pilots. I'm happy to try to add more support for other systems as needed to make this as universal as possible. While a group could host this themselves, I'm happy to host any and all airports on this service. This project will also be compatible with the FAA Weathercam project, and we can make webcam data available to that group as well. All weather data comes from platforms that contribute to NOAA's forecasting models to help pilots and well, all people who have to deal with weather.
                </p>
                <p style="margin-top: 1rem;">
                    This service is provided <strong>free of charge</strong> to the aviation community, including all upkeep and maintenance costs. The project is entirely open source, so if Alex is unable to continue the effort for any reason, the community can continue to maintain and improve it.
                </p>
                <p style="margin-top: 1rem;">
                    Built for pilots, owners, airport operators, and the entire aviation community.
                </p>
            </div>
            
            <div class="about-box" style="margin-top: 2rem; border-top: 3px solid #0066cc;">
                <h3 style="color: #0066cc; margin-top: 0;">💻 Open Source Project</h3>
                <p>AviationWX is fully open source and welcomes contributions. View the codebase, submit issues, or contribute improvements.</p>
                
                <div class="btn-group" style="margin-top: 1.5rem; justify-content: center;">
                    <a href="https://github.com/alexwitherspoon/aviationwx.org" class="btn-primary" target="_blank" rel="noopener">
                        View on GitHub
                    </a>
                    <a href="https://github.com/alexwitherspoon/aviationwx.org/blob/main/CONTRIBUTING.md" class="btn-secondary" target="_blank" rel="noopener">
                        Contributing Guidelines
                    </a>
                </div>
                <p style="margin-top: 1rem; font-size: 0.9rem; color: #666; text-align: center;">Deep technical documentation lives in the GitHub repository.</p>
            </div>
            
            <div class="about-box" style="margin-top: 2rem; border-top: 3px solid #28a745;">
                <h3 style="color: #28a745; margin-top: 0;">Donating</h3>
                <p>
                    If you'd like to support this project financially, that's wonderful and greatly appreciated! However, <strong>donations are completely optional</strong> - AviationWX will always remain free to use for everyone in the aviation community.
                </p>
                <p style="margin-top: 1rem;">
                    You can sponsor this project through <a href="https://github.com/sponsors/alexwitherspoon" target="_blank" rel="noopener">GitHub Sponsors</a>. Every contribution helps cover hosting costs, maintenance, and continued development of new features.
                </p>
                <div class="btn-group" style="margin-top: 1.5rem; justify-content: center;">
                    <a href="https://github.com/sponsors/alexwitherspoon" class="btn-primary" target="_blank" rel="noopener">
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
