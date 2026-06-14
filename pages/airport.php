<?php
// Load SEO utilities and config (for getGitSha function)
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/seo.php';
require_once __DIR__ . '/../lib/address-formatter.php';
require_once __DIR__ . '/../lib/weather/utils.php';
require_once __DIR__ . '/../lib/weather/source-timestamps.php';
require_once __DIR__ . '/../lib/weather/outage-detection.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/station-power/station-power-cache.php';
require_once __DIR__ . '/../lib/station-power/station-power-dashboard-format.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/runways.php';
require_once __DIR__ . '/../lib/version.php';
require_once __DIR__ . '/../lib/partner-logo-luminance.php';

// Check if airport has any weather sources configured
$hasWeatherSources = hasWeatherSources($airport);

// =============================================================================
// Version Cookie & Emergency Cleanup Detection
// Set version cookie on every response for cross-subdomain version tracking
// =============================================================================

$buildVersion = getBuildVersionInfo();
$buildTimestamp = $buildVersion['timestamp'];
$buildHash = $buildVersion['hash'];
$maxNoUpdateDays = $buildVersion['max_no_update_days'];
$stuckClientCleanup = $buildVersion['stuck_client_cleanup']; // Enable in airports.json when needed
$buildHashShort = $buildVersion['hash_short'];
$versionCookieValue = $buildHashShort . '.' . $buildTimestamp;

// Set version cookie on every response (cross-subdomain via .aviationwx.org)
$baseDomainForCookie = getBaseDomain();
$cookieDomain = '.' . $baseDomainForCookie;
$cookieExpiry = time() + 31536000; // 1 year

// Set cookie with proper options
$cookieOptions = [
    'expires' => $cookieExpiry,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => false, // JS needs to read it
    'samesite' => 'Lax'
];

// Only set domain for non-localhost environments (localhost doesn't support domain cookies)
$hostname = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (!str_contains($hostname, 'localhost') && !str_contains($hostname, '127.0.0.1')) {
    $cookieOptions['domain'] = $cookieDomain;
}

setcookie('aviationwx_v', $versionCookieValue, $cookieOptions);

// Detect stuck clients: no version cookie OR stale version cookie
// Only perform stuck client cleanup detection if enabled in config
$clientVersionCookie = $_COOKIE['aviationwx_v'] ?? null;
$injectStuckClientCleanup = false;
$stuckClientCleanupReason = '';

if ($stuckClientCleanup) {
    if ($clientVersionCookie === null) {
        // Check if client has OTHER aviationwx cookies (indicates returning user, not first visit)
        $hasOtherCookies = false;
        foreach ($_COOKIE as $name => $value) {
            if (strpos($name, 'aviationwx_') === 0 && $name !== 'aviationwx_v') {
                $hasOtherCookies = true;
                break;
            }
        }
        
        if ($hasOtherCookies) {
            // Has preference cookies but no version cookie = likely stuck on old code
            $injectStuckClientCleanup = true;
            $stuckClientCleanupReason = 'Missing version cookie but has preference cookies';
        }
    } else {
        // Parse cookie: "hash.timestamp"
        $parts = explode('.', $clientVersionCookie);
        if (count($parts) === 2) {
            $clientHash = $parts[0];
            $clientTimestamp = (int)$parts[1];
            
            // If client timestamp is very old AND hash doesn't match, might be stuck
            $maxAgeSeconds = $maxNoUpdateDays * 24 * 60 * 60;
            $cookieAge = time() - $clientTimestamp;
            
            if ($cookieAge > $maxAgeSeconds && $clientHash !== $buildHashShort) {
                $injectStuckClientCleanup = true;
                $stuckClientCleanupReason = 'Version cookie is ' . round($cookieAge / 86400) . ' days old with hash mismatch';
            }
        }
    }
}

