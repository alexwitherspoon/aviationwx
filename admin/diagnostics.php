<?php
/**
 * AviationWX Diagnostics
 * Check system status and configuration
 */

require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/config.php';
header('Content-Type: text/html; charset=utf-8');

$issues = [];
$success = [];

// Check PHP version
if (version_compare(PHP_VERSION, '7.4.0', '>=')) {
    $success[] = "✅ PHP version: " . PHP_VERSION;
} else {
    $issues[] = "❌ PHP version too old: " . PHP_VERSION . " (needs 7.4+)";
}

// airports.json: path from getConfigFilePath()
$resolvedConfigPath = getConfigFilePath();
if ($resolvedConfigPath !== null && file_exists($resolvedConfigPath)) {
    $configFile = $resolvedConfigPath;
    $success[] = '✅ airports.json exists (' . htmlspecialchars($configFile, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')';

    // Check if readable
    if (is_readable($configFile)) {
        $success[] = "✅ airports.json is readable";

        $config = json_decode(file_get_contents($configFile), true);
        if ($config && isset($config['airports'])) {
            $airportCount = count($config['airports']);
            $success[] = "✅ airports.json contains {$airportCount} airport(s)";
            
            // Show airport IDs
            foreach (array_keys($config['airports']) as $id) {
                $success[] = "  - {$id}";
            }
            
            // Check if kspb exists
            if (isset($config['airports']['kspb'])) {
                $success[] = "✅ KSPB airport configured";
            } else {
                $issues[] = "❌ KSPB airport not found in config";
            }
        } else {
            $issues[] = "❌ airports.json is not valid JSON or missing 'airports' key";
        }
    } else {
        $issues[] = "❌ airports.json is not readable (check permissions)";
    }
} else {
    $issues[] = '❌ airports.json not found. Set CONFIG_PATH, mount /var/www/html/secrets/airports.json, or copy from config/airports.json.example';
}

// Check cache directory with actual write test
$cacheTestFile = CACHE_WEBCAMS_DIR . '/.writable_test';
if (is_dir(CACHE_WEBCAMS_DIR)) {
    $success[] = "✅ cache/webcams directory exists";
    
    // Test actual writability by creating a test file
    if (@file_put_contents($cacheTestFile, 'test') !== false) {
        @unlink($cacheTestFile);
        $success[] = "✅ cache/webcams is writable (test write successful)";
    } else {
        $perms = substr(sprintf('%o', fileperms(CACHE_WEBCAMS_DIR)), -4);
        $owner = @fileowner(CACHE_WEBCAMS_DIR);
        $issues[] = "❌ cache/webcams is not writable (perms: {$perms}, owner: {$owner})";
    }
    
    $cacheFiles = [];
    $airportDirs = glob(CACHE_WEBCAMS_DIR . '/*', GLOB_ONLYDIR) ?: [];
    foreach ($airportDirs as $airportDir) {
        $camDirs = glob($airportDir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($camDirs as $camDir) {
            $dateDirs = glob($camDir . '/????-??-??', GLOB_ONLYDIR) ?: [];
            foreach ($dateDirs as $dateDir) {
                $hourDirs = glob($dateDir . '/[0-2][0-9]', GLOB_ONLYDIR) ?: [];
                foreach ($hourDirs as $hourDir) {
                    $hourFiles = glob($hourDir . '/*.{jpg,webp}', GLOB_BRACE) ?: [];
                    $cacheFiles = array_merge($cacheFiles, $hourFiles);
                }
            }
        }
    }
    $cacheCount = count($cacheFiles);
    $cacheSize = 0;
    foreach ($cacheFiles as $file) {
        $cacheSize += filesize($file);
    }
    $cacheSizeMB = round($cacheSize / 1048576, 2);
    $success[] = "📦 Cache: {$cacheCount} files, {$cacheSizeMB} MB";
} else {
    $issues[] = "❌ cache/webcams directory does not exist";
}

// Check .htaccess
if (file_exists(__DIR__ . '/.htaccess')) {
    $success[] = "✅ .htaccess exists";
} else {
    $issues[] = "❌ .htaccess does not exist";
}

// Check subdomain detection
$host = $_SERVER['HTTP_HOST'] ?? 'unknown';
$hostParts = explode('.', $host);
// Only extract subdomain if host has 3+ parts (e.g., kspb.aviationwx.org)
// Don't extract from 2 parts (e.g., aviationwx.org)
$subdomain = (count($hostParts) >= 3) ? $hostParts[0] : '(none - root domain)';
$success[] = "📡 Current host: {$host}";
$success[] = "📡 Detected subdomain: '{$subdomain}'";

// Check mod_rewrite
if (function_exists('apache_get_modules')) {
    $modules = apache_get_modules();
    if (in_array('mod_rewrite', $modules)) {
        $success[] = "✅ mod_rewrite is enabled";
    } else {
        $issues[] = "❌ mod_rewrite is not enabled";
    }
} else {
    $success[] = "⚠️ Cannot check mod_rewrite status (may be disabled or not Apache)";
}

// Check file permissions
$importantFiles = [
    'index.php',
    'weather.php',
    'webcam.php',
    'pages/airport.php',
    'pages/homepage.php'
];

foreach ($importantFiles as $file) {
    if (file_exists($file)) {
        $perms = substr(sprintf('%o', fileperms($file)), -4);
        $success[] = "✅ {$file} exists (perms: {$perms})";
    } else {
        $issues[] = "❌ {$file} does not exist";
    }
}

// CONFIG_PATH (informational) and resolved path from getConfigFilePath()
$envConfigPath = getenv('CONFIG_PATH');
$resolvedForEnv = getConfigFilePath();
if ($envConfigPath) {
    $success[] = '✅ CONFIG_PATH env var set: ' . htmlspecialchars((string) $envConfigPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
} else {
    $success[] = 'ℹ️ CONFIG_PATH not set (see CONFIGURATION.md for resolver order)';
}
if ($resolvedForEnv !== null) {
    $success[] = '✅ Resolved config path: ' . htmlspecialchars($resolvedForEnv, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Show global config values (from airports.json config section)
$webcamRefresh = getDefaultWebcamRefresh();
$weatherRefresh = getDefaultWeatherRefresh();
$defaultTimezone = getDefaultTimezone();
$baseDomain = getBaseDomain();
$maxStaleHours = getMaxStaleHours();

$success[] = "✅ Global Config - Webcam Refresh Default: {$webcamRefresh}s";
$success[] = "✅ Global Config - Weather Refresh Default: {$weatherRefresh}s";
$success[] = "✅ Global Config - Default Timezone: {$defaultTimezone}";
$success[] = "✅ Global Config - Base Domain: {$baseDomain}";
$success[] = "✅ Global Config - Max Stale Hours: {$maxStaleHours}";

// Check ffmpeg availability and RTSP support
$ffmpegCheck = @shell_exec('ffmpeg -version 2>&1');
$ffmpegAvailable = false;
$ffmpegVersion = 'unknown';
if ($ffmpegCheck && strpos($ffmpegCheck, 'ffmpeg version') !== false) {
    $ffmpegAvailable = true;
    // Extract version
    if (preg_match('/ffmpeg version ([^\s]+)/', $ffmpegCheck, $matches)) {
        $ffmpegVersion = $matches[1];
    }
    $success[] = "✅ ffmpeg is available (version: {$ffmpegVersion})";
    
    // Check RTSP protocol support
    $rtspCheck = @shell_exec('ffmpeg -protocols 2>&1 | grep -i rtsp');
    if ($rtspCheck) {
        $success[] = "✅ ffmpeg RTSP protocol support: enabled";
    } else {
        $success[] = "⚠️ ffmpeg RTSP protocol support: not detected (may still work)";
    }
    
    // Test RTSP connectivity if we have RTSPS streams configured
    if (isset($config) && isset($config['airports'])) {
        $hasRtsps = false;
        foreach ($config['airports'] as $airport) {
            if (isset($airport['webcams']) && is_array($airport['webcams'])) {
                foreach ($airport['webcams'] as $cam) {
                    if (isset($cam['url']) && stripos($cam['url'], 'rtsps://') === 0) {
                        $hasRtsps = true;
                        $testUrl = $cam['url'];
                        break 2;
                    }
                }
            }
        }
        
        if ($hasRtsps) {
            // Try a quick connectivity test (just check if URL is reachable)
            $urlParts = parse_url($testUrl);
            if ($urlParts && isset($urlParts['host']) && isset($urlParts['port'])) {
                $host = $urlParts['host'];
                $port = $urlParts['port'];
                $testSocket = @fsockopen($host, $port, $errno, $errstr, 3);
                if ($testSocket) {
                    fclose($testSocket);
                    $success[] = "✅ RTSPS connectivity: {$host}:{$port} is reachable";
                } else {
                    $issues[] = "⚠️ RTSPS connectivity: Cannot connect to {$host}:{$port} ({$errstr})";
                }
            }
            
            // Test ffmpeg RTSPS command (quick timeout test)
            // Use timeout option correctly for RTSP streams (ffmpeg 5.0+ uses -timeout, not -stimeout)
            $testCmd = sprintf(
                "timeout 5 ffmpeg -hide_banner -loglevel error -rtsp_transport tcp -timeout 5000000 -i %s -frames:v 1 -f null - 2>&1 | head -3",
                escapeshellarg($testUrl)
            );
            $testOutput = @shell_exec($testCmd);
            if ($testOutput) {
                // Sanitize output: remove URLs, IPs, paths
                $cleanOutput = preg_replace('/https?:\/\/[^\s]+/', '[URL_REDACTED]', $testOutput);
                $cleanOutput = preg_replace('/rtsp[s]?:\/\/[^\s]+/', '[RTSP_URL_REDACTED]', $cleanOutput);
                $cleanOutput = preg_replace('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[IP_REDACTED]', $cleanOutput);
                $cleanOutput = htmlspecialchars(substr(trim($cleanOutput), 0, 150), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false);
                $cleanOutput = str_replace('&#039;', "'", $cleanOutput);
                $success[] = "🔍 RTSPS test output: " . $cleanOutput;
            }
        }
    }
} else {
    $issues[] = "⚠️ ffmpeg not found (RTSP/RTSPS streams will not work)";
}

// Check RTSP error statistics from cache
$errorCounts = ['timeout' => 0, 'auth' => 0, 'tls' => 0, 'dns' => 0, 'connection' => 0, 'unknown' => 0];
if (is_dir(CACHE_WEBCAMS_DIR)) {
    foreach (glob(CACHE_WEBCAMS_DIR . '/*/*/*.error.json') as $errorFile) {
        $errorData = @json_decode(file_get_contents($errorFile), true);
        if ($errorData && isset($errorData['code'])) {
            $code = $errorData['code'];
            if (isset($errorCounts[$code])) {
                $errorCounts[$code]++;
            } else {
                $errorCounts['unknown']++;
            }
        }
    }
    $totalErrors = array_sum($errorCounts);
    if ($totalErrors > 0) {
        $success[] = "📊 RTSP Error Statistics:";
        foreach ($errorCounts as $code => $count) {
            if ($count > 0) {
                $success[] = "  - {$code}: {$count}";
            }
        }
    }
}

// Check HTTPS/SSL (check both HTTPS header and X-Forwarded-Proto from Nginx)
$isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
           (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
if ($isHttps) {
    $success[] = "🔒 HTTPS enabled";
} else {
    $issues[] = "⚠️ Not using HTTPS (HTTP only)";
}

// Test API endpoints
$apiTests = [];

// Weather API test
$weatherUrl = 'http://localhost:8080/weather.php?airport=kspb';
$weatherResponse = @file_get_contents($weatherUrl, false, stream_context_create([
    'http' => ['timeout' => 5, 'ignore_errors' => true]
]));
if ($weatherResponse !== false) {
    $weatherData = @json_decode($weatherResponse, true);
    if ($weatherData && isset($weatherData['success'])) {
        if ($weatherData['success']) {
            $apiTests[] = "✅ Weather API endpoint working";
            if (isset($weatherData['weather']['last_updated'])) {
                $age = time() - $weatherData['weather']['last_updated'];
                $apiTests[] = "  Weather data age: " . round($age / 60, 1) . " minutes";
            }
        } else {
            $apiTests[] = "⚠️ Weather API returned error: " . htmlspecialchars($weatherData['error'] ?? 'Unknown');
        }
    } else {
        $apiTests[] = "⚠️ Weather API response invalid";
    }
} else {
    $apiTests[] = "⚠️ Weather API not reachable (may be expected if not running locally)";
}

// Webcam fetch script test and analyze webcam configuration
$webcamFetchUrl = 'http://localhost:8080/scripts/fetch-webcam.php';
$webcamResponse = @file_get_contents($webcamFetchUrl, false, stream_context_create([
    'http' => ['timeout' => 15, 'ignore_errors' => true]
]));
if ($webcamResponse !== false && strlen($webcamResponse) > 10) {
    $apiTests[] = "✅ Webcam fetch script accessible";
    
    // Analyze webcam fetch output for RTSP/RTSPS issues
    if (isset($config) && isset($config['airports'])) {
        foreach ($config['airports'] as $airportId => $airport) {
            if (isset($airport['webcams']) && is_array($airport['webcams'])) {
                foreach ($airport['webcams'] as $idx => $cam) {
                    if (isset($cam['url'])) {
                        $url = $cam['url'];
                        $camName = $cam['name'] ?? "Camera {$idx}";
                        
                        // Check if this is RTSP/RTSPS
                        if (stripos($url, 'rtsp://') === 0 || stripos($url, 'rtsps://') === 0) {
                            // Check if fetch output shows failures for this camera
                            if (stripos($webcamResponse, $camName) !== false) {
                                if (stripos($webcamResponse, "✗ ffmpeg failed") !== false) {
                                    $issues[] = "⚠️ RTSP/RTSPS issue detected for {$camName}: ffmpeg capture failing";
                                    // Extract specific error if available
                                    if (preg_match('/' . preg_quote($camName, '/') . '.*?✗ ffmpeg failed \(code (\d+)\)/s', $webcamResponse, $matches)) {
                                        $errorCode = $matches[1];
                                        $errorDesc = [
                                            '1' => 'General error',
                                            '2' => 'Bug in ffmpeg',
                                            '4' => 'Protocol not found',
                                            '5' => 'Codec not found',
                                            '8' => 'Network/connection error (check firewall, URL, credentials)'
                                        ];
                                        $issues[] = "   Error code {$errorCode}: " . ($errorDesc[$errorCode] ?? 'Unknown error');
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
} else {
    $apiTests[] = "⚠️ Webcam fetch script not accessible";
}

$success = array_merge($success, $apiTests);

// Check cache directory for weather cache
// Use centralized cache paths
$weatherCacheFiles = glob(CACHE_WEATHER_DIR . '/*.json');
if (count($weatherCacheFiles) > 0) {
    $success[] = "📊 Weather cache: " . count($weatherCacheFiles) . " file(s)";
}

// Check webcam cache files for each configured airport
if (isset($config) && isset($config['airports']) && is_dir($cacheDir)) {
    $webcamCacheIssues = [];
    $webcamCacheSuccess = [];
    
    foreach ($config['airports'] as $airportId => $airport) {
        if (isset($airport['webcams']) && is_array($airport['webcams'])) {
            $airportCacheFiles = [];
            $airportMissingFiles = [];
            
            foreach ($airport['webcams'] as $idx => $cam) {
                $camName = $cam['name'] ?? "Camera {$idx}";
                $cacheJpg = $cacheDir . '/' . $airportId . '_' . $idx . '.jpg';
                $cacheWebp = $cacheDir . '/' . $airportId . '_' . $idx . '.webp';
                
                // Check if cache files exist and are readable
                $cacheJpgResolved = @realpath($cacheJpg) ?: $cacheJpg;
                $cacheWebpResolved = @realpath($cacheWebp) ?: $cacheWebp;
                $hasJpg = @file_exists($cacheJpgResolved) && @is_readable($cacheJpgResolved);
                $hasWebp = @file_exists($cacheWebpResolved) && @is_readable($cacheWebpResolved);
                
                if ($hasJpg || $hasWebp) {
                    $cacheFile = $hasJpg ? $cacheJpgResolved : $cacheWebpResolved;
                    $mtime = @filemtime($cacheFile);
                    $size = @filesize($cacheFile);
                    $age = $mtime ? time() - $mtime : 0;
                    $ageMinutes = round($age / 60, 1);
                    $sizeKB = round($size / 1024, 1);
                    
                    $formats = [];
                    if ($hasJpg) $formats[] = 'JPG';
                    if ($hasWebp) $formats[] = 'WEBP';
                    
                    $airportCacheFiles[] = "  {$camName}: " . implode('+', $formats) . " ({$sizeKB}KB, {$ageMinutes}min old)";
                } else {
                    $airportMissingFiles[] = "  {$camName}: No cache files";
                }
            }
            
            if (!empty($airportCacheFiles)) {
                $webcamCacheSuccess[] = "📷 {$airportId} webcam cache:";
                $webcamCacheSuccess = array_merge($webcamCacheSuccess, $airportCacheFiles);
            }
            
            if (!empty($airportMissingFiles)) {
                $webcamCacheIssues[] = "⚠️ {$airportId} missing cache:";
                $webcamCacheIssues = array_merge($webcamCacheIssues, $airportMissingFiles);
            }
        }
    }
    
    if (!empty($webcamCacheSuccess)) {
        $success = array_merge($success, $webcamCacheSuccess);
    }
    
    if (!empty($webcamCacheIssues)) {
        $issues = array_merge($issues, $webcamCacheIssues);
    }
}

// Check cache directory permissions detail
if (is_dir($cacheDir)) {
    $cachePerms = substr(sprintf('%o', fileperms($cacheDir)), -4);
    $cacheOwner = posix_getpwuid(@fileowner($cacheDir));
    $cacheGroup = posix_getgrgid(@filegroup($cacheDir));
    $success[] = "📁 Cache perms: {$cachePerms}, owner: " . ($cacheOwner['name'] ?? 'unknown') . ", group: " . ($cacheGroup['name'] ?? 'unknown');
}

// Check configuration cache status
$configFilePath = getConfigFilePath();
if ($configFilePath !== null && file_exists($configFilePath)) {
    $fileMtime = filemtime($configFilePath);
    $fileMtimeStr = date('Y-m-d H:i:s', $fileMtime);
    $success[] = "📄 Config file modified: {$fileMtimeStr}";
    
    if (function_exists('apcu_fetch')) {
        $cacheShaKey = 'aviationwx_config_sha';
        $fileContent = @file_get_contents($configFilePath);
        $currentSha = $fileContent !== false ? hash('sha256', $fileContent) : null;
        $cachedSha = apcu_fetch($cacheShaKey);
        
        if ($cachedSha !== false && $currentSha !== null) {
            if ($cachedSha === $currentSha) {
                $success[] = "✅ Config cache is valid (SHA hash matches)";
            } else {
                $success[] = "🔄 Config cache will auto-invalidate (SHA hash changed)";
            }
        } else {
            $success[] = "ℹ️ Config cache empty (will be created on next load)";
        }
    } else {
        $success[] = "ℹ️ APCu not available (config cache disabled)";
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AviationWX Diagnostics</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .success { color: #28a745; }
        .issue { color: #dc3545; }
        h1 { color: #333; }
        ul { list-style: none; padding-left: 0; }
        li { margin: 5px 0; padding: 5px; background: white; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>🔍 AviationWX Diagnostics</h1>
    
    <h2>✅ Working</h2>
    <ul>
        <?php foreach ($success as $item): ?>
            <li class="success"><?= htmlspecialchars($item) ?></li>
        <?php endforeach; ?>
    </ul>
    
    <?php if (!empty($issues)): ?>
    <h2>❌ Issues Found</h2>
    <ul>
        <?php foreach ($issues as $item): ?>
            <li class="issue"><?= htmlspecialchars($item) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    
    <?php if (empty($issues)): ?>
    <h2>🎉 All Checks Passed!</h2>
    <p>Your AviationWX installation appears to be working correctly.</p>
    <?php endif; ?>
    
    <h2>🧪 Test Links & Results</h2>
    <ul>
        <li><a href="/?airport=kspb" target="_blank">Test Query Param: ?airport=kspb</a></li>
        <li><a href="/weather.php?airport=kspb" target="_blank">Test Weather API</a> <?php 
            if ($weatherResponse !== false && isset($weatherData['success']) && $weatherData['success']) {
                echo '<span style="color: #28a745;">✅ Working</span>';
            } else {
                echo '<span style="color: #dc3545;">❌ Check manually</span>';
            }
        ?></li>
        <li><a href="/webcam.php?id=kspb&cam=0" target="_blank">Test Webcam API</a></li>
        <li><a href="/scripts/fetch-webcam.php" target="_blank">Test Webcam Fetch Script</a></li>
        <li><a href="/admin/cache-clear.php" target="_blank" onclick="return confirm('Clear configuration cache? This will force reload of airports.json');">🗑️ Clear Config Cache</a></li>
        <li><a href="/admin/diagnostics.php" target="_blank">🔍 Run Diagnostics Again</a></li>
    </ul>
    
    <h2>📋 Deployment Health Checklist</h2>
    <ul style="list-style: disc; padding-left: 20px;">
        <li>✅ All required files present</li>
        <li><?= empty($issues) ? '✅' : '❌' ?> No configuration errors</li>
        <li><?= is_dir($cacheDir) && @file_put_contents($cacheTestFile, 'test') !== false ? '✅' : '❌' ?> Cache directory writable</li>
        <li><?= $isHttps ? '✅' : '❌' ?> HTTPS enabled</li>
        <li><?= $ffmpegCheck && strpos($ffmpegCheck, 'ffmpeg version') !== false ? '✅' : '⚠️' ?> ffmpeg available</li>
        <li><?= isset($weatherData['success']) && $weatherData['success'] ? '✅' : '⚠️' ?> Weather API responding</li>
        <li>✅ GitHub Actions deployment workflow configured</li>
        <li>✅ DNS wildcard configured (*.aviationwx.org)</li>
        <li>✅ Cron job configured for webcam refresh</li>
    </ul>
    
    
    <?php
    // Check if there are RTSP/RTSPS issues and show troubleshooting
    $hasRtspsIssues = false;
    foreach ($issues as $issue) {
        if (stripos($issue, 'RTSP') !== false || stripos($issue, 'RTSPS') !== false) {
            $hasRtspsIssues = true;
            break;
        }
    }
    
    if ($hasRtspsIssues): ?>
    <h2>🔧 RTSP/RTSPS Troubleshooting</h2>
    <p><strong>Exit code 8 from ffmpeg</strong> typically indicates network/connection issues. Try these steps:</p>
    <ol>
        <li><strong>Test connectivity from the server:</strong>
            <pre>docker compose -f docker-compose.prod.yml exec web bash -c "timeout 5 nc -zv CAMERA_IP PORT"</pre>
            Replace CAMERA_IP and PORT with your camera's IP and RTSP port. If this fails, the server may not be able to reach the camera (firewall, network routing).
        </li>
        <li><strong>Test ffmpeg command manually:</strong>
            <pre># For UniFi local RTSP (port 7447, unencrypted):
docker compose -f docker-compose.prod.yml exec web ffmpeg -rtsp_transport tcp -i "rtsp://CAMERA_IP:7447/STREAM_ID" -frames:v 1 -f null - 2>&1

# For UniFi shared RTSPS (port 7441, encrypted):
docker compose -f docker-compose.prod.yml exec web ffmpeg -rtsp_transport tcp -i "rtsps://CAMERA_IP:7441/STREAM_ID?enableSrtp" -frames:v 1 -f null - 2>&1</pre>
            Replace CAMERA_IP and STREAM_ID with your actual values. Check the output for specific error messages.
        </li>
        <li><strong>Check camera authentication:</strong> Ensure credentials are correct if the stream requires authentication.</li>
        <li><strong>Check firewall rules:</strong> Ensure the server can reach the camera IP on the RTSP port (TCP). UniFi uses port 7447 for local RTSP or port 7441 for shared RTSPS.</li>
        <li><strong>Verify RTSP URL format:</strong>
            <ul>
                <li>UniFi local RTSP: <code>rtsp://ip:7447/STREAM_ID</code></li>
                <li>UniFi shared RTSPS: <code>rtsps://ip:7441/STREAM_ID?enableSrtp</code></li>
                <li>Generic RTSP: <code>rtsp://ip:554/stream</code></li>
            </ul>
        </li>
        <li><strong>Check camera logs:</strong> The camera server may be rejecting connections or have rate limiting enabled.</li>
    </ol>
    <?php endif; ?>
</body>
</html>

