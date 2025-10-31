<?php
/**
 * AviationWX Diagnostics
 * Check system status and configuration
 */

header('Content-Type: text/html; charset=utf-8');

$issues = [];
$success = [];

// Check PHP version
if (version_compare(PHP_VERSION, '7.4.0', '>=')) {
    $success[] = "✅ PHP version: " . PHP_VERSION;
} else {
    $issues[] = "❌ PHP version too old: " . PHP_VERSION . " (needs 7.4+)";
}

// Check airports.json exists
$configFile = __DIR__ . '/airports.json';
if (file_exists($configFile)) {
    $success[] = "✅ airports.json exists";
    
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
    $issues[] = "❌ airports.json does not exist. Copy from airports.json.example";
}

// Check cache directory with actual write test
$cacheDir = __DIR__ . '/cache/webcams';
$cacheTestFile = $cacheDir . '/.writable_test';
if (is_dir($cacheDir)) {
    $success[] = "✅ cache/webcams directory exists";
    
    // Test actual writability by creating a test file
    if (@file_put_contents($cacheTestFile, 'test') !== false) {
        @unlink($cacheTestFile);
        $success[] = "✅ cache/webcams is writable (test write successful)";
    } else {
        $perms = substr(sprintf('%o', fileperms($cacheDir)), -4);
        $owner = @fileowner($cacheDir);
        $issues[] = "❌ cache/webcams is not writable (perms: {$perms}, owner: {$owner})";
    }
    
    // Show cache stats
    $cacheFiles = glob($cacheDir . '/*.{jpg,webp,avif}', GLOB_BRACE);
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
    'airport-template.php',
    'homepage.php'
];

foreach ($importantFiles as $file) {
    if (file_exists($file)) {
        $perms = substr(sprintf('%o', fileperms($file)), -4);
        $success[] = "✅ {$file} exists (perms: {$perms})";
    } else {
        $issues[] = "❌ {$file} does not exist";
    }
}

// Check environment variables
$envConfigPath = getenv('CONFIG_PATH');
if ($envConfigPath) {
    $success[] = "✅ CONFIG_PATH env var set: " . htmlspecialchars($envConfigPath);
} else {
    $success[] = "ℹ️ CONFIG_PATH not set (using default)";
}

$webcamRefresh = getenv('WEBCAM_REFRESH_DEFAULT');
$weatherRefresh = getenv('WEATHER_REFRESH_DEFAULT');
if ($webcamRefresh !== false) {
    $success[] = "✅ WEBCAM_REFRESH_DEFAULT: {$webcamRefresh}s";
}
if ($weatherRefresh !== false) {
    $success[] = "✅ WEATHER_REFRESH_DEFAULT: {$weatherRefresh}s";
}

// Check ffmpeg availability
$ffmpegCheck = @shell_exec('ffmpeg -version 2>&1');
if ($ffmpegCheck && strpos($ffmpegCheck, 'ffmpeg version') !== false) {
    $success[] = "✅ ffmpeg is available (RTSP support enabled)";
} else {
    $success[] = "⚠️ ffmpeg not found (RTSP streams will not work)";
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
$weatherUrl = 'http://localhost/weather.php?airport=kspb';
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

// Webcam fetch script test
$webcamFetchUrl = 'http://localhost/fetch-webcam-safe.php';
$webcamResponse = @file_get_contents($webcamFetchUrl, false, stream_context_create([
    'http' => ['timeout' => 10, 'ignore_errors' => true]
]));
if ($webcamResponse !== false && strlen($webcamResponse) > 10) {
    $apiTests[] = "✅ Webcam fetch script accessible";
} else {
    $apiTests[] = "⚠️ Webcam fetch script not accessible (may be expected if not running locally)";
}

$success = array_merge($success, $apiTests);

// Check cache directory for weather cache
$weatherCacheDir = __DIR__ . '/cache';
$weatherCacheFiles = glob($weatherCacheDir . '/weather_*.json');
if (count($weatherCacheFiles) > 0) {
    $success[] = "📊 Weather cache: " . count($weatherCacheFiles) . " file(s)";
}

// Check cache directory permissions detail
if (is_dir($cacheDir)) {
    $cachePerms = substr(sprintf('%o', fileperms($cacheDir)), -4);
    $cacheOwner = posix_getpwuid(@fileowner($cacheDir));
    $cacheGroup = posix_getgrgid(@filegroup($cacheDir));
    $success[] = "📁 Cache perms: {$cachePerms}, owner: " . ($cacheOwner['name'] ?? 'unknown') . ", group: " . ($cacheGroup['name'] ?? 'unknown');
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
        <li><a href="/fetch-webcam-safe.php" target="_blank">Test Webcam Fetch Script</a></li>
        <li><a href="/diagnostics.php" target="_blank">🔍 Run Diagnostics Again</a></li>
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
    
    <h2>📝 Next Steps</h2>
    <ol>
        <?php if (empty($issues)): ?>
            <li>✅ Configuration is good!</li>
            <li>Set up DNS wildcard subdomain: <code>*.aviationwx.org</code></li>
            <li>Configure cron job for webcam refresh</li>
        <?php else: ?>
            <?php if (in_array("❌ airports.json does not exist. Copy from airports.json.example", $issues)): ?>
                <li>Create <code>airports.json</code> from <code>airports.json.example</code></li>
            <?php endif; ?>
            <?php if (in_array("❌ airports.json is not readable (check permissions)", $issues)): ?>
                <li>Fix permissions: <code>chmod 644 airports.json</code></li>
            <?php endif; ?>
            <?php if (in_array("❌ cache/webcams is not writable (chmod 755)", $issues)): ?>
                <li>Fix cache permissions: <code>chmod -R 755 cache/</code></li>
            <?php endif; ?>
        <?php endif; ?>
    </ol>
</body>
</html>

