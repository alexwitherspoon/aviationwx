<?php
// Load SEO utilities and config (for getGitSha function)
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/seo.php';
require_once __DIR__ . '/../lib/address-formatter.php';
require_once __DIR__ . '/../lib/weather/utils.php';
require_once __DIR__ . '/../lib/weather/source-timestamps.php';
require_once __DIR__ . '/../lib/weather/outage-detection.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/logger.php';

// Normalize weather source for METAR-only airports (sets weather_source if metar_station is configured)
normalizeWeatherSource($airport);

/**
 * Extract actual image capture timestamp from EXIF data
 * 
 * Attempts to read EXIF DateTimeOriginal from JPEG images.
 * Falls back to file modification time if EXIF is not available.
 * 
 * @param string $filePath Path to image file
 * @return int Unix timestamp, or 0 if unable to determine
 */
function getImageCaptureTimeForPage($filePath) {
    // Try to read EXIF data from JPEG files
    if (function_exists('exif_read_data') && file_exists($filePath)) {
        // Use @ to suppress errors for non-critical EXIF operations
        // We handle failures explicitly with fallback to filemtime below
        $exif = @exif_read_data($filePath, 'EXIF', true);
        if ($exif !== false && isset($exif['EXIF']['DateTimeOriginal'])) {
            $dateTime = $exif['EXIF']['DateTimeOriginal'];
            // Parse EXIF date format: "YYYY:MM:DD HH:MM:SS"
            $timestamp = @strtotime(str_replace(':', '-', substr($dateTime, 0, 10)) . ' ' . substr($dateTime, 11));
            if ($timestamp !== false && $timestamp > 0) {
                return (int)$timestamp;
            }
        }
        // Also check main EXIF array (some cameras store it there)
        if (isset($exif['DateTimeOriginal'])) {
            $dateTime = $exif['DateTimeOriginal'];
            $timestamp = @strtotime(str_replace(':', '-', substr($dateTime, 0, 10)) . ' ' . substr($dateTime, 11));
            if ($timestamp !== false && $timestamp > 0) {
                return (int)$timestamp;
            }
        }
    }
    
    // Fallback to file modification time
    // Use @ to suppress errors for non-critical file operations
    // We handle failures explicitly by returning 0
    $mtime = @filemtime($filePath);
    return $mtime !== false ? (int)$mtime : 0;
}


// SEO variables - emphasize live webcams and runway conditions
$webcamCount = isset($airport['webcams']) ? count($airport['webcams']) : 0;
$webcamText = $webcamCount > 0 ? $webcamCount . ' live webcam' . ($webcamCount > 1 ? 's' : '') . ' and ' : '';
// Get primary identifier (ICAO > IATA > FAA > Airport ID) for display
$primaryIdentifier = getPrimaryIdentifier($airportId, $airport);
$pageTitle = htmlspecialchars($airport['name']) . ' (' . htmlspecialchars($primaryIdentifier) . ') - Live Webcams & Runway Conditions';
$pageDescription = 'Live webcams and real-time runway conditions for ' . htmlspecialchars($airport['name']) . ' (' . htmlspecialchars($primaryIdentifier) . '). ' . $webcamText . 'current weather, wind, visibility, and aviation metrics. Free for pilots.';
$pageKeywords = htmlspecialchars($primaryIdentifier) . ', ' . htmlspecialchars($airport['name']) . ', live airport webcam, runway conditions, ' . htmlspecialchars($primaryIdentifier) . ' weather, airport webcam, pilot weather, aviation weather';
// Get base domain from global config
require_once __DIR__ . '/../lib/config.php';
$baseDomain = getBaseDomain();
$airportUrl = 'https://' . $airportId . '.' . $baseDomain;
$canonicalUrl = $airportUrl; // Always use subdomain URL for canonical
$ogImage = null; // Will be set to first webcam if available

// Get first webcam image for Open Graph
if (isset($airport['webcams']) && count($airport['webcams']) > 0) {
    $ogImage = $airportUrl . '/webcam.php?id=' . urlencode($airportId) . '&cam=0&fmt=jpg';
} else {
    $baseUrl = getBaseUrl();
    // Prefer WebP for about-photo, fallback to JPG
    $aboutPhotoWebp = __DIR__ . '/../public/images/about-photo.webp';
    $aboutPhotoJpg = __DIR__ . '/../public/images/about-photo.jpg';
    $ogImage = file_exists($aboutPhotoWebp) 
        ? $baseUrl . '/public/images/about-photo.webp'
        : $baseUrl . '/public/images/about-photo.jpg';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        // Remove deprecated window.styleMedia immediately to prevent Safari warnings
        // Must run before any other scripts to catch Safari's early property check
        (function() {
            'use strict';
            try {
                if ('styleMedia' in window) {
                    var descriptor = Object.getOwnPropertyDescriptor(window, 'styleMedia');
                    if (descriptor && descriptor.configurable) {
                        delete window.styleMedia;
                    } else {
                        try {
                            Object.defineProperty(window, 'styleMedia', {
                                value: undefined,
                                writable: false,
                                configurable: true,
                                enumerable: false
                            });
                            delete window.styleMedia;
                        } catch (e) {
                            Object.defineProperty(window, 'styleMedia', {
                                get: function() { return undefined; },
                                set: function() {},
                                configurable: false,
                                enumerable: false
                            });
                        }
                    }
                }
            } catch (e) {}
        })();
    </script>
    <title><?= $pageTitle ?></title>
    
    <?php
    // Favicon and icon tags
    echo generateFaviconTags();
    echo "\n    ";
    ?>
    
    <!-- Preconnect to same origin for faster CSS loading -->
    <?php
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
    // Get base domain from global config
    $baseDomain = getBaseDomain();
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $baseDomain;
    ?>
    <link rel="preconnect" href="<?= $protocol ?>://<?= htmlspecialchars($host) ?>">
    
    <!-- Resource hints for external APIs (conditional based on weather source) -->
    <?php
    // Only preconnect to APIs that are actually used by this airport's weather source
    $weatherSourceType = isset($airport['weather_source']['type']) ? $airport['weather_source']['type'] : null;
    $needsMetar = isMetarEnabled($airport);
    
    switch ($weatherSourceType) {
        case 'tempest':
            echo "    <link rel=\"preconnect\" href=\"https://swd.weatherflow.com\" crossorigin>\n";
            echo "    <link rel=\"dns-prefetch\" href=\"https://swd.weatherflow.com\">\n";
            break;
        case 'ambient':
            echo "    <link rel=\"preconnect\" href=\"https://api.ambientweather.net\" crossorigin>\n";
            echo "    <link rel=\"dns-prefetch\" href=\"https://api.ambientweather.net\">\n";
            break;
        case 'weatherlink':
            echo "    <link rel=\"preconnect\" href=\"https://api.weatherlink.com\" crossorigin>\n";
            echo "    <link rel=\"dns-prefetch\" href=\"https://api.weatherlink.com\">\n";
            break;
        case 'pwsweather':
            echo "    <link rel=\"preconnect\" href=\"https://api.aerisapi.com\" crossorigin>\n";
            echo "    <link rel=\"dns-prefetch\" href=\"https://api.aerisapi.com\">\n";
            break;
    }
    
    if ($needsMetar) {
        echo "    <link rel=\"preconnect\" href=\"https://aviationweather.gov\" crossorigin>\n";
        echo "    <link rel=\"dns-prefetch\" href=\"https://aviationweather.gov\">\n";
    }
    ?>
    
    <?php
    // Enhanced meta tags
    echo generateEnhancedMetaTags($pageDescription, $pageKeywords);
    echo "\n    ";
    
    // Canonical URL
    echo generateCanonicalTag($airportUrl);
    echo "\n    ";
    
    // Open Graph and Twitter Card tags
    echo generateSocialMetaTags($pageTitle, $pageDescription, $airportUrl, $ogImage);
    echo "\n    ";
    
    // Structured data (LocalBusiness schema for airport)
    echo generateStructuredDataScript(generateAirportSchema($airport, $airportId));
    ?>
    
    <?php
    // Inline CSS to eliminate render-blocking request
    // Use minified CSS if available, fallback to regular CSS
    $cssPath = file_exists(__DIR__ . '/../public/css/styles.min.css') 
        ? __DIR__ . '/../public/css/styles.min.css' 
        : __DIR__ . '/../public/css/styles.css';
    
    // Defensive error handling: prevent file read failures from outputting warnings
    $cssContent = '';
    if (file_exists($cssPath) && is_readable($cssPath)) {
        $cssContent = @file_get_contents($cssPath);
        if ($cssContent === false) {
            error_log('Failed to read CSS file: ' . $cssPath);
            $cssContent = ''; // Fallback to empty CSS
        }
    } else {
        error_log('CSS file not found or not readable: ' . $cssPath);
    }
    ?>
    <style><?= $cssContent ?></style>
    <script>
        // Register service worker for offline support with cache busting
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                // Unregister any old service workers at incorrect paths (e.g., /sw.js)
                navigator.serviceWorker.getRegistrations().then((registrations) => {
                    for (let registration of registrations) {
                        // Check if this is an old registration at wrong path
                        if (registration.scope && (registration.active?.scriptURL?.includes('/sw.js') || registration.waiting?.scriptURL?.includes('/sw.js') || registration.installing?.scriptURL?.includes('/sw.js'))) {
                            console.log('[SW] Unregistering old service worker:', registration.scope);
                            registration.unregister();
                        }
                    }
                });
                
                // Add cache-busting query parameter based on service worker file modification time
                // This ensures the service worker is re-fetched when the file changes on deploy
                const swMtime = <?= file_exists(__DIR__ . '/../public/js/service-worker.js') ? filemtime(__DIR__ . '/../public/js/service-worker.js') : time() ?>;
                const swUrl = '/public/js/service-worker.js?v=' + swMtime;
                
                navigator.serviceWorker.register(swUrl, { updateViaCache: 'none' })
                    .then((registration) => {
                        console.log('[SW] Registered:', registration.scope);

                        // Check for updates immediately after registration
                        registration.update();

                        // If there's a waiting SW, activate it immediately
                        if (registration.waiting) {
                            console.log('[SW] Waiting service worker found, activating...');
                            registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                            // Force reload after a short delay
                            setTimeout(() => {
                                window.location.reload();
                            }, 100);
                        }

                        // Listen for updates; when installed and waiting, take over
                        registration.addEventListener('updatefound', () => {
                            console.log('[SW] Update found, new service worker installing...');
                            const newWorker = registration.installing;
                            if (!newWorker) return;
                            
                            newWorker.addEventListener('statechange', () => {
                                if (newWorker.state === 'installed') {
                                    if (navigator.serviceWorker.controller) {
                                        // There's a new SW waiting - activate it immediately
                                        console.log('[SW] New service worker installed, activating...');
                                        newWorker.postMessage({ type: 'SKIP_WAITING' });
                                        // Force reload after activation
                                        setTimeout(() => {
                                            window.location.reload();
                                        }, 100);
                                    } else {
                                        // First install
                                        console.log('[SW] Service worker installed for first time');
                                    }
                                }
                            });
                        });

                        // Auto-reload when new SW takes control
                        let refreshing = false;
                        navigator.serviceWorker.addEventListener('controllerchange', () => {
                            if (!refreshing) {
                                refreshing = true;
                                console.log('[SW] Controller changed, reloading page...');
                                window.location.reload();
                            }
                        });

                        // Check for updates every 5 minutes (reduced from 1 hour for faster updates)
                        setInterval(() => {
                            registration.update();
                        }, 300000); // 5 minutes
                    })
                    .catch((err) => {
                        console.warn('[SW] Registration failed:', err);
                    });
            });
        }
    </script>
    <style>
        @keyframes skeleton-loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        .webcam-skeleton {
            display: none;
        }
    </style>
