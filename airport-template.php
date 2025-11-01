<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($airport['name']) ?> - <?= htmlspecialchars($airport['icao']) ?></title>
    <!-- Resource hints for external APIs -->
    <link rel="preconnect" href="https://swd.weatherflow.com" crossorigin>
    <link rel="preconnect" href="https://api.ambientweather.net" crossorigin>
    <link rel="preconnect" href="https://aviationweather.gov" crossorigin>
    <link rel="dns-prefetch" href="https://swd.weatherflow.com">
    <link rel="dns-prefetch" href="https://api.ambientweather.net">
    <link rel="dns-prefetch" href="https://aviationweather.gov">
    <?php
    // Use minified CSS if available, fallback to regular CSS
    $cssFile = file_exists(__DIR__ . '/styles.min.css') ? 'styles.min.css' : 'styles.css';
    ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($cssFile) ?>">
    <meta name="description" content="Real-time weather and conditions for <?= htmlspecialchars($airport['icao']) ?> - <?= htmlspecialchars($airport['name']) ?>">
    <script>
        // Register service worker for offline support
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js')
                    .then((registration) => {
                        console.log('[SW] Registered:', registration.scope);

                        // If there's a waiting SW, activate it immediately
                        if (registration.waiting) {
                            registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                        }

                        // Listen for updates; when installed and waiting, take over
                        registration.addEventListener('updatefound', () => {
                            const newWorker = registration.installing;
                            if (!newWorker) return;
                            newWorker.addEventListener('statechange', () => {
                                if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                    newWorker.postMessage({ type: 'SKIP_WAITING' });
                                }
                            });
                        });

                        // Auto-reload when new SW takes control
                        navigator.serviceWorker.addEventListener('controllerchange', () => {
                            window.location.reload();
                        });

                        // Check for updates every hour
                        setInterval(() => {
                            registration.update();
                        }, 3600000);
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
                                 onerror="console.error('Webcam image failed to load:', this.src); document.getElementById('webcam-skeleton-<?= $index ?>').style.display='none'"
                                 onload="const skel=document.getElementById('webcam-skeleton-<?= $index ?>'); if(skel) skel.style.display='none'"
                                 onclick="openLiveStream(this.src)">
                        </picture>
                        <div class="webcam-info">
                            <button class="live-btn" onclick="openLiveStream('<?= htmlspecialchars($cam['url']) ?>')">
                                View Source
                            </button>
                        </div>
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
                <a href="<?= htmlspecialchars($airport['airnav_url']) ?>" target="_blank" rel="noopener" class="btn" style="margin-right: 1rem;">
                    View on AirNav
                </a>
                <a href="https://skyvector.com/airport/<?= htmlspecialchars(strtoupper($airport['icao'])) ?>" target="_blank" rel="noopener" class="btn" style="margin-right: 1rem;">
                    View on SkyVector
                </a>
                <a href="https://www.aopa.org/destinations/airports/<?= htmlspecialchars(strtoupper($airport['icao'])) ?>" target="_blank" rel="noopener" class="btn" style="margin-right: 1rem;">
                    View on AOPA
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
                    View on FAA Weather
                </a>
            </div>
        </section>

        <!-- Current Time -->
        <section class="time-section">
            <div class="time-grid">
                <div class="time-item">
                    <span class="label">Local Time:</span>
                    <span class="value" id="localTime">--:--:--</span> <span style="font-size: 0.85rem; color: #666;">PDT</span>
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
                <a href="https://aviationwx.org">aviationwx.org</a>
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
    const localTime = now.toLocaleTimeString('en-US', { hour12: false });
    document.getElementById('localTime').textContent = localTime;
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
    
    const timeStr = diffSeconds >= 3600 ? 'Over an hour stale.' : formatRelativeTime(diffSeconds);
    const weatherEl = document.getElementById('weather-last-updated');
    const windEl = document.getElementById('wind-last-updated');
    weatherEl.textContent = timeStr;
    windEl.textContent = timeStr;
    const stale = diffSeconds >= 3600;
    [weatherEl, windEl].forEach(el => { el.style.color = stale ? '#c00' : '#666'; });
}

