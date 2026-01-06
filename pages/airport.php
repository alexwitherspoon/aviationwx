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

// =============================================================================
// Version Cookie & Emergency Cleanup Detection
// Set version cookie on every response for cross-subdomain version tracking
// =============================================================================

$versionFile = __DIR__ . '/../config/version.json';
$buildTimestamp = time();
$buildHash = 'unknown';
$maxNoUpdateDays = 7;
$stuckClientCleanup = false; // Default off, enable in airports.json when needed

if (file_exists($versionFile)) {
    $versionData = json_decode(file_get_contents($versionFile), true);
    if ($versionData) {
        $buildTimestamp = $versionData['timestamp'] ?? time();
        $buildHash = $versionData['hash'] ?? 'unknown';
        $maxNoUpdateDays = $versionData['max_no_update_days'] ?? 7;
        $stuckClientCleanup = $versionData['stuck_client_cleanup'] ?? false;
    }
}

// Build version cookie value: short_hash.timestamp
// If hash is 'unknown', generate a deterministic hash for testing/development
if ($buildHash === 'unknown') {
    // Generate a deterministic hex hash based on project root and timestamp
    // This ensures consistent hash in test/dev environments and matches expected format
    $projectRoot = dirname(__DIR__);
    $buildHash = substr(md5($projectRoot . $buildTimestamp), 0, 7);
}
$buildHashShort = substr($buildHash, 0, 7);
$versionCookieValue = $buildHashShort . '.' . $buildTimestamp;

// Set version cookie on every response (cross-subdomain via .aviationwx.org)
$baseDomainForCookie = getBaseDomain();
$cookieDomain = '.' . $baseDomainForCookie;
$cookieExpiry = time() + 31536000; // 1 year

// Set cookie with proper options
setcookie('aviationwx_v', $versionCookieValue, [
    'expires' => $cookieExpiry,
    'path' => '/',
    'domain' => $cookieDomain,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => false, // JS needs to read it
    'samesite' => 'Lax'
]);

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

// =============================================================================
// TEMPORARY DEBUG CODE - REMOVE AFTER 2025-03-15 (or when kspb cache issue resolved)
// =============================================================================
// Meta refresh cleanup for stuck kspb clients with old cached HTML/JS
// This forces a cache-busting reload even when service worker has cached HTML
// Only triggers for kspb when version cookie is older than 14 days
// TODO: Remove this entire block after cleanup period (target: ~2 months from implementation)
// =============================================================================
$injectMetaRefresh = false;
$metaRefreshReason = '';

// Only process for kspb airport
if (strtolower($airportId) === 'kspb') {
    // Check if we've already attempted refresh (prevent loops)
    $refreshAttempted = isset($_COOKIE['aviationwx_refresh_attempted']);
    $hasRefreshParam = isset($_GET['refresh']) && $_GET['refresh'] === '1';
    
    // Don't refresh if we already attempted or if refresh param is present
    if (!$refreshAttempted && !$hasRefreshParam) {
        $clientVersionCookie = $_COOKIE['aviationwx_v'] ?? null;
        
        if ($clientVersionCookie === null) {
            // No version cookie - check if they have other aviationwx cookies
            // (indicates returning user, not first visit)
            $hasOtherCookies = false;
            foreach ($_COOKIE as $name => $value) {
                if (strpos($name, 'aviationwx_') === 0 && $name !== 'aviationwx_v' && $name !== 'aviationwx_refresh_attempted') {
                    $hasOtherCookies = true;
                    break;
                }
            }
            
            if ($hasOtherCookies) {
                $injectMetaRefresh = true;
                $metaRefreshReason = 'kspb: Missing version cookie but has preference cookies';
            }
        } else {
            // Parse cookie: "hash.timestamp"
            $parts = explode('.', $clientVersionCookie);
            if (count($parts) === 2) {
                $clientTimestamp = (int)$parts[1];
                $cookieAgeDays = (time() - $clientTimestamp) / 86400;
                
                // Only refresh if cookie is older than 14 days
                if ($cookieAgeDays > 14) {
                    $injectMetaRefresh = true;
                    $metaRefreshReason = 'kspb: Version cookie is ' . round($cookieAgeDays) . ' days old';
                }
            }
        }
        
        // If we're going to inject refresh, set a cookie to prevent loops
        if ($injectMetaRefresh) {
            setcookie('aviationwx_refresh_attempted', '1', [
                'expires' => time() + 86400, // 1 day
                'path' => '/',
                'domain' => $cookieDomain,
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'httponly' => false,
                'samesite' => 'Lax'
            ]);
        }
    }
}
// =============================================================================
// END TEMPORARY DEBUG CODE
// =============================================================================

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
$webcamText = $webcamCount > 0 ? $webcamCount . ' live webcam' . ($webcamCount > 1 ? 's' : '') : '';
// Get primary identifier (ICAO > IATA > FAA > Airport ID) for display
$primaryIdentifier = getPrimaryIdentifier($airportId, $airport);
$pageTitle = htmlspecialchars($airport['name']) . ' (' . htmlspecialchars($primaryIdentifier) . ') - Live Webcams & Runway Conditions';
// Optimized meta description - action-oriented, under 160 chars
$pageDescription = 'Check current conditions at ' . htmlspecialchars($airport['name']) . ' (' . htmlspecialchars($primaryIdentifier) . ')' . 
    ($webcamText ? ' - ' . $webcamText . ', real-time wind & weather.' : ' - real-time wind, visibility & weather.') . 
    ' Updated every minute. Free.';