</head>
<body>
    <noscript>
        <style>
            html { scroll-behavior: auto; }
            body { margin: 0; padding: 0; }
        </style>
        <div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 0; padding: 1rem; margin: 0 0 1.5rem 0; text-align: center; color: #856404; font-size: 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.2); width: 100%; box-sizing: border-box;">
            <strong>⚠️ JavaScript is required</strong> for this site to function properly. Please enable JavaScript in your browser to view weather data and interactive features.
        </div>
    </noscript>
    <?php if (isAirportInMaintenance($airport)): ?>
    <div class="maintenance-banner">
        ⚠️ This airport is currently under maintenance. Data may be missing or unreliable.
    </div>
    <?php endif; ?>
    <?php 
    $outageStatus = checkDataOutageStatus($airportId, $airport);
    if ($outageStatus !== null): 
    ?>
    <div id="data-outage-banner" class="data-outage-banner" data-newest-timestamp="<?= $outageStatus['newest_timestamp'] ?>">
        ⚠️ Data Outage Detected: All local data sources are currently offline due to a local outage.<br>
        The latest information shown is from <span id="outage-newest-time">--</span> and may not reflect current conditions.<br>
        Data will automatically update once the local site is back online.
    </div>
    <?php endif; ?>
    <main>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <h1><?= htmlspecialchars($airport['name']) ?> (<?= htmlspecialchars($primaryIdentifier) ?>)</h1>
        </header>

        <?php if (isset($airport['webcams']) && !empty($airport['webcams']) && count($airport['webcams']) > 0): ?>
        <!-- Webcams -->
        <section class="webcam-section">
            <div class="webcam-grid">
                <?php foreach ($airport['webcams'] as $index => $cam): ?>
                <div class="webcam-item">
                    <div class="webcam-container">
                        <div id="webcam-skeleton-<?= $index ?>" class="webcam-skeleton" style="background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%); background-size: 200% 100%; animation: skeleton-loading 1.5s ease-in-out infinite; width: 100%; aspect-ratio: 16/9; border-radius: 4px; position: absolute; top: 0; left: 0; z-index: 1;"></div>
                        <picture style="position: relative; z-index: 2;">
                            <?php
                            // Generate cache-friendly immutable hash from mtime (for CDN compatibility)
                            // Cache is at root level, not in pages directory
                            // Use EXIF capture time when available for accurate timestamp display
                            $base = __DIR__ . '/../cache/webcams/' . $airportId . '_' . $index;
                            $mtimeJpg = 0;
                            $sizeJpg = 0;
                            foreach (['.jpg', '.webp'] as $ext) {
                                $filePath = $base . $ext;
                                if (file_exists($filePath)) {
                                    // Use EXIF capture time if available, otherwise filemtime
                                    $mtimeJpg = getImageCaptureTimeForPage($filePath);
                                    $sizeJpg = filesize($filePath);
                                    break;
                                }
                            }
                            // Match webcam.php hash generation: airport_id + cam_index + fmt + mtime + size
                            $imgHash = substr(md5($airportId . '_' . $index . '_jpg_' . $mtimeJpg . '_' . $sizeJpg), 0, 8);
                            ?>
                            <source id="webcam-webp-<?= $index ?>" type="image/webp" srcset="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http' ?>://<?= htmlspecialchars($_SERVER['HTTP_HOST']) ?>/webcam.php?id=<?= urlencode($airportId) ?>&cam=<?= $index ?>&fmt=webp&v=<?= $imgHash ?>">
                            <img id="webcam-<?= $index ?>" 
                                 src="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http' ?>://<?= htmlspecialchars($_SERVER['HTTP_HOST']) ?>/webcam.php?id=<?= urlencode($airportId) ?>&cam=<?= $index ?>&fmt=jpg&v=<?= $imgHash ?>"
                                 data-initial-timestamp="<?= $mtimeJpg ?>" 
                                 alt="<?= htmlspecialchars($cam['name']) ?>"
                                 title="<?= htmlspecialchars($cam['name']) ?>"
                                 class="webcam-image"
                                 width="1600"
                                 height="900"
                                 style="aspect-ratio: 16/9; width: 100%; height: auto;"
                                 fetchpriority="high"
                                 decoding="async"
                                 onerror="handleWebcamError(<?= $index ?>, this)"
                                 onload="const skel=document.getElementById('webcam-skeleton-<?= $index ?>'); if(skel) skel.style.display='none'"
                                 onclick="openLiveStream(this.src)">
                        </picture>
                    </div>
                    <div class="webcam-name-label">
                        <span class="webcam-name-text"><?= htmlspecialchars($cam['name']) ?></span>
                        <span class="webcam-timestamp">Last updated: <span id="webcam-timestamp-warning-<?= $index ?>" class="webcam-timestamp-warning" style="display: none;">⚠️ </span><span id="webcam-timestamp-<?= $index ?>" data-timestamp="<?= $mtimeJpg ?>">--</span></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if (isset($airport['weather_source']) && !empty($airport['weather_source'])): ?>
        <!-- Weather Data -->
        <section class="weather-section">
            <div class="weather-header-container" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem;">
                <div class="weather-header-left" style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <h2 class="weather-header-title" style="margin: 0;">Current Conditions</h2>
                    <div class="weather-toggle-buttons" style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                        <button id="time-format-toggle" style="background: #f5f5f5; border: 1px solid #ccc; border-radius: 6px; padding: 0.5rem 1rem; cursor: pointer; font-size: 0.9rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; color: #333; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.1); min-width: 50px; height: auto;" title="Toggle time format (12hr/24hr)" onmouseover="this.style.background='#e8e8e8'; this.style.borderColor='#999';" onmouseout="this.style.background='#f5f5f5'; this.style.borderColor='#ccc';">
                            <span id="time-format-display">12hr</span>
                        </button>
                        <button id="temp-unit-toggle" style="background: #f5f5f5; border: 1px solid #ccc; border-radius: 6px; padding: 0.5rem 1rem; cursor: pointer; font-size: 0.9rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; color: #333; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.1); min-width: 50px; height: auto;" title="Toggle temperature unit (F/C)" onmouseover="this.style.background='#e8e8e8'; this.style.borderColor='#999';" onmouseout="this.style.background='#f5f5f5'; this.style.borderColor='#ccc';">
                            <span id="temp-unit-display">°F</span>
                        </button>
                        <button id="distance-unit-toggle" style="background: #f5f5f5; border: 1px solid #ccc; border-radius: 6px; padding: 0.5rem 1rem; cursor: pointer; font-size: 0.9rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; color: #333; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.1); min-width: 50px; height: auto;" title="Toggle distance unit (ft/m)" onmouseover="this.style.background='#e8e8e8'; this.style.borderColor='#999';" onmouseout="this.style.background='#f5f5f5'; this.style.borderColor='#ccc';">
                            <span id="distance-unit-display">ft</span>
                        </button>
                    </div>
                </div>
                <p class="weather-last-updated-text" style="font-size: 0.85rem; color: #555; margin: 0;">Last updated: <span id="weather-timestamp-warning" class="weather-timestamp-warning" style="display: none;">⚠️ </span><span id="weather-last-updated">--</span></p>
            </div>
            <div id="weather-data" class="weather-grid">
                <div class="weather-item loading">
                    <span class="label">Loading...</span>
                </div>
            </div>
        </section>

        <!-- Runway Wind Visual -->
        <section class="wind-visual-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem;">
                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <h2 style="margin: 0;">Runway Wind</h2>
                    <button id="wind-speed-unit-toggle" style="background: #f5f5f5; border: 1px solid #ccc; border-radius: 6px; padding: 0.5rem 1rem; cursor: pointer; font-size: 0.9rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; color: #333; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.1); min-width: 50px; height: auto;" title="Toggle wind speed unit (kts/mph/km/h)" onmouseover="this.style.background='#e8e8e8'; this.style.borderColor='#999';" onmouseout="this.style.background='#f5f5f5'; this.style.borderColor='#ccc';">
                        <span id="wind-speed-unit-display">kts</span>
                    </button>
                </div>
                <p style="font-size: 0.85rem; color: #555; margin: 0;">Last updated: <span id="wind-timestamp-warning" class="weather-timestamp-warning" style="display: none;">⚠️ </span><span id="wind-last-updated">--</span></p>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 2rem; align-items: center; justify-content: center;">
                <div id="wind-visual" class="wind-visual-container">
                    <canvas id="windCanvas" width="300" height="300"></canvas>
                </div>
                <div id="wind-details" style="display: flex; flex-direction: column; gap: 0.5rem; min-width: 200px;">
                    <!-- Wind details will be populated by JavaScript -->
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Airport Information -->
        <section class="airport-info">
            <div class="info-grid">
                <?php
                // Build geo: URI for location - uses standard geo URI scheme (RFC 5870)
                // Browser/OS will handle opening in default mapping application
                // Safari doesn't handle geo: URIs, so we provide Apple Maps URL as fallback
                $geoUrl = '';
                $appleMapsUrl = '';
                $addressText = '';
                $formattedAddress = '';
                $hasCoordinates = isset($airport['lat']) && isset($airport['lon']) && is_numeric($airport['lat']) && is_numeric($airport['lon']);
                
                if ($hasCoordinates) {
                    $lat = (float)$airport['lat'];
                    $lon = (float)$airport['lon'];
                    $geoUrl = 'geo:' . $lat . ',' . $lon;
                    
                    // Build Apple Maps URL for Safari compatibility
                    if (!empty($airport['address'])) {
                        $addressText = $airport['address'];
                        $formattedAddress = formatAddressEnvelope($airport['address']);
                        $geoUrl .= '?q=' . urlencode($airport['address']);
                        $appleMapsUrl = 'https://maps.apple.com/?q=' . urlencode($airport['address']) . '&ll=' . $lat . ',' . $lon;
                    } else {
                        $addressText = $lat . ', ' . $lon;
                        $formattedAddress = htmlspecialchars($addressText);
                        $appleMapsUrl = 'https://maps.apple.com/?ll=' . $lat . ',' . $lon;
                    }
                } else if (!empty($airport['address'])) {
                    // Address only - display as semantic text (geo: requires coordinates)
                    $addressText = $airport['address'];
                    $formattedAddress = formatAddressEnvelope($airport['address']);
                }
                
                // Only show location if we have either coordinates or address
                if ((!empty($geoUrl) || !empty($addressText)) && !empty($addressText)):
                ?>
                <div class="info-item">
                    <span class="label">Location:</span>
                    <span class="value">
                        <?php if (!empty($geoUrl)): ?>
                        <a href="<?= htmlspecialchars($geoUrl) ?>" 
                           <?php if (!empty($appleMapsUrl)): ?>data-apple-maps="<?= htmlspecialchars($appleMapsUrl) ?>"<?php endif; ?>
                           class="address-link"
                           title="Open location in maps">
                            <?= $formattedAddress ?>
                        </a>
                        <?php else: ?>
                        <span class="address-text"><?= $formattedAddress ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
                <div class="info-item">
                    <span class="label">Elevation:</span>
                    <span class="value"><?= $airport['elevation_ft'] ?> ft</span>
                </div>
                <div class="info-item">
                    <span class="label">Fuel:</span>
                    <span class="value">
                        <?php
                        $fuel = '';
                        if (isset($airport['services']['fuel']) && is_string($airport['services']['fuel'])) {
                            $fuel = trim($airport['services']['fuel']);
                        }
                        echo !empty($fuel) ? htmlspecialchars($fuel) : 'Not Available';
                        ?>
                    </span>
                </div>
                <div class="info-item">
                    <span class="label">Repairs:</span>
                    <span class="value">
                        <?= !empty($airport['services']['repairs_available'] ?? false) ? 'Available' : 'Not Available' ?>
                    </span>
                </div>
            </div>

            <!-- Frequencies -->
            <div class="frequencies">
                <h3>Frequencies</h3>
                <div class="freq-grid">
                    <?php 
                    // Display all frequencies present in the config
                    if (!empty($airport['frequencies'])) {
                        foreach ($airport['frequencies'] as $key => $value): 
                            // Format the label - default to uppercase for aviation frequencies
                            $label = strtoupper($key);
                    ?>
                    <div class="freq-item">
                        <span class="label"><?= htmlspecialchars($label) ?>:</span>
                        <span class="value"><?= htmlspecialchars($value) ?></span>
                    </div>
                    <?php 
                        endforeach;
                    } ?>
                </div>
            </div>

            <div class="links">
                <?php
                // Get the best available identifier for external links (ICAO > IATA > FAA)
                $linkIdentifier = getBestIdentifierForLinks($airport);
                
                // AirNav link (manual override or auto-generated)
                $airnavUrl = null;
                if (!empty($airport['airnav_url'])) {
                    $airnavUrl = $airport['airnav_url'];
                } elseif ($linkIdentifier !== null) {
                    $airnavUrl = 'https://www.airnav.com/airport/' . $linkIdentifier;
                }
                if ($airnavUrl !== null): ?>
                <a href="<?= htmlspecialchars($airnavUrl) ?>" target="_blank" rel="noopener" class="btn">
                    AirNav
                </a>
                <?php endif; ?>
                
                <?php
                // SkyVector link (manual override or auto-generated)
                $skyvectorUrl = null;
                if (!empty($airport['skyvector_url'])) {
                    $skyvectorUrl = $airport['skyvector_url'];
                } elseif ($linkIdentifier !== null) {
                    $skyvectorUrl = 'https://skyvector.com/airport/' . $linkIdentifier;
                }
                if ($skyvectorUrl !== null): ?>
                <a href="<?= htmlspecialchars($skyvectorUrl) ?>" target="_blank" rel="noopener" class="btn">
                    SkyVector
                </a>
                <?php endif; ?>
                
                <?php
                // AOPA link (manual override or auto-generated)
                $aopaUrl = null;
                if (!empty($airport['aopa_url'])) {
                    $aopaUrl = $airport['aopa_url'];
                } elseif ($linkIdentifier !== null) {
                    $aopaUrl = 'https://www.aopa.org/destinations/airports/' . $linkIdentifier;
                }
                if ($aopaUrl !== null): ?>
                <a href="<?= htmlspecialchars($aopaUrl) ?>" target="_blank" rel="noopener" class="btn">
                    AOPA
                </a>
                <?php endif; ?>
                
                <?php
                // FAA Weather link (manual override or auto-generated)
                $faaWeatherUrl = null;
                if (!empty($airport['faa_weather_url'])) {
                    $faaWeatherUrl = $airport['faa_weather_url'];
                } elseif ($linkIdentifier !== null && !empty($airport['lat']) && !empty($airport['lon'])) {
                    // Generate FAA Weather Cams URL
                    // URL format: https://weathercams.faa.gov/map/{min_lon},{min_lat},{max_lon},{max_lat}/airport/{identifier}/
                    $buffer = 2.0;
                    $min_lon = $airport['lon'] - $buffer;
                    $min_lat = $airport['lat'] - $buffer;
                    $max_lon = $airport['lon'] + $buffer;
                    $max_lat = $airport['lat'] + $buffer;
                    // Remove K prefix from identifier if present (e.g., KSPB -> SPB)
                    $faa_identifier = preg_replace('/^K/', '', $linkIdentifier);
                    $faaWeatherUrl = sprintf(
                        'https://weathercams.faa.gov/map/%.5f,%.5f,%.5f,%.5f/airport/%s/',
                        $min_lon,
                        $min_lat,
                        $max_lon,
                        $max_lat,
                        $faa_identifier
                    );
                }
                if ($faaWeatherUrl !== null): ?>
                <a href="<?= htmlspecialchars($faaWeatherUrl) ?>" target="_blank" rel="noopener" class="btn">
                    FAA Weather
                </a>
                <?php endif; ?>
                
                <?php
                // ForeFlight link (manual override or auto-generated) - mobile only
                // ForeFlight accepts ICAO, IATA, or FAA codes (prefer ICAO > IATA > FAA)
                $foreflightUrl = null;
                if (!empty($airport['foreflight_url'])) {
                    $foreflightUrl = $airport['foreflight_url'];
                } elseif ($linkIdentifier !== null) {
                    // ForeFlight deeplink format: foreflightmobile://maps/search?q={identifier}
                    $foreflightUrl = 'foreflightmobile://maps/search?q=' . urlencode($linkIdentifier);
                }
                if ($foreflightUrl !== null): ?>
                <a href="<?= htmlspecialchars($foreflightUrl) ?>" target="_blank" rel="noopener" class="btn foreflight-link">
                    ForeFlight
                </a>
                <?php endif; ?>
                <?php
                // Render custom links if configured
                if (!empty($airport['links']) && is_array($airport['links'])) {
                    foreach ($airport['links'] as $link) {
                        // Validate that both label and url are present
                        if (!empty($link['label']) && !empty($link['url'])) {
                            ?>
                            <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" rel="noopener" class="btn">
                                <?= htmlspecialchars($link['label']) ?>
                            </a>
                            <?php
                        }
                    }
                }
                ?>
            </div>
        </section>

        <!-- Current Time -->
        <section class="time-section">
            <div class="time-grid">
                <div class="time-item">
                    <span class="label">Local Time:</span>
                    <span class="value" id="localTime">--:--:--</span> <span id="localTimezone" style="font-size: 0.85rem; color: #555;">--</span>
                </div>
                <div class="time-item">
                    <span class="label">Zulu Time:</span>
                    <span class="value" id="zuluTime">--:--:--</span> <span style="font-size: 0.85rem; color: #555;">UTC</span>
                </div>
            </div>
        </section>

        <!-- Partnerships & Credits -->
        <?php
        // Collect unique weather data sources by name
        $weatherSources = [];
        
        // Add primary weather source (only if configured)
        if (isset($airport['weather_source']) && !empty($airport['weather_source']) && isset($airport['weather_source']['type'])) {
            switch ($airport['weather_source']['type']) {
                case 'tempest':
                    $weatherSources[] = [
                        'name' => 'Tempest Weather',
                        'url' => 'https://tempestwx.com'
                    ];
                    break;
                case 'ambient':
                    $weatherSources[] = [
                        'name' => 'Ambient Weather',
                        'url' => 'https://ambientweather.net'
                    ];
                    break;
                case 'weatherlink':
                    $weatherSources[] = [
                        'name' => 'Davis WeatherLink',
                        'url' => 'https://weatherlink.com'
                    ];
                    break;
                case 'metar':
                    $weatherSources[] = [
                        'name' => 'Aviation Weather',
                        'url' => 'https://aviationweather.gov'
                    ];
                    break;
            }
            
            // Add METAR source if using Tempest, Ambient, or WeatherLink (since we supplement with METAR)
            // Only add if not already using METAR as primary source AND metar_station is configured
            $hasAviationWeather = false;
            foreach ($weatherSources as $source) {
                if ($source['name'] === 'Aviation Weather') {
                    $hasAviationWeather = true;
                    break;
                }
            }
            
            if (!$hasAviationWeather && 
                in_array($airport['weather_source']['type'], ['tempest', 'ambient', 'weatherlink', 'pwsweather'])) {
                // Show METAR source if metar_station is configured
                if (isMetarEnabled($airport)) {
                    $weatherSources[] = [
                        'name' => 'Aviation Weather',
                        'url' => 'https://aviationweather.gov'
                    ];
                }
            }
        }
        
        // Get partners from new partners[] array
        $partners = $airport['partners'] ?? [];
        
        // Only show section if we have partners or data sources
        if (!empty($partners) || !empty($weatherSources)):
        ?>
        <section class="partnerships-section">
            <div class="partnerships-container">
                <?php if (!empty($partners)): ?>
                <div class="partnerships-content">
                    <h3 class="partnerships-heading">Support These Partners</h3>
                    <p class="partnerships-subheading">These organizations make this airport service possible. Click to visit and show your support.</p>
                    <div class="partners-grid">
                        <?php foreach ($partners as $partner): ?>
                        <div class="partner-item">
                            <a href="<?= htmlspecialchars($partner['url']) ?>" target="_blank" rel="noopener" class="partner-link" title="<?= htmlspecialchars($partner['description'] ?? $partner['name']) ?>">
                                <?php if (!empty($partner['logo'])): ?>
                                <img src="/api/partner-logo.php?url=<?= urlencode($partner['logo']) ?>" 
                                     alt="<?= htmlspecialchars($partner['name']) ?> logo" 
                                     class="partner-logo"
                                     onerror="this.style.display='none';">
                                <?php endif; ?>
                                <span class="partner-name"><?= htmlspecialchars($partner['name']) ?></span>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($weatherSources)): ?>
                <div class="data-sources-content">
                    <div class="data-sources-list">
                        <span class="data-sources-label">Weather data at this airport from </span>
                        <?php foreach ($weatherSources as $index => $source): ?>
                        <a href="<?= htmlspecialchars($source['url']) ?>" target="_blank" rel="noopener" class="data-source-link">
                            <?= htmlspecialchars($source['name']) ?>
                        </a>
                        <?php if ($index < count($weatherSources) - 1): ?><span class="data-source-separator"> & </span><?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Footer -->
        <footer class="footer">
            <p class="footer-disclaimer">
                <em>Data is for advisory use only. Consult official weather sources for flight planning purposes.</em>
            </p>
            <p>
                &copy; <?= date('Y') ?> <a href="https://aviationwx.org">AviationWX.org</a> | 
                <a href="https://aviationwx.org#about-the-project">Built for pilots, by pilots</a> | 
                <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener">Open Source<?php $gitSha = getGitSha(); echo $gitSha ? ' - ' . htmlspecialchars($gitSha) : ''; ?></a> | 
                <a href="https://status.aviationwx.org">Status</a>
            </p>
        </footer>
    </div>
    </main>

    <?php
    // Load JavaScript minification utility
    require_once __DIR__ . '/../lib/js-minify.php';
    
    // Simple JavaScript minification function that preserves PHP code and template literals
    // (Function is now in lib/js-minify.php, but kept here for backward compatibility)
    if (!function_exists('minifyJavaScript')) {
        function minifyJavaScript($js) {
        // Protect PHP tags by replacing them with placeholders
        $phpTags = [];
        $placeholder = '___PHP_TAG_' . uniqid() . '___';
        $pattern = '/<\?[=]?php?[^>]*\?>/';
        preg_match_all($pattern, $js, $matches);
        foreach ($matches[0] as $i => $tag) {
            $js = str_replace($tag, $placeholder . $i, $js);
            $phpTags[$i] = $tag;
        }
        
        // Protect template literals (backtick strings) - they can contain any characters
        $templateLiterals = [];
        $templatePlaceholder = '___TEMPLATE_' . uniqid() . '___';
        $templatePattern = '/`(?:[^`\\\\]|\\\\.|`)*`/s';
        preg_match_all($templatePattern, $js, $templateMatches);
        foreach ($templateMatches[0] as $i => $template) {
            $js = str_replace($template, $templatePlaceholder . $i, $js);
            $templateLiterals[$i] = $template;
        }
        
        // Protect string literals (single and double quotes) to avoid breaking them
        $stringLiterals = [];
        $stringPlaceholder = '___STRING_' . uniqid() . '___';
        // Match strings, handling escaped quotes
        $stringPattern = '/(["\'])(?:[^\\\\\1]|\\\\.)*?\1/s';
        preg_match_all($stringPattern, $js, $stringMatches);
        foreach ($stringMatches[0] as $i => $string) {
            $js = str_replace($string, $stringPlaceholder . $i, $js);
            $stringLiterals[$i] = $string;
        }
        
        // Remove single-line comments (but not inside protected strings)
        $js = preg_replace('/\/\/[^\n\r]*/m', '', $js);
        
        // Remove multi-line comments
        $js = preg_replace('/\/\*[\s\S]*?\*\//', '', $js);
        
        // Remove leading/trailing whitespace from lines
        $js = preg_replace('/^\s+|\s+$/m', '', $js);
        
        // Collapse multiple spaces/newlines to single space (but preserve newlines in some contexts)
        $js = preg_replace('/[ \t]+/', ' ', $js);
        $js = preg_replace('/\n\s*\n+/', "\n", $js);
        
        // Restore string literals
        foreach ($stringLiterals as $i => $string) {
            $js = str_replace($stringPlaceholder . $i, $string, $js);
        }
        
        // Restore template literals
        foreach ($templateLiterals as $i => $template) {
            $js = str_replace($templatePlaceholder . $i, $template, $js);
        }
        
        // Restore PHP tags
        foreach ($phpTags as $i => $tag) {
            $js = str_replace($placeholder . $i, $tag, $js);
        }
        
            return trim($js);
        }
    }
    
    // Start output buffering to capture JavaScript
    // Check for existing output buffers to prevent nesting issues
    $obLevel = ob_get_level();
    if ($obLevel > 0) {
        error_log('Warning: Output buffer already active (level: ' . $obLevel . ') before JavaScript buffer start');
        // Clean any existing output that might have leaked
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
    ob_start();
    ?>
    <script>
// Airport page JavaScript
const AIRPORT_ID = '<?= $airportId ?>';
const AIRPORT_DATA = <?php
    // Defensive JSON encoding with error handling
    $airportJson = json_encode($airport, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($airportJson === false) {
        error_log('JSON encode failed for airport data: ' . json_last_error_msg());
        echo '{}'; // Fallback to empty object
    } else {
        echo $airportJson;
    }
?>;
// Default timezone (from global config)
const DEFAULT_TIMEZONE = <?php
    $defaultTz = getDefaultTimezone();
    echo json_encode($defaultTz, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;
const RUNWAYS = <?php
    // Defensive JSON encoding with error handling
    $runwaysJson = json_encode($airport['runways'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($runwaysJson === false) {
        error_log('JSON encode failed for runways data: ' . json_last_error_msg());
        echo '[]'; // Fallback to empty array
    } else {
        echo $runwaysJson;
    }
?>;

// Weather staleness thresholds (from constants.php)
const WEATHER_STALENESS_WARNING_HOURS_METAR = <?= WEATHER_STALENESS_WARNING_HOURS_METAR ?>;
const WEATHER_STALENESS_ERROR_HOURS_METAR = <?= WEATHER_STALENESS_ERROR_HOURS_METAR ?>;
const WEATHER_STALENESS_WARNING_MULTIPLIER = <?= WEATHER_STALENESS_WARNING_MULTIPLIER ?>;
const WEATHER_STALENESS_ERROR_MULTIPLIER = <?= WEATHER_STALENESS_ERROR_MULTIPLIER ?>;
const MAX_STALE_HOURS = <?= MAX_STALE_HOURS ?>;
const DATA_OUTAGE_BANNER_HOURS = <?= DATA_OUTAGE_BANNER_HOURS ?>;
const SECONDS_PER_HOUR = 3600;

// Production logging removed - only log errors in console

/**
 * Detect if device is mobile (iOS or Android)
 * Uses multiple detection methods to handle edge cases where user agent may be modified
 * @returns {boolean} True if device is mobile
 */
function isMobileDevice() {
    const userAgent = navigator.userAgent || navigator.vendor || window.opera || '';
    const ua = userAgent.toLowerCase();
    
    // Primary detection: user agent patterns
    const isMobileUA = /android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini|mobile/i.test(ua);
    
    // Fallback detection: touch capability + screen size (handles modified UAs)
    const hasTouchScreen = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
    const isSmallScreen = window.innerWidth < 768;
    
    return isMobileUA || (hasTouchScreen && isSmallScreen);
}

/**
 * Detect if browser is Safari (desktop or mobile)
 * Safari doesn't handle geo: URIs, so we need to use Apple Maps URLs instead
 * @returns {boolean} True if browser is Safari
 */
function isSafari() {
    const userAgent = navigator.userAgent || '';
    // Safari detection: Safari has "Safari" in UA but not "Chrome" or "Chromium"
    // Also check for Safari-specific properties
    const hasSafariUA = /safari/i.test(userAgent) && !/chrome|chromium|crios|fxios/i.test(userAgent);
    const hasSafariProperty = typeof window.safari !== 'undefined';
    return hasSafariUA || hasSafariProperty;
}

// Fix address links for Safari - replace geo: URI with Apple Maps URL
(function() {
    function fixAddressLinksForSafari() {
        if (!isSafari()) {
            return; // Not Safari, geo: URI will work
        }
        
        const addressLinks = document.querySelectorAll('.address-link[data-apple-maps]');
        addressLinks.forEach(link => {
            const appleMapsUrl = link.getAttribute('data-apple-maps');
            if (appleMapsUrl) {
                link.href = appleMapsUrl;
            }
        });
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fixAddressLinksForSafari);
    } else {
        fixAddressLinksForSafari();
    }
})();

// Show ForeFlight link only on mobile devices
(function() {
    function showForeFlightLink() {
        const foreflightLink = document.querySelector('.foreflight-link');
        if (foreflightLink && isMobileDevice()) {
            foreflightLink.style.display = 'inline-block';
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', showForeFlightLink);
    } else {
        showForeFlightLink();
    }
})();

/**
 * Get timezone abbreviation for the airport's timezone
 * Uses Intl.DateTimeFormat to get the correct abbreviation (e.g., PST, PDT, EST, EDT)
 * Automatically handles DST transitions based on the current date
 * @param {Date} date - Optional date to use for timezone calculation (defaults to now)
 * @returns {string} Timezone abbreviation (e.g., "PST", "PDT", "EST", "EDT") or "--" on error
 */
function getTimezoneAbbreviation(date = null) {
    const now = date || new Date();
    
    // Get airport timezone, default to UTC if not available (configurable via DEFAULT_TIMEZONE)
    const defaultTimezone = typeof DEFAULT_TIMEZONE !== 'undefined' ? DEFAULT_TIMEZONE : 'UTC';
    const timezone = (AIRPORT_DATA && AIRPORT_DATA.timezone) || defaultTimezone;
    
    try {
        const formatter = new Intl.DateTimeFormat('en-US', {
            timeZone: timezone,
            timeZoneName: 'short'
        });
        const parts = formatter.formatToParts(now);
        const timezonePart = parts.find(part => part.type === 'timeZoneName');
        return timezonePart ? timezonePart.value : '--';
    } catch (error) {
        console.error('[Timezone] Error getting timezone abbreviation:', error);
        return '--';
    }
}

// Update clocks
function updateClocks() {
    const now = new Date();
    
    // Get airport timezone, default to UTC if not available (configurable via DEFAULT_TIMEZONE)
    const defaultTimezone = typeof DEFAULT_TIMEZONE !== 'undefined' ? DEFAULT_TIMEZONE : 'UTC';
    const timezone = (AIRPORT_DATA && AIRPORT_DATA.timezone) || defaultTimezone;
    
    // Format local time in airport's timezone based on user preference
    const timeFormat = getTimeFormat();
    const localTimeOptions = {
        timeZone: timezone,
        hour12: timeFormat === '12hr',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    };
    const localTime = now.toLocaleTimeString('en-US', localTimeOptions);
    document.getElementById('localTime').textContent = localTime;
    
    // Get timezone abbreviation and UTC offset (e.g., PST, PDT, EST, EDT)
    // Use the reusable function
    try {
        const timezoneAbbr = getTimezoneAbbreviation(now);
        
        // Calculate UTC offset in hours
        // Use a simple approach: format the same moment in both UTC and local timezone
        // and calculate the difference
        const utcTimeStr = now.toLocaleTimeString('en-US', {
            timeZone: 'UTC',
            hour12: false,
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        const localTimeStr = now.toLocaleTimeString('en-US', {
            timeZone: timezone,
            hour12: false,
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        
        // Parse time strings to get hours, minutes, seconds
        const parseTime = (timeStr) => {
            const parts = timeStr.split(':').map(Number);
            return parts[0] * 3600 + parts[1] * 60 + parts[2];
        };
        
        let utcSeconds = parseTime(utcTimeStr);
        let localSeconds = parseTime(localTimeStr);
        
        // Calculate offset (local - UTC)
        // Handle day boundaries by checking if difference is > 12 hours
        let offsetSeconds = localSeconds - utcSeconds;
        if (offsetSeconds > 12 * 3600) {
            offsetSeconds -= 24 * 3600; // Subtract a day (local is next day)
        } else if (offsetSeconds < -12 * 3600) {
            offsetSeconds += 24 * 3600; // Add a day (local is previous day)
        }
        
        const offsetHours = offsetSeconds / 3600;
        
        // Format offset as "(UTC-7)" or "(UTC+5)" with sign
        // Note: offset is inverted (UTC-7 means UTC is 7 hours ahead, so local is UTC-7)
        const offsetSign = offsetHours >= 0 ? '+' : '';
        const offsetDisplay = `(UTC${offsetSign}${Math.round(offsetHours)})`;
        
        // Display timezone abbreviation with offset
        document.getElementById('localTimezone').textContent = `${timezoneAbbr} ${offsetDisplay}`;
    } catch (error) {
        console.error('[Time] Error getting timezone abbreviation:', error);
        document.getElementById('localTimezone').textContent = '--';
    }
    
    // Zulu time (UTC)
    const zuluTime = now.toISOString().substr(11, 8);
    document.getElementById('zuluTime').textContent = zuluTime;
}
updateClocks();
setInterval(updateClocks, 1000);

// Store weather update time
let weatherLastUpdated = null;

// Store current weather data globally for toggle re-rendering
let currentWeatherData = null;

// Cookie helper functions for cross-subdomain preference sharing
// Hybrid approach: cookies (source of truth, cross-subdomain) + localStorage (fast cache)

/**
 * Get cookie value by name
 * @param {string} name - Cookie name
 * @returns {string|null} Cookie value or null if not found
 */
function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) {
        return decodeURIComponent(parts.pop().split(';').shift());
    }
    return null;
}

/**
 * Set cookie with cross-subdomain support
 * @param {string} name - Cookie name
 * @param {string} value - Cookie value
 * @param {number} days - Expiration in days (default: 365)
 */
function setCookie(name, value, days = 365) {
    const expires = new Date();
    expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));
    
    // Extract base domain (e.g., "aviationwx.org" from "kspb.aviationwx.org")
    const hostname = window.location.hostname;
    const domain = hostname.includes('.') 
        ? '.' + hostname.split('.').slice(-2).join('.')  // .aviationwx.org
        : hostname;  // localhost fallback
    
    // Build cookie string with cross-subdomain support
    let cookieString = `${name}=${encodeURIComponent(value)}; expires=${expires.toUTCString()}; path=/`;
    
    // Only set domain if not localhost (cookies don't work with localhost domain)
    if (!hostname.includes('localhost') && !hostname.includes('127.0.0.1')) {
        cookieString += `; domain=${domain}`;
    }
    
    // Add Secure flag in production (HTTPS only)
    if (window.location.protocol === 'https:') {
        cookieString += '; Secure';
    }
    
    // SameSite=Lax for CSRF protection while allowing cross-site navigation
    cookieString += '; SameSite=Lax';
    
    document.cookie = cookieString;
    
    // Also update localStorage as cache
    try {
        localStorage.setItem(name, value);
    } catch (e) {
        // localStorage may be disabled or full - continue without cache
        console.warn('[Preferences] Could not update localStorage cache:', e);
    }
}

/**
 * Sync preferences from cookies to localStorage on page load
 * Also migrates existing localStorage to cookies for backward compatibility
 */
function syncPreferencesFromCookies() {
    const preferences = [
        'aviationwx_time_format',
        'aviationwx_temp_unit',
        'aviationwx_distance_unit',
        'aviationwx_wind_speed_unit'
    ];
    
    preferences.forEach(pref => {
        const cookieValue = getCookie(pref);
        const localValue = localStorage.getItem(pref);
        
        if (cookieValue) {
            // Cookie exists - use it as source of truth, sync to localStorage
            try {
                localStorage.setItem(pref, cookieValue);
            } catch (e) {
                // localStorage may be disabled - continue
            }
        } else if (localValue) {
            // No cookie but localStorage exists - migrate to cookie
            setCookie(pref, localValue);
        }
    });
}

// Sync preferences on page load
syncPreferencesFromCookies();

// Time format preference (default to 12hr)
function getTimeFormat() {
    // Try cookie first (source of truth), then localStorage (cache), then default
    const format = getCookie('aviationwx_time_format') 
        || localStorage.getItem('aviationwx_time_format')
        || '12hr';
    return format;
}

function setTimeFormat(format) {
    // Set cookie (source of truth, cross-subdomain)
    setCookie('aviationwx_time_format', format);
    // localStorage is updated by setCookie, but ensure it's set
    try {
        localStorage.setItem('aviationwx_time_format', format);
    } catch (e) {
        // localStorage may be disabled - continue
    }
}

// Format time string (HH:MM or HH:MM:SS) based on preference
// Input: "07:15" or "07:15:30" (24-hour format)
// Output: "7:15 AM" or "07:15" based on preference
function formatTime(timeStr) {
    if (!timeStr || timeStr === '--') return timeStr;
    
    const format = getTimeFormat();
    if (format === '24hr') {
        return timeStr; // Already in 24-hour format
    }
    
    // Convert 24-hour to 12-hour format
    const parts = timeStr.split(':');
    if (parts.length < 2) return timeStr;
    
    let hours = parseInt(parts[0], 10);
    const minutes = parts[1];
    const seconds = parts[2] || '';
    
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours === 0 ? 12 : hours; // 0 should be 12
    
    if (seconds) {
        return `${hours}:${minutes}:${seconds} ${ampm}`;
    } else {
        return `${hours}:${minutes} ${ampm}`;
    }
}

// Temperature unit preference (default to F)
function getTempUnit() {
    // Try cookie first (source of truth), then localStorage (cache), then default
    const unit = getCookie('aviationwx_temp_unit')
        || localStorage.getItem('aviationwx_temp_unit')
        || 'F';
    return unit;
}

function setTempUnit(unit) {
    // Set cookie (source of truth, cross-subdomain)
    setCookie('aviationwx_temp_unit', unit);
    // localStorage is updated by setCookie, but ensure it's set
    try {
        localStorage.setItem('aviationwx_temp_unit', unit);
    } catch (e) {
        // localStorage may be disabled - continue
    }
}

// Convert Celsius to Fahrenheit
function cToF(c) {
    return (c * 9/5) + 32;
}

// Convert Fahrenheit to Celsius
function fToC(f) {
    return (f - 32) * 5/9;
}

// Format temperature based on current unit preference
function formatTemp(tempC) {
    if (tempC === null || tempC === undefined) return '--';
    // Validate that tempC is a valid number
    const numTemp = Number(tempC);
    if (isNaN(numTemp) || !isFinite(numTemp)) return '--';
    const unit = getTempUnit();
    if (unit === 'C') {
        return numTemp.toFixed(1);
    } else {
        return cToF(numTemp).toFixed(1);
    }
}

// Format temperature spread (allows decimals) based on current unit preference
function formatTempSpread(spreadC) {
    if (spreadC === null || spreadC === undefined) return '--';
    const unit = getTempUnit();
    if (unit === 'C') {
        return spreadC.toFixed(1);
    } else {
        // Convert spread from Celsius to Fahrenheit (spread conversion is same as temp: multiply by 9/5)
        return (spreadC * 9/5).toFixed(1);
    }
}

// Format timestamp as "at h:m:am/pm" or "at HH:MM" using airport's timezone
// Returns HTML with styling matching weather-unit class
function formatTempTimestamp(timestamp) {
    if (timestamp === null || timestamp === undefined) return '';
    
    try {
        // Get airport timezone, default to 'America/Los_Angeles' if not available
        const timezone = (AIRPORT_DATA && AIRPORT_DATA.timezone) || 'America/Los_Angeles';
        
        // Create date from timestamp (assumes UTC seconds)
        const date = new Date(timestamp * 1000);
        
        // Format in airport's local timezone based on user preference
        const timeFormat = getTimeFormat();
        const options = {
            timeZone: timezone,
            hour: 'numeric',
            minute: '2-digit',
            hour12: timeFormat === '12hr'
        };
        
        const formatted = date.toLocaleTimeString('en-US', options);
        
        // Return formatted time with "at" prefix and same styling as weather-unit
        return ` <span style="font-size: 0.9rem; color: #555;">at ${formatted}</span>`;
    } catch (error) {
        console.error('[TempTimestamp] Error formatting timestamp:', error);
        return '';
    }
}

// Distance/altitude unit preference (default to imperial/feet)
function getDistanceUnit() {
    // Try cookie first (source of truth), then localStorage (cache), then default
    const unit = getCookie('aviationwx_distance_unit')
        || localStorage.getItem('aviationwx_distance_unit')
        || 'ft';
    return unit;
}

function setDistanceUnit(unit) {
    // Set cookie (source of truth, cross-subdomain)
    setCookie('aviationwx_distance_unit', unit);
    // localStorage is updated by setCookie, but ensure it's set
    try {
        localStorage.setItem('aviationwx_distance_unit', unit);
    } catch (e) {
        // localStorage may be disabled - continue
    }
}

// Convert feet to meters
function ftToM(ft) {
    return Math.round(ft * 0.3048);
}

// Convert meters to feet
function mToFt(m) {
    return Math.round(m / 0.3048);
}

// Convert inches to centimeters
function inToCm(inches) {
    return (inches * 2.54).toFixed(2);
}

// Format altitude (feet) based on current unit preference
function formatAltitude(ft) {
    if (ft === null || ft === undefined || ft === '--') return '--';
    const unit = getDistanceUnit();
    return unit === 'm' ? ftToM(ft) : Math.round(ft);
}

// Format rainfall (inches) based on current unit preference
function formatRainfall(inches) {
    if (inches === null || inches === undefined) return '--';
    const unit = getDistanceUnit();
    if (unit === 'm') {
        return inToCm(inches);
    } else {
        return inches.toFixed(2);
    }
}

// Convert statute miles to kilometers
function smToKm(sm) {
    return sm * 1.609344;
}

// Format visibility (statute miles) based on current unit preference
function formatVisibility(sm) {
    if (sm === null || sm === undefined) return '--';
    const unit = getDistanceUnit();
    if (unit === 'm') {
        return smToKm(sm).toFixed(1);
    } else {
        return sm.toFixed(1);
    }
}

// Format ceiling (feet) based on current unit preference
function formatCeiling(ft) {
    if (ft === null || ft === undefined) return null;
    const unit = getDistanceUnit();
    return unit === 'm' ? ftToM(ft) : Math.round(ft);
}

// Wind speed unit preference (default to knots)
function getWindSpeedUnit() {
    // Try cookie first (source of truth), then localStorage (cache), then default
    const unit = getCookie('aviationwx_wind_speed_unit')
        || localStorage.getItem('aviationwx_wind_speed_unit')
        || 'kts';
    return unit;
}

function setWindSpeedUnit(unit) {
    // Set cookie (source of truth, cross-subdomain)
    setCookie('aviationwx_wind_speed_unit', unit);
    // localStorage is updated by setCookie, but ensure it's set
    try {
        localStorage.setItem('aviationwx_wind_speed_unit', unit);
    } catch (e) {
        // localStorage may be disabled - continue
    }
}

// Convert knots to miles per hour
function ktsToMph(kts) {
    return Math.round(kts * 1.15078);
}

// Convert knots to kilometers per hour
function ktsToKmh(kts) {
    return Math.round(kts * 1.852);
}

// Format wind speed based on current unit preference
function formatWindSpeed(kts) {
    if (kts === null || kts === undefined || kts === 0) return '0';
    const unit = getWindSpeedUnit();
    switch (unit) {
        case 'mph':
            return ktsToMph(kts);
        case 'km/h':
            return ktsToKmh(kts);
        default: // 'kts'
            return Math.round(kts);
    }
}

// Get wind speed unit label
function getWindSpeedUnitLabel() {
    const unit = getWindSpeedUnit();
    switch (unit) {
        case 'mph':
            return 'mph';
        case 'km/h':
            return 'km/h';
        default: // 'kts'
            return 'kts';
    }
}

// Temperature unit toggle handler
function initTempUnitToggle() {
    const toggle = document.getElementById('temp-unit-toggle');
    const display = document.getElementById('temp-unit-display');
    
    function updateToggle() {
        const unit = getTempUnit();
        display.textContent = unit === 'C' ? '°C' : '°F';
        toggle.title = `Switch to ${unit === 'C' ? 'Fahrenheit' : 'Celsius'}`;
    }
    
    toggle.addEventListener('click', () => {
        const currentUnit = getTempUnit();
        const newUnit = currentUnit === 'F' ? 'C' : 'F';
        setTempUnit(newUnit);
        updateToggle();
        // Re-render weather data with new unit if we have weather data
        if (currentWeatherData) {
            displayWeather(currentWeatherData);
        }
    });
    
    updateToggle();
}

// Distance unit toggle handler
function initDistanceUnitToggle() {
    const toggle = document.getElementById('distance-unit-toggle');
    const display = document.getElementById('distance-unit-display');
    
    function updateToggle() {
        const unit = getDistanceUnit();
        display.textContent = unit === 'm' ? 'm' : 'ft';
        toggle.title = `Switch to ${unit === 'm' ? 'feet' : 'meters'}`;
    }
    
    toggle.addEventListener('click', () => {
        const currentUnit = getDistanceUnit();
        const newUnit = currentUnit === 'ft' ? 'm' : 'ft';
        setDistanceUnit(newUnit);
        updateToggle();
        // Re-render weather data with new unit if we have weather data
        if (currentWeatherData) {
            displayWeather(currentWeatherData);
        }
    });
    
    updateToggle();
}

// Time format toggle handler
function initTimeFormatToggle() {
    const toggle = document.getElementById('time-format-toggle');
    const display = document.getElementById('time-format-display');
    
    function updateToggle() {
        const format = getTimeFormat();
        display.textContent = format === '24hr' ? '24hr' : '12hr';
        toggle.title = `Switch to ${format === '24hr' ? '12-hour' : '24-hour'} format`;
    }
    
    toggle.addEventListener('click', () => {
        const currentFormat = getTimeFormat();
        const newFormat = currentFormat === '12hr' ? '24hr' : '12hr';
        setTimeFormat(newFormat);
        updateToggle();
        // Update clocks immediately
        updateClocks();
        // Re-render weather data with new format if we have weather data
        if (currentWeatherData) {
            displayWeather(currentWeatherData);
        }
        // Update webcam timestamps to reflect new time format
        if (typeof updateWebcamTimestamps === 'function') {
            updateWebcamTimestamps();
        }
    });
    
    updateToggle();
}

// Initialize temperature unit toggle
// Try multiple initialization methods to ensure it works
function initTempToggle() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTempUnitToggle);
    } else {
        // DOM already loaded
        initTempUnitToggle();
    }
}

// Also try immediate initialization in case script is at end of body
if (document.getElementById('temp-unit-toggle')) {
    initTempUnitToggle();
} else {
    initTempToggle();
}

// Initialize distance unit toggle
if (document.getElementById('distance-unit-toggle')) {
    initDistanceUnitToggle();
} else {
    function initDistToggle() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initDistanceUnitToggle);
        } else {
            initDistanceUnitToggle();
        }
    }
    initDistToggle();
}

