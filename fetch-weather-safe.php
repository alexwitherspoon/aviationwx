<?php
/**
 * Safe Weather Data Fetcher
 * Refreshes weather cache for all airports via cron
 * Similar to fetch-webcam-safe.php but for weather data
 */

require_once __DIR__ . '/config-utils.php';
require_once __DIR__ . '/logger.php';

// Determine base URL for weather endpoint
// Try to detect from environment or use localhost
$baseUrl = getenv('WEATHER_REFRESH_URL') ?: 'http://localhost';
// Remove trailing slash if present
$baseUrl = rtrim($baseUrl, '/');

// Load config (support CONFIG_PATH env override, no cache for CLI script)
$config = loadConfig(false);

if ($config === null || !is_array($config)) {
    die("Error: Could not load configuration\n");
}

if (!isset($config['airports']) || !is_array($config['airports'])) {
    die("Error: No airports configured\n");
}

// Check if we're in a web context (add HTML) or CLI (plain text)
$isWeb = !empty($_SERVER['REQUEST_METHOD']);

if ($isWeb) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>AviationWX Weather Fetcher</title>";
    echo "<style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .header { background: #333; color: #fff; padding: 10px; margin: -20px -20px 20px -20px; }
        .airport { background: #fff; padding: 15px; margin-bottom: 15px; border-left: 4px solid #007bff; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .info { color: #666; }
    </style></head><body>";
    echo "<div class='header'><h1>AviationWX Weather Fetcher</h1></div>";
} else {
    echo "AviationWX Weather Fetcher\n";
    echo "========================\n\n";
}

// Iterate through all airports
foreach ($config['airports'] as $airportId => $airport) {
    if ($isWeb) {
        echo "<div class='airport'>";
        echo "<strong>Airport: " . htmlspecialchars(strtoupper($airportId)) . "</strong><br>\n";
    } else {
        echo "Airport: " . strtoupper($airportId) . "\n";
    }
    
    // Build weather endpoint URL
    $weatherUrl = $baseUrl . '/weather.php?airport=' . urlencode($airportId);
    
    // Use curl to fetch weather (this refreshes the cache)
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $weatherUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45, // Match weather.php timeout
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'AviationWX Weather Cron Bot',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200 && $response !== false) {
        $data = json_decode($response, true);
        if ($data !== null && isset($data['success']) && $data['success'] === true) {
            $stale = isset($data['stale']) && $data['stale'] === true;
            $lastUpdated = isset($data['weather']['last_updated']) ? $data['weather']['last_updated'] : null;
            
            if ($stale) {
                aviationwx_log('info', 'weather refresh triggered (stale cache)', [
                    'airport' => $airportId,
                    'last_updated' => $lastUpdated
                ]);
                if ($isWeb) {
                    echo "<span class='info'>✓ Refresh triggered (cache was stale)</span><br>\n";
                } else {
                    echo "    ✓ Refresh triggered (cache was stale)\n";
                }
            } else {
                aviationwx_log('info', 'weather refresh triggered (fresh cache)', [
                    'airport' => $airportId,
                    'last_updated' => $lastUpdated
                ]);
                if ($isWeb) {
                    echo "<span class='success'>✓ Cache refreshed</span><br>\n";
                } else {
                    echo "    ✓ Cache refreshed\n";
                }
            }
        } else {
            aviationwx_log('warning', 'weather refresh returned invalid response', [
                'airport' => $airportId,
                'http_code' => $httpCode
            ]);
            if ($isWeb) {
                echo "<span class='error'>✗ Invalid response</span><br>\n";
            } else {
                echo "    ✗ Invalid response\n";
            }
        }
    } else {
        aviationwx_log('error', 'weather refresh failed', [
            'airport' => $airportId,
            'http_code' => $httpCode,
            'error' => $error
        ]);
        if ($isWeb) {
            echo "<span class='error'>✗ Failed: HTTP {$httpCode}" . ($error ? " - {$error}" : "") . "</span><br>\n";
        } else {
            echo "    ✗ Failed: HTTP {$httpCode}" . ($error ? " - {$error}" : "") . "\n";
        }
    }
    
    if ($isWeb) {
        echo "</div>";
    }
}

if ($isWeb) {
    echo "<div style='margin-top: 20px; padding: 15px; background: #fff; border-left: 4px solid #28a745;'>";
    echo "<strong>✓ Done!</strong> Weather cache refreshed.<br>";
    echo "</body></html>";
} else {
    echo "\n\nDone! Weather cache refreshed.\n";
}

aviationwx_maybe_log_alert();
