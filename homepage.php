<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AviationWX.org - Real-time Aviation Weather</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        /* Smooth scrolling for anchor links */
        html {
            scroll-behavior: smooth;
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
        }
        .feature-card {
            background: #f8f9fa;
            padding: 2rem;
            border-radius: 8px;
            border-left: 4px solid #0066cc;
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
            color: #666;
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
            color: #666;
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
            color: #155724;
        }
        .flight-condition.mvfr {
            background: #fff3cd;
            color: #856404;
        }
        .flight-condition.ifr {
            background: #f8d7da;
            color: #721c24;
        }
        .flight-condition.lifr {
            background: #d1ecf1;
            color: #0c5460;
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
            color: #666;
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
            color: #666;
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
            color: #666;
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
        code {
            background: #f4f4f4;
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
            font-family: monospace;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="hero">
            <h1>✈️ AviationWX.org</h1>
            <p>Real-time weather and conditions for participating airports</p>
            <p class="subtitle">Get instant access to weather data, webcams, and aviation metrics at airports across the network.</p>
        </div>

        <!-- Stats -->
        <?php
        $configFile = __DIR__ . '/airports.json';
        $totalAirports = 0;
        $totalWebcams = 0;
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
            if (isset($config['airports'])) {
                $totalAirports = count($config['airports']);
                foreach ($config['airports'] as $airport) {
                    if (isset($airport['webcams'])) {
                        $totalWebcams += count($airport['webcams']);
                    }
                }
            }
        }
        ?>
        
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
                <div class="stat-number">3</div>
                <div class="stat-label">Weather Sources</div>
            </div>
        </div>

        <section>
            <h2>Why AviationWX?</h2>
            <p>AviationWX provides real-time, localized weather data specifically designed for pilots making flight decisions. Each airport dashboard includes:</p>
            
            <div class="features">
                <div class="feature-card">
                    <h3>🌡️ Real-Time Weather</h3>
                    <p>Live data from on-site weather stations including Tempest, Ambient Weather, or METAR observations.</p>
                </div>
                
                <div class="feature-card">
                    <h3>📹 Multiple Webcams</h3>
                    <p>Visual conditions with strategically positioned webcams showing current airport conditions.</p>
                </div>
                
                <div class="feature-card">
                    <h3>🧭 Wind Visualization</h3>
                    <p>Interactive runway wind diagram showing wind speed, direction, and crosswind components.</p>
                </div>
                
                <div class="feature-card">
                    <h3>✈️ Aviation Metrics</h3>
                    <p>Density altitude, pressure altitude, VFR/IFR status, and other critical pilot information.</p>
                </div>
                
                <div class="feature-card">
                    <h3>📊 Current Conditions</h3>
                    <p>Temperature, humidity, visibility, ceiling, precipitation, and more—all in one place.</p>
                </div>
                
                <div class="feature-card">
                    <h3>⏰ Local & Zulu Time</h3>
                    <p>Dual time display with sunrise/sunset times for proper flight planning.</p>
                </div>
                
                <div class="feature-card">
                    <h3>📱 Mobile & Desktop Friendly</h3>
                    <p>Lightweight, quick-loading website optimized for both mobile devices and desktops. Fast access to weather data when you need it most.</p>
                </div>
                
                <div class="feature-card">
                    <h3>🆓 Free for All</h3>
                    <p>No fees, no subscriptions, no ads, and no apps needed. Access all weather data and webcams completely free—just visit the website in your browser.</p>
                </div>
            </div>
        </section>

        <section id="participating-airports">
            <h2>Participating Airports</h2>
            <?php if ($totalAirports > 0 && file_exists($configFile)): ?>
            <?php
            $config = json_decode(file_get_contents($configFile), true);
            $airports = isset($config['airports']) ? $config['airports'] : [];
            $airportsPerPage = 9;
            $currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $totalPages = max(1, ceil(count($airports) / $airportsPerPage));
            $currentPage = min($currentPage, $totalPages);
            $startIndex = ($currentPage - 1) * $airportsPerPage;
            $airportsOnPage = array_slice($airports, $startIndex, $airportsPerPage, true);
            
            // Function to fetch weather data for an airport
            function getAirportWeather($airportId) {
                $cacheFile = __DIR__ . '/cache/weather_' . $airportId . '.json';
                if (file_exists($cacheFile)) {
                    $cacheData = json_decode(file_get_contents($cacheFile), true);
                    // Cache file stores weather data directly (not wrapped in 'weather' key)
                    if ($cacheData && is_array($cacheData)) {
                        return $cacheData;
                    }
                }
                return null;
            }
            
            // Function to format relative time
            function formatRelativeTime($timestamp) {
                if (!$timestamp || $timestamp <= 0) return 'Unknown';
                $diff = time() - $timestamp;
                if ($diff < 60) return 'Just now';
                if ($diff < 3600) return floor($diff / 60) . 'm ago';
                if ($diff < 86400) return floor($diff / 3600) . 'h ago';
                return floor($diff / 86400) . 'd ago';
            }
            
            // Function to get newest timestamp from displayed data
            function getNewestDataTimestamp($weather) {
                if (!$weather) return null;
                $timestamps = [];
                
                // Temperature comes from primary source
                if (isset($weather['temperature_f']) || isset($weather['temperature'])) {
                    if (isset($weather['last_updated_primary']) && $weather['last_updated_primary'] > 0) {
                        $timestamps[] = $weather['last_updated_primary'];
                    }
                }
                
                // Wind comes from primary source
                if (isset($weather['wind_speed']) && $weather['wind_speed'] !== null) {
                    if (isset($weather['last_updated_primary']) && $weather['last_updated_primary'] > 0) {
                        $timestamps[] = $weather['last_updated_primary'];
                    }
                }
                
                // Condition comes from METAR source
                if (isset($weather['flight_category']) && $weather['flight_category'] !== null) {
                    if (isset($weather['last_updated_metar']) && $weather['last_updated_metar'] > 0) {
                        $timestamps[] = $weather['last_updated_metar'];
                    }
                }
                
                return !empty($timestamps) ? max($timestamps) : null;
            }
            ?>
            <div class="airports-list">
                <?php foreach ($airportsOnPage as $airportId => $airport): 
                    $url = 'https://' . $airportId . '.aviationwx.org';
                    $weather = getAirportWeather($airportId);
                    $flightCategory = $weather['flight_category'] ?? null;
                    $temperature = $weather['temperature_f'] ?? $weather['temperature'] ?? null;
                    // Convert Celsius to Fahrenheit if needed
                    if ($temperature !== null && $temperature < 50 && !isset($weather['temperature_f'])) {
                        $temperature = ($temperature * 9/5) + 32;
                    }
                    $windSpeed = $weather['wind_speed'] ?? null;
                    $newestTimestamp = getNewestDataTimestamp($weather);
                ?>
                <div class="airport-card">
                    <a href="<?= htmlspecialchars($url) ?>">
                        <div class="airport-code"><?= htmlspecialchars($airport['icao']) ?></div>
                        <div class="airport-name"><?= htmlspecialchars($airport['name']) ?></div>
                        <div class="airport-location"><?= htmlspecialchars($airport['address']) ?></div>
                        
                        <div class="airport-metrics">
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
                            
                            <?php if ($newestTimestamp): ?>
                            <div class="metric" style="flex-basis: 100%; margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid #e9ecef;">
                                <div class="metric-label" style="font-size: 0.7rem;">Last Updated</div>
                                <div class="metric-value" style="font-size: 0.8rem; color: #666; font-weight: 500;">
                                    <?= htmlspecialchars(formatRelativeTime($newestTimestamp)) ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
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
                // Show page numbers (max 5 visible)
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
            <p style="text-align: center; color: #666; padding: 2rem;">No airports currently configured.</p>
            <?php endif; ?>
        </section>
        
        <script>
        function changePage(page) {
            const url = new URL(window.location);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }
        </script>

        <!-- User Groups -->
        <section class="user-groups">
            <h2>For Everyone in Aviation</h2>
            
            <!-- For Pilots -->
            <div class="user-group-section">
                <h3>✈️ For Pilots</h3>
                <p>Use AviationWX to make better-informed flight decisions with real-time weather data and visual conditions.</p>
                <p style="margin-top: 1rem; font-weight: 500;">Share this service with fellow pilots to help grow the aviation weather network!</p>
                
                <div class="btn-group" style="margin-top: 1.5rem; justify-content: flex-start;">
                    <a href="#participating-airports" class="btn-primary">View Airports</a>
                </div>
            </div>

            <!-- For Airport Operators -->
            <div class="user-group-section">
                <h3>🏢 For Airport Operators</h3>
                <p><strong>Add Your Airport - It's Easy!</strong></p>
                <p>Getting your airport on AviationWX is straightforward and free. Here's what you need:</p>
                <ul style="margin: 1rem 0 0 2rem;">
                    <li>A local weather station (Tempest or Ambient Weather) - we'll help you set it up</li>
                    <li>Optional webcam feeds (MJPEG streams or static images)</li>
                    <li>Basic airport metadata (runways, frequencies, services)</li>
                </ul>
                <p style="margin-top: 1.5rem; font-weight: 500;">We handle all the technical setup and provide you with a dedicated subdomain using your ICAO airport code: <code>ICAO.aviationwx.org</code> (e.g., <code>KSPB.aviationwx.org</code>)</p>
                
                <div class="contact-info" style="margin-top: 2rem;">
                    <strong>Ready to get started?</strong><br>
                    Contact Alex Witherspoon: <a href="mailto:alex@alexwitherspoon.com">alex@alexwitherspoon.com</a>
                </div>
            </div>

            <!-- For Developers -->
            <div class="user-group-section">
                <h3>💻 For Developers</h3>
                <p><strong>Open Source Project</strong></p>
                <p>AviationWX is fully open source and welcomes contributions from the developer community.</p>
                <ul style="margin: 1rem 0 0 2rem;">
                    <li>View the codebase on GitHub</li>
                    <li>Submit bug reports and feature requests</li>
                    <li>Contribute improvements and new features</li>
                    <li>Review contribution guidelines</li>
                </ul>
                
                <div class="btn-group" style="margin-top: 1.5rem; justify-content: flex-start;">
                    <a href="https://github.com/alexwitherspoon/aviationwx.org" class="btn-primary" target="_blank" rel="noopener">
                        View on GitHub
                    </a>
                    <a href="https://github.com/alexwitherspoon/aviationwx.org/blob/main/CONTRIBUTING.md" class="btn-secondary" target="_blank" rel="noopener">
                        Contributing Guidelines
                    </a>
                </div>
            </div>
        </section>

        <!-- Contact & Support -->
        <section>
            <h2>Contact & Support</h2>
            <div class="highlight-box">
                <h3>Report Issues</h3>
                <p>Found a bug or have a suggestion? We'd love to hear from you!</p>
                <ul style="margin: 1rem 0 0 2rem;">
                    <li><strong>Email:</strong> <a href="mailto:alex@alexwitherspoon.com">alex@alexwitherspoon.com</a></li>
                    <li><strong>GitHub Issues:</strong> <a href="https://github.com/alexwitherspoon/aviationwx.org/issues" target="_blank" rel="noopener">Open a bug report or feature request</a></li>
                </ul>
            </div>
        </section>

        <section>
            <h2>Supported Weather Sources</h2>
            <div class="features">
                <div class="feature-card">
                    <h3>Tempest Weather</h3>
                    <p>Real-time data from Tempest weather stations with comprehensive meteorological observations.</p>
                </div>
                <div class="feature-card">
                    <h3>Ambient Weather</h3>
                    <p>Integration with Ambient Weather stations for reliable local weather data.</p>
                </div>
                <div class="feature-card">
                    <h3>METAR Data</h3>
                    <p>Automated parsing of METAR observations with visibility and ceiling information.</p>
                </div>
            </div>
        </section>

        <section>
            <h2>Supported Webcam Sources & Formats</h2>
            <div class="features">
                <div class="feature-card">
                    <h3>MJPEG Streams</h3>
                    <p>Motion JPEG streams that automatically extract frames. Works with most IP cameras and webcam servers.</p>
                    <p style="font-size: 0.9rem; color: #666; margin-top: 0.5rem;">Example: <code>https://camera.example.com/video.mjpg</code></p>
                </div>
                <div class="feature-card">
                    <h3>Static Images</h3>
                    <p>JPEG or PNG images that are automatically downloaded and cached. PNG images are converted to JPEG for consistency.</p>
                    <p style="font-size: 0.9rem; color: #666; margin-top: 0.5rem;">Example: <code>https://camera.example.com/webcam.jpg</code></p>
                </div>
                <div class="feature-card">
                    <h3>RTSP/RTSPS Streams</h3>
                    <p>Real Time Streaming Protocol streams (including secure RTSPS over TLS) captured via ffmpeg. Supports TCP and UDP transport.</p>
                    <p style="font-size: 0.9rem; color: #666; margin-top: 0.5rem;">Example: <code>rtsp://camera.example.com:554/stream</code></p>
                </div>
            </div>
            <p style="text-align: center; color: #666; margin-top: 1rem; font-size: 0.9rem;">
                The system automatically detects the source type from the URL format. All formats are cached and optimized for fast loading.
            </p>
        </section>

        <!-- About the Project -->
        <section id="about-the-project">
            <h2>About the Project</h2>
            <img src="/about-photo.jpg" alt="AviationWX - Built for pilots, by pilots" class="about-image">
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
            
            <div class="about-box" style="margin-top: 2rem; border-top: 3px solid #28a745;">
                <h3 style="color: #28a745; margin-top: 0;">Donating</h3>
                <p>
                    If you'd like to support this project financially, that's wonderful and greatly appreciated! However, <strong>donations are completely optional</strong> – AviationWX will always remain free to use for everyone in the aviation community.
                </p>
                <p style="margin-top: 1rem;">
                    You can sponsor this project through <a href="https://github.com/sponsors/alexwitherspoon" target="_blank" rel="noopener">GitHub Sponsors</a>. Every contribution helps cover hosting costs, maintenance, and continued development of new features.
                </p>
                <div class="btn-group" style="margin-top: 1.5rem; justify-content: flex-start;">
                    <a href="https://github.com/sponsors/alexwitherspoon" class="btn-primary" target="_blank" rel="noopener">
                        Support on GitHub Sponsors
                    </a>
                </div>
            </div>
        </section>

        <footer class="footer">
            <p>
                <strong>AviationWX.org</strong>
            </p>
            <p>
                &copy; <?= date('Y') ?> AviationWX.org | 
                Built for pilots, by pilots | 
                <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener">Open Source</a>
            </p>
        </footer>
    </div>
</body>
</html>
