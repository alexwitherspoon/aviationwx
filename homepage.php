<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AviationWX.org - Real-time Aviation Weather</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .hero {
            background: linear-gradient(135deg, #1a1a1a 0%, #0066cc 100%);
            color: white;
            padding: 5rem 2rem;
            text-align: center;
            margin: -1rem -1rem 4rem -1rem;
        }
        .hero h1 {
            font-size: 3.5rem;
            margin-bottom: 1rem;
        }
        .hero p {
            font-size: 1.3rem;
            opacity: 0.95;
            max-width: 700px;
            margin: 0 auto 2rem;
        }
        .hero .subtitle {
            font-size: 1rem;
            opacity: 0.85;
            font-style: italic;
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
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }
        .airport-card {
            background: white;
            padding: 1.5rem;
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
            font-size: 2rem;
            font-weight: bold;
            color: #0066cc;
            margin-bottom: 0.5rem;
        }
        .airport-name {
            font-size: 1rem;
            color: #333;
            margin-bottom: 0.25rem;
        }
        .airport-location {
            font-size: 0.9rem;
            color: #666;
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
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 2rem;
            border-radius: 8px;
            border-left: 5px solid #0066cc;
            margin: 2rem 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="hero">
            <h1>✈️ AviationWX.org</h1>
            <p>Real-time weather and conditions for participating airports. Built for pilots, by pilots.</p>
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
            </div>
        </section>

        <section>
            <h2>Participating Airports</h2>
            <?php if ($totalAirports > 0 && file_exists($configFile)): ?>
            <div class="airports-list">
                <?php
                $config = json_decode(file_get_contents($configFile), true);
                if (isset($config['airports'])) {
                    foreach ($config['airports'] as $airportId => $airport):
                        $url = 'https://' . $airportId . '.aviationwx.org';
                ?>
                <div class="airport-card">
                    <a href="<?= htmlspecialchars($url) ?>">
                        <div class="airport-code"><?= htmlspecialchars($airport['icao']) ?></div>
                        <div class="airport-name"><?= htmlspecialchars($airport['name']) ?></div>
                        <div class="airport-location"><?= htmlspecialchars($airport['address']) ?></div>
                    </a>
                </div>
                <?php 
                    endforeach;
                }
                ?>
            </div>
            <?php else: ?>
            <p style="text-align: center; color: #666; padding: 2rem;">No airports currently configured.</p>
            <?php endif; ?>
        </section>

        <div class="cta-section">
            <h2>Open Source Project</h2>
            <p>AviationWX is an open-source project designed to help pilots make informed decisions. We welcome contributions and airport additions to the network.</p>
            
            <div class="btn-group">
                <a href="https://github.com/alexwitherspoon/aviationwx.org" class="btn-primary" target="_blank" rel="noopener">
                    View on GitHub
                </a>
                <a href="#how-it-works" class="btn-secondary">
                    How It Works
                </a>
            </div>
        </div>

        <section id="how-it-works">
            <h2>How It Works</h2>
            
            <div class="highlight-box">
                <h3>For Pilots</h3>
                <p>Visit any participating airport's subdomain (e.g., <code>kspb.aviationwx.org</code>) to view:</p>
                <ul style="margin: 1rem 0 0 2rem; line-height: 1.8;">
                    <li>Current weather conditions and VFR/IFR status</li>
                    <li>Live webcam views of the airport</li>
                    <li>Wind visualization aligned with runway headings</li>
                    <li>Aviation-specific metrics (density altitude, pressure altitude)</li>
                    <li>Local and Zulu time with sunrise/sunset</li>
                </ul>
            </div>
            
            <div class="highlight-box">
                <h3>For Airport Operators</h3>
                <p>Want to add your airport to the network?</p>
                <ul style="margin: 1rem 0 0 2rem; line-height: 1.8;">
                    <li>Set up a local weather station (Tempest or Ambient Weather)</li>
                    <li>Configure webcam feeds (MJPEG or static images)</li>
                    <li>Provide airport metadata (runways, frequencies, services)</li>
                    <li>Get a dedicated subdomain: <code>your-airport.aviationwx.org</code></li>
                </ul>
                <p style="margin-top: 1rem;"><a href="https://github.com/alexwitherspoon/aviationwx.org/blob/main/CONFIGURATION.md">View configuration guide →</a></p>
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
