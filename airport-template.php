<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($airport['name']) ?> - <?= htmlspecialchars($airport['icao']) ?></title>
    <link rel="stylesheet" href="styles.css">
    <meta name="description" content="Real-time weather and conditions for <?= htmlspecialchars($airport['icao']) ?> - <?= htmlspecialchars($airport['name']) ?>">
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
                        <img id="webcam-<?= $index ?>" 
                             src="webcam.php?id=<?= urlencode($airportId) ?>&cam=<?= $index ?>" 
                             alt="<?= htmlspecialchars($cam['name']) ?>"
                             class="webcam-image"
                             onerror="this.src='placeholder.jpg'"
                             onclick="openLiveStream(this.src)">
                        <div class="webcam-info">
                            <span class="last-updated">Last updated: <span id="update-<?= $index ?>" data-timestamp="<?php 
                                $cacheFile = __DIR__ . '/cache/webcams/' . $airportId . '_' . $index . '.jpg';
                                echo file_exists($cacheFile) ? filemtime($cacheFile) : '0';
                            ?>">--</span></span>
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
            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
                <h2>Current Conditions</h2>
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
            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
                <h2>Runway Wind</h2>
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
                    View on AirNav
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
    
    const timeStr = formatRelativeTime(diffSeconds);
    document.getElementById('weather-last-updated').textContent = timeStr;
    document.getElementById('wind-last-updated').textContent = timeStr;
}

// Fetch weather data
async function fetchWeather() {
    try {
        const response = await fetch(`weather.php?airport=${AIRPORT_ID}`);
        const data = await response.json();
        if (data.success) {
            displayWeather(data.weather);
            updateWindVisual(data.weather);
            weatherLastUpdated = new Date(); // Record when data was fetched
            updateWeatherTimestamp(); // Update the timestamp
        } else {
            displayError(data.error || 'Failed to fetch weather data');
        }
    } catch (error) {
        console.error('Error fetching weather:', error);
        displayError('Unable to load weather data');
    }
}

function displayWeather(weather) {
    // Determine weather emojis based on conditions
    function getWeatherEmojis(weather) {
        const emojis = [];
        const tempF = weather.temperature_f;
        const precip = weather.precip_accum || 0;
        const windSpeed = weather.wind_speed || 0;
        
        // Temperature emoji
        if (tempF !== null) {
            if (tempF > 80) emojis.push('🔥'); // Hot
            else if (tempF > 50) emojis.push('☀️'); // Warm/Sunny
            else if (tempF > 32) emojis.push('🌤️'); // Cool
            else emojis.push('❄️'); // Freezing
        }
        
        // Precipitation emoji
        if (precip > 0.01) {
            if (tempF < 32) emojis.push('❄️'); // Snow
            else emojis.push('🌧️'); // Rain
        }
        
        // Wind emoji
        if (windSpeed > 20) emojis.push('💨'); // Strong wind
        else if (windSpeed > 10) emojis.push('🌬️'); // Moderate wind
        
        // Cloud emoji based on ceiling
        if (weather.ceiling !== null) {
            if (weather.ceiling < 1000) emojis.push('☁️'); // Overcast
            else if (weather.ceiling < 3000) emojis.push('🌥️'); // Broken
            else if (weather.cloud_cover === 'SCT') emojis.push('⛅'); // Scattered
            else emojis.push('☀️'); // Clear or few clouds
        } else if (weather.cloud_cover) {
            switch (weather.cloud_cover) {
                case 'OVC':
                case 'OVX':
                    emojis.push('☁️');
                    break;
                case 'BKN':
                    emojis.push('🌥️');
                    break;
                case 'SCT':
                    emojis.push('⛅');
                    break;
                case 'FEW':
                    emojis.push('🌤️');
                    break;
            }
        }
        
        return emojis.length > 0 ? emojis.join(' ') : '🌡️';
    }
    
    const weatherEmojis = getWeatherEmojis(weather);
    
    const container = document.getElementById('weather-data');
    
    container.innerHTML = `
        <!-- Current Status -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Condition</span><span class="weather-value ${weather.flight_category_class}">${weather.flight_category}</span></div>
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
            <div class="weather-item"><span class="label">Today's High</span><span class="weather-value">${weather.temp_high_today ? Math.round((weather.temp_high_today * 9/5) + 32) : '--'}</span><span class="weather-unit">°F</span></div>
            <div class="weather-item"><span class="label">Current Temperature</span><span class="weather-value">${weather.temperature_f || '--'}</span><span class="weather-unit">°F</span></div>
            <div class="weather-item"><span class="label">Today's Low</span><span class="weather-value">${weather.temp_low_today ? Math.round((weather.temp_low_today * 9/5) + 32) : '--'}</span><span class="weather-unit">°F</span></div>
        </div>
        
        <!-- Moisture & Precipitation -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Dewpoint Spread</span><span class="weather-value">${weather.dewpoint_spread !== null ? weather.dewpoint_spread.toFixed(1) : '--'}</span><span class="weather-unit">°C</span></div>
            <div class="weather-item"><span class="label">Dewpoint</span><span class="weather-value">${weather.dewpoint_f || '--'}</span><span class="weather-unit">°F</span></div>
            <div class="weather-item"><span class="label">Rainfall Today</span><span class="weather-value">${weather.precip_accum > 0 ? weather.precip_accum.toFixed(2) : '0.00'}</span><span class="weather-unit">in</span></div>
        </div>
        
        <!-- Visibility & Ceiling -->
        <div class="weather-group">
            <div class="weather-item">
                <div class="label" style="display: block;">Weather</div>
                <div class="weather-value" style="font-size: 1.5rem;">${weatherEmojis}</div>
            </div>
            <div class="weather-item"><span class="label">Visibility</span><span class="weather-value">${weather.visibility !== null ? weather.visibility.toFixed(1) : '--'}</span><span class="weather-unit">${weather.visibility !== null ? 'SM' : ''}</span></div>
            <div class="weather-item"><span class="label">Ceiling</span><span class="weather-value">${weather.ceiling !== null ? weather.ceiling : (weather.visibility !== null ? 'Unlimited' : '--')}</span><span class="weather-unit">${weather.ceiling !== null ? 'ft AGL' : ''}</span></div>
        </div>
        
        <!-- Pressure & Altitude -->
        <div class="weather-group">
            <div class="weather-item"><span class="label">Pressure</span><span class="weather-value">${weather.pressure ? weather.pressure.toFixed(2) : '--'}</span><span class="weather-unit">inHg</span></div>
            <div class="weather-item"><span class="label">Pressure Altitude</span><span class="weather-value">${weather.pressure_altitude || '--'}</span><span class="weather-unit">ft</span></div>
            <div class="weather-item"><span class="label">Density Altitude</span><span class="weather-value">${weather.density_altitude || '--'}</span><span class="weather-unit">ft</span></div>
        </div>
    `;
}