// Initialize time format toggle
if (document.getElementById('time-format-toggle')) {
    initTimeFormatToggle();
} else {
    function initTimeFormatToggleWrapper() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initTimeFormatToggle);
        } else {
            initTimeFormatToggle();
        }
    }
    initTimeFormatToggleWrapper();
}

// Wind speed unit toggle handler
function initWindSpeedUnitToggle() {
    const toggle = document.getElementById('wind-speed-unit-toggle');
    const display = document.getElementById('wind-speed-unit-display');
    
    function updateToggle() {
        const unit = getWindSpeedUnit();
        display.textContent = getWindSpeedUnitLabel();
        
        // Determine next unit for tooltip
        let nextUnit = 'mph';
        if (unit === 'kts') nextUnit = 'mph';
        else if (unit === 'mph') nextUnit = 'km/h';
        else nextUnit = 'kts';
        
        toggle.title = `Switch to ${nextUnit === 'mph' ? 'miles per hour' : nextUnit === 'km/h' ? 'kilometers per hour' : 'knots'}`;
    }
    
    toggle.addEventListener('click', () => {
        const currentUnit = getWindSpeedUnit();
        // Cycle: kts -> mph -> km/h -> kts
        let newUnit = 'kts';
        if (currentUnit === 'kts') newUnit = 'mph';
        else if (currentUnit === 'mph') newUnit = 'km/h';
        else newUnit = 'kts';
        
        setWindSpeedUnit(newUnit);
        updateToggle();
        // Re-render wind data with new unit if we have weather data
        if (currentWeatherData) {
            updateWindVisual(currentWeatherData);
        }
    });
    
    updateToggle();
}

