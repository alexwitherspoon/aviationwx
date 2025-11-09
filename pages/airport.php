<?php
// Load SEO utilities and config (for getGitSha function)
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/seo.php';

// SEO variables - emphasize live webcams and runway conditions
$webcamCount = isset($airport['webcams']) ? count($airport['webcams']) : 0;
$webcamText = $webcamCount > 0 ? $webcamCount . ' live webcam' . ($webcamCount > 1 ? 's' : '') . ' and ' : '';
$pageTitle = htmlspecialchars($airport['name']) . ' (' . htmlspecialchars($airport['icao']) . ') - Live Webcams & Runway Conditions';
$pageDescription = 'Live webcams and real-time runway conditions for ' . htmlspecialchars($airport['name']) . ' (' . htmlspecialchars($airport['icao']) . '). ' . $webcamText . 'current weather, wind, visibility, and aviation metrics. Free for pilots.';
$pageKeywords = htmlspecialchars($airport['icao']) . ', ' . htmlspecialchars($airport['name']) . ', live airport webcam, runway conditions, ' . htmlspecialchars($airport['icao']) . ' weather, airport webcam, pilot weather, aviation weather';
$airportUrl = 'https://' . $airportId . '.aviationwx.org';
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
    <title><?= $pageTitle ?></title>
    
    <?php
    // Favicon and icon tags
    echo generateFaviconTags();
    echo "\n    ";
    ?>
    
    <!-- Resource hints for external APIs -->
    <link rel="preconnect" href="https://swd.weatherflow.com" crossorigin>
    <link rel="preconnect" href="https://api.ambientweather.net" crossorigin>
    <link rel="preconnect" href="https://aviationweather.gov" crossorigin>
    <link rel="dns-prefetch" href="https://swd.weatherflow.com">
    <link rel="dns-prefetch" href="https://api.ambientweather.net">
    <link rel="dns-prefetch" href="https://aviationweather.gov">
    
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
    // Use minified CSS if available, fallback to regular CSS
    $cssFile = file_exists(__DIR__ . '/../public/css/styles.min.css') ? 'public/css/styles.min.css' : 'public/css/styles.css';
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($cssFile) ?>">
    <script>
        // Suppress Safari warning about deprecated window.styleMedia
        // Safari warns when window.styleMedia exists, even if not used
        // We use window.matchMedia instead (the modern API) for media queries
        // This script ensures we never access the deprecated property
        (function() {
            // Override styleMedia to prevent Safari warnings
            // Note: We don't use styleMedia anywhere - we use matchMedia for media queries
            if (typeof window.styleMedia !== 'undefined') {
                try {
                    // Try to delete the property (may not work in all browsers)
                    delete window.styleMedia;
                } catch (e) {
                    // If deletion fails, override it to prevent warnings
                    Object.defineProperty(window, 'styleMedia', {
                        get: function() {
                            // Return null instead of the deprecated object
                            return null;
                        },
                        configurable: true,
                        enumerable: false
                    });
                }
            }
        })();
        
        // Register service worker for offline support with cache busting
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
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
    <div class="container">
        <!-- Header -->
        <header class="header">
            <h1><?= htmlspecialchars($airport['name']) ?> (<?= htmlspecialchars($airport['icao']) ?>)</h1>
            <h2 style="font-size: 1.2rem; color: #666; margin-top: 0.25rem; font-weight: normal;"><?= htmlspecialchars($airport['address']) ?></h2>
            <p style="font-style: italic; font-size: 0.85rem; color: #666; margin-top: 0.5rem;">Data is for advisory use only. Consult official weather sources for flight planning purposes.</p>
        </header>

        <!-- Webcams -->
        <section class="webcam-section">
            <div class="webcam-grid">
                <?php foreach ($airport['webcams'] as $index => $cam): ?>
                <div class="webcam-item">
                    <h3><?= htmlspecialchars($cam['name']) ?></h3>
                    <div class="webcam-container">
                        <div id="webcam-skeleton-<?= $index ?>" class="webcam-skeleton" style="background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%); background-size: 200% 100%; animation: skeleton-loading 1.5s ease-in-out infinite; width: 100%; height: 300px; border-radius: 4px; position: absolute; top: 0; left: 0; z-index: 1;"></div>
                        <picture style="position: relative; z-index: 2;">
                            <?php
                            // Generate cache-friendly immutable hash from mtime (for CDN compatibility)
                            $base = __DIR__ . '/cache/webcams/' . $airportId . '_' . $index;
                            $mtimeJpg = 0;
                            $sizeJpg = 0;
                            foreach (['.jpg', '.webp'] as $ext) {
                                $filePath = $base . $ext;
                                if (file_exists($filePath)) {
                                    $mtimeJpg = filemtime($filePath);
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
                                 alt="<?= htmlspecialchars($cam['name']) ?>"
                                 class="webcam-image"
                                 loading="lazy"
                                 decoding="async"
                                 onerror="handleWebcamError(<?= $index ?>, this)"
                                 onload="const skel=document.getElementById('webcam-skeleton-<?= $index ?>'); if(skel) skel.style.display='none'"
                                 onclick="openLiveStream(this.src)">
                        </picture>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Weather Data -->
        <section class="weather-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem;">
                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <h2 style="margin: 0;">Current Conditions</h2>
                    <button id="temp-unit-toggle" style="background: #f5f5f5; border: 1px solid #ccc; border-radius: 6px; padding: 0.5rem 1rem; cursor: pointer; font-size: 0.9rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; color: #333; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.1); min-width: 50px; height: auto;" title="Toggle temperature unit (F/C)" onmouseover="this.style.background='#e8e8e8'; this.style.borderColor='#999';" onmouseout="this.style.background='#f5f5f5'; this.style.borderColor='#ccc';">
                        <span id="temp-unit-display">°F</span>
                    </button>
                    <button id="distance-unit-toggle" style="background: #f5f5f5; border: 1px solid #ccc; border-radius: 6px; padding: 0.5rem 1rem; cursor: pointer; font-size: 0.9rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; color: #333; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.1); min-width: 50px; height: auto;" title="Toggle distance unit (ft/m)" onmouseover="this.style.background='#e8e8e8'; this.style.borderColor='#999';" onmouseout="this.style.background='#f5f5f5'; this.style.borderColor='#ccc';">
                        <span id="distance-unit-display">ft</span>
                    </button>
                </div>
                <p style="font-size: 0.85rem; color: #666; margin: 0;">Last updated: <span id="weather-last-updated">--</span></p>
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
                <p style="font-size: 0.85rem; color: #666; margin: 0;">Last updated: <span id="wind-last-updated">--</span></p>
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

        <!-- Airport Information -->
        <section class="airport-info">
            <div class="info-grid">
                <div class="info-item">
                    <span class="label">ICAO:</span>
                    <span class="value"><?= htmlspecialchars($airport['icao']) ?></span>
                </div>
                <div class="info-item">
                    <span class="label">Elevation:</span>
                    <span class="value"><?= $airport['elevation_ft'] ?> ft</span>
                </div>
                <?php if ($airport['services']['fuel_available']): ?>
                <div class="info-item">
                    <span class="label">Fuel:</span>
                    <span class="value"><?= $airport['services']['100ll'] ? '100LL' : '' ?><?= ($airport['services']['100ll'] && $airport['services']['jet_a']) ? ', ' : '' ?><?= $airport['services']['jet_a'] ? 'Jet-A' : '' ?></span>
                </div>
                <?php endif; ?>
                <?php if ($airport['services']['repairs_available']): ?>
                <div class="info-item">
                    <span class="label">Repairs:</span>
                    <span class="value">Available</span>
                </div>
                <?php endif; ?>
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
                <a href="<?= htmlspecialchars($airport['airnav_url']) ?>" target="_blank" rel="noopener" class="btn">
                    AirNav
                </a>
                <a href="https://skyvector.com/airport/<?= htmlspecialchars(strtoupper($airport['icao'])) ?>" target="_blank" rel="noopener" class="btn">
                    SkyVector
                </a>
                <a href="https://www.aopa.org/destinations/airports/<?= htmlspecialchars(strtoupper($airport['icao'])) ?>" target="_blank" rel="noopener" class="btn">
                    AOPA
                </a>
                <?php
                // Generate FAA Weather Cams URL
                // URL format: https://weathercams.faa.gov/map/{min_lon},{min_lat},{max_lon},{max_lat}/airport/{icao}/
                // Create bounding box around airport (2 degree buffer for visibility)
                $buffer = 2.0;
                $min_lon = $airport['lon'] - $buffer;
                $min_lat = $airport['lat'] - $buffer;
                $max_lon = $airport['lon'] + $buffer;
                $max_lat = $airport['lat'] + $buffer;
                // Remove K prefix from ICAO if present (e.g., KSPB -> SPB)
                $faa_icao = preg_replace('/^K/', '', strtoupper($airport['icao']));
                $faa_weather_url = sprintf(
                    'https://weathercams.faa.gov/map/%.5f,%.5f,%.5f,%.5f/airport/%s/',
                    $min_lon,
                    $min_lat,
                    $max_lon,
                    $max_lat,
                    $faa_icao
                );
                ?>
                <a href="<?= htmlspecialchars($faa_weather_url) ?>" target="_blank" rel="noopener" class="btn">
                    FAA Weather
                </a>
            </div>
        </section>

        <!-- Current Time -->
        <section class="time-section">
            <div class="time-grid">
                <div class="time-item">
                    <span class="label">Local Time:</span>
                    <span class="value" id="localTime">--:--:--</span> <span id="localTimezone" style="font-size: 0.85rem; color: #666;">--</span>
                </div>
                <div class="time-item">
                    <span class="label">Zulu Time:</span>
                    <span class="value" id="zuluTime">--:--:--</span> <span style="font-size: 0.85rem; color: #666;">UTC</span>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="footer">
            <p>
                &copy; <?= date('Y') ?> <a href="https://aviationwx.org">AviationWX.org</a> | 
                <a href="https://aviationwx.org#about-the-project">Built for pilots, by pilots</a> | 
                <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener">Open Source<?php $gitSha = getGitSha(); echo $gitSha ? ' - ' . htmlspecialchars($gitSha) : ''; ?></a> | 
                <a href="https://status.aviationwx.org">Status</a>
            </p>
            <p>
                <?php
                // Collect unique weather data sources by name
                $weatherSourcesNames = [];
                
                // Add primary weather source
                switch ($airport['weather_source']['type']) {
                    case 'tempest':
                        $weatherSourcesNames['Tempest Weather'] = '<a href="https://tempestwx.com" target="_blank" rel="noopener">Tempest Weather</a>';
                        break;
                    case 'ambient':
                        $weatherSourcesNames['Ambient Weather'] = '<a href="https://ambientweather.net" target="_blank" rel="noopener">Ambient Weather</a>';
                        break;
                    case 'metar':
                        $weatherSourcesNames['Aviation Weather'] = '<a href="https://aviationweather.gov" target="_blank" rel="noopener">Aviation Weather</a>';
                        break;
                }
                
                // Add METAR source if using Tempest or Ambient (since we supplement with METAR)
                // Only add if not already using METAR as primary source
                if (!isset($weatherSourcesNames['Aviation Weather']) && in_array($airport['weather_source']['type'], ['tempest', 'ambient'])) {
                    $weatherSourcesNames['Aviation Weather'] = '<a href="https://aviationweather.gov" target="_blank" rel="noopener">Aviation Weather</a>';
                }
                
                // Collect unique webcam partners
                $webcamPartners = [];
                if (!empty($airport['webcams'])) {
                    foreach ($airport['webcams'] as $cam) {
                        if (isset($cam['partner_name'])) {
                            $key = $cam['partner_name'];
                            // Only add once (deduplicate)
                            if (!isset($webcamPartners[$key])) {
                                if (isset($cam['partner_link'])) {
                                    $webcamPartners[$key] = '<a href="' . htmlspecialchars($cam['partner_link']) . '" target="_blank" rel="noopener">' . htmlspecialchars($cam['partner_name']) . '</a>';
                                } else {
                                    $webcamPartners[$key] = htmlspecialchars($cam['partner_name']);
                                }
                            }
                        }
                    }
                }
                
                // Format footer credits
                echo 'Weather data from ' . implode(' & ', $weatherSourcesNames);
                if (!empty($webcamPartners)) {
                    echo ' | Webcams in Partnership with ' . implode(' & ', $webcamPartners);
                }
                ?>
            </p>
        </footer>
    </div>

    <script>
// Airport page JavaScript
const AIRPORT_ID = '<?= $airportId ?>';
const AIRPORT_DATA = <?= json_encode($airport) ?>;
const RUNWAYS = <?= json_encode($airport['runways']) ?>;

// Production logging removed - only log errors in console

// Update clocks
function updateClocks() {
    const now = new Date();
    
    // Get airport timezone, default to 'America/Los_Angeles' if not available
    const timezone = (AIRPORT_DATA && AIRPORT_DATA.timezone) || 'America/Los_Angeles';
    
    // Format local time in airport's timezone
    const localTimeOptions = {
        timeZone: timezone,
        hour12: false,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    };
    const localTime = now.toLocaleTimeString('en-US', localTimeOptions);
    document.getElementById('localTime').textContent = localTime;
    
    // Get timezone abbreviation and UTC offset (e.g., PST, PDT, EST, EDT)
    // Use Intl.DateTimeFormat for reliable timezone abbreviation
    try {
        const formatter = new Intl.DateTimeFormat('en-US', {
            timeZone: timezone,
            timeZoneName: 'short'
        });
        const parts = formatter.formatToParts(now);
        const timezonePart = parts.find(part => part.type === 'timeZoneName');
        const timezoneAbbr = timezonePart ? timezonePart.value : '--';
        
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

// Temperature unit preference (default to F)
function getTempUnit() {
    const unit = localStorage.getItem('aviationwx_temp_unit');
    return unit || 'F'; // Default to Fahrenheit
}

function setTempUnit(unit) {
    localStorage.setItem('aviationwx_temp_unit', unit);
}

// Convert Celsius to Fahrenheit
function cToF(c) {
    return Math.round((c * 9/5) + 32);
}

// Convert Fahrenheit to Celsius
function fToC(f) {
    return Math.round((f - 32) * 5/9);
}

// Format temperature based on current unit preference
function formatTemp(tempC) {
    if (tempC === null || tempC === undefined) return '--';
    const unit = getTempUnit();
    return unit === 'C' ? Math.round(tempC) : cToF(tempC);
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

// Format timestamp as "at h:m:am/pm" using airport's timezone
// Returns HTML with styling matching weather-unit class
function formatTempTimestamp(timestamp) {
    if (timestamp === null || timestamp === undefined) return '';
    
    try {
        // Get airport timezone, default to 'America/Los_Angeles' if not available
        const timezone = (AIRPORT_DATA && AIRPORT_DATA.timezone) || 'America/Los_Angeles';
        
        // Create date from timestamp (assumes UTC seconds)
        const date = new Date(timestamp * 1000);
        
        // Format in airport's local timezone
        const options = {
            timeZone: timezone,
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        };
        
        const formatted = date.toLocaleTimeString('en-US', options);
        
        // Return formatted time with "at" prefix and same styling as weather-unit
        return ` <span style="font-size: 0.9rem; color: #666;">at ${formatted}</span>`;
    } catch (error) {
        console.error('[TempTimestamp] Error formatting timestamp:', error);
        return '';
    }
}

// Distance/altitude unit preference (default to imperial/feet)
function getDistanceUnit() {
    const unit = localStorage.getItem('aviationwx_distance_unit');
    return unit || 'ft'; // Default to feet
}

function setDistanceUnit(unit) {
    localStorage.setItem('aviationwx_distance_unit', unit);
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
    if (inches === null || inches === undefined) return '0.00';
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
    const unit = localStorage.getItem('aviationwx_wind_speed_unit');
    return unit || 'kts'; // Default to knots
}

function setWindSpeedUnit(unit) {
    localStorage.setItem('aviationwx_wind_speed_unit', unit);
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
    
    function formatRelativeTime(seconds) {
        if (seconds < 60) {
            return seconds + ' seconds ago';
        } else if (seconds < 3600) {
            return Math.floor(seconds / 60) + ' minutes ago';
        } else if (seconds < 86400) {
            return Math.floor(seconds / 3600) + ' hours ago';
        } else {
            return Math.floor(seconds / 86400) + ' days ago';
        }
    }
    
    // Solution C: Enhanced visual indicators for stale data
    // Show warnings earlier (20 minutes) and more aggressive styling
    const isStale = diffSeconds >= 1200; // 20 minutes - earlier warning
    const isVeryStale = diffSeconds >= 3600; // 1 hour - critical warning
    
    let timeStr;
    if (isVeryStale) {
        timeStr = '⚠️ Over an hour stale - data may be outdated';
    } else if (isStale) {
        timeStr = '⚠️ ' + formatRelativeTime(diffSeconds) + ' - refreshing...';
    } else {
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
        
        // Check if existing data is stale (>20 minutes old)
        // If so, force a refresh to bypass cache
        const shouldForceRefresh = forceRefresh || (weatherLastUpdated !== null && (Date.now() - weatherLastUpdated.getTime()) > 20 * 60 * 1000);
        
        // Use absolute path to ensure it works from subdomains
        const baseUrl = window.location.protocol + '//' + window.location.host;
        let url = `${baseUrl}/weather.php?airport=${AIRPORT_ID}`;
        
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
            
            // If server indicates data is stale, schedule a fresh fetch soon (30 seconds)
            if (isStale) {
                console.log('[Weather] Received stale data from server - scheduling refresh in 30 seconds');
                // Clear any existing stale refresh timer
                if (window.staleRefreshTimer) {
                    clearTimeout(window.staleRefreshTimer);
                }
                // Schedule a refresh with cache bypass in 30 seconds
                window.staleRefreshTimer = setTimeout(() => {
                    fetchWeather(true); // Force refresh
                }, 30000);
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
        <!-- Current Status -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Condition</span><span class="weather-value ${weather.flight_category_class || ''}">${weather.flight_category || '---'} ${weather.flight_category ? weatherEmojis : ''}</span></div>
            <div class="weather-item sunrise-sunset">
                <span style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-size: 1.2rem;">🌅</span>
                    <span class="label">Sunrise</span>
                </span>
                <span class="weather-value">${weather.sunrise || '--'} <span style="font-size: 0.75rem; color: #666;">PDT</span></span>
            </div>
            <div class="weather-item sunrise-sunset">
                <span style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-size: 1.2rem;">🌇</span>
                    <span class="label">Sunset</span>
                </span>
                <span class="weather-value">${weather.sunset || '--'} <span style="font-size: 0.75rem; color: #666;">PDT</span></span>
            </div>
        </div>
        
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
        
        <!-- Visibility & Ceiling -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Rainfall Today</span><span class="weather-value">${formatRainfall(weather.precip_accum)}</span><span class="weather-unit">${getDistanceUnit() === 'm' ? 'cm' : 'in'}</span></div>
            <div class="weather-item"><span class="label">Visibility</span><span class="weather-value">${formatVisibility(weather.visibility)}</span><span class="weather-unit">${weather.visibility !== null ? (getDistanceUnit() === 'm' ? 'km' : 'SM') : ''}</span>${weather.visibility !== null && (weather.obs_time_metar || weather.obs_time || weather.last_updated_metar) ? formatTempTimestamp(weather.obs_time_metar || weather.obs_time || weather.last_updated_metar) : ''}</div>
            <div class="weather-item"><span class="label">Ceiling</span><span class="weather-value">${weather.ceiling !== null ? formatCeiling(weather.ceiling) : (weather.visibility !== null ? 'Unlimited' : '--')}</span><span class="weather-unit">${weather.ceiling !== null ? (getDistanceUnit() === 'm' ? 'm AGL' : 'ft AGL') : ''}</span>${(weather.ceiling !== null || weather.visibility !== null) && (weather.obs_time_metar || weather.obs_time || weather.last_updated_metar) ? formatTempTimestamp(weather.obs_time_metar || weather.obs_time || weather.last_updated_metar) : ''}</div>
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
    const ws = weather.wind_speed || 0;
    const wd = weather.wind_direction;
    const isVariableWind = wd === 'VRB' || wd === 'vrb';
    const windDirNumeric = typeof wd === 'number' && wd > 0 ? wd : null;
    
    // Get today's peak gust from server
    const todaysPeakGust = weather.peak_gust_today || 0;
    
    // Populate wind details section
    const windDetails = document.getElementById('wind-details');
    const gustFactor = weather.gust_factor || 0;
    
    const windUnitLabel = getWindSpeedUnitLabel();
    windDetails.innerHTML = `
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e0e0e0;">
            <span style="color: #666;">Wind Speed:</span>
            <span style="font-weight: bold;">${ws > 0 ? formatWindSpeed(ws) + ' ' + windUnitLabel : 'Calm'}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e0e0e0;">
            <span style="color: #666;">Wind Direction:</span>
            <span style="font-weight: bold;">${isVariableWind ? 'VRB' : (windDirNumeric ? windDirNumeric + '°' : '--')}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e0e0e0;">
            <span style="color: #666;">Gust Factor:</span>
            <span style="font-weight: bold;">${gustFactor > 0 ? formatWindSpeed(gustFactor) + ' ' + windUnitLabel : '0'}</span>
        </div>
        <div style="padding: 0.5rem 0; border-bottom: 1px solid #e0e0e0;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                <span style="color: #666;">Today's Peak Gust:</span>
                <span style="font-weight: bold;">${todaysPeakGust > 0 ? formatWindSpeed(todaysPeakGust) + ' ' + windUnitLabel : '--'}</span>
            </div>
            ${weather.peak_gust_time ? `<div style="text-align: right; font-size: 0.9rem; color: #666; padding-left: 0.5rem;">at ${new Date(weather.peak_gust_time * 1000).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})}</div>` : ''}
        </div>
    `;
    
    if (ws > 1 && !isVariableWind && windDirNumeric !== null) {
        // Store for animation (only if we have a valid numeric direction)
        windDirection = (windDirNumeric * Math.PI) / 180;
        windSpeed = ws;
        
        // Draw wind arrow
        drawWindArrow(ctx, cx, cy, r, windDirection, windSpeed, 0);
    } else if (ws > 1 && isVariableWind) {
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

// Update webcam timestamps (called periodically to refresh relative time display)
function updateWebcamTimestamps() {
    <?php foreach ($airport['webcams'] as $index => $cam): ?>
    const timestamp<?= $index ?> = document.getElementById('update-<?= $index ?>')?.dataset.timestamp;
    if (timestamp<?= $index ?> && timestamp<?= $index ?> !== '0') {
        const updateDate = new Date(parseInt(timestamp<?= $index ?>) * 1000);
        const now = new Date();
        const diffSeconds = Math.floor((now - updateDate) / 1000);
        
        const elem = document.getElementById('update-<?= $index ?>');
        if (elem) {
            elem.textContent = formatRelativeTime(diffSeconds);
        }
    }
    <?php endforeach; ?>
}

// Function to reload webcam images with cache busting
function reloadWebcamImages() {
    <?php foreach ($airport['webcams'] as $index => $cam): ?>
    safeSwapCameraImage(<?= $index ?>);
    <?php endforeach; ?>
}

// Update relative timestamps every 10 seconds for better responsiveness
updateWebcamTimestamps();
setInterval(updateWebcamTimestamps, 10000); // Update every 10 seconds

// Debounce timestamps per camera to avoid multiple fetches when all formats load
const timestampCheckPending = {};
const timestampCheckRetries = {}; // Track retry attempts
const CAM_TS = {}; // In-memory timestamps per camera (no UI field)

// Helper to format relative time
function formatRelativeTime(seconds) {
    // Handle edge cases
    if (isNaN(seconds) || seconds < 0) {
        return '--';
    }
    
    if (seconds < 60) {
        return seconds + ' seconds ago';
    } else if (seconds < 3600) {
        return Math.floor(seconds / 60) + ' minutes ago';
    } else if (seconds < 86400) {
        return Math.floor(seconds / 3600) + ' hours ago';
    } else {
        return Math.floor(seconds / 86400) + ' days ago';
    }
}

// Helper to update timestamp display
function updateTimestampDisplay(elem, timestamp) {
    if (!timestamp) return;
    
    const updateDate = new Date(timestamp * 1000);
    const now = new Date();
    const diffSeconds = Math.floor((now - updateDate) / 1000);
    
    if (elem) {
        elem.textContent = formatRelativeTime(diffSeconds);
        elem.dataset.timestamp = timestamp.toString();
    }
    CAM_TS[lastCamIndexForElem(elem)] = timestamp; // best-effort record
}

function lastCamIndexForElem(elem) {
    if (!elem || !elem.id) return undefined;
    const m = elem.id.match(/^update-(\d+)$/);
    return m ? parseInt(m[1]) : undefined;
}

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
                const newTimestamp = parseInt(data.timestamp);
                const currentTimestamp = CAM_TS[camIndex] ? parseInt(CAM_TS[camIndex]) : (elem ? parseInt(elem.dataset.timestamp || '0') : 0);
                // Only update if timestamp is newer
                if (newTimestamp > currentTimestamp || retryCount > 0) {
                    updateTimestampDisplay(elem, newTimestamp);
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

// Reload webcam images using per-camera intervals
<?php foreach ($airport['webcams'] as $index => $cam): 
    $defaultWebcamRefresh = 60;
    $airportWebcamRefresh = isset($airport['webcam_refresh_seconds']) ? intval($airport['webcam_refresh_seconds']) : $defaultWebcamRefresh;
    $perCam = isset($cam['refresh_seconds']) ? intval($cam['refresh_seconds']) : $airportWebcamRefresh;
?>
// Setup image load handlers for camera <?= $index ?>
// Note: For picture elements, only the final <img> fires load events
const imgEl<?= $index ?> = document.getElementById('webcam-<?= $index ?>');
if (imgEl<?= $index ?>) {
    // Check timestamp on initial load (images may already be cached)
    if (imgEl<?= $index ?>.complete && imgEl<?= $index ?>.naturalHeight !== 0) {
        // Image already loaded, check timestamp immediately
        setTimeout(() => updateWebcamTimestampOnLoad(<?= $index ?>), 100);
    } else {
        // Image not loaded yet, wait for load event
        imgEl<?= $index ?>.addEventListener('load', () => {
            updateWebcamTimestampOnLoad(<?= $index ?>);
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

updateWeatherTimestamp();
setInterval(updateWeatherTimestamp, 10000); // Update relative time every 10 seconds

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

// Fetch weather data every minute
fetchWeather();
setInterval(fetchWeather, 60000);

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
    const timestampElem = document.getElementById(`update-${camIndex}`); // may be null
    const currentTs = CAM_TS[camIndex] ? parseInt(CAM_TS[camIndex]) : (timestampElem ? parseInt(timestampElem.dataset.timestamp || '0') : 0);

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
            
            // Only update if timestamp is newer
            if (newTs <= currentTs) return; // Not newer

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
                    if (skeleton) skeleton.style.display = 'none';
                }
                CAM_TS[camIndex] = newTs;
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
</script>
</body>
</html>