// Set cache control headers for HTML
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

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
            // Our pipeline writes EXIF in UTC, so parse as UTC
            $timestamp = @strtotime(str_replace(':', '-', substr($dateTime, 0, 10)) . ' ' . substr($dateTime, 11) . ' UTC');
            if ($timestamp !== false && $timestamp > 0) {
                return (int)$timestamp;
            }
        }
        // Also check main EXIF array (some cameras store it there)
        if (isset($exif['DateTimeOriginal'])) {
            $dateTime = $exif['DateTimeOriginal'];
            // Our pipeline writes EXIF in UTC, so parse as UTC
            $timestamp = @strtotime(str_replace(':', '-', substr($dateTime, 0, 10)) . ' ' . substr($dateTime, 11) . ' UTC');
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
$webcamText = $webcamCount > 0 ? $webcamCount . ' live webcam' . ($webcamCount > 1 ? 's' : '') : '';
// Primary identifier for routing/search; formal identifier for user-facing title/meta
$primaryIdentifier = getPrimaryIdentifier($airportId, $airport);
$displayName = formatAirportNameWithIdentifier($airport['name'], $airport);
$pageTitle = $displayName . ' - Live Webcams & Runway Conditions';
// Optimized meta description - action-oriented, under 160 chars
$pageDescription = 'Check current conditions at ' . $displayName .
    ($webcamText ? ' - ' . $webcamText . ', real-time wind & weather.' : ' - real-time wind, visibility & weather.') . 
    ' Updated every minute. Free.';
$pageKeywords = buildAirportPageKeywords($airport);
// Get base domain from global config (config.php already loaded at top of file)
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

// Server-side theme detection - prevents flash by setting initial class
// Read saved theme preference from cookie
$themeCookie = $_COOKIE['aviationwx_theme'] ?? null;
$htmlThemeClass = '';

// Only set class if user has explicitly chosen dark mode
// Note: 'day' means light mode (no class), 'auto' means follow OS preference (no class)
// Night mode is time-based and handled by JavaScript
if ($themeCookie === 'dark') {
    $htmlThemeClass = 'dark-mode';
}
// Note: We don't set night-mode server-side because it's time-based and requires JS
// for mobile detection and sunset/sunrise calculations
?>
<!DOCTYPE html>
<html lang="en"<?= $htmlThemeClass ? ' class="' . htmlspecialchars($htmlThemeClass) . '"' : '' ?>>
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
                    const descriptor = Object.getOwnPropertyDescriptor(window, 'styleMedia');
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
                        } catch {
                            Object.defineProperty(window, 'styleMedia', {
                                get: function() { return undefined; },
                                set: function() {},
                                configurable: false,
                                enumerable: false
                            });
                        }
                    }
                }
            } catch {}
        })();
    </script>
    <?php
    // Night mode: evening civil twilight until morning civil twilight (FAA definition of night)
    $nightModeData = [];
    if (isset($airport['lat']) && isset($airport['lon']) && isset($airport['timezone'])) {
        try {
            $sunInfo = getSunInfoForAirport($airport);
            if ($sunInfo === null) {
                if (function_exists('aviationwx_log')) {
                    aviationwx_log('warning', 'sun info unavailable for night mode', [
                        'airport' => $airport['icao'] ?? $airport['id'] ?? 'unknown',
                        'lat' => $airport['lat'],
                        'lon' => $airport['lon'],
                    ], 'app');
                }
            } else {
                $tz = new DateTimeZone($airport['timezone']);
                $now = new DateTime('now', $tz);
            $today = $now->format('Y-m-d');
            $civilDusk = $sunInfo['civil_twilight_end'];
            $civilDawn = $sunInfo['civil_twilight_begin'];
            if ($civilDusk !== null && $civilDawn !== null) {
                $dusk = new DateTime('@' . $civilDusk);
                $dusk->setTimezone($tz);
                $dawn = new DateTime('@' . $civilDawn);
                $dawn->setTimezone($tz);
                $nightModeData = [
                    'timezone' => $airport['timezone'],
                    'nightStartHour' => (int) $dusk->format('G'),
                    'nightStartMin' => (int) $dusk->format('i'),
                    'nightEndHour' => (int) $dawn->format('G'),
                    'nightEndMin' => (int) $dawn->format('i'),
                    'currentHour' => (int) $now->format('G'),
                    'currentMin' => (int) $now->format('i'),
                    'todayDate' => $today
                ];
            } elseif ($sunInfo['sunrise'] === null && $sunInfo['sunset'] === null) {
                $sunAltitude = getSunAltitude((float) $airport['lat'], (float) $airport['lon'], (int) $now->getTimestamp());
                $nightModeData = [
                    'timezone' => $airport['timezone'],
                    'polarNight' => $sunAltitude <= -6.0,
                    'currentHour' => (int) $now->format('G'),
                    'currentMin' => (int) $now->format('i'),
                    'todayDate' => $today
                ];
            }
            }
        } catch (\Exception $e) {
            if (function_exists('aviationwx_log')) {
                aviationwx_log('warning', 'night mode timezone error', [
                    'airport' => $airport['icao'] ?? $airport['id'] ?? 'unknown',
                    'message' => $e->getMessage(),
                ], 'app');
            }
        }
    }
    ?>
    <script>
        // Theme Mode - Instant activation before first paint
        // Four modes: auto (browser preference), day (light), dark (classic dark), night (red night vision)
        // Priority: 
        //   1) Mobile after evening civil twilight -> Night mode (safety priority, unless manually overridden today)
        //   2) Saved cookie preference (auto/day/dark)
        //   3) Default: auto (follows browser prefers-color-scheme)
        (function() {
            'use strict';
            
            var nightData = <?= json_encode($nightModeData) ?>;
            
            // Check if mobile device (for auto night mode)
            function isMobile() {
                return window.innerWidth <= 768 || ('ontouchstart' in window);
            }
            
            // Check browser/OS dark mode preference
            function browserPrefersDark() {
                return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            }
            
            // Get cookie value
            function getCookie(name) {
                var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
                return match ? decodeURIComponent(match[2]) : null;
            }
            
            // Apply theme class
            function applyTheme(theme) {
                // Remove all theme classes first (in case server set one)
                document.documentElement.classList.remove('night-mode', 'dark-mode', 'light-mode');
                
                // Apply the selected theme
                if (theme === 'night') {
                    document.documentElement.classList.add('night-mode');
                } else if (theme === 'dark') {
                    document.documentElement.classList.add('dark-mode');
                } else if (theme === 'day') {
                    document.documentElement.classList.add('light-mode');
                }
                // auto mode = no class (allows @media prefers-color-scheme to apply)
            }
            
            // Apply auto mode - removes all classes to let browser preference work
            function applyAutoTheme() {
                // Remove all theme classes - let CSS @media handle it
                document.documentElement.classList.remove('night-mode', 'dark-mode', 'light-mode');
            }
            
            // Check if it's currently night (evening civil twilight until morning civil twilight)
            function isNightTime() {
                if (!nightData || !nightData.timezone) { return false; }
                if (nightData.polarNight) { return true; }
                if (nightData.nightStartHour === undefined || nightData.nightEndHour === undefined) { return false; }
                var currentMins = nightData.currentHour * 60 + nightData.currentMin;
                var nightStartMins = nightData.nightStartHour * 60 + nightData.nightStartMin;
                var nightEndMins = nightData.nightEndHour * 60 + nightData.nightEndMin;
                return currentMins >= nightStartMins || currentMins < nightEndMins;
            }
            
            // Get theme preference (auto/day/dark are stored - night is time-based)
            var themePref = getCookie('aviationwx_theme');
            var manualOverride = getCookie('aviationwx_theme_override') || getCookie('aviationwx_night_override');
            
            // Legacy support: convert old preferences
            if (!themePref) {
                var oldNightPref = getCookie('aviationwx_night_mode');
                if (oldNightPref === 'off') { themePref = 'day'; }
            }
            
            // Note: 'night' preference is now valid (manually selected by user)
            
            // PRIORITY 1: Mobile after evening civil twilight -> Auto night mode (safety priority)
            if (isMobile() && isNightTime()) {
                // Check if user manually overrode auto-night today
                if (nightData && manualOverride === nightData.todayDate) {
                    // User has manually interacted with the toggle today
                    // Respect their choice, apply their saved preference
                    if (themePref === 'day' || themePref === 'dark' || themePref === 'night') {
                        applyTheme(themePref);
                    } else {
                        // They're in auto mode but clicked away from auto-night
                        // Don't auto-night them again today
                        applyAutoTheme();
                    }
                } else {
                    // No manual override today, auto-night for safety
                    applyTheme('night');
                }
                return;
            }
            
            // PRIORITY 2: Saved cookie preference (day/dark/night - explicit choice)
            if (themePref === 'day' || themePref === 'dark' || themePref === 'night') {
                applyTheme(themePref);
                return;
            }
            
            // PRIORITY 3: Auto mode (explicit 'auto' or no preference) -> follow browser preference
            // This is the default when no preference is stored
            applyAutoTheme();
        })();
    </script>
    <title><?= htmlspecialchars($pageTitle) ?></title>
    
    <?php
    // Favicon and icon tags
    echo generateFaviconTags();
    echo "\n    ";
    ?>
    
    <?php
    // Enhanced meta tags
    echo generateEnhancedMetaTags($pageDescription, $pageKeywords);
    echo "\n    ";
    
    // For unlisted airports, add noindex/nofollow to prevent search engine indexing
    // This provides defense-in-depth if URLs are shared
    if (isAirportUnlisted($airport)) {
        echo '<meta name="robots" content="noindex, nofollow">';
        echo "\n    ";
    }
    
    // Canonical URL
    echo generateCanonicalTag($airportUrl);
    echo "\n    ";
    
    // Open Graph and Twitter Card tags
    echo generateSocialMetaTags($pageTitle, $pageDescription, $airportUrl, $ogImage);
    echo "\n    ";
    
    // Structured data (Airport schema)
    echo generateStructuredDataScript(generateAirportSchema($airport, $airportId));
    echo "\n    ";
    
    // Breadcrumb structured data
    echo generateStructuredDataScript(generateAirportBreadcrumbs($airport));
    ?>
    
    <?php
    // External stylesheet with build-hash cache busting: pilots re-check the
    // same airport constantly, so a cached stylesheet beats re-inlining ~85KB
    // of CSS into every HTML response. Minified build is produced by the
    // Docker image build (scripts/minify-css.sh); fall back to styles.css
    // when it is absent (local dev with the repo bind-mounted).
    $cssHref = file_exists(__DIR__ . '/../public/css/styles.min.css')
        ? '/public/css/styles.min.css'
        : '/public/css/styles.css';
    ?>
    <link rel="stylesheet" href="<?= $cssHref ?>?v=<?= $buildHashShort ?>">
    <script>
        // The service worker was removed: it registered with /public/js/
        // scope (no Service-Worker-Allowed header), so it never controlled
        // the page and its offline/weather caching never ran. Unregister
        // legacy registrations so existing clients drop them promptly;
        // their update checks 404 and unregister as a fallback.
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.getRegistrations()
                    .then((registrations) => Promise.allSettled(registrations.map((registration) => {
                        console.log('[SW] Unregistering legacy service worker:', registration.scope);
                        return registration.unregister();
                    })))
                    .then((results) => {
                        results.forEach((result) => {
                            // unregister() can reject or resolve false;
                            // either way a legacy worker may remain on the
                            // client, which is worth seeing in the console
                            if (result.status === 'rejected') {
                                console.warn('[SW] Legacy service worker unregister failed:', result.reason);
                            } else if (result.value === false) {
                                console.warn('[SW] Legacy service worker unregister returned false');
                            }
                        });
                    })
                    .catch((err) => {
                        console.warn('[SW] Could not enumerate legacy service workers:', err);
                    });
            });
        }
    </script>
    <?php if ($injectStuckClientCleanup): ?>
    <!-- Stuck client cleanup - clears caches for clients on old code -->
    <script>
    (function() {
        'use strict';
        console.warn('[StuckClientCleanup] Triggered: <?= addslashes($stuckClientCleanupReason) ?>');
        
        try {
            if (sessionStorage.getItem('aviationwx-cleanup-in-progress')) {
                console.log('[StuckClientCleanup] Already in progress, skipping');
                return;
            }
            sessionStorage.setItem('aviationwx-cleanup-in-progress', Date.now().toString());
        } catch (e) {
            console.warn('[StuckClientCleanup] sessionStorage unavailable:', e.message);
            return;
        }
        
        (async function() {
            try {
                // Clear Cache API
                if ('caches' in window) {
                    const names = await caches.keys();
                    await Promise.all(names.map(n => caches.delete(n)));
                    console.log('[StuckClientCleanup] Cleared caches');
                }
                
                // Clear localStorage
                try { localStorage.clear(); } catch(e) {}
                
                // Clear sessionStorage (except cleanup flag)
                try {
                    const flag = sessionStorage.getItem('aviationwx-cleanup-in-progress');
                    sessionStorage.clear();
                    if (flag) { sessionStorage.setItem('aviationwx-cleanup-in-progress', flag); }
                } catch(e) {}
                
                // Unregister service workers
                if ('serviceWorker' in navigator) {
                    const regs = await navigator.serviceWorker.getRegistrations();
                    await Promise.all(regs.map(r => r.unregister()));
                    console.log('[StuckClientCleanup] Unregistered service workers');
                }
                
                console.log('[StuckClientCleanup] Complete, reloading...');
                setTimeout(() => location.reload(true), 100);
            } catch(e) {
                console.error('[StuckClientCleanup] Error:', e);
                location.reload(true);
            }
        })();
    })();
    </script>
    <?php endif; ?>
    <?php renderClientVersionCheckScript($buildHash, $buildTimestamp, $maxNoUpdateDays); ?>
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
    <div id="status-banners"></div>
    <div id="notam-banner-container"></div>
    <main>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <h1><?= htmlspecialchars($displayName) ?></h1>
        </header>
        
        <?php
        // Check if we should show the airport navigation menu (only if multiple airports configured)
        // Use getListedAirports() to exclude unlisted airports from search/nearby
        $config = loadConfig();
        $listedAirports = $config ? getListedAirports($config) : [];
        $showMenu = count($listedAirports) > 1;
        
        if ($showMenu && isset($airport['lat']) && isset($airport['lon'])):
            // Calculate nearby airports (within 200 miles)
            $currentLat = (float)$airport['lat'];
            $currentLon = (float)$airport['lon'];
            $nearbyAirports = [];
            
            foreach ($listedAirports as $otherAirportId => $otherAirport) {
                // Skip current airport
                if ($otherAirportId === $airportId) {
                    continue;
                }
                
                // Skip if missing coordinates
                if (!isset($otherAirport['lat']) || !isset($otherAirport['lon'])) {
                    continue;
                }
                
                $otherLat = (float)$otherAirport['lat'];
                $otherLon = (float)$otherAirport['lon'];
                
                // Haversine formula to calculate distance in statute miles
                $earthRadiusMiles = 3959; // Earth radius in statute miles
                $dLat = deg2rad($otherLat - $currentLat);
                $dLon = deg2rad($otherLon - $currentLon);
                $a = sin($dLat / 2) * sin($dLat / 2) +
                     cos(deg2rad($currentLat)) * cos(deg2rad($otherLat)) *
                     sin($dLon / 2) * sin($dLon / 2);
                $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                $distanceMiles = $earthRadiusMiles * $c;
                
                // Only include airports within 200 miles
                if ($distanceMiles <= 200) {
                    $otherPrimaryIdentifier = getPrimaryIdentifier($otherAirportId, $otherAirport);
                    $nearbyAirports[] = [
                        'id' => $otherAirportId,
                        'name' => $otherAirport['name'] ?? '',
                        'identifier' => $otherPrimaryIdentifier,
                        'distance_miles' => $distanceMiles
                    ];
                }
            }
            
            // Sort by distance and take top 5
            usort($nearbyAirports, function($a, $b) {
                return $a['distance_miles'] <=> $b['distance_miles'];
            });
            $nearbyAirports = array_slice($nearbyAirports, 0, 5);
            
            // Prepare all airports for search (for autocomplete)
            $allAirportsForSearch = [];
            foreach ($listedAirports as $searchAirportId => $searchAirport) {
                if ($searchAirportId === $airportId) {
                    continue; // Skip current airport
                }
                $searchPrimaryIdentifier = getPrimaryIdentifier($searchAirportId, $searchAirport);
                
                // Calculate distance if coordinates are available
                $distanceMiles = null;
                if (isset($searchAirport['lat']) && isset($searchAirport['lon'])) {
                    $otherLat = (float)$searchAirport['lat'];
                    $otherLon = (float)$searchAirport['lon'];
                    
                    // Haversine formula to calculate distance in statute miles
                    $earthRadiusMiles = 3959; // Earth radius in statute miles
                    $dLat = deg2rad($otherLat - $currentLat);
                    $dLon = deg2rad($otherLon - $currentLon);
                    $a = sin($dLat / 2) * sin($dLat / 2) +
                         cos(deg2rad($currentLat)) * cos(deg2rad($otherLat)) *
                         sin($dLon / 2) * sin($dLon / 2);
                    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                    $distanceMiles = $earthRadiusMiles * $c;
                }
                
                $allAirportsForSearch[] = [
                    'id' => $searchAirportId,
                    'name' => $searchAirport['name'] ?? '',
                    'identifier' => $searchPrimaryIdentifier,
                    'icao' => $searchAirport['icao'] ?? '',
                    'iata' => $searchAirport['iata'] ?? '',
                    'faa' => $searchAirport['faa'] ?? '',
                    'lat' => $searchAirport['lat'] ?? null,
                    'lon' => $searchAirport['lon'] ?? null,
                    'distance_miles' => $distanceMiles
                ];
            }
        ?>
        <!-- Airport Navigation Menu -->
        <div class="airport-nav-container">
            <div class="airport-nav-menu">
                <?php if (!isSingleAirportMode()): ?>
                <div class="airport-search-container">
                    <input type="text" 
                           id="airport-search" 
                           class="airport-search-input" 
                           placeholder="Search by name, ICAO, IATA, or FAA code..." 
                           autocomplete="off"
                           title="Search airports by name or identifier (ICAO, IATA, FAA)"
                           aria-label="Search airports by name or identifier">
                </div>
                <div class="nearby-airports-container">
                    <button id="nearby-airports-btn" class="nearby-airports-btn" title="Show airports within 200 miles" aria-label="Show nearby airports within 200 miles">
                        <span class="nearby-btn-text">Nearby<span class="nearby-btn-text-long"> Airports</span></span>
                        <span class="dropdown-arrow">▼</span>
                    </button>
                </div>
                <?php endif; ?>
                <div class="nav-theme-toggle-container">
                    <button id="night-mode-toggle" title="Toggle theme: Auto -> Day -> Dark -> Night" aria-label="Toggle theme mode">
                        <span id="night-mode-icon">🔄</span>
                    </button>
                </div>
                <div class="nav-hamburger-container">
                    <button id="nav-hamburger-btn" class="nav-hamburger-btn" title="Navigation menu" aria-label="Open navigation menu">
                        <span class="hamburger-icon">☰</span>
                    </button>
                </div>
                <?php if (!isSingleAirportMode()): ?>
                <!-- Unified dropdown for both search and nearby airports -->
                <div id="airport-dropdown" class="airport-dropdown">
                    <!-- Content populated by JavaScript -->
                </div>
                <?php endif; ?>
                <!-- Hamburger menu dropdown -->
                <div id="nav-hamburger-dropdown" class="nav-hamburger-dropdown">
                    <?php if (!isSingleAirportMode()): ?>
                    <a href="https://aviationwx.org" class="nav-hamburger-item">
                        <span class="nav-item-icon">🏠</span>
                        <span>AviationWX.org Home</span>
                    </a>
                    <a href="https://airports.aviationwx.org" class="nav-hamburger-item">
                        <span class="nav-item-icon">✈️</span>
                        <span>Browse All Airports</span>
                    </a>
                    <?php else: ?>
                    <a href="/<?= strtolower($airportId) ?>" class="nav-hamburger-item">
                        <span class="nav-item-icon">🏠</span>
                        <span>Dashboard</span>
                    </a>
                    <?php endif; ?>
                    <a href="https://airports.<?= htmlspecialchars(getBaseDomain(), ENT_QUOTES, 'UTF-8') ?>/#add-to-home-screen" class="nav-hamburger-item">
                        <span class="nav-item-icon" aria-hidden="true">
                            <img src="/public/favicons/android-chrome-192x192.png" alt="" width="18" height="18" decoding="async" class="nav-hamburger-pwa-icon">
                        </span>
                        <span>Add to your Home Screen</span>
                    </a>
                    <a href="https://embed.aviationwx.org" class="nav-hamburger-item">
                        <span class="nav-item-icon">🔗</span>
                        <span>Embed Generator</span>
                    </a>
                    <a href="https://<?= getBaseDomain() ?>/api/docs" class="nav-hamburger-item">
                        <span class="nav-item-icon">📡</span>
                        <span>API Documentation</span>
                    </a>
                    <a href="https://status.aviationwx.org" class="nav-hamburger-item">
                        <span class="nav-item-icon">📊</span>
                        <span>System Status</span>
                    </a>
                </div>
            </div>
        </div>
        <script>
            // Pass airport data to JavaScript
            window.AIRPORT_NAV_DATA = {
                currentAirportId: <?= json_encode($airportId) ?>,
                currentIdentifier: <?= json_encode($primaryIdentifier) ?>,
                baseDomain: <?= json_encode(getBaseDomain()) ?>,
                nearbyAirports: <?= json_encode($nearbyAirports) ?>,
                allAirports: <?= json_encode($allAirportsForSearch) ?>,
                webcamHistoryEnabled: <?= json_encode(isWebcamHistoryEnabledForAirport($airportId)) ?>,
                isSingleAirportMode: <?= json_encode(isSingleAirportMode()) ?>
            };
        </script>
        <?php endif; ?>

        <?php if (isset($airport['webcams']) && !empty($airport['webcams']) && count($airport['webcams']) > 0): ?>
        <script>
        (function() {
            const handled = new Set();
            window.handleWebcamError = function(camIndex, img) {
                const key = camIndex + '-' + (img.src || '');
                if (handled.has(key) || (img.src && img.src.includes('cam=999'))) {
                    return;
                }
                handled.add(key);
                const airportId = (typeof AIRPORT_ID !== 'undefined') ? AIRPORT_ID : '';
                const protocol = (window.location.protocol === 'https:') ? 'https:' : 'http:';
                const placeholderUrl = protocol + '//' + window.location.host + '/webcam.php?id=' + encodeURIComponent(airportId) + '&cam=999';
                img.onerror = null;
                const newImg = img.cloneNode(false);
                if (img.id) {
                    newImg.id = img.id;
                }
                if (img.className) {
                    newImg.className = img.className;
                }
                if (img.parentNode) {
                    img.parentNode.replaceChild(newImg, img);
                    newImg.src = placeholderUrl;
                    handled.add(camIndex + '-' + placeholderUrl);
                }
            };
        })();
        </script>
        <!-- Webcams -->
        <section class="webcam-section">
            <div class="webcam-grid<?= count($airport['webcams']) === 1 ? ' webcam-grid-single' : '' ?>">
                <?php 
                require_once __DIR__ . '/../lib/webcam-metadata.php';
                foreach ($airport['webcams'] as $index => $cam): 
                    // Get webcam metadata
                    $meta = getWebcamMetadata($airportId, $index);
                    $aspectRatio = $meta ? $meta['aspect_ratio'] : 1.777; // Default 16:9
                    $width = $meta ? $meta['width'] : 1920;
                    $height = $meta ? $meta['height'] : 1080;
                    $aspectRatioStr = round($aspectRatio, 3);
                    
                    // Get latest timestamp (used for both data-initial-timestamp and CAM_TS)
                    // Store in variable for reuse in JavaScript initialization
                    $mtimeJpg = 0;
                    if ($meta && isset($meta['timestamp'])) {
                        $mtimeJpg = $meta['timestamp'];
                    } else {
                        foreach (['jpg', 'webp'] as $ext) {
                            $filePath = getCacheSymlinkPath($airportId, $index, $ext);
                            if (file_exists($filePath)) {
                                $mtimeJpg = getImageCaptureTimeForPage($filePath);
                                break;
                            }
                        }
                    }
                    // Store for JavaScript CAM_TS initialization (ensures consistency)
                    $webcamTimestamps[$index] = $mtimeJpg;
                    
                    // Get available variants for browser selection
                    $availableVariants = [];
                    if ($mtimeJpg > 0) {
                        $availableVariants = getAvailableVariants($airportId, $index, $mtimeJpg);
                    }
                    
                    // Build srcset for each format with all available sizes
                    // Browser will select best size based on viewport and pixel density
                    $enabledFormats = getEnabledWebcamFormats();
                    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
                    $host = htmlspecialchars($_SERVER['HTTP_HOST']);
                    $baseUrl = $protocol . '://' . $host . '/webcam.php?id=' . urlencode($airportId) . '&cam=' . $index . '&ts=' . $mtimeJpg;
                    
                    // Helper to build srcset with width descriptors
                    // Browser selects best size based on viewport and pixel density
                    // $excludeOriginal: when true (dashboard), omit original if sized variants exist
                    // (Sized variants have WebP; original often JPG-only. Prefer sized for LCP.)
                    $buildSrcset = function($format, $variants, $aspectRatio, $originalWidth, $originalHeight, $excludeOriginal = false) use ($baseUrl) {
                        $srcsetParts = [];
                        $variantList = [];
                        $hasSized = false;
                        
                        foreach ($variants as $variant => $formats) {
                            if (in_array($format, $formats)) {
                                if ($variant === 'original') {
                                    if (!$excludeOriginal) {
                                        $variantList[] = 'original';
                                    }
                                } elseif (is_numeric($variant)) {
                                    $variantList[] = (int)$variant;
                                    $hasSized = true;
                                }
                            }
                        }
                        
                        $numericVariants = array_filter($variantList, 'is_numeric');
                        rsort($numericVariants);
                        // Add original: when not excluding, or when no sized variants exist (must have something)
                        if (in_array('original', $variantList)) {
                            $numericVariants[] = 'original';
                        } elseif ($excludeOriginal && !$hasSized && isset($variants['original']) && in_array($format, $variants['original'])) {
                            $numericVariants[] = 'original';
                        }
                        
                        foreach ($numericVariants as $variant) {
                            $url = $baseUrl . '&fmt=' . $format . '&size=' . $variant;
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
                    };
                    
                    // Default size for initial load: prefer largest sized variant (never original if sized exist)
                    $sizedHeights = array_filter(array_keys($availableVariants), 'is_numeric');
                    $defaultSize = !empty($sizedHeights) ? max(array_map('intval', $sizedHeights)) : 'original';
                ?>
                <div class="webcam-item" 
                     data-aspect-ratio="<?= $aspectRatioStr ?>"
                     data-width="<?= $width ?>"
                     data-height="<?= $height ?>">
                    <div class="webcam-container">
                        <div id="webcam-skeleton-<?= $index ?>" class="webcam-skeleton" style="background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%); background-size: 200% 100%; animation: skeleton-loading 1.5s ease-in-out infinite; width: 100%; aspect-ratio: <?= $width ?>/<?= $height ?>; border-radius: 4px; position: absolute; top: 0; left: 0; z-index: 1;"></div>
                        <picture>
                            <?php if (in_array('webp', $enabledFormats) && !empty($availableVariants)): ?>
                            <source srcset="<?= $buildSrcset('webp', $availableVariants, $aspectRatio, $width, $height, true) ?>" type="image/webp" sizes="100vw">
                            <?php endif; ?>
                            <img id="webcam-<?= $index ?>" 
                                 srcset="<?= !empty($availableVariants) ? $buildSrcset('jpg', $availableVariants, $aspectRatio, $width, $height, true) : ($baseUrl . '&fmt=jpg&size=' . $defaultSize) ?>"
                                 src="<?= $baseUrl ?>&fmt=jpg&size=<?= $defaultSize ?>"
                                 sizes="100vw"
                                 data-initial-timestamp="<?= $mtimeJpg ?>" 
                                 alt="<?= htmlspecialchars($cam['name']) ?> - Tap to see historical time-lapse"
                                 title="<?= htmlspecialchars($cam['name']) ?> - Tap to see historical time-lapse"
                                 aria-label="<?= htmlspecialchars($cam['name']) ?> webcam image - Tap to see historical time-lapse"
                                 role="button"
                                 tabindex="0"
                                 class="webcam-image"
                                 width="<?= $width ?>"
                                 height="<?= $height ?>"
                                 style="aspect-ratio: <?= $width ?>/<?= $height ?>; width: 100%; height: auto; position: relative; z-index: 2;"
                                 <?php // First cam is the LCP element; below-fold cams load lazily so they
                                       // stop competing with it for bandwidth on slow connections. Layout is
                                       // reserved via aspect-ratio, so lazy loading cannot shift the page. ?>
                                 fetchpriority="<?= $index === 0 ? 'high' : 'auto' ?>"
                                 loading="<?= $index === 0 ? 'eager' : 'lazy' ?>"
                                 decoding="async"
                                 onerror="handleWebcamError(<?= $index ?>, this)"
                                 onload="if(typeof observeWebcamFormat === 'function') { observeWebcamFormat(<?= $index ?>, this); } const skel=document.getElementById('webcam-skeleton-<?= $index ?>'); if(skel) skel.style.display='none'"
                                 onclick="openWebcamPlayer('<?= htmlspecialchars($airportId) ?>', <?= $index ?>, '<?= htmlspecialchars(addslashes($cam['name'])) ?>', this.currentSrc || this.src)"
                                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openWebcamPlayer('<?= htmlspecialchars($airportId) ?>', <?= $index ?>, '<?= htmlspecialchars(addslashes($cam['name'])) ?>', this.currentSrc || this.src)}">
                        </picture>
                    </div>
                    <div class="webcam-name-label">
                        <span class="webcam-name-text"><?= htmlspecialchars($cam['name']) ?></span>
                        <span class="webcam-timestamp">Last Updated: <span id="webcam-timestamp-clock-skew-<?= $index ?>" class="timestamp-clock-skew" style="display: none;" title="Your device clock may be incorrect">🕐⚠️ </span><span id="webcam-timestamp-warning-<?= $index ?>" class="webcam-timestamp-warning" style="display: none;">⚠️ </span><span id="webcam-timestamp-<?= $index ?>" data-timestamp="<?= $mtimeJpg ?>">--</span></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Build weather sources for attribution -->
        <?php
        // Credit any source that is actively providing data, with "Source Name (ICAO)" for ICAO-keyed sources
        $weatherSources = [];
        $addedKeys = []; // Track (sourceType, stationId) to avoid duplicates
        
        // Load weather cache to check which sources have fresh data
        $weatherCacheFile = getWeatherCachePath($airportId);
        $weatherData = null;
        if (file_exists($weatherCacheFile)) {
            $weatherData = @json_decode(@file_get_contents($weatherCacheFile), true);
        }
        
        require_once __DIR__ . '/../lib/constants.php';
        
        // Use a generous threshold for attribution - we want to credit sources providing data
        $staleThreshold = getStaleWarningSeconds($airport);
        
        // Helper to add source with optional station ICAO for "Source Name (ICAO)" format
        $addSource = function($sourceType, $stationId = null) use (&$weatherSources, &$addedKeys) {
            $key = $sourceType . ':' . ($stationId ?? '');
            if (in_array($key, $addedKeys)) {
                return;
            }
            $sourceInfo = getWeatherSourceInfo($sourceType);
            if ($sourceInfo !== null) {
                $displayName = getWeatherSourceDisplayName($sourceType, $stationId);
                $weatherSources[] = [
                    'name' => $displayName,
                    'url' => $sourceInfo['url']
                ];
                $addedKeys[] = $key;
            }
        };
        
        // Build attribution from field source map (includes station for ICAO-keyed sources)
        $fieldSourceMap = is_array($weatherData) ? ($weatherData['_field_source_map'] ?? []) : [];
        $fieldStationMap = is_array($weatherData) ? ($weatherData['_field_station_map'] ?? []) : [];
        
        foreach ($fieldSourceMap as $field => $sourceType) {
            $stationId = $fieldStationMap[$field] ?? null;
            if ($sourceType !== 'metar') {
                $addSource($sourceType, $stationId);
            }
        }
        
        // METAR attribution: use station only from a METAR-sourced field (wind_speed etc. may be swob_auto)
        if (is_array($weatherData) && isset($weatherData['last_updated_metar']) && $weatherData['last_updated_metar'] > 0) {
            $metarStaleThreshold = 7200; // 2 hours - METAR updates hourly with specials
            $metarAge = time() - $weatherData['last_updated_metar'];
            if ($metarAge < $metarStaleThreshold) {
                $metarStationId = null;
                foreach ($fieldSourceMap as $f => $src) {
                    if ($src === 'metar') {
                        $metarStationId = $fieldStationMap[$f] ?? null;
                        break;
                    }
                }
                $addSource('metar', $metarStationId);
            }
        }
        ?>

        <?php 
        // Show runway wind section if any weather sources are configured
        // This matches the JavaScript condition that determines whether to fetch weather
        if (hasWeatherSources($airport)): ?>
        <!-- Runway Wind Visual -->
        <section class="wind-visual-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem;">
                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <h2 style="margin: 0;">Runway Wind</h2>
                    <button id="wind-speed-unit-toggle" style="background: #f5f5f5; border: 1px solid #ccc; border-radius: 6px; padding: 0.5rem 1rem; cursor: pointer; font-size: 0.9rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; color: #333; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.1); min-width: 50px; height: auto;" title="Toggle wind speed unit (kts/mph/km/h)" onmouseover="this.style.background='#e8e8e8'; this.style.borderColor='#999';" onmouseout="this.style.background='#f5f5f5'; this.style.borderColor='#ccc';">
                        <span id="wind-speed-unit-display">kts</span>
                    </button>
                </div>
                <p style="font-size: 0.85rem; color: #555; margin: 0;">Last updated: <span id="wind-timestamp-clock-skew" class="timestamp-clock-skew" style="display: none;" title="Your device clock may be incorrect">🕐⚠️ </span><span id="wind-timestamp-warning" class="weather-timestamp-warning" style="display: none;">⚠️ </span><span id="wind-last-updated" title="Latest observation time for displayed data (not server fetch time when observation times are present)">--</span></p>
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 2rem; align-items: center; justify-content: center;">
                <div id="wind-visual" class="wind-visual-container">
                    <canvas id="windCanvas" width="300" height="300"></canvas>
                </div>
                <div id="wind-details" style="display: flex; flex-direction: column; gap: 0.5rem; min-width: 200px;">
                    <!-- Wind details will be populated by JavaScript -->
                </div>
            </div>
            <?php if (!empty($weatherSources)): ?>
            <div class="data-sources-content" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e0e0e0;">
                <div class="data-sources-list" style="text-align: center; font-size: 0.85rem; color: #555;">
                    <span class="data-sources-label">Weather data at this airport from </span>
                    <?php foreach ($weatherSources as $index => $source): ?>
                    <?php if (!empty($source['url'])): ?>
                    <a href="<?= htmlspecialchars($source['url']) ?>" target="_blank" rel="noopener" class="data-source-link">
                        <?= htmlspecialchars($source['name']) ?>
                    </a>
                    <?php else: ?>
                    <span class="data-source-link"><?= htmlspecialchars($source['name']) ?></span>
                    <?php endif; ?>
                    <?php if ($index < count($weatherSources) - 1): ?><span class="data-source-separator"> & </span><?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php 
        // Show weather section if any weather sources are configured
        // This matches the JavaScript condition that determines whether to fetch weather
        if (hasWeatherSources($airport)): ?>
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
                        <button id="baro-unit-toggle" style="background: #f5f5f5; border: 1px solid #ccc; border-radius: 6px; padding: 0.5rem 1rem; cursor: pointer; font-size: 0.9rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; color: #333; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.1); min-width: 60px; height: auto;" title="Toggle barometer unit (inHg/hPa/mmHg)" onmouseover="this.style.background='#e8e8e8'; this.style.borderColor='#999';" onmouseout="this.style.background='#f5f5f5'; this.style.borderColor='#ccc';">
                            <span id="baro-unit-display">inHg</span>
                        </button>
                    </div>
                </div>
                <p class="weather-last-updated-text" style="font-size: 0.85rem; color: #555; margin: 0;">Last updated: <span id="weather-timestamp-clock-skew" class="timestamp-clock-skew" style="display: none;" title="Your device clock may be incorrect">🕐⚠️ </span><span id="weather-timestamp-warning" class="weather-timestamp-warning" style="display: none;">⚠️ </span><span id="weather-last-updated" title="Latest observation time for displayed data (not server fetch time when observation times are present)">--</span></p>
            </div>
            <div id="weather-data" class="weather-grid">
                <div class="weather-item loading">
                    <span class="label">Loading...</span>
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
                        // GPS-style strings: link by coordinates only. A ?q= search confuses geocoders
                        // (e.g. degree text → wrong region/place name) and geo: ?q= is unnecessary.
                        if (addressLooksLikeGpsCoordinates($airport['address'])) {
                            $appleMapsUrl = 'https://maps.apple.com/?ll=' . $lat . ',' . $lon;
                        } else {
                            $geoUrl .= '?q=' . urlencode($airport['address']);
                            $appleMapsUrl = 'https://maps.apple.com/?q=' . urlencode($airport['address']) . '&ll=' . $lat . ',' . $lon;
                        }
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
                
                // Access Type field
                $accessType = $airport['access_type'] ?? null;
                if ($accessType):
                    $accessEmoji = '';
                    $accessText = '';
                    $permissionRequired = isset($airport['permission_required']) && $airport['permission_required'] === true;
                    
                    if ($accessType === 'public') {
                        $accessEmoji = '🛫';
                        $accessText = 'Public';
                    } elseif ($accessType === 'private') {
                        $accessEmoji = 'Ⓡ';
                        $accessText = 'Private';
                        if ($permissionRequired) {
                            $accessEmoji .= '🔑';
                            $accessText .= ' (Permission Required)';
                        }
                    }
                ?>
                <div class="info-item">
                    <span class="label">Access:</span>
                    <span class="value"><?= $accessEmoji ?> <?= htmlspecialchars($accessText) ?></span>
                </div>
                <?php endif; ?>
                
                <?php
                // Tower Status field
                $towerStatus = $airport['tower_status'] ?? null;
                if ($towerStatus):
                    $towerEmoji = '';
                    $towerText = '';
                    
                    if ($towerStatus === 'towered') {
                        $towerEmoji = '🗼';
                        $towerText = 'Towered';
                    } elseif ($towerStatus === 'non_towered') {
                        $towerText = 'Non-Towered';
                    }
                ?>
                <div class="info-item">
                    <span class="label">Tower:</span>
                    <span class="value"><?= $towerEmoji ? $towerEmoji . ' ' : '' ?><?= htmlspecialchars($towerText) ?></span>
                </div>
                <?php endif; ?>
                
                <?php
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
                $resolvedBuiltinLinks = airportExternalLinksBuildResolvedList($airport);
                foreach ($resolvedBuiltinLinks as $bl) {
                    $lbl = $bl['label'];
                    $u = $bl['url'];
                    $isForeflight = ($lbl === 'ForeFlight');
                    $btnClass = $isForeflight ? 'btn foreflight-link' : 'btn';
                    $title = $isForeflight
                        ? 'Open this airport in ForeFlight app'
                        : ($lbl === 'AirNav'
                            ? 'View airport information on AirNav (opens in new tab)'
                            : ($lbl === 'FAA Weather'
                                ? 'View FAA weather cameras for this area (opens in new tab)'
                                : 'View ' . $lbl . ' (opens in new tab)'));
                    ?>
                <a href="<?= htmlspecialchars($u) ?>" target="_blank" rel="noopener" class="<?= htmlspecialchars($btnClass) ?>" title="<?= htmlspecialchars($title) ?>">
                    <?= htmlspecialchars($lbl) ?>
                </a>
                <?php
                }
                ?>
                <?php
                // Render custom links if configured
                if (!empty($airport['links']) && is_array($airport['links'])) {
                    foreach ($airport['links'] as $link) {
                        // Validate that both label and url are present
                        if (!empty($link['label']) && !empty($link['url'])) {
                            ?>
                            <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" rel="noopener" class="btn" title="<?= htmlspecialchars($link['label']) ?> (opens in new tab)">
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

        <?php
        $showStationPowerBlock = isAirportLimitedAvailability($airport) && isAirportStationPowerConfigured($airport);
        $stationPowerData = null;
        $stationPowerDisplayable = false;
        $stationPowerSampleTsSec = 0;
        $stationPowerPollSeconds = 60;
        if ($showStationPowerBlock) {
            $stationPowerPollSeconds = getStationPowerRefreshSeconds($airport);
            $stationPowerData = loadStationPowerCache($airportId);
            $stationPowerDisplayable = is_array($stationPowerData) && stationPowerCacheIsDisplayable($stationPowerData);
            if ($stationPowerDisplayable && is_array($stationPowerData)) {
                $sampleMs = isset($stationPowerData['sample_time_ms']) ? (int) $stationPowerData['sample_time_ms'] : 0;
                if ($sampleMs > 0) {
                    $stationPowerSampleTsSec = (int) floor($sampleMs / 1000);
                }
            }
        }
        ?>
        <?php if ($showStationPowerBlock): ?>
        <section class="station-power-section" aria-labelledby="station-power-heading">
            <div class="station-power-header">
                <div class="station-power-header-titles">
                    <h2 id="station-power-heading" class="station-power-title">Station Power</h2>
                </div>
                <p id="station-power-last-updated-row" class="station-power-last-updated weather-last-updated-text" style="font-size: 0.85rem; color: #555; margin: 0; display: <?= $stationPowerDisplayable ? 'block' : 'none' ?>;">Last updated: <span id="station-power-timestamp-clock-skew" class="timestamp-clock-skew" style="display: none;" title="Your device clock may be incorrect">🕐⚠️ </span><span id="station-power-timestamp-warning" class="weather-timestamp-warning" style="display: none;">⚠️ </span><span id="station-power-last-updated"<?= $stationPowerSampleTsSec > 0 ? ' data-timestamp="' . htmlspecialchars((string) $stationPowerSampleTsSec, ENT_QUOTES, 'UTF-8') . '"' : '' ?> title="Latest observation time for displayed averages (not server fetch time)">--</span></p>
            </div>
            <p id="station-power-empty-state" class="station-power-empty" style="display: <?= $stationPowerDisplayable ? 'none' : 'block' ?>;">No station power data yet.</p>
                <?php
                /** @var array<string,mixed> $sp */
                $sp = ($stationPowerDisplayable && is_array($stationPowerData)) ? $stationPowerData : [];
                $cells = stationPowerDashboardFormatCells($sp);
                ?>
            <div id="station-power-data" class="station-power-data" style="display: <?= $stationPowerDisplayable ? 'block' : 'none' ?>;">
            <div class="station-power-layout">
                <div class="station-power-inputs">
                    <div class="station-power-metric">
                        <span class="label">Solar production now</span>
                        <span id="station-power-value-solar-now" class="value"><?= $cells['solar_now'] ?></span>
                    </div>
                    <div class="station-power-metric">
                        <span id="station-power-label-solar-today" class="label" title="<?= htmlspecialchars($cells['solar_today_title'], ENT_QUOTES, 'UTF-8') ?>">Solar production today</span>
                        <span id="station-power-value-solar-today" class="value"><?= $cells['solar_today'] ?></span>
                    </div>
                </div>
                <div class="station-power-hero" role="group" aria-labelledby="station-power-battery-heading">
                    <div class="station-power-metric">
                        <span class="label" id="station-power-battery-heading">Battery charge</span>
                    </div>
                    <div class="station-power-battery-trio">
                        <div class="station-power-metric station-power-battery-side station-power-battery-volts">
                            <span class="label" title="Last sampled battery voltage">Volts</span>
                            <span id="station-power-value-battery-volts" class="value station-power-battery-secondary"><?= $cells['battery_volts'] ?></span>
                        </div>
                        <div class="station-power-battery-center">
                            <div id="station-power-soc-text" class="station-power-soc"><?= $cells['soc_text'] ?></div>
                            <meter id="station-power-soc-meter" class="<?= htmlspecialchars($cells['soc_meter_class'], ENT_QUOTES, 'UTF-8') ?>" min="0" max="100" value="<?= $cells['soc_meter_value'] ?>" aria-label="Battery state of charge" style="display: <?= $cells['soc_show_meter'] ? 'inline-block' : 'none' ?>;"><?= $cells['soc_meter_inner_text'] ?></meter>
                            <p id="station-power-soc-fallback" class="station-power-meter-fallback" role="status" style="display: <?= $cells['soc_show_meter'] ? 'none' : 'block' ?>;">---</p>
                        </div>
                        <div class="station-power-metric station-power-battery-side station-power-battery-ttg">
                            <span class="label" title="Estimated time left on battery at current loads">Time left</span>
                            <span id="station-power-value-ttg" class="value station-power-battery-secondary"><?= $cells['ttg'] ?></span>
                        </div>
                    </div>
                </div>
                <div class="station-power-outputs">
                    <div class="station-power-metric">
                        <span class="label">DC load now</span>
                        <span id="station-power-value-dc-load-now" class="value"><?= $cells['dc_load_now'] ?></span>
                    </div>
                    <div class="station-power-metric">
                        <span id="station-power-label-load-today" class="label" title="<?= htmlspecialchars($cells['load_today_title'], ENT_QUOTES, 'UTF-8') ?>">DC load today</span>
                        <span id="station-power-value-load-today" class="value"><?= $cells['load_today'] ?></span>
                    </div>
                </div>
            </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Partnerships & Credits -->
        <?php
        // Get partners from new partners[] array
        $partners = $airport['partners'] ?? [];
        
        // Only show section if we have partners
        if (!empty($partners)):
        ?>
        <section class="partnerships-section">
            <div class="partnerships-container">
                <?php if (!empty($partners)): ?>
                <div class="partnerships-content">
                    <h3 class="partnerships-heading">Support These Partners</h3>
                    <p class="partnerships-subheading">These organizations make this airport service possible. Click to visit and show your support.</p>
                    <div class="partners-grid">
                        <?php foreach ($partners as $partner): ?>
                        <?php
                        $partnerLogoLum = null;
                        if (!empty($partner['logo'])) {
                            $partnerLogoLum = getPartnerLogoMeanLuminance((string) $partner['logo']);
                        }
                        $partnerLinkAttrs = '';
                        if ($partnerLogoLum !== null) {
                            $partnerLinkAttrs = ' data-logo-lum="' . htmlspecialchars(
                                number_format($partnerLogoLum, 4, '.', ''),
                                ENT_QUOTES,
                                'UTF-8'
                            ) . '"';
                        }
                        ?>
                        <div class="partner-item">
                            <a href="<?= htmlspecialchars($partner['url']) ?>" target="_blank" rel="noopener" class="partner-link" title="<?= htmlspecialchars($partner['description'] ?? $partner['name']) ?>"<?= $partnerLinkAttrs ?>>
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
            </div>
        </section>
        <?php endif; ?>

        <!-- Footer -->
        <footer class="footer">
            <p class="footer-disclaimer">
                <em>Data is for advisory use only. Consult official weather sources for flight planning purposes.</em>
            </p>
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

    <script src="/public/js/webcam-player-utils.js?v=<?= $buildHashShort ?>"></script>
    <script src="/public/js/webcam-player-scroll-lock.js?v=<?= $buildHashShort ?>"></script>
    <script src="/public/js/weather-timestamp-utils.js?v=<?= $buildHashShort ?>"></script>
    <script src="/public/js/runway-label-layout.js?v=<?= $buildHashShort ?>"></script>
    <?php
    // Webcam seed data for the dashboard bootstrap below. Timestamps reuse
    // $webcamTimestamps from the markup loop so CAM_TS always matches the
    // data-initial-timestamp attributes; refresh intervals cascade per-cam ->
    // airport -> global default with a 60s floor.
    $webcamInitialTimestamps = [];
    $webcamRefreshSeconds = [];
    if (isset($airport['webcams']) && is_array($airport['webcams'])) {
        $defaultWebcamRefresh = getDefaultWebcamRefresh();
        $airportWebcamRefresh = isset($airport['webcam_refresh_seconds'])
            ? intval($airport['webcam_refresh_seconds'])
            : $defaultWebcamRefresh;
        foreach ($airport['webcams'] as $camIndex => $cam) {
            $initialMtime = isset($webcamTimestamps[$camIndex]) ? $webcamTimestamps[$camIndex] : 0;
            if ($initialMtime === 0) {
                foreach (['jpg', 'webp'] as $ext) {
                    $filePath = getCacheSymlinkPath($airportId, $camIndex, $ext);
                    if (file_exists($filePath)) {
                        $initialMtime = getImageCaptureTimeForPage($filePath);
                        break;
                    }
                }
            }
            $webcamInitialTimestamps[] = (int) $initialMtime;
            $perCamRefresh = isset($cam['refresh_seconds']) ? intval($cam['refresh_seconds']) : $airportWebcamRefresh;
            $webcamRefreshSeconds[] = max(60, $perCamRefresh);
        }
    }
    ?>
    <script>
// Airport page JavaScript
const AIRPORT_ID = '<?= $airportId ?>';
const SERVER_TIME_UTC = <?= time() ?>; // For client clock skew detection (5 min threshold)
const CLIENT_CLOCK_SKEW_SECONDS = 300; // 5 min - if client differs from server by more, show warning
let clientClockSkewDetected = (function() {
    const clientUtc = Math.floor(Date.now() / 1000);
    return Math.abs(clientUtc - SERVER_TIME_UTC) > CLIENT_CLOCK_SKEW_SECONDS;
})();
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

// Initial banner state for status banners (maintenance, outage, limited-availability)
const INITIAL_BANNER_STATE = <?php
    $outageStatus = checkDataOutageStatus($airportId, $airport);
    echo json_encode([
        'maintenance' => isAirportInMaintenance($airport),
        'in_outage' => $outageStatus !== null,
        'limited_availability' => $outageStatus !== null && ($outageStatus['limited_availability'] ?? false),
        'newest_timestamp' => $outageStatus !== null ? $outageStatus['newest_timestamp'] : 0
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

// Initial weather data (embedded from cache for immediate display)
const INITIAL_WEATHER_DATA = <?php
    // Load cached weather data for immediate display
    $weatherCacheFile = getWeatherCachePath($airportId);
    $initialWeatherData = null;
    if (file_exists($weatherCacheFile)) {
        $cachedWeather = @json_decode(@file_get_contents($weatherCacheFile), true);
        if (is_array($cachedWeather)) {
            // Apply staleness checks to ensure we don't show stale data
            require_once __DIR__ . '/../lib/constants.php';
            require_once __DIR__ . '/../lib/weather/cache-utils.php';
            
            // Get failclosed thresholds for staleness checks
            $failclosedSeconds = getStaleFailclosedSeconds($airport);
            $failclosedSecondsMetar = getMetarStaleFailclosedSeconds();
            
            // Null out stale fields before embedding (using failclosed threshold)
            // isMetarOnly = all configured sources are METAR type
            $isMetarOnly = true;
            if (isset($airport['weather_sources']) && is_array($airport['weather_sources'])) {
                foreach ($airport['weather_sources'] as $source) {
                    if (!empty($source['type']) && $source['type'] !== 'metar') {
                        $isMetarOnly = false;
                        break;
                    }
                }
            }
            nullStaleFieldsBySource($cachedWeather, $failclosedSeconds, $failclosedSecondsMetar, $isMetarOnly);
            
            $initialWeatherData = $cachedWeather;
        }
    }
    
    // Defensive JSON encoding with error handling
    if ($initialWeatherData === null) {
        echo 'null'; // No cached data available
    } else {
        $weatherJson = json_encode($initialWeatherData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($weatherJson === false) {
            error_log('JSON encode failed for initial weather data: ' . json_last_error_msg());
            echo 'null'; // Fallback to null
        } else {
            echo $weatherJson;
        }
    }
?>;

// Default timezone (from global config)
const DEFAULT_TIMEZONE = <?php
    $defaultTz = getDefaultTimezone();
    echo json_encode($defaultTz, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

// Server-computed timezone display (reliable IANA data; avoids browser Intl abbreviation bugs)
const INITIAL_TIMEZONE_DISPLAY = <?php
    $tzDisplay = getTimezoneDisplayForAirport($airport);
    echo json_encode($tzDisplay, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

// Default preferences for unit toggles (merged: global config -> airport override)
const DEFAULT_PREFERENCES = <?php
    $defaultPrefs = getDefaultPreferencesForAirport($airportId);
    echo json_encode($defaultPrefs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

// Magnetic declination (fallback when wind_direction_magnetic not in API response)
const MAGNETIC_DECLINATION = <?= (float) getMagneticDeclination($airport) ?>;

// Precomputed runway segments for wind visualization (normalized -1..1, North=+y, magnetic north)
// Segments are rotated to magnetic in PHP (lib/heading-conversion.php)
const RUNWAY_SEGMENTS = <?php
    $segments = getRunwaySegmentsForAirport($airportId, $airport);
    $segmentsJson = json_encode($segments ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($segmentsJson === false) {
        error_log('JSON encode failed for runway segments: ' . json_last_error_msg());
        echo '[]';
    } else {
        echo $segmentsJson;
    }
?>;

// Staleness thresholds (3-tier model from config)
// Thresholds cascade: airport config -> global config -> built-in defaults
const STALE_WARNING_SECONDS = <?= getStaleWarningSeconds($airport) ?>;
const STALE_ERROR_SECONDS = <?= getStaleErrorSeconds($airport) ?>;
const STALE_FAILCLOSED_SECONDS = <?= getStaleFailclosedSeconds($airport) ?>;

// Outage banner: 30 min for limited_availability (overridable), else failclosed
const OUTAGE_BANNER_THRESHOLD_SECONDS = <?= getOutageBannerThresholdSeconds($airport) ?>;

// METAR-specific thresholds (global only)
const METAR_STALE_WARNING_SECONDS = <?= getMetarStaleWarningSeconds() ?>;
const METAR_STALE_ERROR_SECONDS = <?= getMetarStaleErrorSeconds() ?>;
const METAR_STALE_FAILCLOSED_SECONDS = <?= getMetarStaleFailclosedSeconds() ?>;

const SECONDS_PER_HOUR = 3600;

// Partner logo contrast thresholds (must match lib/constants.php)
const PARTNER_LOGO_LUM_LIGHT = <?= PARTNER_LOGO_LUMINANCE_LIGHT_THRESHOLD ?>;
const PARTNER_LOGO_LUM_DARK = <?= PARTNER_LOGO_LUMINANCE_DARK_THRESHOLD ?>;

// Station power UI (limited-availability airports with configured sensors)
const STATION_POWER_POLL_MS = <?= !empty($showStationPowerBlock) ? (int) ($stationPowerPollSeconds * 1000) : 0 ?>;
const HAS_STATION_POWER_UI = <?= !empty($showStationPowerBlock) ? 'true' : 'false' ?>;

// Night mode window (civil twilight times in the airport timezone)
var NIGHT_MODE_DATA = <?= json_encode($nightModeData) ?>;

// Webcam seeds: initial capture timestamps (EXIF or file mtime, 0 when
// unavailable) and per-camera refresh intervals in seconds
const WEBCAM_INITIAL_TIMESTAMPS = <?= json_encode($webcamInitialTimestamps) ?>;
const WEBCAM_REFRESH_SECONDS = <?= json_encode($webcamRefreshSeconds) ?>;
    </script>
    <script src="/public/js/airport-dashboard.js?v=<?= $buildHashShort ?>"></script>

<!-- Webcam History Player (hidden by default) -->
<div id="webcam-player" class="webcam-player" role="dialog" aria-modal="true" aria-labelledby="webcam-player-title">
    <div class="webcam-player-header">
        <button class="webcam-player-back" onclick="closeWebcamPlayer()" aria-label="Close player and go back" title="Close player and return to dashboard (Escape key)">← Back</button>
        <span class="webcam-player-title" id="webcam-player-title">Camera</span>
        <span style="width: 60px;"></span>
    </div>
    
    <div class="webcam-player-image-container" id="webcam-player-swipe-area">
        <img id="webcam-player-image" class="webcam-player-image" src="" alt="Webcam history frame">
        <div id="webcam-player-loading-bar" class="webcam-player-loading-bar" style="width: 0%;" role="progressbar" aria-label="Loading frames"></div>
    </div>
    
    <div class="webcam-player-timestamp" id="webcam-player-timestamp" aria-live="polite">--</div>
    
    <div class="webcam-player-controls">
        <!-- Period selector: shown above timeline on mobile portrait -->
        <div class="webcam-player-period-selector webcam-player-period-mobile" id="webcam-player-period-selector-mobile">
            <span class="period-label">History:</span>
            <div class="period-buttons" id="webcam-player-period-buttons-mobile" role="group" aria-label="History period selection">
                <!-- Period buttons dynamically inserted by JavaScript -->
            </div>
        </div>
        <label for="webcam-player-timeline" class="visually-hidden">Timeline scrubber</label>
        <input type="range" 
               id="webcam-player-timeline" 
               class="webcam-player-timeline" 
               min="0" 
               max="0" 
               value="0"
               aria-label="Timeline - drag to navigate through history"
               title="Drag to scrub through history">
        <div class="webcam-player-time-range" aria-hidden="true">
            <span id="webcam-player-time-start">--</span>
            <span id="webcam-player-time-end">--</span>
        </div>
        <div class="webcam-player-buttons" role="group" aria-label="Playback controls">
            <!-- Period selector: shown in button row on desktop/landscape -->
            <div class="webcam-player-period-selector webcam-player-period-desktop" id="webcam-player-period-selector-desktop">
                <span class="period-label">History:</span>
                <div class="period-buttons" id="webcam-player-period-buttons-desktop" role="group" aria-label="History period selection">
                    <!-- Period buttons dynamically inserted by JavaScript -->
                </div>
            </div>
            <button class="webcam-player-btn" id="webcam-player-prev-btn" onclick="webcamPlayerPrev()" aria-label="Previous frame" title="Previous frame (<- arrow key)">⏮</button>
            <button class="webcam-player-btn play" id="webcam-player-play-btn" onclick="webcamPlayerTogglePlay()" aria-label="Play or pause" title="Play/pause time-lapse (Space bar)">▶</button>
            <button class="webcam-player-btn" id="webcam-player-next-btn" onclick="webcamPlayerNext()" aria-label="Next frame" title="Next frame (-> arrow key)">⏭</button>
            <span class="webcam-player-btn-divider"></span>
            <button class="webcam-player-btn toggle" id="webcam-player-autoplay-btn" onclick="webcamPlayerToggleAutoplay()" aria-label="Toggle autoplay" title="Toggle continuous playback">🔄</button>
            <button class="webcam-player-btn" id="webcam-player-download-btn" onclick="webcamPlayerDownload()" aria-label="Download original image" title="Download original image with EXIF data">⬇️</button>
            <button class="webcam-player-btn toggle" id="webcam-player-hideui-btn" onclick="webcamPlayerToggleHideUI()" aria-label="Toggle fullscreen mode" title="Hide controls for full-screen view">⛶</button>
        </div>
    </div>
</div>

<!-- EXIF timestamp extractor for webcam image verification (loaded before timer) -->
<script src="/public/js/exif-timestamp.js?v=<?= $buildHashShort ?>"></script>

<!-- Timer lifecycle manager (deferred, non-blocking) -->
<script src="/public/js/timer-lifecycle.js?v=<?= $buildHashShort ?>" defer></script>
</body>
</html>