// Initialize wind speed unit toggle
if (document.getElementById('wind-speed-unit-toggle')) {
    initWindSpeedUnitToggle();
} else {
    function initWindToggle() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initWindSpeedUnitToggle);
        } else {
            initWindSpeedUnitToggle();
        }
    }
    initWindToggle();
}

// Set weather last updated time to relative
function updateWeatherTimestamp() {
    if (weatherLastUpdated === null) {
        document.getElementById('weather-last-updated').textContent = '--';
        document.getElementById('wind-last-updated').textContent = '--';
        return;
    }
    
    const now = new Date();
    const diffSeconds = Math.floor((now - weatherLastUpdated) / 1000);
    
    // Determine if using METAR-only source
    const isMetarOnly = AIRPORT_DATA && 
                        AIRPORT_DATA.weather_source && 
                        AIRPORT_DATA.weather_source.type === 'metar';
    
    // Get weather refresh interval (default to 60 seconds if not configured)
    // Ensure minimum value to prevent invalid thresholds
    const weatherRefreshSeconds = Math.max(1, (AIRPORT_DATA && AIRPORT_DATA.weather_refresh_seconds) 
        ? AIRPORT_DATA.weather_refresh_seconds 
        : 60);
    
    // Use METAR-specific thresholds when using METAR-only source
    // METARs are published hourly, so thresholds are based on hours, not minutes
    // For non-METAR sources, use multiplier-based thresholds (similar to webcams)
    let staleThresholdSeconds, veryStaleThresholdSeconds;
    if (isMetarOnly) {
        // METAR thresholds: warning at 1 hour, very stale at 2 hours
        staleThresholdSeconds = WEATHER_STALENESS_WARNING_HOURS_METAR * SECONDS_PER_HOUR;
        veryStaleThresholdSeconds = WEATHER_STALENESS_ERROR_HOURS_METAR * SECONDS_PER_HOUR;
    } else {
        // Primary source thresholds: use multiplier-based approach (like webcams)
        // Warning at 5x refresh interval, error at 10x refresh interval
        staleThresholdSeconds = weatherRefreshSeconds * WEATHER_STALENESS_WARNING_MULTIPLIER;
        veryStaleThresholdSeconds = weatherRefreshSeconds * WEATHER_STALENESS_ERROR_MULTIPLIER;
    }
    
    const isStale = diffSeconds >= staleThresholdSeconds;
    const isVeryStale = diffSeconds >= veryStaleThresholdSeconds;
    
    // Show/hide warning elements based on staleness
    const weatherWarningEl = document.getElementById('weather-timestamp-warning');
    const windWarningEl = document.getElementById('wind-timestamp-warning');
    if (weatherWarningEl) {
        weatherWarningEl.style.display = (isStale || isVeryStale) ? 'inline' : 'none';
    }
    if (windWarningEl) {
        windWarningEl.style.display = (isStale || isVeryStale) ? 'inline' : 'none';
    }
    
    // Format timestamp: show actual time with relative time in parentheses if >= 1 hour, otherwise relative time only
    let timeStr;
    if (diffSeconds < 0) {
        timeStr = 'just now';
    } else if (diffSeconds >= 3600) {
        // Show actual time with relative time in parentheses (>= 1 hour)
        try {
            const defaultTimezone = typeof DEFAULT_TIMEZONE !== 'undefined' ? DEFAULT_TIMEZONE : 'UTC';
            const timezone = (AIRPORT_DATA && AIRPORT_DATA.timezone) || defaultTimezone;
            const timeFormat = getTimeFormat();
            
            const timeOptions = {
                timeZone: timezone,
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
                hour12: timeFormat === '12hr'
            };
            
            const actualTime = weatherLastUpdated.toLocaleTimeString('en-US', timeOptions);
            const relativeTime = formatRelativeTime(diffSeconds);
            
            // Zero-width space before parenthesis allows responsive wrapping
            timeStr = `${actualTime}\u200B (${relativeTime})`;
        } catch (error) {
            console.error('[WeatherTimestamp] Error formatting timestamp:', error);
            timeStr = formatRelativeTime(diffSeconds);
        }
    } else {
        // Show only relative time for recent updates (< 1 hour)
        timeStr = formatRelativeTime(diffSeconds);
    }
    
    const weatherEl = document.getElementById('weather-last-updated');
    const windEl = document.getElementById('wind-last-updated');
    weatherEl.textContent = timeStr;
    windEl.textContent = timeStr;
    
    // Apply visual styling based on staleness
    [weatherEl, windEl].forEach(el => {
        if (isVeryStale) {
            el.style.color = '#c00'; // Red for very stale
            el.style.fontWeight = 'bold';
        } else if (isStale) {
            el.style.color = '#f80'; // Orange for stale
            el.style.fontWeight = '500';
        } else {
            el.style.color = '#666'; // Gray for fresh
            el.style.fontWeight = 'normal';
        }
    });
}