function displayError(msg) {
    document.getElementById('weather-data').innerHTML = `<div class="weather-item loading">${msg}</div>`;
}

function formatTemp(c) {
    if (c === null || c === undefined) return '--';
    return Math.round((c * 9/5) + 32);
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
    
    windDetails.innerHTML = `
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e0e0e0;">
            <span style="color: #666;">Wind Speed:</span>
            <span style="font-weight: bold;">${ws > 0 ? Math.round(ws) + ' kts' : 'Calm'}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e0e0e0;">
            <span style="color: #666;">Wind Direction:</span>
            <span style="font-weight: bold;">${wd > 0 ? wd + '°' : '--'}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e0e0e0;">
            <span style="color: #666;">Gust Factor:</span>
            <span style="font-weight: bold;">${gustFactor > 0 ? gustFactor + ' kts' : '0'}</span>
        </div>
        <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
            <span style="color: #666;">Today's Peak Gust:</span>
            <span style="font-weight: bold;">${todaysPeakGust > 0 ? Math.round(todaysPeakGust) + ' kts' : '--'}</span>
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

// Update webcam timestamps
function updateWebcamTimestamps() {
    <?php foreach ($airport['webcams'] as $index => $cam): ?>
    const timestamp<?= $index ?> = document.getElementById('update-<?= $index ?>')?.dataset.timestamp;
    if (timestamp<?= $index ?> && timestamp<?= $index ?> !== '0') {
        const updateDate = new Date(parseInt(timestamp<?= $index ?>) * 1000);
        const now = new Date();
        const diffSeconds = Math.floor((now - updateDate) / 1000);
        let timeStr = '';
        
        if (diffSeconds < 60) {
            timeStr = diffSeconds + ' seconds ago';
        } else if (diffSeconds < 3600) {
            timeStr = Math.floor(diffSeconds / 60) + ' minutes ago';
        } else if (diffSeconds < 86400) {
            timeStr = Math.floor(diffSeconds / 3600) + ' hours ago';
        } else {
            timeStr = updateDate.toLocaleString();
        }
        
        const elem = document.getElementById('update-<?= $index ?>');
        if (elem) elem.textContent = timeStr;
    }
    <?php endforeach; ?>
}

// Function to reload webcam images with cache busting
function reloadWebcamImages() {
    <?php foreach ($airport['webcams'] as $index => $cam): ?>
    const img<?= $index ?> = document.getElementById('webcam-<?= $index ?>');
    if (img<?= $index ?>) {
        const oldSrc = img<?= $index ?>.src.split('&t=')[0]; // Remove old cache buster
        img<?= $index ?>.src = oldSrc + '&t=' + Date.now();
    }
    <?php endforeach; ?>
}

// Update relative timestamps every 10 seconds for better responsiveness
updateWebcamTimestamps();
setInterval(updateWebcamTimestamps, 10000); // Update every 10 seconds

// Reload webcam images every 60 seconds to get fresh images
setInterval(reloadWebcamImages, 60000);

updateWeatherTimestamp();
setInterval(updateWeatherTimestamp, 10000); // Update relative time every 10 seconds

// Fetch weather data every minute
fetchWeather();
setInterval(fetchWeather, 60000);
</script>
</body>
</html>

