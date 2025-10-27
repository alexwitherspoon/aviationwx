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

// Check cache directory
$cacheDir = __DIR__ . '/cache/webcams';
if (is_dir($cacheDir)) {
    $success[] = "✅ cache/webcams directory exists";
    if (is_writable($cacheDir)) {
        $success[] = "✅ cache/webcams is writable";
    } else {
        $issues[] = "❌ cache/webcams is not writable (chmod 755)";
    }
} else {
    $issues[] = "❌ cache/webcams directory does not exist (creating...";
    if (mkdir($cacheDir, 0755, true)) {
        $success[] = "✅ Created cache/webcams directory";
    } else {
        $issues[] = "❌ Failed to create cache/webcams directory";
    }
}

// Check .htaccess
if (file_exists(__DIR__ . '/.htaccess')) {
    $success[] = "✅ .htaccess exists";
} else {
    $issues[] = "❌ .htaccess does not exist";
}

// Check subdomain detection
$host = $_SERVER['HTTP_HOST'] ?? 'unknown';
$subdomain = explode('.', $host)[0] ?? '';
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
    
    <h2>🧪 Test Links</h2>
    <ul>
        <li><a href="/?airport=kspb">Test Query Param: ?airport=kspb</a></li>
        <li><a href="/weather.php?airport=kspb">Test Weather API</a></li>
        <li><a href="/webcam.php?id=kspb&cam=0">Test Webcam API</a></li>
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