/**
 * Fetch outage status from server and update banner
 * Called periodically to sync with server state
 */
async function fetchOutageStatus() {
    const banner = document.getElementById('data-outage-banner');
    if (!banner && !AIRPORT_ID) {
        return; // No banner and no airport ID
    }
    
    try {
        const baseUrl = window.location.protocol + '//' + window.location.host;
        const url = `${baseUrl}/api/outage-status.php?airport=${AIRPORT_ID}`;
        
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            console.warn('[OutageBanner] Failed to fetch outage status:', response.status);
            return;
        }
        
        const data = await response.json();
        
        if (!data.success) {
            console.warn('[OutageBanner] Server returned error:', data.error);
            return;
        }
        
        // Update banner based on server state
        if (data.in_outage && data.newest_timestamp > 0) {
            // Show banner if it exists, or create it if needed
            if (banner) {
                banner.style.display = 'block';
                banner.dataset.newestTimestamp = data.newest_timestamp.toString();
                updateOutageBannerTimestamp();
            }
        } else {
            // Hide banner if server says no outage
            if (banner) {
                banner.style.display = 'none';
            }
        }
    } catch (error) {
        console.warn('[OutageBanner] Error fetching outage status:', error);
        // Silently fail - client-side checks will continue
    }
}

/**
 * Check if all configured data sources are stale and update outage banner
 * Called after weather data updates and webcam updates
 * Uses client-side data for immediate feedback
 */
function checkAndUpdateOutageBanner() {
    const banner = document.getElementById('data-outage-banner');
    if (!banner) {
        return; // Banner doesn't exist (not in outage state)
    }
    
    const outageThresholdSeconds = DATA_OUTAGE_BANNER_HOURS * SECONDS_PER_HOUR;
    const now = Math.floor(Date.now() / 1000);
    const sources = [];
    let newestTimestamp = 0;
    
    // Check primary weather source (if configured and not METAR-only)
    // METAR-only airports are handled separately below
    const isMetarOnly = AIRPORT_DATA && AIRPORT_DATA.weather_source && AIRPORT_DATA.weather_source.type === 'metar';
    const hasPrimarySource = AIRPORT_DATA && AIRPORT_DATA.weather_source && !isMetarOnly;
    
    if (hasPrimarySource) {
        if (weatherLastUpdated) {
            const timestamp = Math.floor(weatherLastUpdated.getTime() / 1000);
            const age = now - timestamp;
            const isStale = age >= outageThresholdSeconds;
            
            sources.push({
                name: 'primary',
                timestamp: timestamp,
                age: age,
                stale: isStale
            });
            
            if (timestamp > newestTimestamp) {
                newestTimestamp = timestamp;
            }
        } else {
            // No weather data - treat as stale
            sources.push({
                name: 'primary',
                timestamp: 0,
                age: Infinity,
                stale: true
            });
        }
    }
    
    // Check METAR source (if configured)
    // METAR is configured if metar_station exists OR weather_source.type === 'metar'
    const hasMetar = (AIRPORT_DATA && AIRPORT_DATA.metar_station) || isMetarOnly;
    
    if (hasMetar) {
        // METAR timestamp comes from weather data
        if (currentWeatherData) {
            let metarTimestamp = 0;
            if (currentWeatherData.obs_time_metar && currentWeatherData.obs_time_metar > 0) {
                metarTimestamp = currentWeatherData.obs_time_metar;
            } else if (currentWeatherData.last_updated_metar && currentWeatherData.last_updated_metar > 0) {
                metarTimestamp = currentWeatherData.last_updated_metar;
            }
            
            if (metarTimestamp > 0) {
                const age = now - metarTimestamp;
                const isStale = age >= outageThresholdSeconds;
                
                sources.push({
                    name: 'metar',
                    timestamp: metarTimestamp,
                    age: age,
                    stale: isStale
                });
                
                if (metarTimestamp > newestTimestamp) {
                    newestTimestamp = metarTimestamp;
                }
            } else {
                // No METAR timestamp - treat as stale
                sources.push({
                    name: 'metar',
                    timestamp: 0,
                    age: Infinity,
                    stale: true
                });
            }
        } else {
            // No weather data - treat as stale
            sources.push({
                name: 'metar',
                timestamp: 0,
                age: Infinity,
                stale: true
            });
        }
    }
    
    // Check all webcams (if configured)
    if (AIRPORT_DATA && AIRPORT_DATA.webcams && Array.isArray(AIRPORT_DATA.webcams) && AIRPORT_DATA.webcams.length > 0) {
        let webcamStaleCount = 0;
        let webcamNewestTimestamp = 0;
        
        AIRPORT_DATA.webcams.forEach((cam, index) => {
            const timestamp = CAM_TS[index] || 0;
            
            if (timestamp > 0) {
                const age = now - timestamp;
                const isStale = age >= outageThresholdSeconds;
                
                if (isStale) {
                    webcamStaleCount++;
                }
                
                if (timestamp > webcamNewestTimestamp) {
                    webcamNewestTimestamp = timestamp;
                }
            } else {
                // No timestamp - treat as stale
                webcamStaleCount++;
            }
        });
        
        const allWebcamsStale = (webcamStaleCount === AIRPORT_DATA.webcams.length);
        
        sources.push({
            name: 'webcams',
            stale: allWebcamsStale,
            total: AIRPORT_DATA.webcams.length,
            stale_count: webcamStaleCount
        });
        
        if (allWebcamsStale && webcamNewestTimestamp > 0) {
            if (webcamNewestTimestamp > newestTimestamp) {
                newestTimestamp = webcamNewestTimestamp;
            }
        }
    }
    
    // Check if ALL configured sources are stale
    let allStale = true;
    for (const source of sources) {
        if (!source.stale) {
            allStale = false;
            break;
        }
    }
    
    // If no sources configured, hide banner
    if (sources.length === 0) {
        banner.style.display = 'none';
        return;
    }
    
    // Show or hide banner based on all-stale status
    if (allStale && newestTimestamp > 0) {
        banner.style.display = 'block';
        banner.dataset.newestTimestamp = newestTimestamp.toString();
        updateOutageBannerTimestamp();
    } else {
        // At least one source is fresh - hide banner
        banner.style.display = 'none';
    }
}

/**
 * Update the timestamp display in the outage banner
 */