// Fetch weather data
async function fetchWeather() {
    try {
        // Use absolute path to ensure it works from subdomains
        const baseUrl = window.location.protocol + '//' + window.location.host;
        const url = `${baseUrl}/weather.php?airport=${AIRPORT_ID}`;
        
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
            },
            credentials: 'same-origin'
        });
        
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
            currentWeatherData = data.weather; // Store globally for toggle re-rendering
            displayWeather(data.weather);
            updateWindVisual(data.weather);
            weatherLastUpdated = data.weather.last_updated ? new Date(data.weather.last_updated * 1000) : new Date();
            updateWeatherTimestamp(); // Update the timestamp
        } else {
            console.error('[Weather] API returned error:', data.error);
            displayError(data.error || 'Failed to fetch weather data');
        }
    } catch (error) {
        console.error('[Weather] Fetch error:', error);
        console.error('[Weather] Error stack:', error.stack);
        displayError('Unable to load weather data: ' + error.message + '. Check browser console for details.');
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
            <div class="weather-item"><span class="label">Visibility</span><span class="weather-value">${weather.visibility !== null ? weather.visibility.toFixed(1) : '--'}</span><span class="weather-unit">${weather.visibility !== null ? 'SM' : ''}</span>${weather.visibility !== null && (weather.obs_time || weather.last_updated_metar) ? formatTempTimestamp(weather.obs_time || weather.last_updated_metar) : ''}</div>
            <div class="weather-item"><span class="label">Ceiling</span><span class="weather-value">${weather.ceiling !== null ? weather.ceiling : (weather.visibility !== null ? 'Unlimited' : '--')}</span><span class="weather-unit">${weather.ceiling !== null ? 'ft AGL' : ''}</span>${(weather.ceiling !== null || weather.visibility !== null) && (weather.obs_time || weather.last_updated_metar) ? formatTempTimestamp(weather.obs_time || weather.last_updated_metar) : ''}</div>
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

function updateWindVisual(weather) {
    const canvas = document.getElementById('windCanvas');
    const ctx = canvas.getContext('2d');
    const cx = canvas.width / 2, cy = canvas.height / 2, r = Math.min(canvas.width, canvas.height) / 2 - 20;
    
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    // Draw outer circle
    ctx.strokeStyle = '#333'; ctx.lineWidth = 2; ctx.beginPath(); ctx.arc(cx, cy, r, 0, 2 * Math.PI); ctx.stroke();
    
    // Draw runways as full-length lines with labels
    RUNWAYS.forEach(rw => {
        const heading1 = rw.heading_1;
        const heading2 = rw.heading_2;
        const angle1 = (heading1 * Math.PI) / 180;
        const angle2 = (heading2 * Math.PI) / 180;
        const runwayLength = r * 0.9;
        
        // Draw runway 1
        ctx.strokeStyle = '#0066cc'; ctx.lineWidth = 8; ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(cx - Math.sin(angle1) * runwayLength / 2, cy + Math.cos(angle1) * runwayLength / 2);
        ctx.lineTo(cx + Math.sin(angle1) * runwayLength / 2, cy - Math.cos(angle1) * runwayLength / 2);
        ctx.stroke();
        
        // Label runway ends (take first 2 digits, zero-padded)
        // Place labels on the approach side (opposite from where we're looking at the runway)
        ctx.fillStyle = '#0066cc'; ctx.font = 'bold 14px sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        const labelAngle1 = angle1;
        const labelDist = runwayLength / 2 + 15;
        const heading1Str = Math.floor(heading1 / 10).toString().padStart(2, '0');
        // Invert to place on approach side (opposite direction)
        ctx.fillText(heading1Str, cx - Math.sin(labelAngle1) * labelDist, cy + Math.cos(labelAngle1) * labelDist);
        
        // Draw runway 2 (opposite end)
        ctx.strokeStyle = '#0066cc'; ctx.lineWidth = 8;
        ctx.beginPath();
        ctx.moveTo(cx - Math.sin(angle2) * runwayLength / 2, cy + Math.cos(angle2) * runwayLength / 2);
        ctx.lineTo(cx + Math.sin(angle2) * runwayLength / 2, cy - Math.cos(angle2) * runwayLength / 2);
        ctx.stroke();
        
        const heading2Str = Math.floor(heading2 / 10).toString().padStart(2, '0');
        // Invert to place on approach side (opposite direction)
        ctx.fillText(heading2Str, cx - Math.sin(angle2) * labelDist, cy + Math.cos(angle2) * labelDist);
    });
    
    // Draw wind only if speed > 0
    const ws = weather.wind_speed || 0;
    const wd = weather.wind_direction || 0;
    
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
            <span style="font-weight: bold;">${wd > 0 ? wd + '°' : '--'}</span>
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
    
    if (ws > 1) {
        // Store for animation
        windDirection = (wd * Math.PI) / 180;
        windSpeed = ws;
        
        // Draw wind arrow
        drawWindArrow(ctx, cx, cy, r, windDirection, windSpeed, 0);
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
    
    // Also listen for error events - don't check timestamp if image failed
    imgEl<?= $index ?>.addEventListener('error', () => {
        // Don't update timestamp if image failed to load
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
            if (!json || !json.success || !json.timestamp) return; // Nothing to do
            const newTs = parseInt(json.timestamp);
            if (isNaN(newTs) || newTs <= currentTs) return; // Not newer

            const ready = json.formatReady || {};
            // Use immutable hash from mtime for CDN-friendly URLs (hash changes only when file updates)
            const hash = json.timestamp ? String(json.timestamp).slice(-8) : Date.now().toString().slice(-8);
            const jpgUrl = `${protocol}//${host}/webcam.php?id=${AIRPORT_ID}&cam=${camIndex}&fmt=jpg&v=${hash}`;
            const webpUrl = `${protocol}//${host}/webcam.php?id=${AIRPORT_ID}&cam=${camIndex}&fmt=webp&v=${hash}`;

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
            }).catch(() => {
                // Hide skeleton on failure
                if (skeleton) skeleton.style.display = 'none';
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