$pageKeywords = htmlspecialchars($primaryIdentifier) . ', ' . htmlspecialchars($airport['name']) . ', live airport webcam, runway conditions, ' . htmlspecialchars($primaryIdentifier) . ' weather, airport webcam, pilot weather, aviation weather';
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
    <?php
    // =============================================================================
    // TEMPORARY DEBUG CODE - REMOVE AFTER 2025-03-15 (or when kspb cache issue resolved)
    // =============================================================================
    // Inject cache-clearing script + meta refresh for stuck kspb clients
    // This executes even when HTML is cached by service worker
    // Script clears all caches, service workers, and storage before reloading
    // TODO: Remove this block along with detection logic above
    // =============================================================================
    if ($injectMetaRefresh) {
        // Inject JavaScript that clears all caches and service workers
        // This runs immediately, even from cached HTML, and waits for cleanup to complete
        echo '<script>' . "\n";
        echo '(async function() {' . "\n";
        echo '    "use strict";' . "\n";
        echo '    console.warn("[CacheCleanup] Clearing all caches for kspb stuck client");' . "\n";
        echo '    ' . "\n";
        echo '    // Prevent multiple cleanup attempts' . "\n";
        echo '    if (sessionStorage.getItem("aviationwx-cleanup-in-progress")) {' . "\n";
        echo '        console.log("[CacheCleanup] Already in progress, skipping");' . "\n";
        echo '        return;' . "\n";
        echo '    }' . "\n";
        echo '    sessionStorage.setItem("aviationwx-cleanup-in-progress", Date.now().toString());' . "\n";
        echo '    ' . "\n";
        echo '    try {' . "\n";
        echo '        // 1. Clear all Cache API caches' . "\n";
        echo '        if ("caches" in window) {' . "\n";
        echo '            const cacheNames = await caches.keys();' . "\n";
        echo '            await Promise.all(cacheNames.map(name => {' . "\n";
        echo '                console.log("[CacheCleanup] Deleting cache:", name);' . "\n";
        echo '                return caches.delete(name);' . "\n";
        echo '            }));' . "\n";
        echo '            console.log("[CacheCleanup] Cleared", cacheNames.length, "caches");' . "\n";
        echo '        }' . "\n";
        echo '        ' . "\n";
        echo '        // 2. Unregister all service workers' . "\n";
        echo '        if ("serviceWorker" in navigator) {' . "\n";
        echo '            const registrations = await navigator.serviceWorker.getRegistrations();' . "\n";
        echo '            await Promise.all(registrations.map(reg => {' . "\n";
        echo '                console.log("[CacheCleanup] Unregistering service worker:", reg.scope);' . "\n";
        echo '                return reg.unregister();' . "\n";
        echo '            }));' . "\n";
        echo '            console.log("[CacheCleanup] Unregistered", registrations.length, "service workers");' . "\n";
        echo '        }' . "\n";
        echo '        ' . "\n";
        echo '        // 3. Clear localStorage' . "\n";
        echo '        try { ' . "\n";
        echo '            localStorage.clear();' . "\n";
        echo '            console.log("[CacheCleanup] Cleared localStorage");' . "\n";
        echo '        } catch(e) {' . "\n";
        echo '            console.warn("[CacheCleanup] Could not clear localStorage:", e);' . "\n";
        echo '        }' . "\n";
        echo '        ' . "\n";
        echo '        // 4. Clear sessionStorage (except cleanup flag)' . "\n";
        echo '        try {' . "\n";
        echo '            const flag = sessionStorage.getItem("aviationwx-cleanup-in-progress");' . "\n";
        echo '            sessionStorage.clear();' . "\n";
        echo '            if (flag) sessionStorage.setItem("aviationwx-cleanup-in-progress", flag);' . "\n";
        echo '            console.log("[CacheCleanup] Cleared sessionStorage");' . "\n";
        echo '        } catch(e) {' . "\n";
        echo '            console.warn("[CacheCleanup] Could not clear sessionStorage:", e);' . "\n";
        echo '        }' . "\n";
        echo '        ' . "\n";
        echo '        // 5. Clear all cookies (by setting expired dates)' . "\n";
        echo '        try {' . "\n";
        echo '            // Get all cookies for current domain' . "\n";
        echo '            const cookies = document.cookie.split(";");' . "\n";
        echo '            const domain = window.location.hostname;' . "\n";
        echo '            const baseDomain = domain.startsWith("www.") ? domain.substring(4) : domain;' . "\n";
        echo '            const cookieDomain = "." + baseDomain;' . "\n";
        echo '            ' . "\n";
        echo '            // Clear all aviationwx_ cookies' . "\n";
        echo '            cookies.forEach(cookie => {' . "\n";
        echo '                const cookieName = cookie.split("=")[0].trim();' . "\n";
        echo '                if (cookieName.startsWith("aviationwx_")) {' . "\n";
        echo '                    // Delete cookie by setting expired date' . "\n";
        echo '                    // Try multiple paths/domains to ensure deletion' . "\n";
        echo '                    document.cookie = cookieName + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; domain=" + cookieDomain;' . "\n";
        echo '                    document.cookie = cookieName + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; domain=" + baseDomain;' . "\n";
        echo '                    document.cookie = cookieName + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/";' . "\n";
        echo '                    console.log("[CacheCleanup] Deleted cookie:", cookieName);' . "\n";
        echo '                }' . "\n";
        echo '            });' . "\n";
        echo '            console.log("[CacheCleanup] Cleared cookies");' . "\n";
        echo '        } catch(e) {' . "\n";
        echo '            console.warn("[CacheCleanup] Could not clear cookies:", e);' . "\n";
        echo '        }' . "\n";
        echo '        ' . "\n";
        echo '        // 6. Clear IndexedDB (if used)' . "\n";
        echo '        try {' . "\n";
        echo '            if ("indexedDB" in window) {' . "\n";
        echo '                const databases = await indexedDB.databases();' . "\n";
        echo '                await Promise.all(databases.map(db => {' . "\n";
        echo '                    return new Promise((resolve, reject) => {' . "\n";
        echo '                        const deleteReq = indexedDB.deleteDatabase(db.name);' . "\n";
        echo '                        deleteReq.onsuccess = () => {' . "\n";
        echo '                            console.log("[CacheCleanup] Deleted IndexedDB:", db.name);' . "\n";
        echo '                            resolve();' . "\n";
        echo '                        };' . "\n";
        echo '                        deleteReq.onerror = () => reject(deleteReq.error);' . "\n";
        echo '                        deleteReq.onblocked = () => resolve(); // Database in use, skip' . "\n";
        echo '                    });' . "\n";
        echo '                }));' . "\n";
        echo '                console.log("[CacheCleanup] Cleared IndexedDB");' . "\n";
        echo '            }' . "\n";
        echo '        } catch(e) {' . "\n";
        echo '            console.warn("[CacheCleanup] Could not clear IndexedDB:", e);' . "\n";
        echo '        }' . "\n";
        echo '        ' . "\n";
        echo '        console.log("[CacheCleanup] Complete, setting new version cookie...");' . "\n";
        echo '        ' . "\n";
        echo '        // 7. Set new version cookie immediately (prevents re-triggering cleanup)' . "\n";
        echo '        // This ensures the new version cookie is set before reload' . "\n";
        echo '        try {' . "\n";
        echo '            const buildHash = "' . $buildHashShort . '";' . "\n";
        echo '            const buildTimestamp = ' . $buildTimestamp . ';' . "\n";
        echo '            const versionCookieValue = buildHash + "." + buildTimestamp;' . "\n";
        echo '            const domain = window.location.hostname;' . "\n";
        echo '            const baseDomain = domain.startsWith("www.") ? domain.substring(4) : domain;' . "\n";
        echo '            const cookieDomain = "." + baseDomain;' . "\n";
        echo '            const expires = new Date(Date.now() + 31536000000).toUTCString(); // 1 year' . "\n";
        echo '            const isSecure = window.location.protocol === "https:";' . "\n";
        echo '            document.cookie = "aviationwx_v=" + versionCookieValue + "; expires=" + expires + "; path=/; domain=" + cookieDomain + (isSecure ? "; secure" : "") + "; SameSite=Lax";' . "\n";
        echo '            console.log("[CacheCleanup] Set new version cookie:", versionCookieValue);' . "\n";
        echo '        } catch(e) {' . "\n";
        echo '            console.warn("[CacheCleanup] Could not set version cookie:", e);' . "\n";
        echo '        }' . "\n";
        echo '        ' . "\n";
        echo '        // 8. Force hard reload with cache-busting parameter' . "\n";
        echo '        // Small delay to ensure cleanup operations complete' . "\n";
        echo '        await new Promise(resolve => setTimeout(resolve, 100));' . "\n";
        echo '        const refreshUrl = "?v=" + Date.now() + "&refresh=1";' . "\n";
        echo '        window.location.href = refreshUrl;' . "\n";
        echo '    } catch(err) {' . "\n";
        echo '        console.error("[CacheCleanup] Error:", err);' . "\n";
        echo '        // Even on error, try to reload' . "\n";
        echo '        window.location.reload(true);' . "\n";
        echo '    }' . "\n";
        echo '})();' . "\n";
        echo '</script>' . "\n";
        
        // Log for monitoring
        error_log('[CacheCleanup] Triggered for kspb: ' . $metaRefreshReason);
    }
    // =============================================================================
    // END TEMPORARY DEBUG CODE
    // =============================================================================
    ?>
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
    // Calculate sunrise/sunset for night mode auto-detection
    // Uses airport's coordinates and timezone for accurate local times
    $nightModeData = [];
    if (isset($airport['lat']) && isset($airport['lon']) && isset($airport['timezone'])) {
        try {
            $tz = new DateTimeZone($airport['timezone']);
            $now = new DateTime('now', $tz);
            $today = $now->format('Y-m-d');
            
            // Get sun info for today at the airport's location
            $sunInfo = date_sun_info(
                strtotime($today . ' 12:00:00 ' . $airport['timezone']),
                $airport['lat'],
                $airport['lon']
            );
            
            if ($sunInfo !== false && isset($sunInfo['sunset']) && isset($sunInfo['sunrise'])) {
                // Format times in airport's timezone
                $sunrise = new DateTime('@' . $sunInfo['sunrise']);
                $sunrise->setTimezone($tz);
                $sunset = new DateTime('@' . $sunInfo['sunset']);
                $sunset->setTimezone($tz);
                
                $nightModeData = [
                    'timezone' => $airport['timezone'],
                    'sunriseHour' => (int)$sunrise->format('G'),
                    'sunriseMin' => (int)$sunrise->format('i'),
                    'sunsetHour' => (int)$sunset->format('G'),
                    'sunsetMin' => (int)$sunset->format('i'),
                    'currentHour' => (int)$now->format('G'),
                    'currentMin' => (int)$now->format('i'),
                    'todayDate' => $today
                ];
            }
        } catch (Exception $e) {
            // Silently fail - night mode just won't auto-activate
        }
    }
    ?>
    <script>
        // Theme Mode - Instant activation before first paint
        // Four modes: auto (browser preference), day (light), dark (classic dark), night (red night vision)
        // Priority: 
        //   1) Mobile after sunset → Night mode (safety priority, unless manually overridden today)
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
                document.documentElement.classList.remove('night-mode', 'dark-mode');
                
                // Apply the selected theme
                if (theme === 'night') {
                    document.documentElement.classList.add('night-mode');
                } else if (theme === 'dark') {
                    document.documentElement.classList.add('dark-mode');
                }
                // 'day' = no class (default light mode)
            }
            
            // Apply theme based on browser preference (for auto mode)
            function applyAutoTheme() {
                applyTheme(browserPrefersDark() ? 'dark' : 'day');
            }
            
            // Check if it's currently night at the airport
            function isNightTime() {
                if (!nightData || !nightData.timezone) return false;
                var currentMins = nightData.currentHour * 60 + nightData.currentMin;
                var sunriseMins = nightData.sunriseHour * 60 + nightData.sunriseMin;
                var sunsetMins = nightData.sunsetHour * 60 + nightData.sunsetMin;
                
                // Night = after sunset OR before sunrise
                return currentMins >= sunsetMins || currentMins < sunriseMins;
            }
            
            // Get theme preference (auto/day/dark are stored - night is time-based)
            var themePref = getCookie('aviationwx_theme');
            var manualOverride = getCookie('aviationwx_theme_override') || getCookie('aviationwx_night_override');
            
            // Legacy support: convert old preferences
            if (!themePref) {
                var oldNightPref = getCookie('aviationwx_night_mode');
                if (oldNightPref === 'off') themePref = 'day';
                // Note: 'night' is no longer stored - it's purely time-based
            }
            
            // Ignore legacy 'night' preference (should not be stored anymore)
            if (themePref === 'night') themePref = null;
            
            // PRIORITY 1: Mobile after sunset → Auto night mode (safety priority)
            if (isMobile() && isNightTime()) {
                // Unless user manually overrode today with day/dark
                if (nightData && manualOverride === nightData.todayDate && (themePref === 'day' || themePref === 'dark')) {
                    applyTheme(themePref);
                } else {
                    applyTheme('night');
                }
                return;
            }
            
            // PRIORITY 2: Saved cookie preference (day/dark - explicit choice)
            if (themePref === 'day' || themePref === 'dark') {
                applyTheme(themePref);
                return;
            }
            
            // PRIORITY 3: Auto mode (explicit 'auto' or no preference) → follow browser preference
            // This is the default when no preference is stored
            applyAutoTheme();
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
        case 'synopticdata':
            echo "    <link rel=\"preconnect\" href=\"https://api.synopticdata.com\" crossorigin>\n";
            echo "    <link rel=\"dns-prefetch\" href=\"https://api.synopticdata.com\">\n";
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
    
    // Structured data (Airport schema)
    echo generateStructuredDataScript(generateAirportSchema($airport, $airportId));
    echo "\n    ";
    
    // Breadcrumb structured data
    echo generateStructuredDataScript(generateAirportBreadcrumbs($airport, $primaryIdentifier));
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
                    for (const registration of registrations) {
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
                            if (!newWorker) {
                                return;
                            }
                            
                            newWorker.addEventListener('statechange', function() {
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
    <?php if ($injectStuckClientCleanup): ?>
    <!-- Stuck client cleanup - clears caches for clients on old code -->
    <script>
    (function() {
        'use strict';
        console.warn('[StuckClientCleanup] Triggered: <?= addslashes($stuckClientCleanupReason) ?>');
        
        // Prevent cleanup loops
        if (sessionStorage.getItem('aviationwx-cleanup-in-progress')) {
            console.log('[StuckClientCleanup] Already in progress, skipping');
            return;
        }
        sessionStorage.setItem('aviationwx-cleanup-in-progress', Date.now().toString());
        
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
                    if (flag) sessionStorage.setItem('aviationwx-cleanup-in-progress', flag);
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
    <script>
        /**
         * Client Version Checking & Dead Man's Switch
         * 
         * Detects stuck/stale client versions and forces a full cleanup when:
         * 1. No service worker update has occurred in max_no_update_days
         * 2. The build timestamp age is unknown (localStorage cleared but client stuck)
         * 3. Server responds with force_cleanup flag
         * 
         * This is a safety net for rare edge cases where normal SW updates fail.
         */
        (function() {
            'use strict';
            
            const BUILD_TIMESTAMP = <?= $buildTimestamp ?>;
            const BUILD_HASH = '<?= $buildHash ?>';
            const MAX_NO_UPDATE_DAYS = <?= $maxNoUpdateDays ?>;
            const LAST_UPDATE_KEY = 'aviationwx-last-sw-update';
            const CLEANUP_IN_PROGRESS_KEY = 'aviationwx-cleanup-in-progress';
            
            /**
             * Perform full cleanup - clear all caches, storage, and service workers
             * This is intentionally aggressive as it only triggers in rare stuck states
             */
            async function performFullCleanup(reason) {
                console.warn('[Version] Performing full cleanup. Reason:', reason);
                
                // Prevent cleanup loops - if we just did a cleanup, don't do another
                const cleanupInProgress = sessionStorage.getItem(CLEANUP_IN_PROGRESS_KEY);
                if (cleanupInProgress) {
                    console.log('[Version] Cleanup already in progress, skipping');
                    return;
                }
                
                // Mark cleanup as in progress (session-scoped to prevent loops)
                sessionStorage.setItem(CLEANUP_IN_PROGRESS_KEY, Date.now().toString());
                
                try {
                    // 1. Clear all Cache API caches
                    if ('caches' in window) {
                        const cacheNames = await caches.keys();
                        await Promise.all(cacheNames.map(name => {
                            console.log('[Version] Deleting cache:', name);
                            return caches.delete(name);
                        }));
                    }
                    
                    // 2. Clear localStorage (all keys)
                    try {
                        localStorage.clear();
                        console.log('[Version] Cleared localStorage');
                    } catch (e) {
                        console.warn('[Version] Could not clear localStorage:', e);
                    }
                    
                    // 3. Clear sessionStorage (except cleanup flag)
                    try {
                        const cleanupFlag = sessionStorage.getItem(CLEANUP_IN_PROGRESS_KEY);
                        sessionStorage.clear();
                        if (cleanupFlag) {
                            sessionStorage.setItem(CLEANUP_IN_PROGRESS_KEY, cleanupFlag);
                        }
                        console.log('[Version] Cleared sessionStorage');
                    } catch (e) {
                        console.warn('[Version] Could not clear sessionStorage:', e);
                    }
                    
                    // 4. Unregister all service workers
                    if ('serviceWorker' in navigator) {
                        const registrations = await navigator.serviceWorker.getRegistrations();
                        await Promise.all(registrations.map(reg => {
                            console.log('[Version] Unregistering service worker:', reg.scope);
                            return reg.unregister();
                        }));
                    }
                    
                    console.log('[Version] Cleanup complete, reloading page...');
                    
                    // 5. Force hard reload from network
                    // Small delay to ensure cleanup operations complete
                    setTimeout(() => {
                        window.location.reload(true);
                    }, 100);
                    
                } catch (err) {
                    console.error('[Version] Cleanup error:', err);
                    // Even on error, try to reload
                    window.location.reload(true);
                }
            }
            
            /**
             * Check if dead man's switch should trigger
             * Returns reason string if cleanup needed, null otherwise
             */
            function checkDeadManSwitch() {
                const now = Date.now();
                const maxAgeMs = MAX_NO_UPDATE_DAYS * 24 * 60 * 60 * 1000;
                
                // Get last SW update timestamp from localStorage
                const lastUpdateStr = localStorage.getItem(LAST_UPDATE_KEY);
                
                if (!lastUpdateStr) {
                    // No record of last update - this could be:
                    // 1. First visit ever (normal)
                    // 2. localStorage was cleared (normal)
                    // 3. Very old client that never had this tracking (concerning)
                    
                    // Check if this build is suspiciously old
                    const buildAge = now - (BUILD_TIMESTAMP * 1000);
                    if (buildAge > maxAgeMs) {
                        // Build is older than max age AND we have no update record
                        // This suggests a stuck client with cleared storage
                        return `Build is ${Math.floor(buildAge / 86400000)} days old with no update record`;
                    }
                    
                    // First visit or recent build - initialize tracking
                    localStorage.setItem(LAST_UPDATE_KEY, now.toString());
                    return null;
                }
                
                const lastUpdate = parseInt(lastUpdateStr, 10);
                if (isNaN(lastUpdate) || lastUpdate <= 0) {
                    // Invalid timestamp - reset and continue
                    localStorage.setItem(LAST_UPDATE_KEY, now.toString());
                    return null;
                }
                
                const timeSinceUpdate = now - lastUpdate;
                if (timeSinceUpdate > maxAgeMs) {
                    return `No SW update in ${Math.floor(timeSinceUpdate / 86400000)} days (max: ${MAX_NO_UPDATE_DAYS})`;
                }
                
                return null;
            }
            
            /**
             * Non-blocking version check via API
             * Uses requestIdleCallback or setTimeout for minimal performance impact
             */
            function scheduleVersionCheck() {
                const doCheck = async () => {
                    try {
                        // Add cache-busting parameter
                        const response = await fetch('/api/v1/version.php?_=' + Date.now(), {
                            cache: 'no-store'
                        });
                        
                        if (!response.ok) {
                            console.warn('[Version] API returned status:', response.status);
                            return;
                        }
                        
                        const serverVersion = await response.json();
                        
                        // Check for emergency force_cleanup flag
                        if (serverVersion.force_cleanup === true) {
                            performFullCleanup('Server requested force_cleanup');
                            return;
                        }
                        
                        // Check if server version is significantly newer
                        // (Hash mismatch alone isn't enough - could be mid-deploy)
                        if (serverVersion.hash !== BUILD_HASH && serverVersion.timestamp) {
                            const serverBuildAge = Date.now() - (serverVersion.timestamp * 1000);
                            const clientBuildAge = Date.now() - (BUILD_TIMESTAMP * 1000);
                            
                            // If client is more than max_no_update_days older than server
                            const maxAgeDiff = (serverVersion.max_no_update_days || MAX_NO_UPDATE_DAYS) * 24 * 60 * 60 * 1000;
                            if (clientBuildAge - serverBuildAge > maxAgeDiff) {
                                performFullCleanup(`Client build is ${Math.floor((clientBuildAge - serverBuildAge) / 86400000)} days behind server`);
                                return;
                            }
                        }
                        
                    } catch (err) {
                        // Network errors are expected when offline - don't log as error
                        if (navigator.onLine !== false) {
                            console.warn('[Version] API check failed:', err.message);
                        }
                    }
                };
                
                // Schedule check during idle time to avoid blocking page load
                if ('requestIdleCallback' in window) {
                    requestIdleCallback(doCheck, { timeout: 10000 });
                } else {
                    // Fallback: run after initial page load settles
                    setTimeout(doCheck, 5000);
                }
            }
            
            /**
             * Update last SW update timestamp when controller changes
             * This indicates a successful service worker update
             */
            function trackSwUpdates() {
                if ('serviceWorker' in navigator) {
                    navigator.serviceWorker.addEventListener('controllerchange', () => {
                        localStorage.setItem(LAST_UPDATE_KEY, Date.now().toString());
                        console.log('[Version] SW controller changed, updated last update timestamp');
                    });
                }
            }
            
            // Initialize version checking
            function init() {
                // Skip if cleanup is in progress (we're about to reload)
                if (sessionStorage.getItem(CLEANUP_IN_PROGRESS_KEY)) {
                    // Clear the flag after reload completes
                    sessionStorage.removeItem(CLEANUP_IN_PROGRESS_KEY);
                    console.log('[Version] Post-cleanup reload complete');
                    // Initialize tracking fresh
                    localStorage.setItem(LAST_UPDATE_KEY, Date.now().toString());
                    return;
                }
                
                // Track SW updates
                trackSwUpdates();
                
                // Check dead man's switch immediately
                const deadManReason = checkDeadManSwitch();
                if (deadManReason) {
                    performFullCleanup(deadManReason);
                    return; // Don't continue, we're reloading
                }
                
                // Schedule non-blocking version API check
                scheduleVersionCheck();
            }
            
            // Run on page load
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
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
    <div id="notam-banner-container"></div>
    <main>
    <div class="container">
        <!-- Header -->
        <header class="header">
            <h1><?= htmlspecialchars($airport['name']) ?> (<?= htmlspecialchars($primaryIdentifier) ?>)</h1>
        </header>
        
        <?php
        // Check if we should show the airport navigation menu (only if multiple airports configured)
        $config = loadConfig();
        $enabledAirports = $config ? getEnabledAirports($config) : [];
        $showMenu = count($enabledAirports) > 1;
        
        if ($showMenu && isset($airport['lat']) && isset($airport['lon'])):
            // Calculate nearby airports (within 200 miles)
            $currentLat = (float)$airport['lat'];
            $currentLon = (float)$airport['lon'];
            $nearbyAirports = [];
            
            foreach ($enabledAirports as $otherAirportId => $otherAirport) {
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
            foreach ($enabledAirports as $searchAirportId => $searchAirport) {
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
                        <span>Nearby Airports</span>
                        <span class="dropdown-arrow">▼</span>
                    </button>
                </div>
                <!-- Unified dropdown for both search and nearby airports -->
                <div id="airport-dropdown" class="airport-dropdown">
                    <!-- Content populated by JavaScript -->
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
                webcamHistoryEnabled: <?= json_encode(isWebcamHistoryEnabledForAirport($airportId)) ?>
            };
        </script>
        <?php endif; ?>

        <?php if (isset($airport['webcams']) && !empty($airport['webcams']) && count($airport['webcams']) > 0): ?>
        <!-- Webcams -->
        <section class="webcam-section">
            <div class="webcam-grid">
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
                    $buildSrcset = function($format, $variants, $aspectRatio, $originalWidth, $originalHeight) use ($baseUrl) {
                        $srcsetParts = [];
                        $variantList = [];
                        
                        // Collect available variants for this format
                        foreach ($variants as $variant => $formats) {
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
                            $url = $baseUrl . '&fmt=' . $format . '&size=' . $variant;
                            if ($variant === 'original') {
                                // Use actual original width
                                $srcsetParts[] = $url . ' ' . $originalWidth . 'w';
                            } else {
                                // For height-based variants, calculate width
                                $variantWidth = (int)round($aspectRatio * (int)$variant);
                                // Cap at 3840px for ultra-wide cameras
                                if ($variantWidth > 3840) {
                                    $variantWidth = 3840;
                                }
                                $srcsetParts[] = $url . ' ' . $variantWidth . 'w';
                            }
                        }
                        return implode(', ', $srcsetParts);
                    };
                ?>
                <div class="webcam-item" 
                     data-aspect-ratio="<?= $aspectRatioStr ?>"
                     data-width="<?= $width ?>"
                     data-height="<?= $height ?>">
                    <div class="webcam-container">
                        <div id="webcam-skeleton-<?= $index ?>" class="webcam-skeleton" style="background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%); background-size: 200% 100%; animation: skeleton-loading 1.5s ease-in-out infinite; width: 100%; aspect-ratio: <?= $width ?>/<?= $height ?>; border-radius: 4px; position: absolute; top: 0; left: 0; z-index: 1;"></div>
                        <picture>
                            <?php if (in_array('avif', $enabledFormats) && !empty($availableVariants)): ?>
                            <source srcset="<?= $buildSrcset('avif', $availableVariants, $aspectRatio, $width, $height) ?>" type="image/avif" sizes="100vw">
                            <?php endif; ?>
                            <?php if (in_array('webp', $enabledFormats) && !empty($availableVariants)): ?>
                            <source srcset="<?= $buildSrcset('webp', $availableVariants, $aspectRatio, $width, $height) ?>" type="image/webp" sizes="100vw">
                            <?php endif; ?>
                            <img id="webcam-<?= $index ?>" 
                                 srcset="<?= !empty($availableVariants) ? $buildSrcset('jpg', $availableVariants, $aspectRatio, $width, $height) : ($baseUrl . '&fmt=jpg&size=original') ?>"
                                 src="<?= $baseUrl ?>&fmt=jpg&size=original"
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
                                 fetchpriority="high"
                                 decoding="async"
                                 onerror="handleWebcamError(<?= $index ?>, this)"
                                 onload="if(typeof observeWebcamFormat === 'function') { observeWebcamFormat(<?= $index ?>, this); } const skel=document.getElementById('webcam-skeleton-<?= $index ?>'); if(skel) skel.style.display='none'"
                                 onclick="openWebcamPlayer('<?= htmlspecialchars($airportId) ?>', <?= $index ?>, '<?= htmlspecialchars(addslashes($cam['name'])) ?>', this.currentSrc || this.src)"
                                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openWebcamPlayer('<?= htmlspecialchars($airportId) ?>', <?= $index ?>, '<?= htmlspecialchars(addslashes($cam['name'])) ?>', this.currentSrc || this.src)}">
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

        <?php 
        // Show weather section if weather_source is configured OR if metar_station is configured
        // This matches the JavaScript condition that determines whether to fetch weather
        $hasWeatherSource = isset($airport['weather_source']) && !empty($airport['weather_source']);
        $hasMetarStation = isset($airport['metar_station']) && !empty($airport['metar_station']);
        if ($hasWeatherSource || $hasMetarStation): ?>
        <!-- Weather Data -->
        <section class="weather-section">
            <div class="weather-header-container" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem;">
                <div class="weather-header-left" style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <h2 class="weather-header-title" style="margin: 0;">Current Conditions</h2>
                    <div class="weather-toggle-buttons" style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                        <button id="night-mode-toggle" style="background: #f5f5f5; border: 1px solid #ccc; border-radius: 6px; padding: 0.5rem 0.75rem; cursor: pointer; font-size: 1.1rem; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; color: #333; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.1); min-width: 40px; height: auto;" title="Toggle theme: Auto → Day → Dark → Night">
                            <span id="night-mode-icon">🌙</span>
                        </button>
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
                <p class="weather-last-updated-text" style="font-size: 0.85rem; color: #555; margin: 0;">Last updated: <span id="weather-timestamp-warning" class="weather-timestamp-warning" style="display: none;">⚠️ </span><span id="weather-last-updated">--</span></p>
            </div>
            <div id="weather-data" class="weather-grid">
                <div class="weather-item loading">
                    <span class="label">Loading...</span>
                </div>
            </div>
        </section>

        <!-- Build weather sources for attribution -->
        <?php
        // Credit any source that is actively providing data, regardless of hierarchy
        $weatherSources = [];
        $addedSourceNames = []; // Track by name to avoid duplicates
        
        // Load weather cache to check which sources have fresh data
        $weatherCacheFile = getWeatherCachePath($airportId);
        $weatherData = null;
        if (file_exists($weatherCacheFile)) {
            $weatherData = @json_decode(@file_get_contents($weatherCacheFile), true);
        }
        
        require_once __DIR__ . '/../lib/constants.php';
        
        // Use a generous threshold for attribution - we want to credit sources providing data
        $staleThreshold = getStaleWarningSeconds($airport);
        
        // Helper to add source if not already added
        $addSource = function($sourceType) use (&$weatherSources, &$addedSourceNames) {
            $sourceInfo = getWeatherSourceInfo($sourceType);
            if ($sourceInfo !== null && !in_array($sourceInfo['name'], $addedSourceNames)) {
                $weatherSources[] = $sourceInfo;
                $addedSourceNames[] = $sourceInfo['name'];
            }
        };
        
        // Check primary PWS source (non-METAR)
        $primaryType = isset($airport['weather_source']['type']) ? $airport['weather_source']['type'] : null;
        if ($primaryType !== null && $primaryType !== 'metar') {
            if (is_array($weatherData) && isset($weatherData['last_updated_primary']) && $weatherData['last_updated_primary'] > 0) {
                $primaryAge = time() - $weatherData['last_updated_primary'];
                if ($primaryAge < $staleThreshold) {
                    $addSource($primaryType);
                }
            }
        }
        
        // Check backup source
        $backupType = isset($airport['weather_source_backup']['type']) ? $airport['weather_source_backup']['type'] : null;
        if ($backupType !== null) {
            if (is_array($weatherData) && isset($weatherData['last_updated_backup']) && $weatherData['last_updated_backup'] > 0) {
                $backupAge = time() - $weatherData['last_updated_backup'];
                if ($backupAge < $staleThreshold) {
                    $addSource($backupType);
                }
            }
        }
        
        // Check METAR source - credit if we have fresh METAR data
        if (is_array($weatherData) && isset($weatherData['last_updated_metar']) && $weatherData['last_updated_metar'] > 0) {
            // METAR has its own staleness threshold (typically longer, ~2 hours for hourly updates)
            $metarStaleThreshold = 7200; // 2 hours - METAR updates hourly with specials
            $metarAge = time() - $weatherData['last_updated_metar'];
            if ($metarAge < $metarStaleThreshold) {
                $addSource('metar');
            }
        }
        ?>

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
            <?php if (!empty($weatherSources)): ?>
            <div class="data-sources-content" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e0e0e0;">
                <div class="data-sources-list" style="text-align: center; font-size: 0.85rem; color: #555;">
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
                <a href="<?= htmlspecialchars($airnavUrl) ?>" target="_blank" rel="noopener" class="btn" title="View airport information on AirNav (opens in new tab)">
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
                <a href="<?= htmlspecialchars($skyvectorUrl) ?>" target="_blank" rel="noopener" class="btn" title="View aeronautical charts on SkyVector (opens in new tab)">
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
                <a href="<?= htmlspecialchars($aopaUrl) ?>" target="_blank" rel="noopener" class="btn" title="View AOPA airport directory page (opens in new tab)">
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
                <a href="<?= htmlspecialchars($faaWeatherUrl) ?>" target="_blank" rel="noopener" class="btn" title="View FAA weather cameras for this area (opens in new tab)">
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
                <a href="<?= htmlspecialchars($foreflightUrl) ?>" target="_blank" rel="noopener" class="btn foreflight-link" title="Open this airport in ForeFlight app">
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
            </div>
        </section>
        <?php endif; ?>

        <!-- Embed This Dashboard Section -->
        <section class="embed-section">
            <div class="embed-container">
                <span class="embed-icon">🔗</span>
                <span class="embed-text">Want to add this dashboard to your website?</span>
                <a href="https://embed.aviationwx.org/?airport=<?= htmlspecialchars($airportId) ?>" class="embed-link" target="_blank" rel="noopener">Create Embed →</a>
            </div>
        </section>

        <!-- Footer -->
        <footer class="footer">
            <p class="footer-disclaimer">
                <em>Data is for advisory use only. Consult official weather sources for flight planning purposes.</em>
            </p>
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
            $isMetarOnly = isset($airport['weather_source']['type']) && $airport['weather_source']['type'] === 'metar';
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

// Default preferences for unit toggles (merged: global config → airport override)
const DEFAULT_PREFERENCES = <?php
    $defaultPrefs = getDefaultPreferencesForAirport($airportId);
    echo json_encode($defaultPrefs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
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

// Staleness thresholds (3-tier model from config)
// Thresholds cascade: airport config → global config → built-in defaults
const STALE_WARNING_SECONDS = <?= getStaleWarningSeconds($airport) ?>;
const STALE_ERROR_SECONDS = <?= getStaleErrorSeconds($airport) ?>;
const STALE_FAILCLOSED_SECONDS = <?= getStaleFailclosedSeconds($airport) ?>;

// METAR-specific thresholds (global only)
const METAR_STALE_WARNING_SECONDS = <?= getMetarStaleWarningSeconds() ?>;
const METAR_STALE_ERROR_SECONDS = <?= getMetarStaleErrorSeconds() ?>;
const METAR_STALE_FAILCLOSED_SECONDS = <?= getMetarStaleFailclosedSeconds() ?>;

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

// =============================================================================
// Timer Worker System
// Uses a Web Worker for reliable timer management that isn't throttled in
// background tabs. Falls back to setInterval if Workers aren't available.
// =============================================================================

// Timer worker configuration
const TIMER_IS_MOBILE = isMobileDevice();
const TIMER_TICK_MS = TIMER_IS_MOBILE ? 10000 : 1000; // 10s mobile, 1s desktop

// Timer callback registry - maps timer IDs to callback functions
const timerCallbacks = new Map();

// Create the timer worker (inline via Blob URL for performance)
let aviationwxTimerWorker = null;
let usingFallbackTimer = false;

try {
    if (typeof Worker !== 'undefined') {
        const workerCode = `
            const TICK_MS = ${TIMER_TICK_MS};
            const timers = new Map();
            let paused = false;
            
            // Master tick loop
            setInterval(() => {
                if (paused) return;
                const now = Date.now();
                for (const [id, t] of timers) {
                    if (now - t.lastFired >= t.interval) {
                        self.postMessage({ type: 'tick', id: id, timestamp: now });
                        t.lastFired = now;
                    }
                }
            }, TICK_MS);
            
            // Handle messages from main thread
            self.onmessage = function(e) {
                const { action, id, interval } = e.data;
                
                if (action === 'register') {
                    timers.set(id, { interval: interval, lastFired: Date.now() });
                    self.postMessage({ type: 'registered', id: id });
                } else if (action === 'unregister') {
                    timers.delete(id);
                } else if (action === 'pause') {
                    paused = true;
                    self.postMessage({ type: 'paused' });
                } else if (action === 'resume') {
                    paused = false;
                    // Reset all lastFired to prevent burst of ticks
                    const now = Date.now();
                    for (const t of timers.values()) {
                        t.lastFired = now;
                    }
                    self.postMessage({ type: 'resumed' });
                } else if (action === 'ping') {
                    self.postMessage({ type: 'pong' });
                }
            };
        `;
        
        const blob = new Blob([workerCode], { type: 'application/javascript' });
        const workerUrl = URL.createObjectURL(blob);
        aviationwxTimerWorker = new Worker(workerUrl);
        
        // Handle messages from worker
        aviationwxTimerWorker.onmessage = function(e) {
            if (e.data.type === 'tick') {
                // Record tick for health monitoring
                if (typeof window.recordWorkerTick === 'function') {
                    window.recordWorkerTick();
                }
                
                // Call the registered callback
                const callback = timerCallbacks.get(e.data.id);
                if (callback) {
                    callback();
                }
            }
        };
        
        aviationwxTimerWorker.onerror = function(e) {
            console.error('[TimerWorker] Error:', e.message);
            // Activate fallback when worker fails to load (CSP, etc.)
            if (!usingFallbackTimer) {
                usingFallbackTimer = true;
                aviationwxTimerWorker = null;
                window.aviationwxTimerWorker = null;
                // Re-register any existing callbacks with fallback system
                if (typeof window.createFallbackTimerSystem === 'function' && timerCallbacks.size > 0) {
                    const fallback = window.createFallbackTimerSystem();
                    window.aviationwxFallbackTimer = fallback;
                    for (const [id, callback] of timerCallbacks) {
                        // Default to 60s interval for re-registered timers
                        fallback.register(id, 60000, callback);
                    }
                }
            }
        };
        
        // Make worker available globally for timer-lifecycle.js
        window.aviationwxTimerWorker = aviationwxTimerWorker;
        
        console.log('[TimerWorker] Initialized with', TIMER_TICK_MS + 'ms tick (' + (TIMER_IS_MOBILE ? 'mobile' : 'desktop') + ')');
    } else {
        throw new Error('Workers not supported');
    }
} catch (e) {
    console.warn('[TimerWorker] Failed to create worker, using fallback:', e.message);
    usingFallbackTimer = true;
}

/**
 * Register a timer with the worker
 * @param {string} id Unique timer identifier
 * @param {number} intervalMs Interval in milliseconds
 * @param {Function} callback Function to call on each tick
 */
function registerTimer(id, intervalMs, callback) {
    timerCallbacks.set(id, callback);
    
    if (aviationwxTimerWorker && !usingFallbackTimer) {
        aviationwxTimerWorker.postMessage({ action: 'register', id: id, interval: intervalMs });
    } else if (typeof window.createFallbackTimerSystem === 'function') {
        // Use fallback system from timer-lifecycle.js
        const fallback = window.aviationwxFallbackTimer || window.createFallbackTimerSystem();
        window.aviationwxFallbackTimer = fallback;
        fallback.register(id, intervalMs, callback);
    } else {
        // Direct setInterval fallback
        const intervalId = setInterval(callback, intervalMs);
        window.fallbackIntervals = window.fallbackIntervals || new Map();
        window.fallbackIntervals.set(id, intervalId);
    }
}

/**
 * Force refresh all registered timers
 * Called when tab becomes visible to catch up
 */
window.forceRefreshAllTimers = function() {
    for (const [id, callback] of timerCallbacks) {
        try {
            callback();
        } catch (e) {
            console.error('[TimerWorker] Error in timer callback:', id, e);
        }
    }
};

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
            } catch {
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

// Time format preference (user cookie → airport config → global config → hardcoded default)
function getTimeFormat() {
    const format = getCookie('aviationwx_time_format') 
        || localStorage.getItem('aviationwx_time_format')
        || (typeof DEFAULT_PREFERENCES !== 'undefined' && DEFAULT_PREFERENCES.time_format)
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

// Temperature unit preference (user cookie → airport config → global config → hardcoded default)
function getTempUnit() {
    const unit = getCookie('aviationwx_temp_unit')
        || localStorage.getItem('aviationwx_temp_unit')
        || (typeof DEFAULT_PREFERENCES !== 'undefined' && DEFAULT_PREFERENCES.temp_unit)
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

// Distance/altitude unit preference (user cookie → airport config → global config → hardcoded default)
function getDistanceUnit() {
    const unit = getCookie('aviationwx_distance_unit')
        || localStorage.getItem('aviationwx_distance_unit')
        || (typeof DEFAULT_PREFERENCES !== 'undefined' && DEFAULT_PREFERENCES.distance_unit)
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

// Barometer unit preference (user cookie → airport config → global config → hardcoded default)
function getBaroUnit() {
    const unit = getCookie('aviationwx_baro_unit')
        || localStorage.getItem('aviationwx_baro_unit')
        || (typeof DEFAULT_PREFERENCES !== 'undefined' && DEFAULT_PREFERENCES.baro_unit)
        || 'inHg';
    return unit;
}

function setBaroUnit(unit) {
    // Set cookie (source of truth, cross-subdomain)
    setCookie('aviationwx_baro_unit', unit);
    // localStorage is updated by setCookie, but ensure it's set
    try {
        localStorage.setItem('aviationwx_baro_unit', unit);
    } catch (e) {
        // localStorage may be disabled - continue
    }
}

// Convert inHg to hPa (hectopascals/millibars)
function inHgToHPa(inHg) {
    return (inHg * 33.8639).toFixed(1);
}

// Convert inHg to mmHg (millimeters of mercury)
function inHgToMmHg(inHg) {
    return (inHg * 25.4).toFixed(1);
}

// Format pressure based on current unit preference
function formatPressure(inHg) {
    if (inHg === null || inHg === undefined) return '--';
    const unit = getBaroUnit();
    switch (unit) {
        case 'hPa':
            return inHgToHPa(inHg);
        case 'mmHg':
            return inHgToMmHg(inHg);
        default:
            return inHg.toFixed(2);
    }
}

// Get pressure unit label
function getPressureUnit() {
    return getBaroUnit();
}

// Convert statute miles to kilometers
function smToKm(sm) {
    return sm * 1.609344;
}

// Format visibility (statute miles) based on current unit preference
function formatVisibility(sm) {
    if (sm === null || sm === undefined) return '--';  // Failed state
    // Sentinel value 999.0 represents unlimited visibility
    if (sm === 999.0) return 'Unlimited';
    const unit = getDistanceUnit();
    if (unit === 'm') {
        return smToKm(sm).toFixed(1);
    } else {
        return sm.toFixed(1);
    }
}

// Format ceiling (feet) based on current unit preference
function formatCeiling(ft) {
    if (ft === null || ft === undefined) return null;  // Failed state (shows as '--' in template)
    // Sentinel value 99999 represents unlimited ceiling
    if (ft === 99999) return 'Unlimited';
    const unit = getDistanceUnit();
    return unit === 'm' ? ftToM(ft) : Math.round(ft);
}

// Wind speed unit preference (user cookie → airport config → global config → hardcoded default)
function getWindSpeedUnit() {
    const unit = getCookie('aviationwx_wind_speed_unit')
        || localStorage.getItem('aviationwx_wind_speed_unit')
        || (typeof DEFAULT_PREFERENCES !== 'undefined' && DEFAULT_PREFERENCES.wind_speed_unit)
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

// Barometer unit toggle handler
function initBaroUnitToggle() {
    const toggle = document.getElementById('baro-unit-toggle');
    const display = document.getElementById('baro-unit-display');
    
    if (!toggle || !display) return;
    
    function updateToggle() {
        const unit = getBaroUnit();
        display.textContent = unit;
        const nextUnit = unit === 'inHg' ? 'hPa' : (unit === 'hPa' ? 'mmHg' : 'inHg');
        toggle.title = `Switch to ${nextUnit}`;
    }
    
    toggle.addEventListener('click', () => {
        const currentUnit = getBaroUnit();
        // Cycle through: inHg -> hPa -> mmHg -> inHg
        let newUnit;
        switch (currentUnit) {
            case 'inHg':
                newUnit = 'hPa';
                break;
            case 'hPa':
                newUnit = 'mmHg';
                break;
            default:
                newUnit = 'inHg';
        }
        setBaroUnit(newUnit);
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

// Initialize barometer unit toggle
if (document.getElementById('baro-unit-toggle')) {
    initBaroUnitToggle();
} else {
    function initBaroToggle() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initBaroUnitToggle);
        } else {
            initBaroUnitToggle();
        }
    }
    initBaroToggle();
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

// Theme mode toggle handler
// Four modes: auto (default, follows browser), day, dark (classic dark theme), night (red night vision)
// Night vision red mode protects pilot night vision (scotopic vision)
// On mobile: auto-activates night mode after sunset until sunrise (unless manually overridden)
// On desktop: manual toggle only
// Manual toggle disables auto mode until the next day

// Night mode data from server (sunrise/sunset times in airport's timezone)
var NIGHT_MODE_DATA = <?= json_encode($nightModeData) ?>;

// Theme modes: 'auto' (default, follows browser), 'day', 'dark' (night is time-based, not stored)
function getThemePreference() {
    // Try cookie first (source of truth), then localStorage (cache)
    var pref = getCookie('aviationwx_theme') || localStorage.getItem('aviationwx_theme');
    // Valid stored preferences: auto/day/dark
    // Night mode is never stored - it's determined by airport time on mobile
    if (pref === 'auto' || pref === 'day' || pref === 'dark') {
        return pref;
    }
    return 'auto'; // Default to auto (follows browser preference)
}

function setThemePreference(value) {
    // Set cookie (source of truth, cross-subdomain)
    setCookie('aviationwx_theme', value);
    // localStorage is updated by setCookie, but ensure it's set
    try {
        localStorage.setItem('aviationwx_theme', value);
    } catch (e) {
        // localStorage may be disabled - continue
    }
}

function setThemeManualOverride() {
    // Set manual override cookie to today's date (in airport's timezone)
    // This disables auto mode until the next day
    var today = NIGHT_MODE_DATA && NIGHT_MODE_DATA.todayDate ? NIGHT_MODE_DATA.todayDate : new Date().toISOString().split('T')[0];
    setCookie('aviationwx_theme_override', today);
    try {
        localStorage.setItem('aviationwx_theme_override', today);
    } catch (e) {
        // localStorage may be disabled - continue
    }
}

function getCurrentTheme() {
    if (document.body.classList.contains('night-mode')) return 'night';
    if (document.body.classList.contains('dark-mode')) return 'dark';
    return 'day';
}

function isNightModeActive() {
    return document.documentElement.classList.contains('night-mode') || 
           document.body.classList.contains('night-mode');
}

function isDarkModeActive() {
    return document.documentElement.classList.contains('dark-mode') || 
           document.body.classList.contains('dark-mode');
}

function applyTheme(theme) {
    // Remove all theme classes
    document.documentElement.classList.remove('night-mode', 'dark-mode');
    document.body.classList.remove('night-mode', 'dark-mode');
    
    // Apply the selected theme
    if (theme === 'night') {
        document.documentElement.classList.add('night-mode');
        document.body.classList.add('night-mode');
    } else if (theme === 'dark') {
        document.documentElement.classList.add('dark-mode');
        document.body.classList.add('dark-mode');
    }
    // 'day' means no class added (default light theme)
}

// Legacy compatibility
function applyNightMode(active) {
    applyTheme(active ? 'night' : 'day');
}

function isMobileDevice() {
    return window.innerWidth <= 768 || ('ontouchstart' in window);
}

function isNightTimeAtAirport() {
    if (!NIGHT_MODE_DATA || !NIGHT_MODE_DATA.timezone) return false;
    
    // Get current time in airport's timezone
    try {
        var now = new Date();
        var formatter = new Intl.DateTimeFormat('en-US', {
            timeZone: NIGHT_MODE_DATA.timezone,
            hour: 'numeric',
            minute: 'numeric',
            hour12: false
        });
        var parts = formatter.formatToParts(now);
        var hour = 0, minute = 0;
        parts.forEach(function(p) {
            if (p.type === 'hour') hour = parseInt(p.value, 10);
            if (p.type === 'minute') minute = parseInt(p.value, 10);
        });
        
        var currentMins = hour * 60 + minute;
        var sunriseMins = NIGHT_MODE_DATA.sunriseHour * 60 + NIGHT_MODE_DATA.sunriseMin;
        var sunsetMins = NIGHT_MODE_DATA.sunsetHour * 60 + NIGHT_MODE_DATA.sunsetMin;
        
        // Night = after sunset OR before sunrise
        return currentMins >= sunsetMins || currentMins < sunriseMins;
    } catch (e) {
        return false;
    }
}

function getTodayInAirportTimezone() {
    if (!NIGHT_MODE_DATA || !NIGHT_MODE_DATA.timezone) {
        return new Date().toISOString().split('T')[0];
    }
    try {
        var now = new Date();
        var formatter = new Intl.DateTimeFormat('en-CA', {
            timeZone: NIGHT_MODE_DATA.timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        });
        return formatter.format(now); // Returns YYYY-MM-DD format
    } catch (e) {
        return new Date().toISOString().split('T')[0];
    }
}

function hasManualOverrideToday() {
    // Check both old and new cookie names for backwards compatibility
    var override = getCookie('aviationwx_theme_override') 
        || localStorage.getItem('aviationwx_theme_override')
        || getCookie('aviationwx_night_override') 
        || localStorage.getItem('aviationwx_night_override');
    if (!override) return false;
    var today = getTodayInAirportTimezone();
    return override === today;
}

function initThemeToggle() {
    var toggle = document.getElementById('night-mode-toggle');
    var icon = document.getElementById('night-mode-icon');
    
    if (!toggle) return;
    
    // Track current preference mode (auto/day/dark/night-visual)
    // This is separate from the visual theme applied to the page
    var currentPreference = getThemePreference(); // Returns 'auto', 'day', or 'dark'
    
    // Check if we're in mobile night mode (visual night, not a preference)
    var visualTheme = getCurrentTheme();
    var isInMobileNightMode = (visualTheme === 'night');
    
    function updateToggle() {
        var visualTheme = getCurrentTheme();
        
        // If in night mode (mobile auto-night), show night icon
        if (visualTheme === 'night') {
            icon.textContent = '🌙';
            toggle.title = 'Night vision mode (auto) - click to switch to auto mode';
        } else if (currentPreference === 'auto') {
            // Auto mode - show auto icon regardless of visual theme
            icon.textContent = '🔄';
            toggle.title = 'Auto mode (follows browser preference) - click to switch to day mode';
        } else if (currentPreference === 'dark') {
            icon.textContent = '🌑';
            toggle.title = 'Dark mode - click to switch to night vision mode';
        } else {
            // day mode
            icon.textContent = '☀️';
            toggle.title = 'Day mode - click to switch to dark mode';
        }
    }
    
    toggle.addEventListener('click', function() {
        var visualTheme = getCurrentTheme();
        var newPreference;
        var newVisualTheme;
        
        // Cycle: auto -> day -> dark -> night -> auto
        // Note: 'night' in this context means the user explicitly chose night vision mode
        if (visualTheme === 'night') {
            // From night (visual) -> auto
            newPreference = 'auto';
            newVisualTheme = browserPrefersDark() ? 'dark' : 'day';
        } else if (currentPreference === 'auto') {
            // From auto -> day
            newPreference = 'day';
            newVisualTheme = 'day';
        } else if (currentPreference === 'day') {
            // From day -> dark
            newPreference = 'dark';
            newVisualTheme = 'dark';
        } else if (currentPreference === 'dark') {
            // From dark -> night
            newPreference = 'night'; // This is a visual choice, not stored
            newVisualTheme = 'night';
        } else {
            // Fallback: go to auto
            newPreference = 'auto';
            newVisualTheme = browserPrefersDark() ? 'dark' : 'day';
        }
        
        // Apply the visual theme
        applyTheme(newVisualTheme);
        
        // Update tracked preference
        currentPreference = newPreference;
        
        // Save preference to cookie (auto/day/dark - night is not stored)
        if (newPreference === 'auto' || newPreference === 'day' || newPreference === 'dark') {
            setThemePreference(newPreference);
        }
        
        // Set manual override for today (disables mobile auto-night until tomorrow)
        setThemeManualOverride();
        
        // Update button display
        updateToggle();
        
        // Update wind canvas colors if needed
        if (typeof updateWindVisual === 'function' && currentWeatherData) {
            updateWindVisual(currentWeatherData);
        }
    });
    
    // Sync with initial state (might have been set by inline script in head)
    // This must happen BEFORE updateToggle() so it reads the correct theme
    if (document.documentElement.classList.contains('night-mode')) {
        document.body.classList.add('night-mode');
    } else if (document.documentElement.classList.contains('dark-mode')) {
        document.body.classList.add('dark-mode');
    }
    
    // Initial update (after sync)
    updateToggle();
    
    // Listen for browser preference changes when in auto mode
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
            // Only react if we're in auto mode and not in mobile night mode
            if (currentPreference === 'auto' && getCurrentTheme() !== 'night') {
                applyTheme(e.matches ? 'dark' : 'day');
                // Update wind canvas if needed
                if (typeof updateWindVisual === 'function' && currentWeatherData) {
                    updateWindVisual(currentWeatherData);
                }
            }
        });
    }
}

// Legacy alias
function initNightModeToggle() {
    initThemeToggle();
}

// Check browser/OS dark mode preference
function browserPrefersDark() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

// Auto-detect night mode on mobile (runs periodically)
function checkNightModeAuto() {
    // Only auto-switch on mobile
    if (!isMobileDevice()) return;
    
    // Don't auto-switch if user manually toggled today
    if (hasManualOverrideToday()) return;
    
    var shouldBeNight = isNightTimeAtAirport();
    var currentTheme = getCurrentTheme();
    var isCurrentlyNight = (currentTheme === 'night');
    
    if (shouldBeNight !== isCurrentlyNight) {
        // Determine target theme
        var targetTheme;
        if (shouldBeNight) {
            targetTheme = 'night';
        } else {
            // Transitioning from night to daytime at sunrise
            // Use saved preference: day/dark are explicit, auto follows browser
            var savedPref = getThemePreference();
            if (savedPref === 'day' || savedPref === 'dark') {
                targetTheme = savedPref;
            } else {
                // Auto mode (or legacy null): follow browser preference
                targetTheme = browserPrefersDark() ? 'dark' : 'day';
            }
        }
        
        applyTheme(targetTheme);
        
        // Update toggle display
        updateThemeToggleDisplay();
        
        // Update wind canvas if needed
        if (typeof updateWindVisual === 'function' && currentWeatherData) {
            updateWindVisual(currentWeatherData);
        }
    }
}

// Initialize night mode toggle
if (document.getElementById('night-mode-toggle')) {
    initNightModeToggle();
} else {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNightModeToggle);
    } else {
        initNightModeToggle();
    }
}

// Check for night mode auto-switch every minute (on mobile)
setInterval(checkNightModeAuto, 60000);

// Also check immediately after page load (in case of timezone edge cases)
setTimeout(checkNightModeAuto, 1000);

// Listen for browser/OS theme preference changes
// This allows real-time updates when user changes system dark mode
// But only if user hasn't set a manual preference
if (window.matchMedia) {
    var darkModeQuery = window.matchMedia('(prefers-color-scheme: dark)');
    
    function handleBrowserThemeChange(e) {
        // Don't switch if user manually toggled today
        // This respects their choice for the current session
        if (hasManualOverrideToday()) return;
        
        // Don't switch if mobile and it's nighttime (night mode takes priority)
        if (isMobileDevice() && isNightTimeAtAirport()) return;
        
        var currentTheme = getCurrentTheme();
        var browserWantsDark = e.matches;
        var targetTheme = browserWantsDark ? 'dark' : 'day';
        
        // Only switch between day/dark based on browser preference
        // Night mode is never triggered by browser preference (only by time)
        if (currentTheme !== 'night' && currentTheme !== targetTheme) {
            applyTheme(targetTheme);
            updateThemeToggleDisplay();
            
            // Update wind canvas colors
            if (typeof updateWindVisual === 'function' && currentWeatherData) {
                updateWindVisual(currentWeatherData);
            }
        }
    }
    
    // Modern browsers
    if (darkModeQuery.addEventListener) {
        darkModeQuery.addEventListener('change', handleBrowserThemeChange);
    } else if (darkModeQuery.addListener) {
        // Older browsers (Safari < 14)
        darkModeQuery.addListener(handleBrowserThemeChange);
    }
}

// Helper to update toggle display from outside initThemeToggle
function updateThemeToggleDisplay() {
    var toggle = document.getElementById('night-mode-toggle');
    var icon = document.getElementById('night-mode-icon');
    if (!toggle || !icon) return;
    
    var theme = getCurrentTheme();
    if (theme === 'night') {
        icon.textContent = '🌙';
        toggle.title = 'Night vision mode - click to switch to day mode';
    } else if (theme === 'dark') {
        icon.textContent = '🌑';
        toggle.title = 'Dark mode - click to switch to night vision mode';
    } else {
        icon.textContent = '☀️';
        toggle.title = 'Day mode - click to switch to dark mode';
    }
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
    try {
        const weatherEl = document.getElementById('weather-last-updated');
        const windEl = document.getElementById('wind-last-updated');
        
        if (!weatherEl || !windEl) {
            console.warn('[Weather] Timestamp elements not found');
            return;
        }
        
        if (weatherLastUpdated === null) {
            weatherEl.textContent = '--';
            windEl.textContent = '--';
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
    // Use 3-tier staleness model (warning, error, failclosed)
    // METAR has its own thresholds since it's only published hourly
    let warningThreshold, errorThreshold;
    if (isMetarOnly) {
        warningThreshold = METAR_STALE_WARNING_SECONDS;
        errorThreshold = METAR_STALE_ERROR_SECONDS;
    } else {
        warningThreshold = STALE_WARNING_SECONDS;
        errorThreshold = STALE_ERROR_SECONDS;
    }
    
    const isStale = diffSeconds >= warningThreshold;
    const isVeryStale = diffSeconds >= errorThreshold;
    
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
    } catch (error) {
        console.error('[Weather] Error updating weather timestamp:', error);
        // Silently fail - don't break weather display
    }
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
    try {
        const banner = document.getElementById('data-outage-banner');
        if (!banner) {
            return; // Banner doesn't exist (not in outage state)
        }
    
    // Outage banner shows when data reaches failclosed tier (too old to display)
    const outageThresholdSeconds = STALE_FAILCLOSED_SECONDS;
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
    } catch (error) {
        console.error('[Weather] Error in checkAndUpdateOutageBanner:', error);
        // Silently fail - don't break weather display
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
            // Only log in development/debug mode to reduce production log noise
            if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
                console.log('[Weather] Forcing refresh - bypassing cache due to stale data');
            }
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
            // Validate weather data structure
            if (!data.weather || typeof data.weather !== 'object') {
                console.error('[Weather] Invalid weather data structure:', data);
                displayEmptyWeather();
                return;
            }
            
            const isStale = data.stale === true || false;
            const serverTimestamp = data.weather.last_updated ? new Date(data.weather.last_updated * 1000) : null;
            
            // Solution C: Detect if server data is older than client data (indicates stale cache was served)
            // But don't force immediate refresh if we already have a stale refresh scheduled
            // This prevents rapid-fire requests that interrupt server's background refresh
            const serverDataIsStale = serverTimestamp && weatherLastUpdated && 
                serverTimestamp.getTime() < weatherLastUpdated.getTime();
            
            if (serverDataIsStale) {
                const hasStaleRefreshScheduled = window.staleRefreshTimer !== null && window.staleRefreshTimer !== undefined;
                
                if (!hasStaleRefreshScheduled) {
                    // Only force immediate refresh if no stale refresh is already scheduled
                    // Give server time to complete background refresh (wait 5 seconds)
                    console.warn('[Weather] Server data is older than client data - stale cache detected, scheduling refresh in 5 seconds to allow server background refresh');
                    window.staleRefreshTimer = setTimeout(() => {
                        fetchWeather(true);
                        window.staleRefreshTimer = null;
                    }, 5000); // Wait 5 seconds for server background refresh to complete
                    return; // Don't update UI with stale data
                } else {
                    // Stale refresh already scheduled, just log and return
                    console.warn('[Weather] Server data is older than client data, but stale refresh already scheduled - waiting for scheduled refresh');
                    return; // Don't update UI with stale data
                }
            }
            
            currentWeatherData = data.weather; // Store globally for toggle re-rendering
            displayWeather(data.weather);
            updateWindVisual(data.weather);
            weatherLastUpdated = serverTimestamp || new Date();
            updateWeatherTimestamp(); // Update the timestamp
            checkAndUpdateOutageBanner(); // Check if outage banner should be shown/hidden
            
            // Log successful weather update
            console.log('[Weather] Updated - ' + (serverTimestamp ? serverTimestamp.toLocaleTimeString() : 'now') + (isStale ? ' (stale)' : ' (fresh)'));
            
            // If server indicates data is stale, schedule a fresh fetch soon (30 seconds)
            // This gives the server's background refresh time to complete
            if (isStale) {
                console.log('[Weather] Stale data - scheduling refresh in 30 seconds');
                // Clear any existing stale refresh timer
                if (window.staleRefreshTimer) {
                    clearTimeout(window.staleRefreshTimer);
                }
                // Schedule a refresh with cache bypass
                // Use half of weather refresh interval, minimum 30 seconds
                // This delay allows server's background refresh to complete
                const weatherRefreshMs = (AIRPORT_DATA && AIRPORT_DATA.weather_refresh_seconds) 
                    ? AIRPORT_DATA.weather_refresh_seconds * 1000 
                    : 60000; // Default 60 seconds
                const staleRefreshDelay = Math.max(30000, weatherRefreshMs / 2);
                window.staleRefreshTimer = setTimeout(() => {
                    fetchWeather(true); // Force refresh
                    window.staleRefreshTimer = null;
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
            displayEmptyWeather();
        }
    } catch (error) {
        console.error('[Weather] Fetch error:', error);
        console.error('[Weather] Error stack:', error.stack);
        displayEmptyWeather();
    } finally {
        isFetchingWeather = false;
    }
}

/**
 * Check if a weather field is stale and should be hidden
 * Uses per-field observation time from _field_obs_time_map
 * Fail-closed: if no obs_time entry, consider stale
 * 
 * @param {string} fieldName - Field name to check
 * @param {object} weatherData - Weather data object with _field_obs_time_map
 * @param {number} refreshIntervalSeconds - Refresh interval for multiplier calculation
 * @param {boolean} isMetarField - True if METAR field (uses hour-based threshold)
 * @returns {boolean} True if field should be hidden
 */
function isFieldStale(fieldName, weatherData, refreshIntervalSeconds, isMetarField) {
    const fieldObsTimeMap = weatherData._field_obs_time_map || {};
    const obsTime = fieldObsTimeMap[fieldName];
    
    // Fail-closed: no obs_time entry = stale
    if (!obsTime || obsTime <= 0) {
        return true;
    }
    
    const now = Math.floor(Date.now() / 1000);
    const age = now - obsTime;
    
    // Use failclosed threshold to determine if field should be hidden
    // METAR fields use METAR-specific thresholds
    const staleThreshold = isMetarField ? METAR_STALE_FAILCLOSED_SECONDS : STALE_FAILCLOSED_SECONDS;
    return age >= staleThreshold;
}

/**
 * Check if calculated field should be shown based on source field validity
 */
function shouldShowGustFactor(weather) {
    return weather.wind_speed !== null && weather.gust_speed !== null;
}

function shouldShowDewpointSpread(weather) {
    return weather.temperature !== null && weather.dewpoint !== null;
}

function shouldShowPressureAltitude(weather) {
    return weather.pressure !== null;
}

function shouldShowDensityAltitude(weather) {
    return weather.temperature !== null && weather.pressure !== null;
}

/**
 * Create sanitized weather data with stale fields nulled
 * This provides client-side validation for offline scenarios
 * 
 * @param {object} weather - Weather data from API
 * @param {number} refreshIntervalSeconds - Refresh interval for staleness calculation
 * @returns {object} Sanitized weather data with stale fields set to null
 */
function sanitizeWeatherDataForDisplay(weather, refreshIntervalSeconds) {
    const sanitized = { ...weather };
    
    // Determine if this is a METAR-only source
    const isMetarOnly = AIRPORT_DATA && AIRPORT_DATA.weather_source && AIRPORT_DATA.weather_source.type === 'metar';
    
    // METAR fields (use hour-based threshold)
    const metarFields = ['visibility', 'ceiling', 'cloud_cover'];
    metarFields.forEach(field => {
        if (isFieldStale(field, weather, refreshIntervalSeconds, true)) {
            sanitized[field] = null;
        }
    });
    
    // Non-METAR fields (use multiplier-based threshold)
    // BUT: For METAR-only sources, ALL fields come from METAR, so use METAR threshold
    const nonMetarFields = [
        'temperature', 'dewpoint', 'humidity', 'wind_speed', 
        'wind_direction', 'gust_speed', 'pressure', 'precip_accum'
    ];
    nonMetarFields.forEach(field => {
        // For METAR-only sources, treat all fields as METAR fields (use METAR threshold)
        if (isFieldStale(field, weather, refreshIntervalSeconds, isMetarOnly)) {
            sanitized[field] = null;
        }
    });
    
    // Calculated fields: null if source fields are invalid
    sanitized.gust_factor = shouldShowGustFactor(sanitized) ? sanitized.gust_factor : null;
    sanitized.dewpoint_spread = shouldShowDewpointSpread(sanitized) ? sanitized.dewpoint_spread : null;
    sanitized.pressure_altitude = shouldShowPressureAltitude(sanitized) ? sanitized.pressure_altitude : null;
    sanitized.density_altitude = shouldShowDensityAltitude(sanitized) ? sanitized.density_altitude : null;
    
    return sanitized;
}

function displayWeather(weather) {
    // Sanitize weather data - null out stale fields for fail-closed behavior
    const refreshIntervalSeconds = (AIRPORT_DATA && AIRPORT_DATA.weather_refresh_seconds) 
        ? AIRPORT_DATA.weather_refresh_seconds 
        : 60;
    const sanitizedWeather = sanitizeWeatherDataForDisplay(weather, refreshIntervalSeconds);
    
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
    
    const weatherEmojis = getWeatherEmojis(sanitizedWeather);
    
    const container = document.getElementById('weather-data');
    if (!container) {
        console.error('[Weather] Container element not found: weather-data');
        return;
    }
    
    container.innerHTML = `
        <!-- Aviation Conditions (METAR-required data) -->
        ${(AIRPORT_DATA && AIRPORT_DATA.metar_station) ? `<div class="weather-group">
            ${(() => {
                // Check if METAR data is actually available (has METAR timestamp)
                const hasMetarData = (sanitizedWeather.obs_time_metar && sanitizedWeather.obs_time_metar > 0) || (sanitizedWeather.last_updated_metar && sanitizedWeather.last_updated_metar > 0);
                if (!hasMetarData) {
                    // METAR is unavailable - show all fields as '--'
                    return `
                    <div class="weather-item"><span class="label">Condition</span><span class="weather-value">--</span></div>
                    <div class="weather-item"><span class="label">Visibility</span><span class="weather-value">--</span></div>
                    <div class="weather-item"><span class="label">Ceiling</span><span class="weather-value">--</span></div>
                    `;
                }
                // METAR data is available - show values (using sanitized data)
                return `
                <div class="weather-item"><span class="label">Condition</span><span class="weather-value ${sanitizedWeather.flight_category_class || ''}">${sanitizedWeather.flight_category || '--'} ${sanitizedWeather.flight_category ? weatherEmojis : ''}</span></div>
                <div class="weather-item"><span class="label">Visibility</span><span class="weather-value">${formatVisibility(sanitizedWeather.visibility)}</span><span class="weather-unit">${sanitizedWeather.visibility !== null && sanitizedWeather.visibility !== undefined ? (getDistanceUnit() === 'm' ? 'km' : 'SM') : ''}</span>${sanitizedWeather.visibility !== null && sanitizedWeather.visibility !== undefined ? formatTempTimestamp(sanitizedWeather.obs_time_metar || sanitizedWeather.last_updated_metar) : ''}</div>
                <div class="weather-item"><span class="label">Ceiling</span><span class="weather-value">${sanitizedWeather.ceiling !== null && sanitizedWeather.ceiling !== undefined ? formatCeiling(sanitizedWeather.ceiling) : (sanitizedWeather.visibility !== null && sanitizedWeather.visibility !== undefined ? 'Unlimited' : '--')}</span><span class="weather-unit">${sanitizedWeather.ceiling !== null && sanitizedWeather.ceiling !== undefined ? (getDistanceUnit() === 'm' ? 'm AGL' : 'ft AGL') : ''}</span>${(sanitizedWeather.ceiling !== null && sanitizedWeather.ceiling !== undefined || (sanitizedWeather.visibility !== null && sanitizedWeather.visibility !== undefined)) ? formatTempTimestamp(sanitizedWeather.obs_time_metar || sanitizedWeather.last_updated_metar) : ''}</div>
                `;
            })()}
        </div>
        ` : ''}
        
        <!-- Temperature -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Today's High</span><span class="weather-value">${formatTemp(sanitizedWeather.temp_high_today)}</span><span class="weather-unit">${getTempUnit() === 'C' ? '°C' : '°F'}</span>${formatTempTimestamp(sanitizedWeather.temp_high_ts)}</div>
            <div class="weather-item"><span class="label">Current Temperature</span><span class="weather-value">${formatTemp(sanitizedWeather.temperature)}</span><span class="weather-unit">${getTempUnit() === 'C' ? '°C' : '°F'}</span></div>
            <div class="weather-item"><span class="label">Today's Low</span><span class="weather-value">${formatTemp(sanitizedWeather.temp_low_today)}</span><span class="weather-unit">${getTempUnit() === 'C' ? '°C' : '°F'}</span>${formatTempTimestamp(sanitizedWeather.temp_low_ts)}</div>
        </div>
        
        <!-- Moisture & Precipitation -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Dewpoint Spread</span><span class="weather-value">${formatTempSpread(sanitizedWeather.dewpoint_spread)}</span><span class="weather-unit">${getTempUnit() === 'C' ? '°C' : '°F'}</span></div>
            <div class="weather-item"><span class="label">Dewpoint</span><span class="weather-value">${formatTemp(sanitizedWeather.dewpoint)}</span><span class="weather-unit">${getTempUnit() === 'C' ? '°C' : '°F'}</span></div>
            <div class="weather-item"><span class="label">Humidity</span><span class="weather-value">${sanitizedWeather.humidity !== null && sanitizedWeather.humidity !== undefined ? Math.round(sanitizedWeather.humidity) : '--'}</span><span class="weather-unit">${sanitizedWeather.humidity !== null && sanitizedWeather.humidity !== undefined ? '%' : ''}</span></div>
        </div>
        
        <!-- Precipitation & Daylight -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Rainfall Today</span><span class="weather-value">${formatRainfall(sanitizedWeather.precip_accum)}</span><span class="weather-unit">${getDistanceUnit() === 'm' ? 'cm' : 'in'}</span></div>
            <div class="weather-item sunrise-sunset">
                <span style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-size: 1.2rem;">🌅</span>
                    <span class="label">Sunrise</span>
                </span>
                <span class="weather-value">${formatTime(sanitizedWeather.sunrise || '--')} <span style="font-size: 0.75rem; color: #555;">${getTimezoneAbbreviation()}</span></span>
            </div>
            <div class="weather-item sunrise-sunset">
                <span style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-size: 1.2rem;">🌇</span>
                    <span class="label">Sunset</span>
                </span>
                <span class="weather-value">${formatTime(sanitizedWeather.sunset || '--')} <span style="font-size: 0.75rem; color: #555;">${getTimezoneAbbreviation()}</span></span>
            </div>
        </div>
        
        <!-- Pressure & Altitude -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Pressure</span><span class="weather-value">${formatPressure(sanitizedWeather.pressure)}</span><span class="weather-unit">${getPressureUnit()}</span></div>
            <div class="weather-item"><span class="label">Pressure Altitude</span><span class="weather-value">${formatAltitude(sanitizedWeather.pressure_altitude)}</span><span class="weather-unit">${getDistanceUnit() === 'm' ? 'm' : 'ft'}</span></div>
            <div class="weather-item"><span class="label">Density Altitude</span><span class="weather-value">${formatAltitude(sanitizedWeather.density_altitude)}</span><span class="weather-unit">${getDistanceUnit() === 'm' ? 'm' : 'ft'}</span></div>
        </div>
    `;
}

/**
 * Display empty weather fields when data is unavailable or invalid
 * Shows all fields as "--" instead of error messages for better UX
 */
function displayEmptyWeather() {
    const container = document.getElementById('weather-data');
    if (!container) return;
    
    // Display all weather fields as empty ("--") to match the structure of displayWeather()
    container.innerHTML = `
        <!-- Aviation Conditions (METAR-required data) -->
        ${(AIRPORT_DATA && AIRPORT_DATA.metar_station) ? `<div class="weather-group">
            <div class="weather-item"><span class="label">Condition</span><span class="weather-value">--</span></div>
            <div class="weather-item"><span class="label">Visibility</span><span class="weather-value">--</span></div>
            <div class="weather-item"><span class="label">Ceiling</span><span class="weather-value">--</span></div>
        </div>
        ` : ''}
        
        <!-- Temperature -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Today's High</span><span class="weather-value">--</span><span class="weather-unit">${getTempUnit() === 'C' ? '°C' : '°F'}</span></div>
            <div class="weather-item"><span class="label">Current Temperature</span><span class="weather-value">--</span><span class="weather-unit">${getTempUnit() === 'C' ? '°C' : '°F'}</span></div>
            <div class="weather-item"><span class="label">Today's Low</span><span class="weather-value">--</span><span class="weather-unit">${getTempUnit() === 'C' ? '°C' : '°F'}</span></div>
        </div>
        
        <!-- Moisture & Precipitation -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Dewpoint Spread</span><span class="weather-value">--</span><span class="weather-unit">${getTempUnit() === 'C' ? '°C' : '°F'}</span></div>
            <div class="weather-item"><span class="label">Dewpoint</span><span class="weather-value">--</span><span class="weather-unit">${getTempUnit() === 'C' ? '°C' : '°F'}</span></div>
            <div class="weather-item"><span class="label">Humidity</span><span class="weather-value">--</span><span class="weather-unit"></span></div>
        </div>
        
        <!-- Precipitation & Daylight -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Rainfall Today</span><span class="weather-value">--</span><span class="weather-unit">${getDistanceUnit() === 'm' ? 'cm' : 'in'}</span></div>
            <div class="weather-item sunrise-sunset">
                <span style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-size: 1.2rem;">🌅</span>
                    <span class="label">Sunrise</span>
                </span>
                <span class="weather-value">-- <span style="font-size: 0.75rem; color: #555;">${getTimezoneAbbreviation()}</span></span>
            </div>
            <div class="weather-item sunrise-sunset">
                <span style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-size: 1.2rem;">🌇</span>
                    <span class="label">Sunset</span>
                </span>
                <span class="weather-value">-- <span style="font-size: 0.75rem; color: #555;">${getTimezoneAbbreviation()}</span></span>
            </div>
        </div>
        
        <!-- Pressure & Altitude -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Pressure</span><span class="weather-value">--</span><span class="weather-unit">${getPressureUnit()}</span></div>
            <div class="weather-item"><span class="label">Pressure Altitude</span><span class="weather-value">--</span><span class="weather-unit">${getDistanceUnit() === 'm' ? 'm' : 'ft'}</span></div>
            <div class="weather-item"><span class="label">Density Altitude</span><span class="weather-value">--</span><span class="weather-unit">${getDistanceUnit() === 'm' ? 'm' : 'ft'}</span></div>
        </div>
    `;
}

function displayError(msg) {
    // Legacy function - now redirects to displayEmptyWeather for better UX
    // Error messages are logged to console but not shown to users
    console.error('[Weather] Error (showing empty fields):', msg);
    displayEmptyWeather();
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
    if (!canvas) {
        console.warn('[Weather] Wind canvas element not found: windCanvas');
        return;
    }
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
    
    // Check if wind data is stale before displaying wind indicators
    const refreshIntervalSeconds = (AIRPORT_DATA && AIRPORT_DATA.weather_refresh_seconds) 
        ? AIRPORT_DATA.weather_refresh_seconds 
        : 60;
    const isMetarOnly = AIRPORT_DATA && AIRPORT_DATA.weather_source && AIRPORT_DATA.weather_source.type === 'metar';
    const windStale = isFieldStale('wind_speed', weather, refreshIntervalSeconds, isMetarOnly) ||
                      isFieldStale('wind_direction', weather, refreshIntervalSeconds, isMetarOnly);
    
    // Check staleness for individual wind fields for details panel
    const windSpeedStale = isFieldStale('wind_speed', weather, refreshIntervalSeconds, isMetarOnly);
    const windDirectionStale = isFieldStale('wind_direction', weather, refreshIntervalSeconds, isMetarOnly);
    const gustSpeedStale = isFieldStale('gust_speed', weather, refreshIntervalSeconds, isMetarOnly);
    
    // Use sanitized values for details panel (null out stale fields)
    const ws = windSpeedStale ? null : (weather.wind_speed ?? null);
    const wd = windDirectionStale ? null : (weather.wind_direction ?? null);
    const isVariableWind = wd === 'VRB' || wd === 'vrb';
    // Allow 0° (north wind) - check for number type and valid range (0-360)
    const windDirNumeric = typeof wd === 'number' && wd >= 0 && wd <= 360 ? wd : null;
    
    // Get today's peak gust from server (daily tracking, never stale)
    const todaysPeakGust = weather.peak_gust_today || 0;
    
    // Populate wind details section
    const windDetails = document.getElementById('wind-details');
    // Gust factor is calculated field - only show if source fields are valid
    const gustFactor = (!windSpeedStale && !gustSpeedStale && weather.wind_speed !== null && weather.gust_speed !== null) 
        ? (weather.gust_factor ?? null) 
        : null;
    
    // Get gust speed/peak gust value (null if stale)
    const gustSpeed = gustSpeedStale ? null : (weather.gust_speed || weather.peak_gust || null);
    
    const windUnitLabel = getWindSpeedUnitLabel();
    const CALM_WIND_THRESHOLD = 3; // Winds below 3 knots are considered calm in aviation
    windDetails.innerHTML = `
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e0e0e0;">
            <span style="color: #555;">Wind Speed:</span>
            <span style="font-weight: bold;">${ws === null || ws === undefined ? '--' : (ws < CALM_WIND_THRESHOLD ? 'Calm' : formatWindSpeed(ws) + ' ' + windUnitLabel)}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e0e0e0;">
            <span style="color: #555;">Wind Direction:</span>
            <span style="font-weight: bold;">${isVariableWind ? 'VRB' : (windDirNumeric ? windDirNumeric + '°' : '--')}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e0e0e0;">
            <span style="color: #555;">Gusting:</span>
            <span style="font-weight: bold;">${gustSpeed !== null && gustSpeed !== undefined ? (gustSpeed > 0 ? formatWindSpeed(gustSpeed) + ' ' + windUnitLabel : '--') : '--'}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e0e0e0;">
            <span style="color: #555;">Gust Factor:</span>
            <span style="font-weight: bold;">${gustFactor === null || gustFactor === undefined ? '--' : (gustFactor > 0 ? formatWindSpeed(gustFactor) + ' ' + windUnitLabel : '0')}</span>
        </div>
        <div style="padding: 0.5rem 0;">
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
    
    // Only draw wind indicators if data is fresh (not stale)
    if (!windStale) {
        if (ws !== null && ws !== undefined && ws >= CALM_WIND_THRESHOLD && !isVariableWind && windDirNumeric !== null) {
            // Convert wind direction FROM to TOWARD (add 180°) for windsock visualization
            // Normalize to 0-360° range (e.g., 270° + 180° = 450° → 90°)
            const windDirToward = (windDirNumeric + 180) % 360;
            windDirection = (windDirToward * Math.PI) / 180;
            windSpeed = ws;
            
            drawWindArrow(ctx, cx, cy, r, windDirection, windSpeed, 0);
        } else if (ws !== null && ws !== undefined && ws >= CALM_WIND_THRESHOLD && isVariableWind) {
            // Variable wind - draw "VRB" text
            ctx.font = 'bold 20px sans-serif'; 
            ctx.textAlign = 'center';
            ctx.strokeStyle = '#fff'; 
            ctx.lineWidth = 3;
            ctx.lineJoin = 'round'; // Prevent miter spike artifacts on letters
            ctx.strokeText('VRB', cx, cy);
            ctx.fillStyle = '#dc3545';
            ctx.fillText('VRB', cx, cy);
        } else if (ws === null || ws === undefined || ws < CALM_WIND_THRESHOLD) {
            // Calm conditions (< 3 knots) - draw CALM text (only when data is fresh)
            ctx.font = 'bold 20px sans-serif'; 
            ctx.textAlign = 'center';
            ctx.strokeStyle = '#fff'; 
            ctx.lineWidth = 3;
            ctx.lineJoin = 'round'; // Prevent miter spike artifacts on letters like M
            ctx.strokeText('CALM', cx, cy);
            ctx.fillStyle = '#333';
            ctx.fillText('CALM', cx, cy);
        }
    }
    // If windStale is true, we don't draw any wind indicators (just runways + compass already drawn above)
    
    // Draw cardinal directions
    ['N', 'E', 'S', 'W'].forEach((l, i) => {
        const ang = (i * 90 * Math.PI) / 180;
        ctx.fillStyle = '#666'; ctx.font = 'bold 16px sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillText(l, cx + Math.sin(ang) * (r + 10), cy - Math.cos(ang) * (r + 10));
    });
}

function drawWindArrow(ctx, cx, cy, r, angle, speed, offset = 0) {
    // Arrow points in direction wind is blowing TOWARD (windsock behavior)
    // angle is already in TOWARD direction (caller converts FROM by adding 180°)
    const arrowLength = Math.min(speed * 6, r - 30);
    const arrowEndX = cx + Math.sin(angle) * arrowLength;
    const arrowEndY = cy - Math.cos(angle) * arrowLength;
    
    ctx.fillStyle = 'rgba(220, 53, 69, 0.2)';
    const circleRadius = Math.max(20, speed * 4);
    ctx.beginPath(); ctx.arc(cx, cy, circleRadius, 0, 2 * Math.PI); ctx.fill();
    
    ctx.strokeStyle = '#dc3545'; ctx.fillStyle = '#dc3545'; ctx.lineWidth = 4; ctx.lineCap = 'round';
    ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(arrowEndX, arrowEndY); ctx.stroke();
    
    const arrowAngle = Math.atan2(arrowEndY - cy, arrowEndX - cx);
    ctx.beginPath();
    ctx.moveTo(arrowEndX, arrowEndY);
    ctx.lineTo(arrowEndX - 15 * Math.cos(arrowAngle - Math.PI / 6), arrowEndY - 15 * Math.sin(arrowAngle - Math.PI / 6));
    ctx.lineTo(arrowEndX - 15 * Math.cos(arrowAngle + Math.PI / 6), arrowEndY - 15 * Math.sin(arrowAngle + Math.PI / 6));
    ctx.closePath(); ctx.fill();
}

function openLiveStream(url) { window.open(url, '_blank'); }

// Webcam History Player
const WebcamPlayer = {
    active: false,
    frames: [],
    currentIndex: 0,
    playing: false,
    playInterval: null,
    preloadedImages: {},
    timezone: 'UTC',
    // State for URL sync and UI control
    airportId: null,
    camIndex: null,
    camName: null,
    controlsVisible: true,
    hideTimeout: null,
    hideUIMode: false,  // Kiosk/signage mode
    enabledFormats: ['jpg'],  // Server-enabled formats
    preferredFormat: 'jpg',   // Browser's preferred format (detected once)
    preferredVariant: 1080,  // Preferred size variant for viewport (height in pixels)
        variantHeights: [],  // Variant heights for selection (from API)
    // Rolling window refresh
    refreshInterval: 60,  // Refresh interval in seconds (from API)
    refreshTimer: null,  // Interval timer for refreshing frames
    isRefreshing: false,  // Guard against overlapping refresh calls
    savedScrollY: 0,  // Store scroll position when opening player (for mobile viewport fix)

    // Update URL to reflect current state (for sharing)
    updateURL() {
        const params = new URLSearchParams(window.location.search);
        
        if (this.active && this.camIndex !== null && this.camIndex !== undefined) {
            // Explicitly convert to string to handle camIndex=0 correctly
            params.set('cam', String(this.camIndex));
            if (this.playing) {
                params.set('autoplay', '1');
            } else {
                params.delete('autoplay');
            }
            if (this.hideUIMode) {
                params.set('hideui', '1');
            } else {
                params.delete('hideui');
            }
        } else {
            params.delete('cam');
            params.delete('autoplay');
            params.delete('hideui');
        }
        
        // Build clean URL (convert autoplay=1 to just autoplay, etc.)
        // Only apply to boolean parameters, not numeric ones like 'cam'
        let paramStr = params.toString();
        paramStr = paramStr.replace(/(autoplay|hideui)=1(&|$)/g, '$1$2').replace(/&$/, '');
        
        const newURL = paramStr 
            ? `${window.location.pathname}?${paramStr}`
            : window.location.pathname;
        
        history.replaceState({ webcamPlayer: this.active }, '', newURL);
    },

    // Show controls (header, timestamp, buttons)
    // In hideUI mode, controls will still auto-hide after 3 seconds
    showControls() {
        const player = document.getElementById('webcam-player');
        player.classList.remove('controls-hidden');
        this.controlsVisible = true;
        this.resetHideTimer();
    },

    // Hide controls
    hideControls() {
        const player = document.getElementById('webcam-player');
        player.classList.add('controls-hidden');
        this.controlsVisible = false;
        this.clearHideTimer();
    },

    // Toggle controls visibility
    toggleControls() {
        if (this.controlsVisible) {
            this.hideControls();
        } else {
            this.showControls();
        }
    },

    // Set up auto-hide timer
    resetHideTimer() {
        this.clearHideTimer();
        // Auto-hide after 3 seconds while playing (or in hideUI mode)
        if (this.playing || this.hideUIMode) {
            this.hideTimeout = setTimeout(() => {
                if (this.playing || this.hideUIMode) {
                    this.hideControls();
                    // Ensure URL reflects current state after auto-hide
                    this.updateURL();
                }
            }, 3000);
        }
    },

    clearHideTimer() {
        if (this.hideTimeout) {
            clearTimeout(this.hideTimeout);
            this.hideTimeout = null;
        }
    },

    // Toggle autoplay and update button state
    toggleAutoplay() {
        if (this.playing) {
            this.stop();
        } else {
            this.play();
        }
        this.updateAutoplayButton();
    },

    // Update autoplay button visual state
    updateAutoplayButton() {
        const btn = document.getElementById('webcam-player-autoplay-btn');
        if (btn) {
            btn.classList.toggle('active', this.playing);
            btn.title = this.playing ? 'Stop autoplay' : 'Start autoplay';
        }
    },

    // Toggle hide UI mode and update button state
    toggleHideUI() {
        this.hideUIMode = !this.hideUIMode;
        this.updateHideUIButton();
        
        if (this.hideUIMode) {
            this.hideControls();
        } else {
            this.showControls();
        }
        this.updateURL();
    },

    // Update hide UI button visual state
    updateHideUIButton() {
        const btn = document.getElementById('webcam-player-hideui-btn');
        if (btn) {
            btn.classList.toggle('active', this.hideUIMode);
            btn.title = this.hideUIMode ? 'Show controls' : 'Hide controls';
        }
    },

    // Enable kiosk/signage mode (URL param: hideui)
    setHideUIMode(enabled) {
        this.hideUIMode = enabled;
        this.updateHideUIButton();
        if (enabled) {
            this.hideControls();
        } else {
            this.showControls();
        }
        this.updateURL();
    },

    // Build URL for a frame using the browser's preferred format and variant
    buildImageUrl(frame) {
        // Use preferred format if available for this frame, otherwise fall back to jpg
        const format = (frame.formats && Array.isArray(frame.formats) && frame.formats.includes(this.preferredFormat))
            ? this.preferredFormat 
            : 'jpg';
        
        // Get available heights from variant manifest
        const availableHeights = frame.variants ? Object.keys(frame.variants)
            .filter(v => v !== 'original' && !isNaN(parseInt(v)))
            .map(v => parseInt(v))
            .sort((a, b) => b - a) : [];
        
        // Choose variant height - use preferred if available, otherwise find best match
        let size = this.preferredVariant;
        if (typeof size === 'number') {
            // Convert to string for object key lookup (variants object has string keys)
            const sizeKey = String(size);
            // Check if preferred height is available for this frame
            if (!frame.variants || !frame.variants[sizeKey] || !frame.variants[sizeKey].includes(format)) {
                // Find best available height
                const availableForFormat = availableHeights.filter(h => {
                    const hKey = String(h);
                    return frame.variants[hKey] && frame.variants[hKey].includes(format);
                });
                if (availableForFormat.length > 0) {
                    // Use largest available height <= preferred
                    size = availableForFormat.find(h => h <= size) || availableForFormat[availableForFormat.length - 1];
                } else if (frame.variants && frame.variants.original && frame.variants.original.includes(format)) {
                    size = 'original';
                } else {
                    // Fallback to any available variant
                    size = availableHeights.length > 0 ? availableHeights[availableHeights.length - 1] : 'original';
                }
            }
        } else if (size === 'original') {
            // Check if original is available
            if (!frame.variants || !frame.variants.original || !frame.variants.original.includes(format)) {
                // Fallback to largest available height
                const availableForFormat = availableHeights.filter(h => {
                    const hKey = String(h);
                    return frame.variants[hKey] && frame.variants[hKey].includes(format);
                });
                size = availableForFormat.length > 0 ? availableForFormat[0] : 'original';
            }
        }
        
        return `${frame.url}&fmt=${format}&size=${size}`;
    },

    async open(airportId, camIndex, camName, currentImageSrc, options = {}) {
        const player = document.getElementById('webcam-player');
        const img = document.getElementById('webcam-player-image');
        const title = document.getElementById('webcam-player-title');

        // Store state for URL sync
        this.airportId = airportId;
        this.camIndex = camIndex;
        this.camName = camName;

        // Show player immediately with current image
        img.src = currentImageSrc;
        title.textContent = camName;
        player.classList.add('active');
        player.classList.remove('controls-hidden');
        this.active = true;
        this.controlsVisible = true;

        // Handle hideui mode
        this.hideUIMode = options.hideui || false;
        this.updateHideUIButton();
        if (this.hideUIMode) {
            this.hideControls();
        }

        // Prevent body scroll - store scroll position first (fixes mobile viewport issue)
        this.savedScrollY = window.scrollY || window.pageYOffset || document.documentElement.scrollTop || 0;
        document.body.style.overflow = 'hidden';
        document.body.style.position = 'fixed';
        document.body.style.width = '100%';
        document.body.style.top = `-${this.savedScrollY}px`;

        // Update URL (don't push state, use replaceState for clean back behavior)
        this.updateURL();

        // Fetch history manifest
        try {
            const response = await fetch(`/api/webcam-history.php?id=${encodeURIComponent(airportId)}&cam=${camIndex}`);
            const data = await response.json();

            // Check if history is enabled and available
            if (data.enabled && data.available && data.frames && data.frames.length > 0) {
                // Full history player mode
                this.frames = data.frames;
                this.timezone = data.timezone || 'UTC';
                this.currentIndex = data.current_index || 0;
                this.refreshInterval = data.refresh_interval || 60;
                
                // Store enabled formats and detect browser's preferred format once
                this.enabledFormats = data.enabledFormats || ['jpg'];
                this.preferredFormat = getPreferredFormat(this.enabledFormats);
                this.variantHeights = data.variantHeights || [1080, 720, 360];
                // Get preferred variant height based on display size
                this.preferredVariant = getPreferredVariant('player', this.variantHeights);
                
                // Log browser format and variant selection
                const variantStr = this.preferredVariant === 'original' ? 'original' : String(this.preferredVariant);
                console.log(`[Webcam Player ${camIndex}] Browser selected format: ${this.preferredFormat}, variant: ${variantStr}`);
                
                this.initTimeline();
                // Display the current frame immediately so navigation works right away
                this.goToFrame(this.currentIndex);
                this.preloadFrames();
                document.querySelector('.webcam-player-controls').style.display = '';
                
                // Start periodic refresh for rolling window
                this.startRefreshTimer();
                
                // Auto-play if requested
                if (options.autoplay && this.frames.length > 1) {
                    this.play();
                }
            } else if (data.enabled && !data.available) {
                // History is configured but not enough frames yet
                this.frames = [];
                document.querySelector('.webcam-player-controls').style.display = 'none';
                document.getElementById('webcam-player-timestamp').textContent = 
                    data.message || 'History not available for this camera, come back later.';
            } else {
                // History is not configured for this airport
                this.frames = [];
                document.querySelector('.webcam-player-controls').style.display = 'none';
                document.getElementById('webcam-player-timestamp').textContent = 'History not available';
            }
        } catch (error) {
            console.error('Failed to load webcam history:', error);
            this.frames = [];
            document.querySelector('.webcam-player-controls').style.display = 'none';
        }

        this.setupGestures();
        this.setupTapToToggle();
    },

    close() {
        if (!this.active) return;

        this.stop();
        this.stopRefreshTimer();
        this.clearHideTimer();
        this.active = false;
        this.hideUIMode = false;

        const player = document.getElementById('webcam-player');
        player.classList.remove('active');
        player.classList.remove('controls-hidden');

        // Restore body scroll and scroll position (fixes mobile viewport issue)
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.width = '';
        document.body.style.top = '';
        
        // Restore scroll position after a brief delay to ensure styles are applied
        // Use requestAnimationFrame to ensure DOM has updated
        requestAnimationFrame(() => {
            window.scrollTo(0, this.savedScrollY);
            this.savedScrollY = 0; // Reset for next time
        });

        // Clean up
        this.preloadedImages = {};
        this.frames = [];
        this.airportId = null;
        this.camIndex = null;
        this.camName = null;

        // Reset UI for next time
        document.querySelector('.webcam-player-controls').style.display = '';
        document.getElementById('webcam-player-timestamp').textContent = '--';
        this.updateHideUIButton();
        this.updateAutoplayButton();
        
        // Update URL to remove player params
        this.updateURL();
    },

    initTimeline() {
        const timeline = document.getElementById('webcam-player-timeline');
        const startEl = document.getElementById('webcam-player-time-start');
        const endEl = document.getElementById('webcam-player-time-end');
        const playBtn = document.getElementById('webcam-player-play-btn');
        const prevBtn = document.getElementById('webcam-player-prev-btn');
        const nextBtn = document.getElementById('webcam-player-next-btn');

        timeline.min = 0;
        timeline.max = this.frames.length - 1;
        timeline.value = this.currentIndex;

        if (this.frames.length > 0) {
            startEl.textContent = this.formatTime(this.frames[0].timestamp);
            endEl.textContent = this.formatTime(this.frames[this.frames.length - 1].timestamp);
        }

        // Disable controls if only 1 frame (nothing to play/navigate)
        const hasMultipleFrames = this.frames.length > 1;
        playBtn.disabled = !hasMultipleFrames;
        prevBtn.disabled = !hasMultipleFrames;
        nextBtn.disabled = !hasMultipleFrames;
        timeline.disabled = !hasMultipleFrames;
        
        if (!hasMultipleFrames) {
            playBtn.style.opacity = '0.4';
            prevBtn.style.opacity = '0.4';
            nextBtn.style.opacity = '0.4';
        } else {
            playBtn.style.opacity = '';
            prevBtn.style.opacity = '';
            nextBtn.style.opacity = '';
        }

        // Remove old listener if any
        const newTimeline = timeline.cloneNode(true);
        timeline.parentNode.replaceChild(newTimeline, timeline);
        newTimeline.addEventListener('input', (e) => {
            this.goToFrame(parseInt(e.target.value));
        });

        this.updateTimestampDisplay();
    },

    formatTime(timestamp) {
        const date = new Date(timestamp * 1000);
        try {
            return date.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                timeZone: this.timezone
            });
        } catch {
            return date.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit'
            });
        }
    },

    updateTimestampDisplay() {
        const el = document.getElementById('webcam-player-timestamp');
        if (this.frames.length > 0 && this.frames[this.currentIndex]) {
            const ts = this.frames[this.currentIndex].timestamp;
            const date = new Date(ts * 1000);
            try {
                el.textContent = date.toLocaleString('en-US', {
                    weekday: 'short',
                    hour: 'numeric',
                    minute: '2-digit',
                    second: '2-digit',
                    timeZone: this.timezone
                });
            } catch {
                el.textContent = date.toLocaleString('en-US', {
                    weekday: 'short',
                    hour: 'numeric',
                    minute: '2-digit',
                    second: '2-digit'
                });
            }
        }
    },

    goToFrame(index) {
        // If no frames available, return early
        if (this.frames.length === 0) return;
        
        // Handle out of bounds - clamp to valid range
        if (index < 0) {
            index = 0;
        } else if (index >= this.frames.length) {
            index = this.frames.length - 1;
        }

        this.currentIndex = index;
        const frame = this.frames[index];
        const img = document.getElementById('webcam-player-image');
        const timeline = document.getElementById('webcam-player-timeline');

        timeline.value = index;

        // Use preloaded image URL if available
        const cacheKey = frame.timestamp;
        if (this.preloadedImages[cacheKey]) {
            img.src = this.preloadedImages[cacheKey];
            img.classList.remove('loading');
            // Extract format and variant from cached URL for logging
            const urlMatch = this.preloadedImages[cacheKey].match(/[&?]fmt=([^&]+)/);
            const sizeMatch = this.preloadedImages[cacheKey].match(/[&?]size=([^&]+)/);
            const format = urlMatch ? urlMatch[1] : 'unknown';
            const variant = sizeMatch ? sizeMatch[1] : 'unknown';
            const timeStr = new Date(frame.timestamp * 1000).toLocaleTimeString();
            console.log(`[Webcam Player ${this.camIndex}] Displaying cached image at ${timeStr} (format: ${format}, variant: ${variant})`);
        } else {
            // Fallback: load directly using preferred format
            img.classList.add('loading');
            const imageUrl = this.buildImageUrl(frame);
            
            // Extract format and variant from URL for logging
            const urlMatch = imageUrl.match(/[&?]fmt=([^&]+)/);
            const sizeMatch = imageUrl.match(/[&?]size=([^&]+)/);
            const format = urlMatch ? urlMatch[1] : 'unknown';
            const variant = sizeMatch ? sizeMatch[1] : 'unknown';
            const timeStr = new Date(frame.timestamp * 1000).toLocaleTimeString();
            console.log(`[Webcam Player ${this.camIndex}] Requesting image at ${timeStr} - format: ${format}, variant: ${variant}`);
            
            const preloadImg = new Image();
            preloadImg.src = imageUrl;
            
            preloadImg.onload = () => {
                img.src = imageUrl;
                img.classList.remove('loading');
                this.preloadedImages[cacheKey] = imageUrl;
                console.log(`[Webcam Player ${this.camIndex}] Image loaded successfully at ${timeStr} - format: ${format}, variant: ${variant}`);
            };
            preloadImg.onerror = () => {
                img.classList.remove('loading');
                console.error(`[Webcam Player ${this.camIndex}] Image load failed at ${timeStr} - format: ${format}, variant: ${variant}`);
            };
        }

        this.updateTimestampDisplay();
    },

    async preloadFrames() {
        const bar = document.getElementById('webcam-player-loading-bar');
        let loaded = 0;

        for (const frame of this.frames) {
            if (!this.active) break;

            const cacheKey = frame.timestamp;
            
            // Skip if already preloaded
            if (this.preloadedImages[cacheKey]) {
                loaded++;
                bar.style.width = `${(loaded / this.frames.length) * 100}%`;
                continue;
            }

            // Preload single format directly (no <picture> element needed)
            const imageUrl = this.buildImageUrl(frame);
            
            // Extract format and variant from URL for logging (only log first few to avoid spam)
            if (loaded < 3 || loaded % 10 === 0) {
                const urlMatch = imageUrl.match(/[&?]fmt=([^&]+)/);
                const sizeMatch = imageUrl.match(/[&?]size=([^&]+)/);
                const format = urlMatch ? urlMatch[1] : 'unknown';
                const variant = sizeMatch ? sizeMatch[1] : 'unknown';
                console.log(`[Webcam Player ${this.camIndex}] Preloading frame ${loaded + 1}/${this.frames.length} - format: ${format}, variant: ${variant}`);
            }
            
            const img = new Image();
            img.src = imageUrl;
            
            await new Promise((resolve) => {
                img.onload = () => {
                    this.preloadedImages[cacheKey] = imageUrl;
                    loaded++;
                    bar.style.width = `${(loaded / this.frames.length) * 100}%`;
                    resolve();
                };
                img.onerror = () => {
                    loaded++;
                    bar.style.width = `${(loaded / this.frames.length) * 100}%`;
                    resolve();
                };
            });
        }

        setTimeout(() => { bar.style.width = '0%'; }, 500);
    },

    togglePlay() {
        if (this.playing) {
            this.stop();
        } else {
            this.play();
        }
    },

    play() {
        if (this.frames.length < 2) return;

        this.playing = true;
        document.getElementById('webcam-player-play-btn').textContent = '⏸';
        this.updateAutoplayButton();

        this.playInterval = setInterval(() => {
            if (this.currentIndex >= this.frames.length - 1) {
                this.currentIndex = 0;
            } else {
                this.currentIndex++;
            }
            const frame = this.frames[this.currentIndex];
            const timeStr = new Date(frame.timestamp * 1000).toLocaleTimeString();
            console.log(`[Webcam Player ${this.camIndex}] Updating - new frame at ${timeStr}`);
            this.goToFrame(this.currentIndex);
        }, 500);  // 500ms = 2 FPS for deliberate time-lapse viewing
        
        // Update URL to reflect playing state
        this.updateURL();
        // Start auto-hide timer
        this.resetHideTimer();
    },

    stop() {
        this.playing = false;
        document.getElementById('webcam-player-play-btn').textContent = '▶';
        this.updateAutoplayButton();
        if (this.playInterval) {
            clearInterval(this.playInterval);
            this.playInterval = null;
        }
        // Update URL to reflect stopped state
        this.updateURL();
        // Show controls when stopped
        if (!this.hideUIMode) {
            this.showControls();
        }
    },

    // Start periodic refresh timer for rolling window
    // Refreshes regardless of playing/paused state
    startRefreshTimer() {
        this.stopRefreshTimer(); // Clear any existing timer
        
        if (!this.active || this.refreshInterval < 60) return;
        
        const intervalMs = this.refreshInterval * 1000;
        this.refreshTimer = setInterval(() => {
            if (this.active) {
                this.refreshFrames();
            }
        }, intervalMs);
    },

    // Stop refresh timer
    stopRefreshTimer() {
        if (this.refreshTimer) {
            clearInterval(this.refreshTimer);
            this.refreshTimer = null;
        }
    },

    // Refresh frames from API and merge with existing frames
    // Always refreshes (regardless of playing/paused state)
    // Maintains rolling window (max_frames limit)
    async refreshFrames() {
        if (!this.active || !this.airportId || this.camIndex === null) return;
        
        // Guard against overlapping refresh calls
        if (this.isRefreshing) return;
        this.isRefreshing = true;

        try {
            const response = await fetch(`/api/webcam-history.php?id=${encodeURIComponent(this.airportId)}&cam=${this.camIndex}`);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();

            if (!data.enabled || !data.frames || data.frames.length === 0) {
                return; // No frames available, keep existing frames
            }

            // Store current frame info for position preservation
            const currentFrame = this.frames[this.currentIndex];
            const currentTimestamp = currentFrame ? currentFrame.timestamp : null;
            const oldFramesLength = this.frames.length;
            const wasAtEnd = currentFrame && this.currentIndex === oldFramesLength - 1;
            const wasPlaying = this.playing;

            // Apply rolling window: always cap at max_frames (most recent N frames)
            const maxFrames = data.max_frames || 12;
            const framesToKeep = data.frames.slice(-maxFrames);
            
            // Edge case: no frames to keep
            if (framesToKeep.length === 0) {
                return;
            }
            
            // Check if frames changed (new frames added or old ones removed)
            const existingTimestamps = new Set(this.frames.map(f => f.timestamp));
            const newTimestamps = new Set(framesToKeep.map(f => f.timestamp));
            const hasChanges = this.frames.length !== framesToKeep.length || 
                              ![...existingTimestamps].every(ts => newTimestamps.has(ts));

            if (hasChanges) {
                // Update frames to rolling window
                this.frames = framesToKeep;

                // Update current index based on current frame position
                if (currentTimestamp !== null) {
                    // Find the frame with the same timestamp, or next available if removed
                    let newIndex = this.frames.findIndex(f => f.timestamp === currentTimestamp);
                    
                    if (newIndex >= 0) {
                        // Current frame still exists - keep position
                        this.currentIndex = newIndex;
                    } else {
                        // Current frame was removed - find next available frame
                        // Look for the first frame at or after the current timestamp
                        newIndex = this.frames.findIndex(f => f.timestamp >= currentTimestamp);
                        
                        if (newIndex >= 0) {
                            // Found a frame at or after current timestamp
                            this.currentIndex = newIndex;
                        } else {
                            // All frames are older than current (shouldn't happen with rolling window)
                            // Jump to newest frame
                            this.currentIndex = Math.max(0, this.frames.length - 1);
                        }
                    }
                } else {
                    // No current frame, go to newest
                    this.currentIndex = Math.max(0, this.frames.length - 1);
                }

                // Special handling for playback at end when new frames arrive
                if (wasAtEnd && wasPlaying && this.frames.length > oldFramesLength) {
                    // We were at the end and playing, new frames were added
                    // Stay at the old end position so playback continues naturally into new frames
                    const oldEndIndex = Math.max(0, oldFramesLength - 1);
                    if (oldEndIndex < this.frames.length - 1) {
                        // Only adjust if we're not already at the new end
                        this.currentIndex = oldEndIndex;
                    }
                }

                // Update timeline
                this.initTimeline();
                
                // Preload new frames in background (non-blocking)
                this.preloadFrames();
                
                // Update display if current frame changed or user is paused/scrubbing
                const frameChanged = currentTimestamp === null || 
                                    this.frames[this.currentIndex]?.timestamp !== currentTimestamp;
                
                if (frameChanged || !wasPlaying) {
                    // Current frame was removed/changed or user is scrubbing/paused
                    // Update display to show current/next available frame
                    this.goToFrame(this.currentIndex);
                }
                // If playing and frame didn't change, play interval will continue naturally
            }
        } catch (error) {
            console.error('Failed to refresh webcam history:', error);
            // Silently fail - keep existing frames
        } finally {
            this.isRefreshing = false;
        }
    },

    prev() {
        if (this.frames.length === 0) return;
        this.stop();
        this.goToFrame(Math.max(0, this.currentIndex - 1));
    },

    next() {
        if (this.frames.length === 0) return;
        this.stop();
        this.goToFrame(Math.min(this.frames.length - 1, this.currentIndex + 1));
    },

    setupGestures() {
        const swipeArea = document.getElementById('webcam-player-swipe-area');
        if (!swipeArea || swipeArea._gesturesSetup) return;
        swipeArea._gesturesSetup = true;

        let startX = 0, startY = 0, currentY = 0;
        // Track if touch event handled the interaction (prevents double-toggle on Android)
        let touchHandled = false;

        swipeArea.addEventListener('touchstart', (e) => {
            touchHandled = false; // Reset on new touch
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            currentY = startY;
            // Reset hide timer on any touch
            this.resetHideTimer();
        }, { passive: true });

        swipeArea.addEventListener('touchmove', (e) => {
            currentY = e.touches[0].clientY;
            const deltaY = currentY - startY;

            if (deltaY > 0) {
                const player = document.getElementById('webcam-player');
                player.style.transform = `translateY(${Math.min(deltaY, 150)}px)`;
                player.style.opacity = Math.max(0.5, 1 - deltaY / 300);
            }
        }, { passive: true });

        swipeArea.addEventListener('touchend', (e) => {
            const deltaX = e.changedTouches[0].clientX - startX;
            const deltaY = currentY - startY;
            const player = document.getElementById('webcam-player');

            player.style.transform = '';
            player.style.opacity = '';

            // Swipe down to dismiss
            if (deltaY > 100 && Math.abs(deltaX) < 50) {
                touchHandled = true;
                this.close();
                return;
            }

            // Horizontal swipe to navigate
            if (Math.abs(deltaX) > 50 && Math.abs(deltaY) < 50) {
                touchHandled = true;
                if (deltaX > 0) {
                    this.prev();
                } else {
                    this.next();
                }
                return;
            }
            
            // Small movement = tap to toggle controls
            if (Math.abs(deltaX) < 10 && Math.abs(deltaY) < 10) {
                touchHandled = true;
                this.toggleControls();
            }
        });

        // Click handler for desktop (mouse) - won't double-fire after touch events
        // This replaces the previous onclick attribute on the swipe area
        swipeArea.addEventListener('click', (e) => {
            // If touch already handled this interaction, skip click
            if (touchHandled) {
                touchHandled = false; // Reset for next interaction
                return;
            }
            // Don't toggle if clicking on controls or header (they have their own handlers)
            if (e.target.closest('.webcam-player-controls') || 
                e.target.closest('.webcam-player-header')) {
                return;
            }
            // Desktop click - toggle controls
            this.toggleControls();
        });
    },

    setupTapToToggle() {
        const player = document.getElementById('webcam-player');
        if (!player || player._tapSetup) return;
        player._tapSetup = true;

        // Reset hide timer on any mouse movement in the player (desktop)
        // Also show controls temporarily on mouse move
        player.addEventListener('mousemove', () => {
            if (!this.controlsVisible) {
                this.showControls();
            }
            this.resetHideTimer();
        });

        // Also reset timer when interacting with controls
        const controls = document.querySelector('.webcam-player-controls');
        const header = document.querySelector('.webcam-player-header');
        
        [controls, header].forEach(el => {
            if (el) {
                el.addEventListener('click', () => this.resetHideTimer());
                el.addEventListener('touchstart', () => this.resetHideTimer(), { passive: true });
            }
        });
    }
};

// Global function for onclick handler - with fallback for disabled history
function openWebcamPlayer(airportId, camIndex, camName, currentSrc, options = {}) {
    if (!window.AIRPORT_NAV_DATA?.webcamHistoryEnabled) {
        openLiveStream(currentSrc);
        return;
    }
    WebcamPlayer.open(airportId, camIndex, camName, currentSrc, options);
}

function closeWebcamPlayer() { WebcamPlayer.close(); }
function webcamPlayerTogglePlay() { WebcamPlayer.togglePlay(); }
function webcamPlayerPrev() { WebcamPlayer.prev(); }
function webcamPlayerNext() { WebcamPlayer.next(); }
function webcamPlayerToggleAutoplay() { WebcamPlayer.toggleAutoplay(); }
function webcamPlayerToggleHideUI() { WebcamPlayer.toggleHideUI(); }
function webcamPlayerToggleControls(event) {
    // Don't toggle if clicking on controls or header
    if (event && event.target && (event.target.closest('.webcam-player-controls') || event.target.closest('.webcam-player-header'))) {
        return;
    }
    WebcamPlayer.toggleControls();
}

// Handle back button / navigation
window.addEventListener('popstate', () => {
    // Check if we should open or close based on URL
    const params = new URLSearchParams(window.location.search);
    const camParam = params.get('cam');
    
    if (camParam !== null && !WebcamPlayer.active) {
        // URL has cam param but player not open - this shouldn't happen normally
        // but handle it gracefully
    } else if (camParam === null && WebcamPlayer.active) {
        // URL no longer has cam param - close the player
        WebcamPlayer.close();
    }
});

// Keyboard navigation for player
window.addEventListener('keydown', (e) => {
    if (!WebcamPlayer.active) return;
    
    switch (e.key) {
        case 'Escape':
            WebcamPlayer.close();
            break;
        case 'ArrowLeft':
            WebcamPlayer.prev();
            break;
        case 'ArrowRight':
            WebcamPlayer.next();
            break;
        case ' ': // Space bar
            e.preventDefault();
            WebcamPlayer.togglePlay();
            break;
        case 'Home':
            WebcamPlayer.goToFrame(0);
            break;
        case 'End':
            WebcamPlayer.goToFrame(WebcamPlayer.frames.length - 1);
            break;
        case 'h':
        case 'H':
            // Toggle hide UI mode
            WebcamPlayer.setHideUIMode(!WebcamPlayer.hideUIMode);
            break;
        case 'c':
        case 'C':
            // Toggle controls visibility (without changing hideUI mode)
            if (!WebcamPlayer.hideUIMode) {
                WebcamPlayer.toggleControls();
            }
            break;
    }
});

// Check URL params on page load and open player if needed
document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    const camParam = params.get('cam');
    
    if (camParam !== null && window.AIRPORT_NAV_DATA?.webcamHistoryEnabled) {
        const camIndex = parseInt(camParam, 10);
        const webcams = <?= json_encode($airport['webcams'] ?? []) ?>;
        
        if (!isNaN(camIndex) && webcams && webcams[camIndex]) {
            const cam = webcams[camIndex];
            const airportId = window.AIRPORT_NAV_DATA.currentAirportId;
            
            // Get the current image src from the page
            const imgEl = document.getElementById(`webcam-${camIndex}`);
            const currentSrc = imgEl ? imgEl.src : '';
            
            // Check for autoplay and hideui params (handle both ?autoplay and ?autoplay=1)
            const options = {
                autoplay: params.has('autoplay') || params.get('autoplay') === '1',
                hideui: params.has('hideui') || params.get('hideui') === '1'
            };
            
            // Small delay to ensure page is fully loaded
            setTimeout(() => {
                openWebcamPlayer(airportId, camIndex, cam.name, currentSrc, options);
            }, 100);
        }
    }
});

<?php if (isset($airport['webcams']) && !empty($airport['webcams']) && count($airport['webcams']) > 0): ?>
// Update webcam timestamps (called periodically to refresh relative time display)
function updateWebcamTimestamps() {
    <?php foreach ($airport['webcams'] as $index => $cam): ?>
    // Update webcam timestamp in label
    // eslint-disable-next-line -- PHP generates variable names (e.g., timestampElem0, timestampElem1) - this is intentional
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
    
    // Check if webcam is stale (exceeds failclosed threshold) and show warning emoji
    const isStale = diffSeconds >= STALE_FAILCLOSED_SECONDS;
    
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
const CAM_LAST_FETCH = {}; // Track when each webcam was last successfully fetched (client time ms)
let webcamVisibilityDebounceTimer = null; // Debounce visibility/focus events

// Initialize CAM_TS with server-side timestamps from initial image load
// Uses EXIF capture time when available, otherwise falls back to filemtime
// Uses webcamTimestamps array if available (ensures consistency with data-initial-timestamp)
<?php foreach ($airport['webcams'] as $index => $cam): 
    // Use timestamp from webcamTimestamps array if available (ensures consistency with data-initial-timestamp)
    $initialMtime = isset($webcamTimestamps[$index]) ? $webcamTimestamps[$index] : 0;
    
    // Fallback: read from file if not in array
    if ($initialMtime === 0) {
        foreach (['jpg', 'webp'] as $ext) {
            $filePath = getCacheSymlinkPath($airportId, $index, $ext);
            if (file_exists($filePath)) {
                $initialMtime = getImageCaptureTimeForPage($filePath);
                break;
            }
        }
    }
?>
CAM_TS[<?= $index ?>] = <?= $initialMtime ?>;
<?php endforeach; ?>

// Initialize timestamp displays after DOM is ready
function initializeWebcamTimestamps() {
    <?php foreach ($airport['webcams'] as $index => $cam): 
        $initialMtime = 0;
        foreach (['jpg', 'webp'] as $ext) {
            $filePath = getCacheSymlinkPath($airportId, $index, $ext);
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
(function() {
    <?php foreach ($airport['webcams'] as $index => $cam): 
        // Get webcam refresh from config with global config fallback
        $defaultWebcamRefresh = getDefaultWebcamRefresh();
        $airportWebcamRefresh = isset($airport['webcam_refresh_seconds']) ? intval($airport['webcam_refresh_seconds']) : $defaultWebcamRefresh;
        $perCam = isset($cam['refresh_seconds']) ? intval($cam['refresh_seconds']) : $airportWebcamRefresh;
    ?>
    (function() {
        const imgEl<?= $index ?> = document.getElementById('webcam-<?= $index ?>');
        if (imgEl<?= $index ?>) {
            // Check timestamp on initial load (images may already be cached)
            // For first webcam (LCP element), delay timestamp check to avoid competing with LCP load
            const timestampDelay = <?= $index === 0 ? '500' : '100' ?>;
            if (imgEl<?= $index ?>.complete && imgEl<?= $index ?>.naturalHeight !== 0) {
                // Image already loaded, observe format immediately and check timestamp after delay
                if (typeof observeWebcamFormat === 'function') {
                    observeWebcamFormat(<?= $index ?>, imgEl<?= $index ?>);
                }
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
    })();

    // Periodic refresh of timestamp (every 30 seconds) even if image doesn't reload
    // Debounced: batched across all cameras to reduce requests

    setupWebcamRefresh(<?= $index ?>, <?= max(60, $perCam) ?>);
    <?php endforeach; ?>
    
    // Visibility change handling is now managed by timer-lifecycle.js
    // which pauses the timer worker on mobile when tab is hidden, and
    // calls forceRefreshAllTimers when tab becomes visible again
})();
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

// NOTAM Banner Management
let notamRefreshInterval = null;

/**
 * Format NOTAM time for display
 * 
 * @param {string} timeUtc ISO 8601 UTC time string
 * @param {string} timeLocal Local time string (from API)
 * @return {string} Formatted time string
 */
function formatNotamTime(timeUtc, timeLocal) {
    if (!timeUtc && !timeLocal) return '';
    
    // Use local time if available, otherwise parse UTC
    if (timeLocal) {
        try {
            const timeFormat = getTimeFormat();
            const dt = new Date(timeLocal);
            if (isNaN(dt.getTime())) {
                // Fallback to parsing UTC
                const dtUtc = new Date(timeUtc);
                const timezone = (AIRPORT_DATA && AIRPORT_DATA.timezone) || DEFAULT_TIMEZONE;
                return dtUtc.toLocaleString('en-US', {
                    timeZone: timezone,
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: timeFormat === '12hr'
                }) + ' ' + getTimezoneAbbreviation(dtUtc, timezone);
            }
            return dt.toLocaleString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: timeFormat === '12hr'
            }) + ' ' + (timeLocal.match(/\s([A-Z]{3,4})$/)?.[1] || '');
        } catch (e) {
            return timeLocal;
        }
    }
    
    // Parse UTC and convert to local
    try {
        const dt = new Date(timeUtc);
        const timezone = (AIRPORT_DATA && AIRPORT_DATA.timezone) || DEFAULT_TIMEZONE;
        const timeFormat = getTimeFormat();
        return dt.toLocaleString('en-US', {
            timeZone: timezone,
            hour: 'numeric',
            minute: '2-digit',
            hour12: timeFormat === '12hr'
        }) + ' ' + getTimezoneAbbreviation(dt, timezone);
    } catch (e) {
        return timeUtc;
    }
}

/**
 * Get timezone abbreviation
 * 
 * @param {Date} date Date object
 * @param {string} timezone Timezone identifier
 * @return {string} Timezone abbreviation
 */
function getTimezoneAbbreviation(date, timezone) {
    try {
        const formatter = new Intl.DateTimeFormat('en-US', {
            timeZone: timezone,
            timeZoneName: 'short'
        });
        const parts = formatter.formatToParts(date);
        const tzPart = parts.find(p => p.type === 'timeZoneName');
        return tzPart ? tzPart.value : '';
    } catch (e) {
        return '';
    }
}

/**
 * Fetch NOTAM data and update banner
 */
async function fetchAndUpdateNotamBanner() {
    if (!AIRPORT_ID) return;
    
    try {
        const baseUrl = window.location.protocol + '//' + window.location.host;
        const url = `${baseUrl}/api/notam.php?airport=${AIRPORT_ID}`;
        
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            console.warn('[NotamBanner] Failed to fetch NOTAMs:', response.status);
            return;
        }
        
        const data = await response.json();
        
        if (data.status !== 'success') {
            console.warn('[NotamBanner] Server returned error:', data.error);
            return;
        }
        
        updateNotamBanner(data.notams || []);
        
    } catch (error) {
        console.warn('[NotamBanner] Error fetching NOTAMs:', error);
    }
}

/**
 * Update NOTAM banner display
 * 
 * @param {Array} notams Array of NOTAM objects
 */
function updateNotamBanner(notams) {
    const container = document.getElementById('notam-banner-container');
    if (!container) return;
    
    // Filter for active and upcoming today
    const activeNotams = notams.filter(n => n.status === 'active');
    const upcomingNotams = notams.filter(n => n.status === 'upcoming_today');
    
    // Clear container
    container.innerHTML = '';
    
    // Show active NOTAMs first (red banner)
    if (activeNotams.length > 0) {
        const banner = createNotamBanner('active', activeNotams);
        container.appendChild(banner);
    }
    
    // Show upcoming NOTAMs (orange banner)
    if (upcomingNotams.length > 0) {
        const banner = createNotamBanner('upcoming', upcomingNotams);
        container.appendChild(banner);
    }
}

/**
 * Create NOTAM banner element
 * 
 * @param {string} type 'active' or 'upcoming'
 * @param {Array} notams Array of NOTAM objects
 * @return {HTMLElement} Banner element
 */
function createNotamBanner(type, notams) {
    const banner = document.createElement('div');
    banner.className = `notam-banner-${type}`;
    
    const isActive = type === 'active';
    const icon = isActive ? '🚨' : '⚠️';
    const statusText = isActive ? 'ACTIVE NOTAM' : 'NOTAM EFFECTIVE TODAY';
    const statusTextPlural = isActive ? 'ACTIVE NOTAMs' : 'NOTAMs EFFECTIVE TODAY';
    
    if (notams.length === 1) {
        const notam = notams[0];
        const startTime = formatNotamTime(notam.start_time_utc, notam.start_time_local);
        const endTime = notam.end_time_utc 
            ? formatNotamTime(notam.end_time_utc, notam.end_time_local)
            : 'until further notice';
        
        const timeRange = isActive 
            ? `from ${startTime} to ${endTime}`
            : `starting at ${startTime}`;
        
        banner.innerHTML = `
            <span class="notam-icon">${icon}</span>
            <span class="notam-status">${statusText}:</span>
            <span class="notam-message">${escapeHtml(notam.message)}</span>
            <span class="notam-time-range">${timeRange}</span>
            ${notam.official_link ? `<a href="${escapeHtml(notam.official_link)}" class="notam-link" target="_blank" rel="noopener noreferrer">View Full NOTAM</a>` : ''}
        `;
    } else {
        // Multiple NOTAMs - show summary with expandable details
        const summary = notams.map(n => {
            const shortMsg = n.message.length > 50 ? n.message.substring(0, 50) + '...' : n.message;
            return shortMsg;
        }).join(', ');
        
        banner.innerHTML = `
            <span class="notam-icon">${icon}</span>
            <span class="notam-status">${statusTextPlural}:</span>
            <span class="notam-message">${escapeHtml(summary)}</span>
            <button class="notam-expand" onclick="toggleNotamDetails(this)">View Details (${notams.length} NOTAMs)</button>
            <div class="notam-details" style="display: none;">
                ${notams.map((notam, idx) => {
                    const startTime = formatNotamTime(notam.start_time_utc, notam.start_time_local);
                    const endTime = notam.end_time_utc 
                        ? formatNotamTime(notam.end_time_utc, notam.end_time_local)
                        : 'until further notice';
                    const timeRange = isActive 
                        ? `from ${startTime} to ${endTime}`
                        : `starting at ${startTime}`;
                    const typeLabel = notam.type === 'aerodrome_closure' ? 'Aerodrome Closure' : 'TFR';
                    return `
                        <div class="notam-item">
                            <strong>${typeLabel}:</strong> ${escapeHtml(notam.message)} ${timeRange}
                            ${notam.official_link ? `<a href="${escapeHtml(notam.official_link)}" target="_blank" rel="noopener noreferrer">View NOTAM</a>` : ''}
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    }
    
    return banner;
}

/**
 * Toggle NOTAM details expansion
 * 
 * @param {HTMLElement} button Expand/collapse button
 */
function toggleNotamDetails(button) {
    const banner = button.closest('.notam-banner-active, .notam-banner-upcoming');
    if (!banner) return;
    
    const details = banner.querySelector('.notam-details');
    if (!details) return;
    
    const isExpanded = details.style.display !== 'none';
    details.style.display = isExpanded ? 'none' : 'block';
    button.textContent = isExpanded 
        ? `View Details (${details.querySelectorAll('.notam-item').length} NOTAMs)`
        : 'Hide Details';
}

/**
 * Escape HTML to prevent XSS
 * 
 * @param {string} text Text to escape
 * @return {string} Escaped text
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Initialize NOTAM banner
 */
function initializeNotamBanner() {
    // Initial fetch
    fetchAndUpdateNotamBanner();
    
    // Refresh every 3 minutes
    notamRefreshInterval = setInterval(fetchAndUpdateNotamBanner, 180000);
}

// Initialize NOTAM banner
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeNotamBanner);
} else {
    initializeNotamBanner();
}

<?php if (isset($airport['webcams']) && !empty($airport['webcams']) && count($airport['webcams']) > 0): ?>
// Batched timestamp refresh for all webcams (debounced to reduce requests)
let timestampBatchPending = false;
function batchRefreshAllTimestamps() {
    if (timestampBatchPending) return;
    timestampBatchPending = true;
    <?php foreach ($airport['webcams'] as $index => $cam): ?>
    const img<?= $index ?> = document.getElementById('webcam-<?= $index ?>');
    if (img<?= $index ?> && img<?= $index ?>.complete && img<?= $index ?>.naturalHeight !== 0) {
        updateWebcamTimestampOnLoad(<?= $index ?>);
    }
    <?php endforeach; ?>
    setTimeout(() => { timestampBatchPending = false; }, 1000);
}
// Refresh all timestamps every 30 seconds (batched)
// Delay initial call to ensure DOM is ready
setTimeout(() => {
    setInterval(batchRefreshAllTimestamps, 30000);
}, 1000);
<?php endif; ?>

// Fetch weather data using airport's configured refresh interval
// Fetch if weather_source is configured OR if metar_station is configured
const hasWeatherSource = AIRPORT_DATA && AIRPORT_DATA.weather_source && Object.keys(AIRPORT_DATA.weather_source).length > 0;
const hasMetarStation = AIRPORT_DATA && AIRPORT_DATA.metar_station;
if (hasWeatherSource || hasMetarStation) {
    // Calculate weather refresh interval from airport config
    const weatherRefreshMs = (AIRPORT_DATA && AIRPORT_DATA.weather_refresh_seconds) 
        ? AIRPORT_DATA.weather_refresh_seconds * 1000 
        : 60000; // Default 60 seconds

    // Display initial weather data immediately if available (from cache)
    if (typeof INITIAL_WEATHER_DATA !== 'undefined' && INITIAL_WEATHER_DATA !== null) {
        currentWeatherData = INITIAL_WEATHER_DATA;
        const refreshIntervalSeconds = (AIRPORT_DATA && AIRPORT_DATA.weather_refresh_seconds) 
            ? AIRPORT_DATA.weather_refresh_seconds 
            : 60;
        displayWeather(INITIAL_WEATHER_DATA);
        updateWindVisual(INITIAL_WEATHER_DATA);
        
        // Set initial timestamp from embedded data
        if (INITIAL_WEATHER_DATA.last_updated) {
            weatherLastUpdated = new Date(INITIAL_WEATHER_DATA.last_updated * 1000);
            updateWeatherTimestamp();
        }
        
        console.log('[Weather] Initial data displayed from cache');
    } else {
        // No initial weather data available - show empty fields
        displayEmptyWeather();
    }
    
    // Fetch immediately on page load (no delay - prioritize showing data quickly)
    // This will update the display with fresh data if available
    fetchWeather();
    
    // Register weather refresh with timer worker
    registerTimer('weather', weatherRefreshMs, function() {
        fetchWeather();
    });
    
    console.log('[Weather] Registered timer, interval:', (weatherRefreshMs / 1000) + 's');
}

// Track which images have already been handled to prevent infinite loops
const webcamErrorHandled = new Set();

/**
 * Check if a webcam image is stale and should show placeholder
 * Uses failclosed threshold - data too old to display
 * 
 * @param {number} camIndex - Camera index
 * @returns {boolean} True if webcam should show placeholder
 */
function isWebcamStale(camIndex) {
    const timestamp = CAM_TS[camIndex] || 0;
    if (timestamp <= 0) {
        return true; // No timestamp = stale
    }
    
    const now = Math.floor(Date.now() / 1000);
    const age = now - timestamp;
    
    return age >= STALE_FAILCLOSED_SECONDS;
}

// Handle webcam image load errors - show placeholder image
function handleWebcamError(camIndex, img) {
    // Prevent infinite loops - if we've already handled this error or it's a placeholder URL, stop
    const errorKey = `${camIndex}-${img.src}`;
    if (webcamErrorHandled.has(errorKey) || img.src.includes('cam=999')) {
        return; // Already handled or is placeholder - prevent infinite loop
    }
    
    webcamErrorHandled.add(errorKey);
    
    // Extract format and variant from image URL for logging
    const formatMatch = img.src.match(/[&?]fmt=([^&]+)/);
    const variantMatch = img.src.match(/[&?]size=([^&]+)/);
    const format = formatMatch ? formatMatch[1] : 'unknown';
    const variant = variantMatch ? variantMatch[1] : 'unknown';
    
    console.error(`[Webcam ${camIndex}] Image failed to load - format: ${format}, variant: ${variant}, URL: ${img.src}`);
    const skeleton = document.getElementById(`webcam-skeleton-${camIndex}`);
    if (skeleton) skeleton.style.display = 'none';
    
    // Show placeholder image instead of broken image
    // Use webcam.php endpoint with invalid cam index to trigger placeholder serving
    // This ensures placeholder is served via the same endpoint with proper fallbacks
    const protocol = (window.location.protocol === 'https:') ? 'https:' : 'http:';
    const host = window.location.host;
    const airportId = AIRPORT_ID || '';
    // Use cam index 999 which will be out of bounds and trigger servePlaceholder()
    const placeholderUrl = `${protocol}//${host}/webcam.php?id=${encodeURIComponent(airportId)}&cam=999`;
    
    // Remove onerror attribute to prevent infinite loop
    img.onerror = null;
    // Remove all error event listeners by cloning the node (preserves other attributes)
    const newImg = img.cloneNode(false);
    // Copy over important attributes
    if (img.id) newImg.id = img.id;
    if (img.className) newImg.className = img.className;
    if (img.dataset) {
        Object.keys(img.dataset).forEach(key => {
            newImg.dataset[key] = img.dataset[key];
        });
    }
    // Replace the node to remove all event listeners
    if (img.parentNode) {
        img.parentNode.replaceChild(newImg, img);
        newImg.src = placeholderUrl;
        // Mark placeholder URL as handled immediately
        webcamErrorHandled.add(`${camIndex}-${placeholderUrl}`);
    }
}

/**
 * Setup webcam refresh using the timer worker
 * 
 * Uses the centralized timer worker for reliable refresh that isn't
 * throttled in background tabs on desktop. Mobile devices pause when
 * the tab is hidden to conserve battery.
 * 
 * @param {number} camIndex Camera index
 * @param {number} baseInterval Refresh interval in seconds (minimum 60, typically 60-900)
 */
function setupWebcamRefresh(camIndex, baseInterval) {
    // Initialize last fetch tracking
    CAM_LAST_FETCH[camIndex] = Date.now();
    
    // First refresh: Immediate (user gets data quickly, handles stale images)
    safeSwapCameraImage(camIndex);
    
    // Register with timer worker
    const timerId = 'webcam-' + camIndex;
    const intervalMs = baseInterval * 1000;
    
    registerTimer(timerId, intervalMs, function() {
        CAM_LAST_FETCH[camIndex] = Date.now();
        safeSwapCameraImage(camIndex);
    });
    
    console.log('[Webcam] Registered timer for cam', camIndex, 'interval:', baseInterval + 's');
}

/**
 * Observe format and variant from picture element and store in localStorage
 * 
 * Extracts format and size from currentSrc URL and stores browser preferences.
 * Called on image load events to detect which format and variant browser selected.
 * 
 * @param {number} camIndex Camera index
 * @param {HTMLImageElement} img Image element
 */
function observeWebcamFormat(camIndex, img) {
    const currentSrc = img.currentSrc || img.src;
    const formatMatch = currentSrc.match(/[&?]fmt=([^&]+)/);
    const sizeMatch = currentSrc.match(/[&?]size=([^&]+)/);
    
    let detectedFormat = null;
    let detectedVariant = null;
    
    if (formatMatch) {
        const format = formatMatch[1].toLowerCase();
        if (['avif', 'webp', 'jpg', 'jpeg'].includes(format)) {
            detectedFormat = format === 'jpeg' ? 'jpg' : format;
        }
    }
    
    if (sizeMatch) {
        const size = sizeMatch[1].toLowerCase();
        // Support both old variant names and new height-based variants
        if (size === 'original' || !isNaN(parseInt(size))) {
            detectedVariant = size === 'original' ? 'original' : parseInt(size);
        } else if (['thumb', 'small', 'medium', 'large', 'primary', 'full'].includes(size)) {
            detectedVariant = size;
        }
    }
    
    // Log detected format and variant for debugging
    if (detectedFormat || detectedVariant) {
        const formatStr = detectedFormat || 'unknown';
        const variantStr = detectedVariant || 'unknown';
        console.log(`[Webcam ${camIndex}] Browser selected format: ${formatStr}, variant: ${variantStr}`);
    }
}

/**
 * Get preferred format for live webcam polling
 * 
 * Returns the best available modern format from server-enabled formats.
 * Priority: AVIF > WebP > JPEG (matching browser <picture> element behavior)
 * 
 * Note: This is used for live webcam polling. The history player and initial
 * webcam display use native <picture> elements for browser-based format selection.
 * 
 * @param {Array<string>} serverFormats Server-enabled formats (from mtime response)
 * @returns {string} Preferred format: 'avif', 'webp', or 'jpg'
 */
function getPreferredFormat(serverFormats) {
    // Return best available format (browser <picture> uses same priority)
    if (serverFormats && serverFormats.includes('avif')) {
        return 'avif';
    }
    if (serverFormats && serverFormats.includes('webp')) {
        return 'webp';
    }
    return 'jpg';
}

/**
 * Get preferred variant height based on actual element display size
 * 
 * Uses the actual rendered height of the webcam element to choose the appropriate
 * image size variant. Returns a height in pixels that should be used.
 * 
 * @param {string} context - Optional context: 'player' for history player, 
 *                           or defaults to grid view
 * @param {Array<number>} availableHeights - Optional array of available heights to choose from
 * @returns {number|string} Preferred height in pixels, or 'original' if display is very large
 */
function getPreferredVariant(context, availableHeights = null) {
    const dpr = window.devicePixelRatio || 1;
    let displayHeight = 0;
    
    // Try to get actual element height based on context
    if (context === 'player') {
        // Webcam history player - use the player container
        const playerContainer = document.querySelector('.webcam-player-image-container');
        if (playerContainer && playerContainer.clientHeight > 0) {
            displayHeight = playerContainer.clientHeight;
        }
    } else {
        // Grid view - use the first webcam container as reference
        // All webcam cards are the same size in the grid
        const webcamContainer = document.querySelector('.webcam-container');
        if (webcamContainer && webcamContainer.clientHeight > 0) {
            displayHeight = webcamContainer.clientHeight;
        }
    }
    
    // Fallback to viewport height if element not found or not rendered yet
    if (displayHeight === 0) {
        // Estimate height from viewport (assume 16:9 aspect ratio for estimation)
        const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
        displayHeight = Math.round(viewportWidth * 9 / 16);
    }
    
    // Effective height accounts for device pixel ratio (retina displays)
    // This ensures sharp images on high-DPI screens
    const effectiveHeight = displayHeight * dpr;
    
    // If available heights provided, choose closest match
    if (availableHeights && Array.isArray(availableHeights) && availableHeights.length > 0) {
        // Sort heights descending
        const sortedHeights = [...availableHeights].sort((a, b) => b - a);
        
        // Find smallest height that's >= effective height (with 1.5x headroom)
        const targetHeight = effectiveHeight * 1.5;
        for (const height of sortedHeights) {
            if (height >= targetHeight) {
                return height;
            }
        }
        
        // No height is large enough - return largest available or original
        return sortedHeights[0] >= effectiveHeight ? sortedHeights[0] : 'original';
    }
    
    // Default heights if not provided
    const defaultHeights = [1080, 720, 360];
    const targetHeight = effectiveHeight * 1.5;
    
    for (const height of defaultHeights) {
        if (height >= targetHeight) {
            return height;
        }
    }
    
    // Very large display - use original
    return 'original';
}

/**
 * Check if image is already rendered on page
 * 
 * @param {number} camIndex Camera index
 * @returns {boolean} True if image is loaded and visible
 */
function hasExistingImage(camIndex) {
    const img = document.getElementById(`webcam-${camIndex}`);
    if (!img) return false;
    
    // Image is loaded and visible
    if (img.complete && img.naturalHeight > 0) {
        return true;
    }
    
    // We have a timestamp (image was loaded before)
    if (CAM_TS[camIndex] && CAM_TS[camIndex] > 0) {
        return true;
    }
    
    return false;
}

/**
 * Calculate image hash for cache busting
 * 
 * @param {string} airportId Airport ID
 * @param {number} camIndex Camera index
 * @param {string} format Format: 'jpg', 'webp', or 'avif'
 * @param {number} timestamp Image timestamp
 * @param {number} size Image size
 * @returns {string} 8-character hex hash
 */
function calculateImageHash(airportId, camIndex, format, timestamp, size) {
    const hashInput = `${airportId}_${camIndex}_${format}_${timestamp}_${size || 0}`;
    let hash = 0;
    for (let i = 0; i < hashInput.length; i++) {
        const char = hashInput.charCodeAt(i);
        hash = ((hash << 5) - hash) + char;
        hash = hash & hash; // Convert to 32-bit integer
    }
    // Convert to hex string and take first 8 chars
    return Math.abs(hash).toString(16).padStart(8, '0').substring(0, 8);
}

/**
 * Update image silently (no user alerts)
 * 
 * @param {number} camIndex Camera index
 * @param {string} blobUrl Blob URL of image
 * @param {number} timestamp Image timestamp
 */
function updateImageSilently(camIndex, blobUrl, timestamp) {
    const img = document.getElementById(`webcam-${camIndex}`);
    if (img) {
        const oldSrc = img.src;
        
        // Clear <source> srcsets so browser uses blob URL instead of cached <picture> sources
        const picture = img.closest('picture');
        if (picture) {
            const sources = picture.querySelectorAll('source');
            sources.forEach(source => {
                source.srcset = '';
            });
        }
        
        img.src = blobUrl;
        
        if (timestamp) {
            img.dataset.initialTimestamp = timestamp.toString();
            img.setAttribute('data-ts', timestamp.toString()); // Set data-ts for timestamp comparison
            CAM_TS[camIndex] = timestamp;
        }
        
        // Cleanup old blob URL if it was a blob (after a delay to ensure new image loads)
        if (oldSrc.startsWith('blob:')) {
            setTimeout(() => {
                URL.revokeObjectURL(oldSrc);
            }, 100);
        }
        
        // Update timestamp display silently
        const timestampElem = document.getElementById(`webcam-timestamp-${camIndex}`);
        if (timestampElem && timestamp) {
            updateTimestampDisplay(timestampElem, timestamp);
        }
        
        // Hide skeleton
        const skeleton = document.getElementById(`webcam-skeleton-${camIndex}`);
        if (skeleton) skeleton.style.display = 'none';
    }
}

/**
 * Load image from URL
 * 
 * @param {string} url Image URL
 * @param {number} camIndex Camera index
 * @param {number} timestamp Image timestamp
 */
async function loadImageFromUrl(url, camIndex, timestamp) {
    try {
        const response = await fetch(url, {
            cache: 'no-store',
            credentials: 'same-origin'
        });
        
        if (response.status === 200) {
            const blob = await response.blob();
            const blobUrl = URL.createObjectURL(blob);
            updateImageSilently(camIndex, blobUrl, timestamp);
        }
    } catch (error) {
        // Silent error - user already has image or placeholder
    }
}

/**
 * Handle JPEG generating (aggressive backoff)
 * 
 * @param {number} camIndex Camera index
 * @param {boolean} hasExisting Whether image is already rendered
 * @param {object} data 202 response data
 */
async function handleJpegGenerating(camIndex, hasExisting, data) {
    const { fallback_url } = data;
    
    if (!hasExisting) {
        // Initial load: wait briefly (0.2s, 0.5s, 1s) then show placeholder
        const backoffs = [200, 500, 1000];
        let attempt = 0;
        let isComplete = false; // Guard to prevent multiple completions
        
        const tryLoad = async () => {
            // Check if we've exceeded attempts or already completed
            if (isComplete || attempt >= backoffs.length) {
                if (!isComplete) {
                    isComplete = true;
                    // Show placeholder via webcam.php endpoint
                    const img = document.getElementById(`webcam-${camIndex}`);
                    if (img) {
                        const protocol = (window.location.protocol === 'https:') ? 'https:' : 'http:';
                        const host = window.location.host;
                        img.src = `${protocol}//${host}/webcam.php?id=${encodeURIComponent(AIRPORT_ID)}&cam=999`;
                    }
                }
                return;
            }
            
            await new Promise(resolve => setTimeout(resolve, backoffs[attempt]));
            attempt++;
            
            try {
                const response = await fetch(fallback_url, {
                    cache: 'no-store',
                    credentials: 'same-origin'
                });
                
                if (response.status === 200) {
                    isComplete = true;
                    const blob = await response.blob();
                    const blobUrl = URL.createObjectURL(blob);
                    updateImageSilently(camIndex, blobUrl);
                    return;
                }
                
                if (response.status === 202) {
                    // Still generating - retry only if we haven't exceeded attempts
                    if (attempt < backoffs.length) {
                        tryLoad();
                    } else {
                        isComplete = true;
                        // Show placeholder
                        const img = document.getElementById(`webcam-${camIndex}`);
                        if (img) {
                            const protocol = (window.location.protocol === 'https:') ? 'https:' : 'http:';
                            const host = window.location.host;
                            img.src = `${protocol}//${host}/webcam.php?id=${encodeURIComponent(AIRPORT_ID)}&cam=999`;
                        }
                    }
                    return;
                }
            } catch (error) {
                // Retry only if we haven't exceeded attempts
                if (attempt < backoffs.length) {
                    tryLoad();
                } else {
                    isComplete = true;
                    // Show placeholder after all attempts failed
                    const img = document.getElementById(`webcam-${camIndex}`);
                    if (img) {
                        const protocol = (window.location.protocol === 'https:') ? 'https:' : 'http:';
                        const host = window.location.host;
                        img.src = `${protocol}//${host}/webcam.php?id=${encodeURIComponent(AIRPORT_ID)}&cam=999`;
                    }
                }
            }
        };
        
        tryLoad();
    } else {
        // Refresh: wait briefly (0.5s, 1s, 2s) then show last known image
        const backoffs = [500, 1000, 2000];
        let attempt = 0;
        let isComplete = false; // Guard to prevent multiple completions
        
        const tryLoad = async () => {
            // Check if we've exceeded attempts or already completed
            if (isComplete || attempt >= backoffs.length) {
                // Keep existing image (already rendered)
                return;
            }
            
            await new Promise(resolve => setTimeout(resolve, backoffs[attempt]));
            attempt++;
            
            try {
                const response = await fetch(fallback_url, {
                    cache: 'no-store',
                    credentials: 'same-origin'
                });
                
                if (response.status === 200) {
                    isComplete = true;
                    const blob = await response.blob();
                    const blobUrl = URL.createObjectURL(blob);
                    updateImageSilently(camIndex, blobUrl);
                    return;
                }
                
                if (response.status === 202) {
                    // Still generating - retry only if we haven't exceeded attempts
                    if (attempt < backoffs.length) {
                        tryLoad();
                    }
                    return;
                }
            } catch (error) {
                // Retry only if we haven't exceeded attempts
                if (attempt < backoffs.length) {
                    tryLoad();
                }
                // If all attempts failed, keep existing image (silent failure)
            }
        };
        
        tryLoad();
    }
}

/**
 * Cancel format retry for camera
 * 
 * @param {number} camIndex Camera index
 */
function cancelFormatRetry(camIndex) {
    if (!window.formatRetries) return;
    
    const retry = window.formatRetries.get(camIndex);
    if (retry) {
        if (retry.abortController) {
            retry.abortController.abort();
        }
        if (retry.timeoutId) {
            clearTimeout(retry.timeoutId);
        }
        window.formatRetries.delete(camIndex);
    }
}

/**
 * Start format retry with lightweight checks
 * 
 * Uses mtime endpoint to check format availability before requesting image.
 * Reduces unnecessary image requests.
 * 
 * @param {number} camIndex Camera index
 * @param {object} data 202 response data
 */
function startFormatRetry(camIndex, data) {
    // Cancel any existing retry for this camera
    cancelFormatRetry(camIndex);
    
    const { preferred_url, format, jpeg_timestamp, estimated_ready_seconds } = data;
    const variantMatch = preferred_url ? preferred_url.match(/[&?]size=([^&]+)/) : null;
    const variant = variantMatch ? variantMatch[1] : 'primary';
    
    console.log(`[Webcam ${camIndex}] Starting format retry - format: ${format}, variant: ${variant}, estimated: ${estimated_ready_seconds || 'unknown'}s`);
    
    const abortController = new AbortController();
    const maxWait = 10000; // 10 seconds max
    const startTime = Date.now();
    let retryCount = 0;
    
    if (!window.formatRetries) {
        window.formatRetries = new Map();
    }
    window.formatRetries.set(camIndex, { abortController, jpegTimestamp: jpeg_timestamp });
    
    const attemptRetry = async () => {
        // Check timeout
        if (Date.now() - startTime > maxWait) {
            window.formatRetries.delete(camIndex);
            return; // Silent timeout - user already has fallback
        }
        
        // Check if new cycle started (timestamp changed)
        try {
            const protocol = (window.location.protocol === 'https:') ? 'https:' : 'http:';
            const host = window.location.host;
            const mtimeResponse = await fetch(
                `${protocol}//${host}/webcam.php?id=${AIRPORT_ID}&cam=${camIndex}&mtime=1&_=${Date.now()}`,
                { 
                    signal: abortController.signal,
                    cache: 'no-store',
                    credentials: 'same-origin'
                }
            );
            
            if (!mtimeResponse.ok) {
                throw new Error('mtime check failed');
            }
            
            const mtimeData = await mtimeResponse.json();
            
            // New cycle started - cancel this retry
            if (mtimeData.timestamp && mtimeData.timestamp !== jpeg_timestamp) {
                window.formatRetries.delete(camIndex);
                return; // Silent cancellation
            }
            
            // Check if format is now available (lightweight check)
            if (mtimeData.formatReady && mtimeData.formatReady[format]) {
                // Format ready! Request the image
                const variantMatch = preferred_url.match(/[&?]size=([^&]+)/);
                const variant = variantMatch ? variantMatch[1] : 'primary';
                console.log(`[Webcam ${camIndex}] Format ready after retry - format: ${format}, variant: ${variant}`);
                
                try {
                    const imageResponse = await fetch(preferred_url, {
                        signal: abortController.signal,
                        cache: 'no-store',
                        credentials: 'same-origin'
                    });
                    
                    if (imageResponse.status === 200) {
                        // Success - upgrade image silently
                        const blob = await imageResponse.blob();
                        const blobUrl = URL.createObjectURL(blob);
                        console.log(`[Webcam ${camIndex}] Upgraded to preferred format - format: ${format}, variant: ${variant}`);
                        updateImageSilently(camIndex, blobUrl, mtimeData.timestamp);
                        window.formatRetries.delete(camIndex);
                        return;
                    }
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        // Network error - retry with backoff
                        console.warn(`[Webcam ${camIndex}] Retry failed - format: ${format}, variant: ${variant}, error: ${error.message}`);
                        scheduleNextRetry();
                    }
                    return;
                }
            }
            
            // Format not ready yet - schedule next check
            retryCount++;
            const variantMatch = preferred_url.match(/[&?]size=([^&]+)/);
            const variant = variantMatch ? variantMatch[1] : 'primary';
            console.log(`[Webcam ${camIndex}] Format not ready (retry ${retryCount}) - format: ${format}, variant: ${variant}`);
            scheduleNextRetry();
            
        } catch (error) {
            if (error.name === 'AbortError') {
                return; // Cancelled
            }
            // Error checking mtime - retry with backoff
            scheduleNextRetry();
        }
        
        function scheduleNextRetry() {
            // Fixed 5 second backoff
            const backoff = 5000;
            retryCount++;
            
            // Check if we'd exceed max wait
            if (Date.now() - startTime + backoff > maxWait) {
                window.formatRetries.delete(camIndex);
                return; // Silent timeout
            }
            
            const timeoutId = setTimeout(attemptRetry, backoff);
            const retry = window.formatRetries.get(camIndex);
            if (retry) {
                retry.timeoutId = timeoutId;
            }
        }
    };
    
    // Start first check after initial delay (5 seconds)
    const initialDelay = 5000;
    const timeoutId = setTimeout(attemptRetry, initialDelay);
    const retry = window.formatRetries.get(camIndex);
    if (retry) {
        retry.timeoutId = timeoutId;
    }
}

/**
 * Handle HTTP 202 response (format generating)
 * 
 * @param {number} camIndex Camera index
 * @param {object} data 202 response data
 * @param {boolean} hasExisting Whether image is already rendered
 * @param {number} jpegTimestamp JPEG timestamp
 */
async function handle202Response(camIndex, data, hasExisting, jpegTimestamp) {
    const { format, fallback_url, preferred_url, estimated_ready_seconds } = data;
    
    // Extract variant from URLs for logging
    const variantMatch = preferred_url ? preferred_url.match(/[&?]size=([^&]+)/) : null;
    const variant = variantMatch ? variantMatch[1] : 'primary';
    
    // Special case: JPEG is generating (our fallback)
    if (format === 'jpg') {
        console.warn(`[Webcam ${camIndex}] JPEG generating (fallback) - variant: ${variant}, estimated: ${estimated_ready_seconds || 'unknown'}s`);
        await handleJpegGenerating(camIndex, hasExisting, data);
        return;
    }
    
    // Preferred format (WebP/AVIF) is generating
    console.log(`[Webcam ${camIndex}] ${format.toUpperCase()} generating - variant: ${variant}, estimated: ${estimated_ready_seconds || 'unknown'}s, using fallback`);
    if (!hasExisting) {
        // Initial load: use fallback immediately, no waiting
        await loadImageFromUrl(fallback_url, camIndex, jpegTimestamp);
        return;
    }
    
    // Refresh: load fallback immediately, retry preferred format in background
    await loadImageFromUrl(fallback_url, camIndex, jpegTimestamp);
    startFormatRetry(camIndex, data);
}

/**
 * Load webcam image with 202 handling
 * 
 * @param {number} camIndex Camera index
 * @param {string} url Image URL (with explicit fmt= parameter)
 * @param {string} preferredFormat Preferred format
 * @param {boolean} hasExisting Whether image is already rendered
 * @param {number} jpegTimestamp JPEG timestamp from mtime endpoint
 */
async function loadWebcamImage(camIndex, url, preferredFormat, hasExisting, jpegTimestamp) {
    // Extract variant from URL for logging
    const variantMatch = url.match(/[&?]size=([^&]+)/);
    const requestedVariant = variantMatch ? variantMatch[1] : 'original';
    
    console.log(`[Webcam ${camIndex}] Requesting image - format: ${preferredFormat}, variant: ${requestedVariant}`);
    
    try {
        const response = await fetch(url, {
            cache: 'no-store',
            credentials: 'same-origin'
        });
        
        if (response.status === 200) {
            // Format ready - load immediately
            const blob = await response.blob();
            const blobUrl = URL.createObjectURL(blob);
            
            console.log(`[Webcam ${camIndex}] Image loaded successfully - format: ${preferredFormat}, variant: ${requestedVariant}`);
            updateImageSilently(camIndex, blobUrl, jpegTimestamp);
            CAM_LAST_FETCH[camIndex] = Date.now();
            return;
        }
        
        if (response.status === 202) {
            // Format generating
            const data = await response.json();
            console.log(`[Webcam ${camIndex}] Format generating (202) - format: ${preferredFormat}, variant: ${requestedVariant}, estimated: ${data.estimated_ready_seconds || 'unknown'}s`);
            await handle202Response(camIndex, data, hasExisting, jpegTimestamp);
            return;
        }
        
        throw new Error(`Unexpected status: ${response.status}`);
        
    } catch (error) {
        // Network error - use fallback
        console.error(`[Webcam ${camIndex}] Request failed - format: ${preferredFormat}, variant: ${requestedVariant}, error: ${error.message}`);
        handleRequestError(error, camIndex, hasExisting);
    }
}

/**
 * Handle request error
 * 
 * @param {Error} error Error object
 * @param {number} camIndex Camera index
 * @param {boolean} hasExisting Whether image is already rendered
 */
function handleRequestError(error, camIndex, hasExisting) {
    // Silent error handling - user already has image or placeholder
    if (!hasExisting) {
        // Initial load failed - show placeholder via webcam.php endpoint
        const img = document.getElementById(`webcam-${camIndex}`);
        if (img) {
            const protocol = (window.location.protocol === 'https:') ? 'https:' : 'http:';
            const host = window.location.host;
            img.src = `${protocol}//${host}/webcam.php?id=${encodeURIComponent(AIRPORT_ID)}&cam=999`;
        }
    }
    // If has existing image, keep it (silent failure)
}

/**
 * Safely swap camera image only when the backend has a newer image and the new image is loaded
 * 
 * @param {number} camIndex Camera index
 * @param {boolean} forceRefresh If true, bypass timestamp check and force image reload (useful after visibility change)
 */
function safeSwapCameraImage(camIndex, forceRefresh = false) {
    // Get current timestamp from CAM_TS, fallback to image data attribute, then 0
    // Note: We don't check staleness here - we fetch the new timestamp first,
    // then check staleness after we have the actual current timestamp from the API
    
    const imgEl = document.getElementById('webcam-' + camIndex);
    const imgDataTs = imgEl ? parseInt(imgEl.getAttribute('data-ts') || '0', 10) : 0;
    const currentTs = CAM_TS[camIndex] || imgDataTs || 0;

    const protocol = (window.location.protocol === 'https:') ? 'https:' : 'http:';
    const host = window.location.host;
    const mtimeUrl = `${protocol}//${host}/webcam.php?id=${AIRPORT_ID}&cam=${camIndex}&mtime=1&_=${Date.now()}`;
    
    fetch(mtimeUrl, { cache: 'no-store', credentials: 'same-origin' })
        .then(r => {
            if (!r.ok) {
                return Promise.reject(new Error(`HTTP ${r.status}`));
            }
            return r.json();
        })
        .then(json => {
            if (!json) return; // Invalid response
            
            // Parse new timestamp from API response
            // API returns 'timestamp' field (Unix epoch seconds)
            const newTs = parseInt(json.timestamp || json.mtime || '0', 10);
            
            // Check if we have a valid timestamp (even if success is false, we might have a timestamp)
            if (isNaN(newTs) || newTs === 0) {
                // No cache available - don't try to update
                console.log('[Webcam ' + camIndex + '] No image available (no timestamp)');
                return;
            }
            
            // Only update if timestamp is newer (strictly greater) OR if force refresh is requested
            // Force refresh is used when returning from background to verify image is displaying correctly
            if (newTs <= currentTs && !forceRefresh) {
                // Timestamp hasn't changed - backend hasn't updated yet, will retry on next interval
                // Still update last fetch time to show interval is working
                CAM_LAST_FETCH[camIndex] = Date.now();
                console.log('[Webcam ' + camIndex + '] No update - timestamp unchanged (' + new Date(newTs * 1000).toLocaleTimeString() + ')');
                return;
            }

            // Check if webcam is stale AFTER getting new timestamp
            // This ensures we check staleness on the actual current image, not the old cached timestamp
            // Update CAM_TS with new timestamp before checking staleness
            CAM_TS[camIndex] = newTs;
            
            if (isWebcamStale(camIndex)) {
                // Webcam is stale - show placeholder instead of loading stale image
                if (imgEl) {
                    imgEl.src = `${protocol}//${host}/webcam.php?id=${encodeURIComponent(AIRPORT_ID)}&cam=999`;
                }
                CAM_LAST_FETCH[camIndex] = Date.now();
                console.warn('[Webcam ' + camIndex + '] Stale image detected - showing placeholder');
                return; // Don't load stale image
            }

            const ready = json.formatReady || {};
            const hasExisting = hasExistingImage(camIndex);
            
            const serverFormats = (json.enabledFormats && Array.isArray(json.enabledFormats)) 
                ? json.enabledFormats 
                : ['jpg'];
            
            const preferredFormat = getPreferredFormat(serverFormats);
            // Get available variant heights from API response or extract from variants
            const variantHeights = json.variantHeights && Array.isArray(json.variantHeights) 
                ? json.variantHeights 
                : (json.variants ? Object.keys(json.variants)
                    .filter(v => v !== 'original' && !isNaN(parseInt(v)))
                    .map(v => parseInt(v))
                    .sort((a, b) => b - a) : [1080, 720, 360]);
            const preferredVariant = getPreferredVariant(null, variantHeights);
            
            // Build image URL with timestamp and variant parameters (immutable cache busting)
            // Format: /webcam.php?id={airport}&cam={index}&ts={timestamp}&fmt={format}&size={height|original}
            // This ensures automatic cache busting when timestamp changes
            const imageUrl = `${protocol}//${host}/webcam.php?id=${AIRPORT_ID}&cam=${camIndex}&ts=${newTs}&fmt=${preferredFormat}&size=${preferredVariant}`;
            
            // Log successful update
            console.log('[Webcam ' + camIndex + '] Updating - new image at ' + new Date(newTs * 1000).toLocaleTimeString() + ' (format: ' + preferredFormat + ', variant: ' + preferredVariant + ')');
            
            // Request preferred format (explicit fmt= triggers 202 if generating)
            loadWebcamImage(camIndex, imageUrl, preferredFormat, hasExisting, newTs);
        })
        .catch((err) => {
            // Enhanced error logging for diagnosis
            console.error('[Webcam ' + camIndex + '] Refresh failed:', err.name, '-', err.message);
            
            // If this was a force refresh (on visibility change), schedule a retry
            if (forceRefresh) {
                console.log('[Webcam ' + camIndex + '] Scheduling retry after force refresh failure...');
                setTimeout(() => {
                    safeSwapCameraImage(camIndex, true);
                }, 2000); // Retry after 2 seconds
            }
        });
}

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    // Cancel all format retries
    if (window.formatRetries) {
        for (const camIndex of window.formatRetries.keys()) {
            cancelFormatRetry(camIndex);
        }
    }
    
    // Clear webcam refresh intervals
    if (window.webcamRefreshIntervals) {
        for (const intervalId of window.webcamRefreshIntervals.values()) {
            clearInterval(intervalId);
        }
    }
});

// Airport Navigation Menu Functionality
(function() {
    'use strict';
    
    // Track which mode we're in: 'search' or 'nearby'
    let currentMode = null;
    let selectedIndex = -1;
    let searchTimeout = null;
    
    // Wait for DOM to be ready and AIRPORT_NAV_DATA to be available
    function initAirportNavigation() {
        // Only initialize if airport nav data is available
        if (!window.AIRPORT_NAV_DATA) {
            return;
        }
        
        const navData = window.AIRPORT_NAV_DATA;
        const searchInput = document.getElementById('airport-search');
        const nearbyBtn = document.getElementById('nearby-airports-btn');
        const airportDropdown = document.getElementById('airport-dropdown');
        
        if (!searchInput || !nearbyBtn || !airportDropdown) {
            return;
        }
        
        // Format distance based on user's distance unit preference
        function formatDistance(miles) {
            // Defensive check - ensure getDistanceUnit is available
            if (typeof getDistanceUnit !== 'function') {
                // Fallback to miles if getDistanceUnit isn't available yet
                return miles.toFixed(1) + ' mi';
            }
            const unit = getDistanceUnit();
            if (unit === 'm') {
                // Convert miles to kilometers
                const km = miles * 1.609344;
                return km.toFixed(1) + ' km';
            } else {
                return miles.toFixed(1) + ' mi';
            }
        }
        
        // Update distance displays when unit changes
        function updateDistanceDisplays() {
            const distanceElements = document.querySelectorAll('.airport-distance[data-distance-miles]');
            distanceElements.forEach(el => {
                const miles = parseFloat(el.dataset.distanceMiles);
                if (!isNaN(miles)) {
                    el.textContent = formatDistance(miles);
                }
            });
        }
        
        // Navigate to airport subdomain
        function navigateToAirport(airportId) {
            const protocol = window.location.protocol;
            const baseDomain = navData.baseDomain;
            const newUrl = `${protocol}//${airportId.toLowerCase()}.${baseDomain}`;
            window.location.href = newUrl;
        }
        
        // Unified function to populate dropdown with results from any source
        // Accepts an array of airport objects with: id, name, identifier, distance_miles (optional)
        function populateDropdown(items, mode) {
            currentMode = mode;
            airportDropdown.innerHTML = '';
            
            if (items.length === 0) {
                const noResults = document.createElement('div');
                noResults.className = 'nearby-airport-item no-results';
                noResults.textContent = mode === 'search' ? 'No airports found' : 'No airports within 200 miles';
                airportDropdown.appendChild(noResults);
            } else {
                items.forEach((item, index) => {
                const airportItem = document.createElement('a');
                airportItem.href = '#';
                airportItem.className = 'nearby-airport-item';
                airportItem.dataset.airportId = item.id || item.airportId;
                airportItem.dataset.index = index;
                
                const name = document.createElement('span');
                name.className = 'airport-name';
                name.textContent = item.name;
                
                const identifier = document.createElement('span');
                identifier.className = 'airport-identifier';
                identifier.textContent = item.identifier;
                
                airportItem.appendChild(name);
                airportItem.appendChild(identifier);
                
                // Add distance if available (works for both nearby airports and search results)
                if (item.distance_miles !== null && item.distance_miles !== undefined) {
                    const distance = document.createElement('span');
                    distance.className = 'airport-distance';
                    distance.dataset.distanceMiles = item.distance_miles;
                    distance.textContent = formatDistance(item.distance_miles);
                    airportItem.appendChild(distance);
                }
                
                airportItem.addEventListener('click', (e) => {
                    e.preventDefault();
                    const airportId = airportItem.dataset.airportId;
                    if (airportId) {
                        navigateToAirport(airportId);
                    }
                });
                
                airportItem.addEventListener('mouseenter', () => {
                    selectedIndex = index;
                    updateSelection();
                });
                
                    airportDropdown.appendChild(airportItem);
                });
            }
            
            airportDropdown.classList.add('show');
            selectedIndex = -1;
        }
        
        // Prepare data from nearby airports source
        function getNearbyAirportsData() {
            return navData.nearbyAirports.map(airport => ({
                id: airport.id,
                name: airport.name,
                identifier: airport.identifier,
                distance_miles: airport.distance_miles
            }));
        }
        
        // Prepare data from search source
        function getSearchResultsData(query) {
        if (!query || query.length < 2) {
            return [];
        }
        
        const queryLower = query.toLowerCase().trim();
        const results = [];
        
        for (const airport of navData.allAirports) {
            const nameMatch = airport.name.toLowerCase().includes(queryLower);
            const icaoMatch = airport.icao && airport.icao.toLowerCase().includes(queryLower);
            const iataMatch = airport.iata && airport.iata.toLowerCase().includes(queryLower);
            const faaMatch = airport.faa && airport.faa.toLowerCase().includes(queryLower);
            const identifierMatch = airport.identifier.toLowerCase().includes(queryLower);
            
            if (nameMatch || icaoMatch || iataMatch || faaMatch || identifierMatch) {
                results.push({
                    id: airport.id,
                    name: airport.name,
                    identifier: airport.identifier,
                    distance_miles: airport.distance_miles || null,
                    icao: airport.icao || null,
                    iata: airport.iata || null
                });
            }
        }
        
        // Sort: exact matches first, then by distance (if available), then by name
        results.sort((a, b) => {
            const aExact = a.identifier.toLowerCase() === queryLower || 
                          (a.icao && a.icao.toLowerCase() === queryLower) ||
                          (a.iata && a.iata.toLowerCase() === queryLower);
            const bExact = b.identifier.toLowerCase() === queryLower || 
                          (b.icao && b.icao.toLowerCase() === queryLower) ||
                          (b.iata && b.iata.toLowerCase() === queryLower);
            
            if (aExact && !bExact) return -1;
            if (!aExact && bExact) return 1;
            
            // If both have distances, sort by distance
            if (a.distance_miles !== null && a.distance_miles !== undefined &&
                b.distance_miles !== null && b.distance_miles !== undefined) {
                return a.distance_miles - b.distance_miles;
            }
            
            // Otherwise sort by name
            return a.name.localeCompare(b.name);
        });
        
            return results.slice(0, 10);
        }
        
        // Search functionality - uses unified data preparation and display
        function performSearch(query) {
            if (!query || query.length < 2) {
                airportDropdown.classList.remove('show');
                currentMode = null;
                return;
            }
            
            const results = getSearchResultsData(query);
            populateDropdown(results, 'search');
        }
        
        function updateSelection() {
            const items = airportDropdown.querySelectorAll('.nearby-airport-item');
            items.forEach((item, index) => {
                if (index === selectedIndex) {
                    item.style.background = '#3a3a3a';
                } else {
                    item.style.background = '';
                }
            });
        }
        
        // Display nearby airports - uses unified data preparation and display
        function displayNearbyAirports() {
            const nearby = getNearbyAirportsData();
            populateDropdown(nearby, 'nearby');
        }
        
        // Search input event handlers
    searchInput.addEventListener('input', (e) => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            performSearch(e.target.value);
        }, 200);
    });
    
    searchInput.addEventListener('focus', () => {
        // If there's a search query, show results
        if (searchInput.value.length >= 2) {
            performSearch(searchInput.value);
        }
    });
    
    searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const items = airportDropdown.querySelectorAll('.nearby-airport-item');
            if (items.length > 0) {
                selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                updateSelection();
                items[selectedIndex].scrollIntoView({ block: 'nearest' });
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const items = airportDropdown.querySelectorAll('.nearby-airport-item');
            if (items.length > 0) {
                selectedIndex = Math.max(selectedIndex - 1, 0);
                updateSelection();
                items[selectedIndex].scrollIntoView({ block: 'nearest' });
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const items = airportDropdown.querySelectorAll('.nearby-airport-item');
            if (selectedIndex >= 0 && selectedIndex < items.length) {
                const airportId = items[selectedIndex].dataset.airportId;
                if (airportId) {
                    navigateToAirport(airportId);
                }
            } else if (items.length === 1) {
                const airportId = items[0].dataset.airportId;
                if (airportId) {
                    navigateToAirport(airportId);
                }
            }
        } else if (e.key === 'Escape') {
            airportDropdown.classList.remove('show');
            searchInput.blur();
        }
    });
    
    // Nearby airports button functionality
    nearbyBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isActive = nearbyBtn.classList.contains('active');
        
        if (isActive) {
            nearbyBtn.classList.remove('active');
            airportDropdown.classList.remove('show');
            currentMode = null;
        } else {
            nearbyBtn.classList.add('active');
            displayNearbyAirports();
            // Update distances in case unit changed
            updateDistanceDisplays();
        }
    });
    
    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        if (!searchInput.contains(e.target) && 
            !nearbyBtn.contains(e.target) && 
            !airportDropdown.contains(e.target)) {
            airportDropdown.classList.remove('show');
            nearbyBtn.classList.remove('active');
            currentMode = null;
        }
    });
    
    // Update distances on page load
    updateDistanceDisplays();
    
    // Listen for distance unit changes (if the toggle exists)
    const distanceUnitToggle = document.getElementById('distance-unit-toggle');
    if (distanceUnitToggle) {
        // Use MutationObserver to detect when the toggle updates the display
        const observer = new MutationObserver(() => {
            updateDistanceDisplays();
        });
        
        const distanceUnitDisplay = document.getElementById('distance-unit-display');
        if (distanceUnitDisplay) {
            observer.observe(distanceUnitDisplay, { childList: true, characterData: true });
        }
        
        // Also listen for click events on the toggle
        distanceUnitToggle.addEventListener('click', () => {
            // Small delay to allow the unit to update
            setTimeout(updateDistanceDisplays, 100);
        });
    }
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAirportNavigation);
    } else {
        // DOM is already ready
        initAirportNavigation();
    }
})();
<?php
    // Capture and minify JavaScript
    $js = ob_get_clean();
    
    // SIMPLIFIED APPROACH: Always strip the opening <script> tag from the buffer
    // This ensures we never output the original opening tag, preventing duplicate tags
    // The buffer starts with <script> (from line 856) and contains JavaScript but no closing tag
    
    // Check for PHP errors (HTML document structure indicates PHP error output)
    $hasHtmlError = false;
    if (stripos($js, '<!DOCTYPE') !== false || stripos($js, '<html') !== false || stripos($js, '<body') !== false) {
        $hasHtmlError = true;
        error_log('PHP error detected in JavaScript output buffer - HTML document structure found');
    }
    
    if ($hasHtmlError) {
        // PHP error detected - output original and ensure closing tag
        // Simple check: if buffer starts with <script> and doesn't end with </script>, add closing tag
        if (preg_match('/^\s*<script[^>]*>/i', $js) && !preg_match('/<\/script>\s*$/i', $js)) {
            echo $js . '</script>';
        } else {
            echo $js;
        }
    } else {
        // Extract JavaScript content by stripping the opening <script> tag
        // Use regex to match and remove the opening tag (handles whitespace and attributes)
        $jsContent = preg_replace('/^\s*<script[^>]*>\s*/s', '', $js);
        
        // If regex didn't match, try simple strpos approach as fallback
        if ($jsContent === $js) {
            $scriptPos = strpos($js, '<script');
            if ($scriptPos !== false) {
                $tagEndPos = strpos($js, '>', $scriptPos);
                if ($tagEndPos !== false) {
                    $jsContent = substr($js, $tagEndPos + 1);
                }
            }
        }
        
        // Process and output the JavaScript content
        if ($jsContent !== null && trim($jsContent) !== '') {
            // Check if content is safe to minify (no HTML tags)
            $htmlInJs = preg_match('/<[a-z][\s>]/i', $jsContent) || strpos($jsContent, '<') !== false;
            
            if (!$htmlInJs) {
                // Safe to minify
                try {
                    $minified = minifyJavaScript($jsContent);
                    // Verify minified output is safe (doesn't contain HTML)
                    if (strpos($minified, '<') === false && !preg_match('/<[a-z][\s>]/i', $minified)) {
                        // Output complete script tag with minified content
                        echo '<script>' . $minified . '</script>';
                    } else {
                        // Minification produced HTML - use original content
                        echo '<script>' . $jsContent . '</script>';
                    }
                } catch (Exception $e) {
                    // Minification failed - use original content
                    error_log('JavaScript minification error: ' . $e->getMessage());
                    echo '<script>' . $jsContent . '</script>';
                }
            } else {
                // Content contains HTML - output as-is (don't minify)
                echo '<script>' . $jsContent . '</script>';
            }
        } else {
            // Extraction failed or content is empty - output buffer as-is and ensure closing tag
            // This should not happen in normal operation
            error_log('Script content extraction failed or empty - buffer length: ' . strlen($js));
            if (preg_match('/^\s*<script[^>]*>/i', $js) && !preg_match('/<\/script>\s*$/i', $js)) {
                echo $js . '</script>';
            } else {
                echo $js;
            }
        }
    }

?>

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
        <label for="webcam-player-timeline" class="visually-hidden">Timeline scrubber</label>
        <input type="range" 
               id="webcam-player-timeline" 
               class="webcam-player-timeline" 
               min="0" 
               max="0" 
               value="0"
               aria-label="Timeline - drag to navigate through history"
               title="Drag to scrub through 24-hour history">
        <div class="webcam-player-time-range" aria-hidden="true">
            <span id="webcam-player-time-start">--</span>
            <span id="webcam-player-time-end">--</span>
        </div>
        <div class="webcam-player-buttons" role="group" aria-label="Playback controls">
            <button class="webcam-player-btn" id="webcam-player-prev-btn" onclick="webcamPlayerPrev()" aria-label="Previous frame" title="Previous frame (← arrow key)">⏮</button>
            <button class="webcam-player-btn play" id="webcam-player-play-btn" onclick="webcamPlayerTogglePlay()" aria-label="Play or pause" title="Play/pause time-lapse (Space bar)">▶</button>
            <button class="webcam-player-btn" id="webcam-player-next-btn" onclick="webcamPlayerNext()" aria-label="Next frame" title="Next frame (→ arrow key)">⏭</button>
            <span class="webcam-player-btn-divider"></span>
            <button class="webcam-player-btn toggle" id="webcam-player-autoplay-btn" onclick="webcamPlayerToggleAutoplay()" aria-label="Toggle autoplay" title="Toggle continuous playback">🔄</button>
            <button class="webcam-player-btn toggle" id="webcam-player-hideui-btn" onclick="webcamPlayerToggleHideUI()" aria-label="Toggle fullscreen mode" title="Hide controls for full-screen view">⛶</button>
        </div>
    </div>
</div>

<!-- Timer lifecycle manager (deferred, non-blocking) -->
<script src="/public/js/timer-lifecycle.js?v=<?= $buildHashShort ?>" defer></script>
</body>
</html>