function updateOutageBannerTimestamp() {
    const banner = document.getElementById('data-outage-banner');
    if (!banner) {
        return;
    }
    
    const timestampElem = document.getElementById('outage-newest-time');
    if (!timestampElem) {
        return;
    }
    
    const newestTimestamp = parseInt(banner.dataset.newestTimestamp || '0');
    if (!newestTimestamp || newestTimestamp <= 0) {
        timestampElem.textContent = 'unknown time';
        return;
    }
    
    // Format timestamp similar to other timestamp displays on the page
    try {
        const timestampDate = new Date(newestTimestamp * 1000);
        const now = new Date();
        const diffSeconds = Math.floor((now - timestampDate) / 1000);
        
        // Get airport timezone
        const defaultTimezone = typeof DEFAULT_TIMEZONE !== 'undefined' ? DEFAULT_TIMEZONE : 'UTC';
        const timezone = (AIRPORT_DATA && AIRPORT_DATA.timezone) || defaultTimezone;
        
        // Format: "December 19, 2024 at 2:30 PM PST" or relative if < 24 hours
        let formattedTime;
        if (diffSeconds < 86400) {
            // Less than 24 hours: show relative time
            const hours = Math.floor(diffSeconds / 3600);
            const minutes = Math.floor((diffSeconds % 3600) / 60);
            
            if (hours === 0) {
                formattedTime = minutes + (minutes === 1 ? ' minute' : ' minutes') + ' ago';
            } else {
                const minStr = minutes > 0 ? ' ' + minutes + (minutes === 1 ? ' minute' : ' minutes') : '';
                formattedTime = hours + (hours === 1 ? ' hour' : ' hours') + minStr + ' ago';
            }
        } else {
            // 24 hours or more: show absolute time with timezone
            const formatter = new Intl.DateTimeFormat('en-US', {
                timeZone: timezone,
                month: 'long',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
            
            const tzAbbr = getTimezoneAbbreviation(timestampDate);
            formattedTime = formatter.format(timestampDate) + ' ' + tzAbbr;
        }
        
        timestampElem.textContent = formattedTime;
    } catch (error) {
        console.error('[OutageBanner] Error formatting timestamp:', error);
        timestampElem.textContent = 'unknown time';
    }
}

// Track fetching state for visual indicators
let isFetchingWeather = false;

// Fetch weather data
// Parameters:
//   forceRefresh: if true, bypass cache to force a fresh fetch
async function fetchWeather(forceRefresh = false) {
    // Prevent concurrent fetches
    if (isFetchingWeather && !forceRefresh) {
        console.log('[Weather] Fetch already in progress, skipping...');
        return;
    }
    
    try {
        isFetchingWeather = true;
        
        // Check if existing data is stale (older than 2x refresh interval)
        // Calculate force refresh threshold based on weather refresh interval
        const weatherRefreshMs = (AIRPORT_DATA && AIRPORT_DATA.weather_refresh_seconds) 
            ? AIRPORT_DATA.weather_refresh_seconds * 1000 
            : 60000; // Default 60 seconds
        // Force refresh if data is older than 2x the refresh interval
        const forceRefreshThreshold = weatherRefreshMs * 2;
        const shouldForceRefresh = forceRefresh || (weatherLastUpdated !== null && (Date.now() - weatherLastUpdated.getTime()) > forceRefreshThreshold);
        
        // Use absolute path to ensure it works from subdomains
        const baseUrl = window.location.protocol + '//' + window.location.host;
        let url = `${baseUrl}/api/weather.php?airport=${AIRPORT_ID}`;
        
        // Build fetch options
        const fetchOptions = {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
            },
            credentials: 'same-origin'
        };
        
        // If data is stale or we're forcing refresh, bypass cache using multiple strategies
        if (shouldForceRefresh) {
            // Strategy 1: Add cache-busting query parameter (forces Service Worker to treat as new request)
            url += `&_cb=${Date.now()}`;
            // Strategy 2: Use cache: 'reload' to bypass browser cache
            fetchOptions.cache = 'reload';
            // Strategy 3: Add Cache-Control header to bypass Service Worker cache
            fetchOptions.headers['Cache-Control'] = 'no-cache';
            console.log('[Weather] Forcing refresh - bypassing cache due to stale data');
        }
        
        const response = await fetch(url, fetchOptions);
        
        if (!response.ok) {
            const text = await response.text();
            console.error('[Weather] Error response body:', text);
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        // Get response as text first to check if it's valid JSON
        const responseText = await response.text();
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (parseError) {
            console.error('[Weather] JSON parse error:', parseError);
            console.error('[Weather] Full response text length:', responseText.length);
            console.error('[Weather] Full response text:', responseText);
            throw new Error(`Invalid JSON response from server. See console for details.`);
        }
        
        if (data.success) {
            const isStale = data.stale === true || false;
            const serverTimestamp = data.weather.last_updated ? new Date(data.weather.last_updated * 1000) : null;
            
            // Solution C: Detect if server data is older than client data (indicates stale cache was served)
            const serverDataIsStale = serverTimestamp && weatherLastUpdated && 
                serverTimestamp.getTime() < weatherLastUpdated.getTime();
            
            if (serverDataIsStale) {
                console.warn('[Weather] Server data is older than client data - stale cache detected, forcing immediate refresh');
                // Force immediate refresh with cache bypass
                setTimeout(() => fetchWeather(true), 100);
                return; // Don't update UI with stale data
            }
            
            currentWeatherData = data.weather; // Store globally for toggle re-rendering
            displayWeather(data.weather);
            updateWindVisual(data.weather);
            weatherLastUpdated = serverTimestamp || new Date();
            updateWeatherTimestamp(); // Update the timestamp
            checkAndUpdateOutageBanner(); // Check if outage banner should be shown/hidden
            
            // If server indicates data is stale, schedule a fresh fetch soon (30 seconds)
            if (isStale) {
                console.log('[Weather] Received stale data from server - scheduling refresh in 30 seconds');
                // Clear any existing stale refresh timer
                if (window.staleRefreshTimer) {
                    clearTimeout(window.staleRefreshTimer);
                }
                // Schedule a refresh with cache bypass
                // Use half of weather refresh interval, minimum 30 seconds
                const weatherRefreshMs = (AIRPORT_DATA && AIRPORT_DATA.weather_refresh_seconds) 
                    ? AIRPORT_DATA.weather_refresh_seconds * 1000 
                    : 60000; // Default 60 seconds
                const staleRefreshDelay = Math.max(30000, weatherRefreshMs / 2);
                window.staleRefreshTimer = setTimeout(() => {
                    fetchWeather(true); // Force refresh
                }, staleRefreshDelay);
            } else {
                // Clear stale refresh timer if we got fresh data
                if (window.staleRefreshTimer) {
                    clearTimeout(window.staleRefreshTimer);
                    window.staleRefreshTimer = null;
                }
            }
        } else {
            console.error('[Weather] API returned error:', data.error);
            displayError(data.error || 'Failed to fetch weather data');
        }
    } catch (error) {
        console.error('[Weather] Fetch error:', error);
        console.error('[Weather] Error stack:', error.stack);
        displayError('Unable to load weather data: ' + error.message + '. Check browser console for details.');
    } finally {
        isFetchingWeather = false;
    }
}

function displayWeather(weather) {
    // Determine weather emojis based on abnormal conditions only
    function getWeatherEmojis(weather) {
        const emojis = [];
        const tempF = weather.temperature_f;
        const precip = weather.precip_accum || 0;
        const windSpeed = weather.wind_speed || 0;
        
        // Precipitation emoji (always show if present - abnormal condition)
        if (precip > 0.01) {
            if (tempF !== null && tempF < 32) {
                emojis.push('❄️'); // Snow
            } else {
                emojis.push('🌧️'); // Rain
            }
        }
        
        // High wind emoji (only show if concerning - abnormal condition)
        if (windSpeed > 25) {
            emojis.push('💨'); // Strong wind (>25 kts)
        } else if (windSpeed > 15) {
            emojis.push('🌬️'); // Moderate wind (15-25 kts)
        }
        // No emoji for ≤ 15 kts (normal wind)
        
        // Low ceiling/poor visibility emoji (only show if concerning - abnormal condition)
        if (weather.ceiling !== null) {
            if (weather.ceiling < 1000) {
                emojis.push('☁️'); // Low ceiling (<1000 ft AGL - IFR/LIFR)
            } else if (weather.ceiling < 3000) {
                emojis.push('🌥️'); // Marginal ceiling (1000-3000 ft AGL - MVFR)
            }
            // No emoji for ≥ 3000 ft (normal VFR ceiling)
        } else if (weather.cloud_cover) {
            // Fallback to cloud cover if ceiling not available
            switch (weather.cloud_cover) {
                case 'OVC':
                case 'OVX':
                    emojis.push('☁️'); // Overcast (typically low ceiling)
                    break;
                case 'BKN':
                    emojis.push('🌥️'); // Broken (marginal conditions)
                    break;
                // No emoji for SCT or FEW (normal VFR conditions)
            }
        }
        
        // Poor visibility (if available and concerning)
        if (weather.visibility !== null && weather.visibility < 3) {
            emojis.push('🌫️'); // Poor visibility (< 3 SM)
        }
        
        // Extreme temperatures (only show if extreme - abnormal condition)
        if (tempF !== null) {
            if (tempF > 90) {
                emojis.push('🥵'); // Extreme heat (>90°F)
            } else if (tempF < 20) {
                emojis.push('❄️'); // Extreme cold (<20°F)
            }
            // No emoji for 20°F to 90°F (normal temperature range)
        }
        
        // Return emojis if any, otherwise empty string (no emojis for normal conditions)
        return emojis.length > 0 ? emojis.join(' ') : '';
    }
    
    const weatherEmojis = getWeatherEmojis(weather);
    
    const container = document.getElementById('weather-data');
    
    container.innerHTML = `
        <!-- Aviation Conditions (METAR-required data) -->
        ${(AIRPORT_DATA && AIRPORT_DATA.metar_station) ? `
        <div class="weather-group">
            ${(() => {
                // Check if METAR data is actually available (has METAR timestamp)
                const hasMetarData = (weather.obs_time_metar && weather.obs_time_metar > 0) || (weather.last_updated_metar && weather.last_updated_metar > 0);
                if (!hasMetarData) {
                    // METAR is unavailable - show all fields as '--'
                    return `
                    <div class="weather-item"><span class="label">Condition</span><span class="weather-value">--</span></div>
                    <div class="weather-item"><span class="label">Visibility</span><span class="weather-value">--</span></div>
                    <div class="weather-item"><span class="label">Ceiling</span><span class="weather-value">--</span></div>
                    `;
                }
                // METAR data is available - show values
                return `
                <div class="weather-item"><span class="label">Condition</span><span class="weather-value ${weather.flight_category_class || ''}">${weather.flight_category || '--'} ${weather.flight_category ? weatherEmojis : ''}</span></div>
                <div class="weather-item"><span class="label">Visibility</span><span class="weather-value">${formatVisibility(weather.visibility)}</span><span class="weather-unit">${weather.visibility !== null && weather.visibility !== undefined ? (getDistanceUnit() === 'm' ? 'km' : 'SM') : ''}</span>${weather.visibility !== null && weather.visibility !== undefined ? formatTempTimestamp(weather.obs_time_metar || weather.last_updated_metar) : ''}</div>
                <div class="weather-item"><span class="label">Ceiling</span><span class="weather-value">${weather.ceiling !== null && weather.ceiling !== undefined ? formatCeiling(weather.ceiling) : (weather.visibility !== null && weather.visibility !== undefined ? 'Unlimited' : '--')}</span><span class="weather-unit">${weather.ceiling !== null && weather.ceiling !== undefined ? (getDistanceUnit() === 'm' ? 'm AGL' : 'ft AGL') : ''}</span>${(weather.ceiling !== null && weather.ceiling !== undefined || (weather.visibility !== null && weather.visibility !== undefined)) ? formatTempTimestamp(weather.obs_time_metar || weather.last_updated_metar) : ''}</div>
                `;
            })()}
        </div>
        ` : ''}
        
        <!-- Temperature -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Today's High</span><span class="weather-value">${formatTemp(weather.temp_high_today)}</span><span class="weather-unit">${getTempUnit() === 'C' ? '°C' : '°F'}</span>${formatTempTimestamp(weather.temp_high_ts)}</div>
            <div class="weather-item"><span class="label">Current Temperature</span><span class="weather-value">${formatTemp(weather.temperature)}</span><span class="weather-unit">${getTempUnit() === 'C' ? '°C' : '°F'}</span></div>
            <div class="weather-item"><span class="label">Today's Low</span><span class="weather-value">${formatTemp(weather.temp_low_today)}</span><span class="weather-unit">${getTempUnit() === 'C' ? '°C' : '°F'}</span>${formatTempTimestamp(weather.temp_low_ts)}</div>
        </div>
        
        <!-- Moisture & Precipitation -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Dewpoint Spread</span><span class="weather-value">${formatTempSpread(weather.dewpoint_spread)}</span><span class="weather-unit">${getTempUnit() === 'C' ? '°C' : '°F'}</span></div>
            <div class="weather-item"><span class="label">Dewpoint</span><span class="weather-value">${formatTemp(weather.dewpoint)}</span><span class="weather-unit">${getTempUnit() === 'C' ? '°C' : '°F'}</span></div>
            <div class="weather-item"><span class="label">Humidity</span><span class="weather-value">${weather.humidity !== null && weather.humidity !== undefined ? Math.round(weather.humidity) : '--'}</span><span class="weather-unit">${weather.humidity !== null && weather.humidity !== undefined ? '%' : ''}</span></div>
        </div>
        
        <!-- Precipitation & Daylight -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Rainfall Today</span><span class="weather-value">${formatRainfall(weather.precip_accum)}</span><span class="weather-unit">${getDistanceUnit() === 'm' ? 'cm' : 'in'}</span></div>
            <div class="weather-item sunrise-sunset">
                <span style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-size: 1.2rem;">🌅</span>
                    <span class="label">Sunrise</span>
                </span>
                <span class="weather-value">${formatTime(weather.sunrise || '--')} <span style="font-size: 0.75rem; color: #555;">${getTimezoneAbbreviation()}</span></span>
            </div>
            <div class="weather-item sunrise-sunset">
                <span style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-size: 1.2rem;">🌇</span>
                    <span class="label">Sunset</span>
                </span>
                <span class="weather-value">${formatTime(weather.sunset || '--')} <span style="font-size: 0.75rem; color: #555;">${getTimezoneAbbreviation()}</span></span>
            </div>
        </div>
        
        <!-- Pressure & Altitude -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Pressure</span><span class="weather-value">${weather.pressure ? weather.pressure.toFixed(2) : '--'}</span><span class="weather-unit">inHg</span></div>
            <div class="weather-item"><span class="label">Pressure Altitude</span><span class="weather-value">${formatAltitude(weather.pressure_altitude)}</span><span class="weather-unit">${getDistanceUnit() === 'm' ? 'm' : 'ft'}</span></div>
            <div class="weather-item"><span class="label">Density Altitude</span><span class="weather-value">${formatAltitude(weather.density_altitude)}</span><span class="weather-unit">${getDistanceUnit() === 'm' ? 'm' : 'ft'}</span></div>
        </div>
    `;
}

function displayError(msg) {
    document.getElementById('weather-data').innerHTML = `<div class="weather-item loading">${msg}</div>`;
}


let windAnimationFrame = null;
let windDirection = 0;
let windSpeed = 0;

// Parse runway name to extract designations (e.g., "28L/10R" → {heading1: 280, designation1: "L", heading2: 100, designation2: "R"})
function parseRunwayName(name) {
    if (!name || typeof name !== 'string') {
        return { designation1: '', designation2: '' };
    }
    
    // Split by / to get both ends
    const parts = name.split('/');
    if (parts.length !== 2) {
        return { designation1: '', designation2: '' };
    }
    
    // Extract designation (L, C, or R) from each end
    const extractDesignation = (str) => {
        const match = str.match(/(\d+)([LCR])/i);
        return match ? match[2].toUpperCase() : '';
    };
    
    return {
        designation1: extractDesignation(parts[0]),
        designation2: extractDesignation(parts[1])
    };
}

// Group parallel runways by similar heading_1 (within 5 degrees)
function groupParallelRunways(runways) {
    const groups = [];
    const processed = new Set();
    
    runways.forEach((rw, i) => {
        if (processed.has(i)) return;
        
        const group = [rw];
        processed.add(i);
        
        // Find all runways with similar heading_1 (within 5 degrees)
        runways.forEach((otherRw, j) => {
            if (i === j || processed.has(j)) return;
            
            const headingDiff = Math.abs(rw.heading_1 - otherRw.heading_1);
            const normalizedDiff = Math.min(headingDiff, 360 - headingDiff); // Handle wrap-around
            
            if (normalizedDiff <= 5) {
                group.push(otherRw);
                processed.add(j);
            }
        });
        
        groups.push(group);
    });
    
    return groups;
}

// Calculate horizontal offset for parallel runways
// Offset is perpendicular to the runway heading
function calculateRunwayOffset(heading, groupIndex, groupSize, maxOffset) {
    if (groupSize === 1) return { x: 0, y: 0 };
    
    // Calculate offset index (centered: -1, 0, 1 for 3 runways)
    const offsetIndex = groupIndex - (groupSize - 1) / 2;
    
    // Calculate perpendicular angle (heading + 90 degrees)
    const perpAngle = ((heading + 90) * Math.PI) / 180;
    
    // Calculate offset distance
    const offsetDist = offsetIndex * maxOffset;
    
    return {
        x: Math.sin(perpAngle) * offsetDist,
        y: -Math.cos(perpAngle) * offsetDist
    };
}

function updateWindVisual(weather) {
    const canvas = document.getElementById('windCanvas');
    const ctx = canvas.getContext('2d');
    const cx = canvas.width / 2, cy = canvas.height / 2, r = Math.min(canvas.width, canvas.height) / 2 - 20;
    
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    // Draw outer circle
    ctx.strokeStyle = '#333'; ctx.lineWidth = 2; ctx.beginPath(); ctx.arc(cx, cy, r, 0, 2 * Math.PI); ctx.stroke();
    
    // Group parallel runways
    const runwayGroups = groupParallelRunways(RUNWAYS);
    const maxOffset = 20; // Maximum offset in pixels for parallel runways
    
    // Draw each runway group
    runwayGroups.forEach(group => {
        group.forEach((rw, groupIndex) => {
            const heading1 = rw.heading_1;
            const heading2 = rw.heading_2;
            const angle1 = (heading1 * Math.PI) / 180;
            const runwayLength = r * 0.9;
            
            // Parse runway name to get designations
            const designations = parseRunwayName(rw.name);
            
            // Calculate offset for parallel runways
            const offset = calculateRunwayOffset(heading1, groupIndex, group.length, maxOffset);
            
            // Draw runway as a single line (not twice!)
            ctx.strokeStyle = '#0066cc';
            ctx.lineWidth = 8;
            ctx.lineCap = 'round';
            ctx.beginPath();
            
            // Calculate runway endpoints with offset
            const startX = cx - Math.sin(angle1) * runwayLength / 2 + offset.x;
            const startY = cy + Math.cos(angle1) * runwayLength / 2 + offset.y;
            const endX = cx + Math.sin(angle1) * runwayLength / 2 + offset.x;
            const endY = cy - Math.cos(angle1) * runwayLength / 2 + offset.y;
            
            ctx.moveTo(startX, startY);
            ctx.lineTo(endX, endY);
            ctx.stroke();
            
            // Label runway ends with designations
            ctx.font = 'bold 14px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            
            // Position labels closer to runway ends (small offset from runway end)
            const labelOffset = 12; // Distance from runway end to label
            
            // Label for heading 1 (at start end)
            const heading1Str = Math.floor(heading1 / 10).toString().padStart(2, '0');
            const label1 = heading1Str + (designations.designation1 || '');
            // Position label just beyond the start end of the runway
            const label1X = (cx - Math.sin(angle1) * (runwayLength / 2 + labelOffset)) + offset.x;
            const label1Y = (cy + Math.cos(angle1) * (runwayLength / 2 + labelOffset)) + offset.y;
            
            // Draw white outline for label 1
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 3;
            ctx.strokeText(label1, label1X, label1Y);
            // Draw label text
            ctx.fillStyle = '#0066cc';
            ctx.fillText(label1, label1X, label1Y);
            
            // Label for heading 2 (at end end)
            const heading2Str = Math.floor(heading2 / 10).toString().padStart(2, '0');
            const label2 = heading2Str + (designations.designation2 || '');
            // Position label just beyond the end end of the runway
            const label2X = (cx + Math.sin(angle1) * (runwayLength / 2 + labelOffset)) + offset.x;
            const label2Y = (cy - Math.cos(angle1) * (runwayLength / 2 + labelOffset)) + offset.y;
            
            // Draw white outline for label 2
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 3;
            ctx.strokeText(label2, label2X, label2Y);
            // Draw label text
            ctx.fillStyle = '#0066cc';
            ctx.fillText(label2, label2X, label2Y);
        });
    });
    
    // Draw wind only if speed > 0
    const ws = weather.wind_speed ?? null;
    const wd = weather.wind_direction;
    const isVariableWind = wd === 'VRB' || wd === 'vrb';
    const windDirNumeric = typeof wd === 'number' && wd > 0 ? wd : null;
    
    // Get today's peak gust from server
    const todaysPeakGust = weather.peak_gust_today || 0;
    
    // Populate wind details section
    const windDetails = document.getElementById('wind-details');
    const gustFactor = weather.gust_factor ?? null;
    
    const windUnitLabel = getWindSpeedUnitLabel();
    windDetails.innerHTML = `
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e0e0e0;">
            <span style="color: #555;">Wind Speed:</span>
            <span style="font-weight: bold;">${ws === null || ws === undefined ? '--' : (ws > 0 ? formatWindSpeed(ws) + ' ' + windUnitLabel : 'Calm')}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e0e0e0;">
            <span style="color: #555;">Wind Direction:</span>
            <span style="font-weight: bold;">${isVariableWind ? 'VRB' : (windDirNumeric ? windDirNumeric + '°' : '--')}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e0e0e0;">
            <span style="color: #555;">Gust Factor:</span>
            <span style="font-weight: bold;">${gustFactor === null || gustFactor === undefined ? '--' : (gustFactor > 0 ? formatWindSpeed(gustFactor) + ' ' + windUnitLabel : '0')}</span>
        </div>
        <div style="padding: 0.5rem 0; border-bottom: 1px solid #e0e0e0;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                <span style="color: #555;">Today's Peak Gust:</span>
                <span style="font-weight: bold;">${todaysPeakGust > 0 ? formatWindSpeed(todaysPeakGust) + ' ' + windUnitLabel : '--'}</span>
            </div>
            ${weather.peak_gust_time ? (() => {
                const timeFormat = getTimeFormat();
                const date = new Date(weather.peak_gust_time * 1000);
                const timezone = (AIRPORT_DATA && AIRPORT_DATA.timezone) || 'America/Los_Angeles';
                const formatted = date.toLocaleTimeString('en-US', {
                    timeZone: timezone,
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: timeFormat === '12hr'
                });
                return `<div style="text-align: right; font-size: 0.9rem; color: #555; padding-left: 0.5rem;">at ${formatted}</div>`;
            })() : ''}
        </div>
    `;
    
    if (ws !== null && ws !== undefined && ws > 1 && !isVariableWind && windDirNumeric !== null) {
        // Store for animation (only if we have a valid numeric direction)
        windDirection = (windDirNumeric * Math.PI) / 180;
        windSpeed = ws;
        
        // Draw wind arrow
        drawWindArrow(ctx, cx, cy, r, windDirection, windSpeed, 0);
    } else if (ws !== null && ws !== undefined && ws > 1 && isVariableWind) {
        // Variable wind - draw "VRB" text
        ctx.font = 'bold 20px sans-serif'; ctx.textAlign = 'center';
        ctx.strokeStyle = '#fff'; ctx.lineWidth = 3;
        ctx.strokeText('VRB', cx, cy);
        ctx.fillStyle = '#dc3545';
        ctx.fillText('VRB', cx, cy);
    } else {
        // Calm conditions - draw a circle
        ctx.font = 'bold 20px sans-serif'; ctx.textAlign = 'center';
        ctx.strokeStyle = '#fff'; ctx.lineWidth = 3;
        ctx.strokeText('CALM', cx, cy);
        ctx.fillStyle = '#333';
        ctx.fillText('CALM', cx, cy);
    }
    
    // Draw cardinal directions
    ['N', 'E', 'S', 'W'].forEach((l, i) => {
        const ang = (i * 90 * Math.PI) / 180;
        ctx.fillStyle = '#666'; ctx.font = 'bold 16px sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillText(l, cx + Math.sin(ang) * (r + 10), cy - Math.cos(ang) * (r + 10));
    });
}

function drawWindArrow(ctx, cx, cy, r, angle, speed, offset = 0) {
    // Wind arrow points INTO the wind (direction from which wind is blowing)
    const arrowLength = Math.min(speed * 6, r - 30);
    const arrowEndX = cx + Math.sin(angle) * arrowLength;
    const arrowEndY = cy - Math.cos(angle) * arrowLength;
    
    // Draw wind speed indicator circle
    ctx.fillStyle = 'rgba(220, 53, 69, 0.2)';
    const circleRadius = Math.max(20, speed * 4);
    ctx.beginPath(); ctx.arc(cx, cy, circleRadius, 0, 2 * Math.PI); ctx.fill();
    
    // Draw wind arrow shaft
    ctx.strokeStyle = '#dc3545'; ctx.fillStyle = '#dc3545'; ctx.lineWidth = 4; ctx.lineCap = 'round';
    ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(arrowEndX, arrowEndY); ctx.stroke();
    
    // Draw arrowhead pointing into the wind
    const arrowAngle = Math.atan2(arrowEndY - cy, arrowEndX - cx);
    ctx.beginPath();
    ctx.moveTo(arrowEndX, arrowEndY);
    ctx.lineTo(arrowEndX - 15 * Math.cos(arrowAngle - Math.PI / 6), arrowEndY - 15 * Math.sin(arrowAngle - Math.PI / 6));
    ctx.lineTo(arrowEndX - 15 * Math.cos(arrowAngle + Math.PI / 6), arrowEndY - 15 * Math.sin(arrowAngle + Math.PI / 6));
    ctx.closePath(); ctx.fill();
}

function openLiveStream(url) { window.open(url, '_blank'); }

<?php if (isset($airport['webcams']) && !empty($airport['webcams']) && count($airport['webcams']) > 0): ?>
// Update webcam timestamps (called periodically to refresh relative time display)
function updateWebcamTimestamps() {
    <?php foreach ($airport['webcams'] as $index => $cam): ?>
    // Update webcam timestamp in label
    const timestampElem<?= $index ?> = document.getElementById('webcam-timestamp-<?= $index ?>');
    if (timestampElem<?= $index ?>) {
        // Try to get timestamp from data attribute first, then from CAM_TS
        const timestampFromAttr<?= $index ?> = timestampElem<?= $index ?>.dataset.timestamp;
        const timestampFromCamTs<?= $index ?> = CAM_TS[<?= $index ?>];
        // Parse timestamps to numbers for comparison
        const attrTimestamp<?= $index ?> = timestampFromAttr<?= $index ?> ? parseInt(timestampFromAttr<?= $index ?>) : 0;
        const camTsTimestamp<?= $index ?> = timestampFromCamTs<?= $index ?> ? parseInt(timestampFromCamTs<?= $index ?>) : 0;
        const timestamp<?= $index ?> = attrTimestamp<?= $index ?> > 0 ? attrTimestamp<?= $index ?> : (camTsTimestamp<?= $index ?> > 0 ? camTsTimestamp<?= $index ?> : null);
        
        if (timestamp<?= $index ?> && timestamp<?= $index ?> > 0) {
            updateTimestampDisplay(timestampElem<?= $index ?>, timestamp<?= $index ?>);
        } else {
            if (!timestampElem<?= $index ?>.textContent || timestampElem<?= $index ?>.textContent === '--') {
                timestampElem<?= $index ?>.textContent = '--';
            }
        }
    }
    <?php endforeach; ?>
    
    // Check outage banner after webcam timestamp updates
    checkAndUpdateOutageBanner();
}

// Function to reload webcam images with cache busting
function reloadWebcamImages() {
    <?php foreach ($airport['webcams'] as $index => $cam): ?>
    safeSwapCameraImage(<?= $index ?>);
    <?php endforeach; ?>
}

// Format relative time with conditional precision
// Under 1 hour: single unit (e.g., "30 seconds ago", "45 minutes ago")
// 1 hour or more: two units (e.g., "1 hour 23 minutes ago", "2 days 5 hours ago")
function formatRelativeTime(seconds) {
    if (isNaN(seconds) || seconds < 0) {
        return '--';
    }
    
    // Less than 1 minute: show seconds only
    if (seconds < 60) {
        return seconds + (seconds === 1 ? ' second' : ' seconds') + ' ago';
    }
    
    // Less than 1 hour: show minutes only (single unit)
    if (seconds < 3600) {
        const minutes = Math.floor(seconds / 60);
        return minutes + (minutes === 1 ? ' minute' : ' minutes') + ' ago';
    }
    
    // 1 hour or more: show two units (hours and minutes)
    if (seconds < 86400) {
        const hours = Math.floor(seconds / 3600);
        const remainingMinutes = Math.floor((seconds % 3600) / 60);
        
        if (remainingMinutes === 0) {
            return hours + (hours === 1 ? ' hour' : ' hours') + ' ago';
        }
        return hours + (hours === 1 ? ' hour' : ' hours') + ' ' +
               remainingMinutes + (remainingMinutes === 1 ? ' minute' : ' minutes') + ' ago';
    }
    
    // 1 day or more: show two units (days and hours)
    const days = Math.floor(seconds / 86400);
    const remainingHours = Math.floor((seconds % 86400) / 3600);
    
    if (remainingHours === 0) {
        return days + (days === 1 ? ' day' : ' days') + ' ago';
    }
    return days + (days === 1 ? ' day' : ' days') + ' ' +
           remainingHours + (remainingHours === 1 ? ' hour' : ' hours') + ' ago';
}

function lastCamIndexForElem(elem) {
    if (!elem || !elem.id) return undefined;
    // Match both old update-* and new webcam-timestamp-* patterns
    const m = elem.id.match(/^(?:update|webcam-timestamp)-(\d+)$/);
    return m ? parseInt(m[1]) : undefined;
}

// Helper to update timestamp display
function updateTimestampDisplay(elem, timestamp) {
    if (!timestamp || !elem) return;
    
    const timestampNum = parseInt(timestamp);
    if (isNaN(timestampNum) || timestampNum <= 0) {
        if (elem) {
            elem.textContent = '--';
        }
        return;
    }
    
    const updateDate = new Date(timestampNum * 1000);
    const now = new Date();
    const diffSeconds = Math.floor((now - updateDate) / 1000);
    
    // Handle future timestamps (clock skew)
    if (diffSeconds < 0) {
        elem.textContent = 'just now';
        elem.dataset.timestamp = timestampNum.toString();
        return;
    }
    
    // Format relative time
    const relativeTime = formatRelativeTime(diffSeconds);
    
    // Check if webcam is stale (exceeds MAX_STALE_HOURS) and show warning emoji
    const maxStaleSeconds = MAX_STALE_HOURS * SECONDS_PER_HOUR;
    const isStale = diffSeconds >= maxStaleSeconds;
    
    // Get camera index once and reuse it
    const camIndex = lastCamIndexForElem(elem);
    if (camIndex !== undefined) {
        // Show/hide warning emoji for webcam timestamps
        const warningElem = document.getElementById(`webcam-timestamp-warning-${camIndex}`);
        if (warningElem) {
            warningElem.style.display = isStale ? 'inline' : 'none';
        }
        // Update CAM_TS with timestamp
        CAM_TS[camIndex] = timestampNum;
    }
    
    // Only show actual time if relative time is >= 1 hour
    if (diffSeconds >= 3600) {
        // Format actual time in airport's timezone
        try {
            // Get airport timezone, default to UTC if not available
            const defaultTimezone = typeof DEFAULT_TIMEZONE !== 'undefined' ? DEFAULT_TIMEZONE : 'UTC';
            const timezone = (AIRPORT_DATA && AIRPORT_DATA.timezone) || defaultTimezone;
            const timeFormat = getTimeFormat();
            
            // Format time with seconds for precision (matches image timestamp format)
            const timeOptions = {
                timeZone: timezone,
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
                hour12: timeFormat === '12hr'
            };
            
            const actualTime = updateDate.toLocaleTimeString('en-US', timeOptions);
            
            // Zero-width space before parenthesis allows responsive wrapping when space is limited
            elem.textContent = `${actualTime}\u200B (${relativeTime})`;
        } catch (error) {
            // Fallback to relative time only if formatting fails
            console.error('[WebcamTimestamp] Error formatting timestamp:', error);
            elem.textContent = relativeTime;
        }
    } else {
        // Show only relative time for recent updates (< 1 hour)
        elem.textContent = relativeTime;
    }
    
    elem.dataset.timestamp = timestampNum.toString();
}

// Debounce timestamps per camera to avoid multiple fetches when all formats load
const timestampCheckPending = {};
const timestampCheckRetries = {}; // Track retry attempts
const CAM_TS = {}; // In-memory timestamps per camera (no UI field)

// Initialize CAM_TS with server-side timestamps from initial image load
// Uses EXIF capture time when available, otherwise falls back to filemtime
<?php foreach ($airport['webcams'] as $index => $cam): 
    $base = __DIR__ . '/../cache/webcams/' . $airportId . '_' . $index;
    $initialMtime = 0;
    foreach (['.jpg', '.webp'] as $ext) {
        $filePath = $base . $ext;
        if (file_exists($filePath)) {
            $initialMtime = getImageCaptureTimeForPage($filePath);
            break;
        }
    }
?>
CAM_TS[<?= $index ?>] = <?= $initialMtime ?>;
<?php endforeach; ?>

// Initialize timestamp displays after DOM is ready
function initializeWebcamTimestamps() {
    <?php foreach ($airport['webcams'] as $index => $cam): 
        $base = __DIR__ . '/../cache/webcams/' . $airportId . '_' . $index;
        $initialMtime = 0;
        foreach (['.jpg', '.webp'] as $ext) {
            $filePath = $base . $ext;
            if (file_exists($filePath)) {
                $initialMtime = getImageCaptureTimeForPage($filePath);
                break;
            }
        }
    ?>
    if (<?= $initialMtime ?> > 0) {
        const timestampElem<?= $index ?> = document.getElementById('webcam-timestamp-<?= $index ?>');
        if (timestampElem<?= $index ?>) {
            updateTimestampDisplay(timestampElem<?= $index ?>, <?= $initialMtime ?>);
        }
    }
    <?php endforeach; ?>
}

// Initialize when DOM is ready
function initializeWebcamTimestampsAndStartUpdates() {
    initializeWebcamTimestamps();
    // Update relative timestamps every 10 seconds for better responsiveness
    updateWebcamTimestamps();
    setInterval(updateWebcamTimestamps, 10000); // Update every 10 seconds
    
    // Check outage banner after webcam timestamp updates
    if (typeof checkAndUpdateOutageBanner === 'function') {
        checkAndUpdateOutageBanner();
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeWebcamTimestampsAndStartUpdates);
} else {
    // DOM already loaded
    initializeWebcamTimestampsAndStartUpdates();
}
<?php endif; ?>

// Function to update timestamp when image loads
function updateWebcamTimestampOnLoad(camIndex, retryCount = 0) {
    // Debounce: if a check is already pending for this camera, skip
    if (timestampCheckPending[camIndex]) {
        return;
    }
    
    timestampCheckPending[camIndex] = true;
    
    // Build absolute URL (works with subdomains)
    const protocol = (window.location.protocol === 'https:') ? 'https:' : 'http:';
    const host = window.location.host;
    const timestampUrl = `${protocol}//${host}/webcam.php?id=${AIRPORT_ID}&cam=${camIndex}&mtime=1&_=${Date.now()}`;
    
    // Create abort controller for timeout
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 5000); // 5 second timeout
    
    fetch(timestampUrl, {
        signal: controller.signal,
        cache: 'no-store', // Prevent browser caching
        credentials: 'same-origin'
    })
        .then(response => {
            clearTimeout(timeoutId);
            
            // Check response status
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            return response.json();
        })
        .then(data => {
            if (data && data.success && data.timestamp) {
                const elem = document.getElementById(`update-${camIndex}`); // may be null (UI removed)
                const timestampElem = document.getElementById(`webcam-timestamp-${camIndex}`);
                const newTimestamp = parseInt(data.timestamp);
                const currentTimestamp = CAM_TS[camIndex] ? parseInt(CAM_TS[camIndex]) : (timestampElem ? parseInt(timestampElem.dataset.timestamp || '0') : 0);
                // Only update if timestamp is newer
                if (newTimestamp > currentTimestamp || retryCount > 0) {
                    updateTimestampDisplay(elem, newTimestamp);
                    // Update webcam timestamp label
                    if (timestampElem) {
                        updateTimestampDisplay(timestampElem, newTimestamp);
                    }
                    CAM_TS[camIndex] = newTimestamp;
                    // Reset retry count on success
                    timestampCheckRetries[camIndex] = 0;
                }
            } else {
                throw new Error('Invalid response data');
            }
        })
        .catch(err => {
            clearTimeout(timeoutId);
            
            // Retry logic: up to 2 retries with exponential backoff
            if (retryCount < 2 && err.name !== 'AbortError') {
                timestampCheckRetries[camIndex] = (timestampCheckRetries[camIndex] || 0) + 1;
                const backoff = Math.min(500 * Math.pow(2, retryCount), 2000); // 500ms, 1000ms, 2000ms max
                
                setTimeout(() => {
                    timestampCheckPending[camIndex] = false;
                    updateWebcamTimestampOnLoad(camIndex, retryCount + 1);
                }, backoff);
                return; // Don't clear pending flag yet
            }
            
            // Failed after retries - silently fail (don't spam console)
            // Only log on first failure to avoid noise
            if (retryCount === 0 && err.name !== 'AbortError') {
                // Could optionally log here for debugging: console.debug('Timestamp check failed:', err);
            }
        })
        .finally(() => {
            // Clear pending flag after debounce window (only if not retrying)
            if (timestampCheckRetries[camIndex] === 0 || retryCount >= 2) {
                setTimeout(() => {
                    timestampCheckPending[camIndex] = false;
                }, 1000);
            }
        });
}

<?php if (isset($airport['webcams']) && !empty($airport['webcams']) && count($airport['webcams']) > 0): ?>
// Reload webcam images using per-camera intervals
<?php foreach ($airport['webcams'] as $index => $cam): 
    // Get webcam refresh from config with global config fallback
    $defaultWebcamRefresh = getDefaultWebcamRefresh();
    $airportWebcamRefresh = isset($airport['webcam_refresh_seconds']) ? intval($airport['webcam_refresh_seconds']) : $defaultWebcamRefresh;
    $perCam = isset($cam['refresh_seconds']) ? intval($cam['refresh_seconds']) : $airportWebcamRefresh;
?>
// Setup image load handlers for camera <?= $index ?>
// Note: For picture elements, only the final <img> fires load events
const imgEl<?= $index ?> = document.getElementById('webcam-<?= $index ?>');
if (imgEl<?= $index ?>) {
    // Check timestamp on initial load (images may already be cached)
    // For first webcam (LCP element), delay timestamp check to avoid competing with LCP load
    const timestampDelay = <?= $index === 0 ? '500' : '100' ?>;
    if (imgEl<?= $index ?>.complete && imgEl<?= $index ?>.naturalHeight !== 0) {
        // Image already loaded, check timestamp after delay
        setTimeout(() => updateWebcamTimestampOnLoad(<?= $index ?>), timestampDelay);
    } else {
        // Image not loaded yet, wait for load event, then delay timestamp check
        imgEl<?= $index ?>.addEventListener('load', () => {
            setTimeout(() => updateWebcamTimestampOnLoad(<?= $index ?>), timestampDelay);
        }, { once: false }); // Allow multiple calls as images refresh
    }
    
    // Also listen for error events - show placeholder if image failed
    imgEl<?= $index ?>.addEventListener('error', function() {
        handleWebcamError(<?= $index ?>, this);
    });
}

// Periodic refresh of timestamp (every 30 seconds) even if image doesn't reload
// Debounced: batched across all cameras to reduce requests

setInterval(() => {
    safeSwapCameraImage(<?= $index ?>);
}, <?= max(1, $perCam) * 1000 ?>);
<?php endforeach; ?>
<?php endif; ?>

updateWeatherTimestamp();
setInterval(updateWeatherTimestamp, 10000); // Update relative time every 10 seconds

// Initialize and update outage banner
function initializeOutageBanner() {
    const banner = document.getElementById('data-outage-banner');
    if (banner) {
        // Initial update - read from data attribute set by server
        updateOutageBannerTimestamp();
        checkAndUpdateOutageBanner();
        
        // Update timestamp display periodically
        setInterval(updateOutageBannerTimestamp, 60000); // Update every minute
        // Check outage status periodically (every 30 seconds) to show/hide banner as data recovers
        setInterval(checkAndUpdateOutageBanner, 30000);
        
        // Fetch outage status from server periodically (every 2.5 minutes) to sync with backend state
        // This ensures banner reflects server-side outage detection and state file persistence
        fetchOutageStatus(); // Initial fetch
        setInterval(fetchOutageStatus, 150000); // Every 2.5 minutes
    } else if (AIRPORT_ID) {
        // Banner doesn't exist yet, but check server periodically in case outage starts
        // This handles cases where outage begins after page load
        fetchOutageStatus(); // Initial fetch
        setInterval(fetchOutageStatus, 150000); // Every 2.5 minutes
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeOutageBanner);
} else {
    // DOM already loaded
    initializeOutageBanner();
}

<?php if (isset($airport['webcams']) && !empty($airport['webcams']) && count($airport['webcams']) > 0): ?>
// Batched timestamp refresh for all webcams (debounced to reduce requests)
let timestampBatchPending = false;
function batchRefreshAllTimestamps() {
    if (timestampBatchPending) return;
    timestampBatchPending = true;
    <?php foreach ($airport['webcams'] as $index => $cam): ?>
    const imgEl<?= $index ?> = document.getElementById('webcam-<?= $index ?>');
    if (imgEl<?= $index ?> && imgEl<?= $index ?>.complete && imgEl<?= $index ?>.naturalHeight !== 0) {
        updateWebcamTimestampOnLoad(<?= $index ?>);
    }
    <?php endforeach; ?>
    setTimeout(() => { timestampBatchPending = false; }, 1000);
}
// Refresh all timestamps every 30 seconds (batched)
setInterval(batchRefreshAllTimestamps, 30000);
<?php endif; ?>

// Fetch weather data using airport's configured refresh interval
// Only fetch if weather_source is configured
if (AIRPORT_DATA && AIRPORT_DATA.weather_source && Object.keys(AIRPORT_DATA.weather_source).length > 0) {
    // Calculate weather refresh interval from airport config
    const weatherRefreshMs = (AIRPORT_DATA && AIRPORT_DATA.weather_refresh_seconds) 
        ? AIRPORT_DATA.weather_refresh_seconds * 1000 
        : 60000; // Default 60 seconds

    // Delay initial fetch to avoid competing with LCP image load
    setTimeout(() => {
        fetchWeather();
    }, 500);
    setInterval(fetchWeather, weatherRefreshMs);
}

// Handle webcam image load errors - show placeholder image
function handleWebcamError(camIndex, img) {
    console.error('Webcam image failed to load:', img.src);
    const skeleton = document.getElementById(`webcam-skeleton-${camIndex}`);
    if (skeleton) skeleton.style.display = 'none';
    
    // Show placeholder image instead of broken image
    const protocol = (window.location.protocol === 'https:') ? 'https:' : 'http:';
    const host = window.location.host;
            img.src = `${protocol}//${host}/public/images/placeholder.jpg`;
    img.onerror = null; // Prevent infinite loop if placeholder also fails
}

// Safely swap camera image only when the backend has a newer image and the new image is loaded
function safeSwapCameraImage(camIndex) {
    // Get current timestamp from CAM_TS, fallback to image data attribute, then 0
    const imgEl = document.getElementById(`webcam-${camIndex}`);
    const initialTs = imgEl ? parseInt(imgEl.dataset.initialTimestamp || '0') : 0;
    const currentTs = CAM_TS[camIndex] ? parseInt(CAM_TS[camIndex]) : initialTs;

    const protocol = (window.location.protocol === 'https:') ? 'https:' : 'http:';
    const host = window.location.host;
    const mtimeUrl = `${protocol}//${host}/webcam.php?id=${AIRPORT_ID}&cam=${camIndex}&mtime=1&_=${Date.now()}`;

    fetch(mtimeUrl, { cache: 'no-store', credentials: 'same-origin' })
        .then(r => r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`)))
        .then(json => {
            if (!json) return; // Invalid response
            
            // Check if we have a valid timestamp (even if success is false, we might have a timestamp)
            const newTs = parseInt(json.timestamp || 0);
            if (isNaN(newTs) || newTs === 0) {
                // No cache available - don't try to update
                return;
            }
            
            // Only update if timestamp is newer (strictly greater)
            if (newTs <= currentTs) {
                // Timestamp hasn't changed - backend hasn't updated yet, will retry on next interval
                return;
            }

            const ready = json.formatReady || {};
            // Match server-side hash calculation: md5(airportId + '_' + camIndex + '_' + fmt + '_' + mtime + '_' + size)
            // Server uses: substr(md5($airportId . '_' . $camIndex . '_' . $fmt . '_' . $fileMtime . '_' . $fileSize), 0, 8)
            // For cache-busting, we use a simple hash that changes with timestamp and size
            // Since MD5 isn't available in browser JS, use a simple hash for cache-busting
            const hashInput = `${AIRPORT_ID}_${camIndex}_jpg_${newTs}_${json.size || 0}`;
            // Simple hash function for cache-busting (doesn't need to match MD5 exactly)
            let hash = 0;
            for (let i = 0; i < hashInput.length; i++) {
                const char = hashInput.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash; // Convert to 32-bit integer
            }
            // Convert to hex string and take first 8 chars
            const hashHex = Math.abs(hash).toString(16).padStart(8, '0').substring(0, 8);
            
            // For webp, use webp format in hash
            const hashInputWebp = `${AIRPORT_ID}_${camIndex}_webp_${newTs}_${json.size || 0}`;
            let hashWebp = 0;
            for (let i = 0; i < hashInputWebp.length; i++) {
                const char = hashInputWebp.charCodeAt(i);
                hashWebp = ((hashWebp << 5) - hashWebp) + char;
                hashWebp = hashWebp & hashWebp;
            }
            const hashHexWebp = Math.abs(hashWebp).toString(16).padStart(8, '0').substring(0, 8);
            
            const jpgUrl = `${protocol}//${host}/webcam.php?id=${AIRPORT_ID}&cam=${camIndex}&fmt=jpg&v=${hashHex}`;
            const webpUrl = `${protocol}//${host}/webcam.php?id=${AIRPORT_ID}&cam=${camIndex}&fmt=webp&v=${hashHexWebp}`;

            // Show skeleton placeholder while loading
            const skeleton = document.getElementById(`webcam-skeleton-${camIndex}`);
            if (skeleton) skeleton.style.display = 'block';

            // Helper to preload an image URL, resolve on load, reject on error
            const preloadUrl = (url) => new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => resolve(true);
                img.onerror = () => reject(new Error('preload_failed'));
                img.src = url;
            });

            // Progressive fallback: ensure JPG first, then upgrade sources independently
            const jpgPromise = ready.jpg ? preloadUrl(jpgUrl) : Promise.reject(new Error('jpg_not_ready'));
            jpgPromise.then(() => {
                const img = document.getElementById(`webcam-${camIndex}`);
                if (img) {
                    img.src = jpgUrl;
                    // Update data attribute to keep it in sync with CAM_TS
                    img.dataset.initialTimestamp = newTs.toString();
                    if (skeleton) skeleton.style.display = 'none';
                }
                CAM_TS[camIndex] = newTs;
                // Update timestamp display in label
                const timestampElem = document.getElementById(`webcam-timestamp-${camIndex}`);
                if (timestampElem) {
                    updateTimestampDisplay(timestampElem, newTs);
                }
                updateWebcamTimestampOnLoad(camIndex);
            }).catch((error) => {
                // Hide skeleton on failure
                if (skeleton) skeleton.style.display = 'none';
                // If image fails to load, show placeholder
                const img = document.getElementById(`webcam-${camIndex}`);
                if (img) {
                    handleWebcamError(camIndex, img);
                }
            });

            // Upgrade WEBP if available; do not block on it
            if (ready.webp) {
                preloadUrl(webpUrl).then(() => {
                    const srcWebp = document.getElementById(`webcam-webp-${camIndex}`);
                    if (srcWebp) srcWebp.setAttribute('srcset', webpUrl);
                }).catch(() => {});
            }
        })
        .catch(() => {
            // Silently ignore; will retry on next interval
        });
}
<?php
    // Capture and minify JavaScript
    $js = ob_get_clean();
    
    // CRITICAL FIX: Ensure script tag is properly closed
    // The output buffer contains <script> but may not have closing </script>
    // Check if script tag is opened but not closed
    $needsClosingTag = preg_match('/<script[^>]*>/', $js) && !preg_match('/<\/script>/', $js);
    
    // Enhanced error detection: Check for actual HTML output or PHP errors
    // Only flag if content looks like actual HTML document structure, not HTML in JavaScript strings
    $hasHtmlError = false;
    $errorType = '';
    
    // Extract content before <script> tag to check for PHP errors
    // PHP errors would appear before the script tag, not inside JavaScript code
    $beforeScript = '';
    if (preg_match('/^(.*?)<script[^>]*>/is', $js, $beforeMatches)) {
        $beforeScript = $beforeMatches[1];
    }
    
    // Check for actual HTML document structure (not HTML in JavaScript strings)
    // These patterns indicate actual HTML output, not JavaScript containing HTML strings
    // Only check BEFORE the script tag to avoid false positives from JavaScript code
    $htmlDocumentPatterns = [
        '<!DOCTYPE' => 'DOCTYPE declaration',
        '<html' => 'HTML tag',
        '<body' => 'Body tag',
    ];
    
    // Check for HTML document structure first (most reliable indicators)
    foreach ($htmlDocumentPatterns as $pattern => $description) {
        if (stripos($js, $pattern) !== false) {
            $hasHtmlError = true;
            $errorType = $description;
            break;
        }
    }
    
    // Check for PHP errors ONLY in content before script tag (to avoid false positives)
    // PHP errors would appear before JavaScript, not inside it
    if (!$hasHtmlError && $beforeScript !== '') {
        $phpErrorPatterns = [
            '/PHP\s+(Fatal|Parse)\s+error/i' => 'PHP fatal/parse error',
            '/Fatal\s+error:\s+/i' => 'PHP fatal error',
            '/Parse\s+error:\s+/i' => 'PHP parse error',
            '/Warning:\s+/i' => 'PHP warning',
            '/Notice:\s+/i' => 'PHP notice',
            '/Deprecated:\s+/i' => 'PHP deprecated warning',
        ];
        
        foreach ($phpErrorPatterns as $pattern => $description) {
            if (preg_match($pattern, $beforeScript)) {
                $hasHtmlError = true;
                $errorType = $description;
                break;
            }
        }
    }
    
    // Only check for individual HTML tags if they appear at the start of the buffer
    // (indicating HTML output before script tag, not HTML in JavaScript strings)
    if (!$hasHtmlError) {
        // Check if content starts with HTML tags (not inside script tag)
        $jsTrimmed = ltrim($js);
        $htmlTagPatterns = [
            '/^<div[^>]*>/i' => 'Div tag at start',
            '/^<span[^>]*>/i' => 'Span tag at start',
            '/^<p[^>]*>/i' => 'Paragraph tag at start',
        ];
        
        foreach ($htmlTagPatterns as $pattern => $description) {
            if (preg_match($pattern, $jsTrimmed)) {
                $hasHtmlError = true;
                $errorType = $description;
                break;
            }
        }
    }
    
    if ($hasHtmlError) {
        // PHP error detected - log details and output original without minification
        $jsLength = strlen($js);
        $first500 = substr($js, 0, 500);
        $last500 = $jsLength > 500 ? substr($js, -500) : '';
        
        error_log(sprintf(
            'PHP error detected in JavaScript output buffer: %s (length: %d). First 500 chars: %s. Last 500 chars: %s',
            $errorType,
            $jsLength,
            addcslashes($first500, "\0..\37"),
            addcslashes($last500, "\0..\37")
        ));
        
        // Output original - ensure closing tag is present
        if ($needsClosingTag) {
            echo $js . '</script>';
        } else {
            echo $js;
        }
    } else {
        // Extract the script tag content - use a more robust pattern
        // Match from <script> to </script>, handling potential </script> in strings
        if (preg_match('/<script[^>]*>(.*?)<\/script>/s', $js, $matches)) {
            $jsContent = $matches[1];
            
            // Enhanced validation: Check for HTML tags in JavaScript content
            // This catches cases where HTML might be injected before the script closes
            $htmlInJs = false;
            if (preg_match('/<[a-z][\s>]/i', $jsContent)) {
                $htmlInJs = true;
                error_log('HTML tags detected in JavaScript content. First 200 chars: ' . substr($jsContent, 0, 200));
            }
            
            // Only minify if content is not empty and doesn't contain HTML
            if (trim($jsContent) !== '' && !$htmlInJs && strpos($jsContent, '<') === false) {
                try {
                    $minified = minifyJavaScript($jsContent);
                    // Verify minified output doesn't contain HTML
                    if (strpos($minified, '<') === false && !preg_match('/<[a-z][\s>]/i', $minified)) {
                        echo '<script>' . $minified . '</script>';
                    } else {
                        // Minification produced HTML - use original
                        error_log('Minification produced HTML output, using original. Minified length: ' . strlen($minified));
                        if ($needsClosingTag) {
                            echo $js . '</script>';
                        } else {
                            echo $js;
                        }
                    }
                } catch (Exception $e) {
                    // If minification fails, output original JavaScript
                    error_log('JavaScript minification error: ' . $e->getMessage());
                    if ($needsClosingTag) {
                        echo $js . '</script>';
                    } else {
                        echo $js;
                    }
                }
            } else {
                // Content is empty or contains HTML - log and output as-is
                if ($htmlInJs || strpos($jsContent, '<') !== false) {
                    error_log('JavaScript content contains HTML - skipping minification. Content length: ' . strlen($jsContent));
                }
                if ($needsClosingTag) {
                    echo $js . '</script>';
                } else {
                    echo $js;
                }
            }
        } else {
            // Fallback if pattern doesn't match - script tag may not be closed
            error_log('Could not extract script tag content from output buffer. Buffer length: ' . strlen($js));
            if ($needsClosingTag) {
                echo $js . '</script>';
            } else {
                echo $js;
            }
        }
    }
?>
</body>
</html>


