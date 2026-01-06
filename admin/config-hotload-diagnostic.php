<?php
/**
 * Config Hotload Diagnostic Tool
 * Diagnoses why config changes aren't being picked up automatically
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';

header('Content-Type: text/html; charset=utf-8');

$issues = [];
$info = [];
$recommendations = [];

// Get config file path
$configFilePath = getConfigFilePath();
$info[] = "Config file path: " . htmlspecialchars($configFilePath ?? 'NOT FOUND');

if (!$configFilePath || !file_exists($configFilePath)) {
    $issues[] = "Config file not found at: " . htmlspecialchars($configFilePath ?? 'unknown');
} else {
    // Check file mtime
    $fileMtime = filemtime($configFilePath);
    $fileMtimeStr = date('Y-m-d H:i:s', $fileMtime);
    $info[] = "File last modified: {$fileMtimeStr} (timestamp: {$fileMtime})";
    
    // Check APCu cache status
    if (function_exists('apcu_fetch')) {
        $cacheTimeKey = 'aviationwx_config_mtime';
        $cacheKey = 'aviationwx_config';
        
        $cachedMtime = apcu_fetch($cacheTimeKey);
        $cachedConfig = apcu_fetch($cacheKey);
        
        if ($cachedMtime !== false) {
            $cachedMtimeStr = date('Y-m-d H:i:s', $cachedMtime);
            $info[] = "APCu cached mtime: {$cachedMtimeStr} (timestamp: {$cachedMtime})";
            
            if ($cachedMtime === $fileMtime) {
                $info[] = "✅ APCu cache mtime matches file mtime (cache should be valid)";
            } else {
                $diff = abs($fileMtime - $cachedMtime);
                $issues[] = "⚠️ APCu cache mtime does NOT match file mtime (diff: {$diff}s)";
                $issues[] = "   This means the cache should have been invalidated, but may not have been cleared";
                $recommendations[] = "Clear APCu cache manually: <a href='/admin/cache-clear.php'>Clear Config Cache</a>";
            }
        } else {
            $info[] = "ℹ️ APCu cache mtime not found (cache empty or expired)";
        }
        
        if ($cachedConfig !== false) {
            $airportCount = isset($cachedConfig['airports']) ? count($cachedConfig['airports']) : 0;
            $info[] = "APCu cached config: {$airportCount} airports";
        } else {
            $info[] = "ℹ️ APCu cached config not found";
        }
    } else {
        $issues[] = "⚠️ APCu not available - config caching disabled";
        $info[] = "Without APCu, each request reloads config from disk (slower but always fresh)";
    }
    
    // Check scheduler status
    $lockFile = '/tmp/scheduler.lock';
    if (file_exists($lockFile)) {
        $lockContent = @file_get_contents($lockFile);
        if ($lockContent) {
            $lockData = json_decode($lockContent, true);
            if ($lockData) {
                $info[] = "Scheduler PID: " . ($lockData['pid'] ?? 'unknown');
                $info[] = "Scheduler started: " . date('Y-m-d H:i:s', $lockData['started'] ?? 0);
                $info[] = "Scheduler last updated: " . date('Y-m-d H:i:s', $lockData['updated'] ?? 0);
                
                if (isset($lockData['config_last_reload'])) {
                    $lastReload = $lockData['config_last_reload'];
                    $lastReloadStr = date('Y-m-d H:i:s', $lastReload);
                    $age = time() - $lastReload;
                    $info[] = "Scheduler last config reload: {$lastReloadStr} ({$age}s ago)";
                    
                    // Check if scheduler has reloaded since file was modified
                    if ($lastReload < $fileMtime) {
                        $issues[] = "⚠️ Scheduler last reload ({$lastReloadStr}) is BEFORE file modification ({$fileMtimeStr})";
                        $issues[] = "   Scheduler should reload config on next check interval";
                        
                        // Get reload interval
                        $config = loadConfig();
                        $reloadInterval = getSchedulerConfigReloadInterval();
                        $info[] = "Scheduler reload interval: {$reloadInterval}s";
                        
                        $nextReload = $lastReload + $reloadInterval;
                        $nextReloadStr = date('Y-m-d H:i:s', $nextReload);
                        $timeUntilReload = $nextReload - time();
                        
                        if ($timeUntilReload > 0) {
                            $info[] = "Next scheduled reload: {$nextReloadStr} (in {$timeUntilReload}s)";
                        } else {
                            $info[] = "⚠️ Next scheduled reload should have already happened";
                            $recommendations[] = "Check scheduler logs for errors preventing config reload";
                        }
                    } else {
                        $info[] = "✅ Scheduler has reloaded since file was modified";
                    }
                }
                
                if (isset($lockData['config_airports_count'])) {
                    $info[] = "Scheduler cached airport count: " . $lockData['config_airports_count'];
                }
            }
        }
    } else {
        $issues[] = "⚠️ Scheduler lock file not found - scheduler may not be running";
        $recommendations[] = "Start scheduler: <code>nohup php scripts/scheduler.php > /dev/null 2>&1 &</code>";
    }
    
    // Test current config load
    $testConfig = loadConfig(true);
    if ($testConfig !== null) {
        $testAirportCount = count($testConfig['airports'] ?? []);
        $info[] = "Current loadConfig() result: {$testAirportCount} airports";
        
        // Compare with file
        $fileContent = @file_get_contents($configFilePath);
        if ($fileContent !== false) {
            $fileConfig = json_decode($fileContent, true);
            if ($fileConfig && isset($fileConfig['airports'])) {
                $fileAirportCount = count($fileConfig['airports']);
                $info[] = "File contains: {$fileAirportCount} airports";
                
                if ($testAirportCount !== $fileAirportCount) {
                    $issues[] = "❌ Config mismatch: loadConfig() returns {$testAirportCount} airports, but file has {$fileAirportCount}";
                    $recommendations[] = "Clear APCu cache to force reload: <a href='/admin/cache-clear.php'>Clear Config Cache</a>";
                } else {
                    $info[] = "✅ Airport count matches between loadConfig() and file";
                }
            }
        }
    } else {
        $issues[] = "❌ loadConfig() returned null - check for validation errors";
    }
    
    // Check file permissions
    $perms = substr(sprintf('%o', fileperms($configFilePath)), -4);
    $readable = is_readable($configFilePath);
    $info[] = "File permissions: {$perms}";
    $info[] = "File readable: " . ($readable ? 'YES' : 'NO');
    
    if (!$readable) {
        $issues[] = "❌ Config file is not readable";
    }
    
    // Check if file is being watched/monitored
    $info[] = "File size: " . number_format(filesize($configFilePath)) . " bytes";
}

// Check PHP-FPM process count (multiple workers = multiple static caches)
if (function_exists('apache_get_modules')) {
    // Apache
    $info[] = "Web server: Apache";
} else {
    // Likely PHP-FPM
    $info[] = "Web server: PHP-FPM (multiple workers may have separate static caches)";
    $recommendations[] = "PHP-FPM workers have separate static caches - APCu cache should be shared";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Config Hotload Diagnostic</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1000px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .error { color: #dc3545; background: #fff; padding: 10px; margin: 5px 0; border-left: 4px solid #dc3545; }
        .warning { color: #856404; background: #fff3cd; padding: 10px; margin: 5px 0; border-left: 4px solid #ffc107; }
        .info { color: #004085; background: #d1ecf1; padding: 10px; margin: 5px 0; border-left: 4px solid #17a2b8; }
        .success { color: #155724; background: #d4edda; padding: 10px; margin: 5px 0; border-left: 4px solid #28a745; }
        h1 { color: #333; }
        h2 { color: #555; margin-top: 30px; }
        code { background: #f8f9fa; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>🔍 Config Hotload Diagnostic</h1>
    
    <p>This tool diagnoses why config file changes may not be picked up automatically.</p>
    
    <?php if (!empty($issues)): ?>
        <h2>⚠️ Issues Found</h2>
        <?php foreach ($issues as $issue): ?>
            <div class="warning"><?= htmlspecialchars($issue) ?></div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="success">
            <strong>✅ No obvious issues detected</strong>
        </div>
    <?php endif; ?>
    
    <h2>ℹ️ Information</h2>
    <?php foreach ($info as $item): ?>
        <div class="info"><?= htmlspecialchars($item) ?></div>
    <?php endforeach; ?>
    
    <?php if (!empty($recommendations)): ?>
        <h2>💡 Recommendations</h2>
        <?php foreach ($recommendations as $rec): ?>
            <div class="info"><?= $rec ?></div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <h2>🔧 How Config Hotload Works</h2>
    <div class="info">
        <p><strong>APCu Cache (Web Requests):</strong></p>
        <ul>
            <li>Uses file modification time (mtime) to detect changes</li>
            <li>When mtime changes, APCu cache is automatically invalidated</li>
            <li>Shared across all PHP-FPM workers</li>
            <li>TTL: 1 hour (but invalidated on file change)</li>
        </ul>
        
        <p><strong>Scheduler Reload:</strong></p>
        <ul>
            <li>Checks for config changes every <code>scheduler_config_reload_seconds</code> (default: 60s)</li>
            <li>Calls <code>loadConfig(false)</code> which bypasses APCu but checks mtime</li>
            <li>Reinitializes ProcessPools when config changes</li>
        </ul>
        
        <p><strong>Potential Issues:</strong></p>
        <ul>
            <li>File mtime may not update if file is edited in place (depends on filesystem)</li>
            <li>Scheduler only checks every 60s, so there's a delay</li>
            <li>PHP-FPM workers have separate static caches (but APCu should be shared)</li>
            <li>If file is copied/moved, mtime might be preserved</li>
        </ul>
    </div>
    
    <h2>🔧 Actions</h2>
    <ul>
        <li><a href="/admin/cache-clear.php">Clear Config Cache (APCu)</a></li>
        <li><a href="/admin/config-validate.php">Validate Config</a></li>
        <li><a href="/admin/diagnostics.php">Full Diagnostics</a></li>
        <li><a href="/admin/config-hotload-diagnostic.php">Refresh This Page</a></li>
    </ul>
    
    <h2>📝 Manual Reload Methods</h2>
    <div class="info">
        <p><strong>Option 1: Clear APCu Cache (Web)</strong></p>
        <p>Visit <a href="/admin/cache-clear.php">/admin/cache-clear.php</a> to clear APCu cache. Next web request will reload config.</p>
        
        <p><strong>Option 2: Wait for Scheduler</strong></p>
        <p>Scheduler automatically reloads config every <code>scheduler_config_reload_seconds</code> (default: 60s).</p>
        
        <p><strong>Option 3: Restart PHP-FPM Workers</strong></p>
        <p>Restart PHP-FPM to clear all static caches: <code>docker compose restart web</code></p>
        
        <p><strong>Option 4: Touch Config File</strong></p>
        <p>Update file mtime to force cache invalidation: <code>touch /path/to/airports.json</code></p>
    </div>
</body>
</html>

