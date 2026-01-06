<?php
/**
 * Config Validation Diagnostic Tool
 * Detailed validation of airports.json configuration
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/push-webcam-validator.php';

header('Content-Type: text/html; charset=utf-8');

$errors = [];
$warnings = [];
$info = [];

// Get config file path
$configFilePath = getConfigFilePath();
$info[] = "Config file path: " . htmlspecialchars($configFilePath ?? 'NOT FOUND');

if (!$configFilePath || !file_exists($configFilePath)) {
    $errors[] = "Config file not found at: " . htmlspecialchars($configFilePath ?? 'unknown');
} else {
    $info[] = "Config file exists and is readable";
    $info[] = "File size: " . number_format(filesize($configFilePath)) . " bytes";
    $info[] = "Last modified: " . date('Y-m-d H:i:s', filemtime($configFilePath));
    
    // Check JSON syntax
    $jsonContent = @file_get_contents($configFilePath);
    if ($jsonContent === false) {
        $errors[] = "Failed to read config file";
    } else {
        // Check for JSON syntax errors
        $config = json_decode($jsonContent, true);
        $jsonError = json_last_error();
        if ($jsonError !== JSON_ERROR_NONE) {
            $errors[] = "JSON syntax error: " . json_last_error_msg() . " (code: $jsonError)";
            
            // Try to find the line with the error
            $lines = explode("\n", $jsonContent);
            $errorLine = null;
            // JSON errors often point to a character position, but we can try to find common issues
            if (preg_match('/line (\d+)/i', json_last_error_msg(), $matches)) {
                $errorLine = (int)$matches[1];
            }
            if ($errorLine) {
                $errors[] = "Error likely at line $errorLine: " . htmlspecialchars(substr($lines[$errorLine - 1] ?? '', 0, 100));
            }
        } else {
            $info[] = "JSON syntax is valid";
            
            // Check for duplicate keys
            $duplicateKeys = detectDuplicateJsonKeys($jsonContent);
            if (!empty($duplicateKeys)) {
                foreach ($duplicateKeys as $dup) {
                    $warnings[] = "Duplicate key '{$dup['key']}' at path '{$dup['path']}' (first at line {$dup['first_line']}, duplicate at line {$dup['duplicate_line']})";
                }
            }
            
            // Try to load config using the actual loadConfig function
            $config = loadConfig(false); // Don't use cache
            if ($config === null) {
                $errors[] = "Config failed to load (check logs for details)";
                
                // Try to identify the specific validation error by running validation manually
                $testConfig = json_decode($jsonContent, true);
                if ($testConfig && isset($testConfig['airports'])) {
                    $validationErrors = [];
                    foreach ($testConfig['airports'] as $aid => $ap) {
                        if (!validateAirportId($aid)) {
                            $validationErrors[] = "Airport key '{$aid}' is invalid (must be 3-50 lowercase alphanumeric characters, hyphens allowed)";
                        }
                    }
                    if (!empty($validationErrors)) {
                        $errors = array_merge($errors, $validationErrors);
                    }
                }
            } else {
                $info[] = "Config loaded successfully";
                $info[] = "Airports count: " . count($config['airports'] ?? []);
                
                // Validate each airport
                if (isset($config['airports']) && is_array($config['airports'])) {
                    foreach ($config['airports'] as $aid => $ap) {
                        // Validate airport ID format
                        if (!validateAirportId($aid)) {
                            $errors[] = "Airport ID '{$aid}' is invalid (must be 3-50 lowercase alphanumeric characters, hyphens allowed)";
                        }
                        
                        // Check required fields
                        $requiredFields = ['name', 'lat', 'lon', 'elevation_ft', 'timezone'];
                        foreach ($requiredFields as $field) {
                            if (!isset($ap[$field])) {
                                $errors[] = "Airport '{$aid}' missing required field: {$field}";
                            }
                        }
                        
                        // Validate webcams
                        if (isset($ap['webcams']) && is_array($ap['webcams'])) {
                            foreach ($ap['webcams'] as $idx => $cam) {
                                $isPush = (isset($cam['type']) && $cam['type'] === 'push') || isset($cam['push_config']);
                                
                                if ($isPush) {
                                    // Validate push camera
                                    $pushValidation = validatePushWebcamConfig($cam, $aid, $idx);
                                    if (!$pushValidation['valid']) {
                                        foreach ($pushValidation['errors'] as $err) {
                                            $errors[] = "Airport '{$aid}' webcam {$idx}: {$err}";
                                        }
                                    }
                                } else {
                                    // Validate pull camera
                                    if (!isset($cam['name'])) {
                                        $errors[] = "Airport '{$aid}' webcam {$idx} missing 'name'";
                                    }
                                    if (!isset($cam['url'])) {
                                        $errors[] = "Airport '{$aid}' webcam {$idx} missing 'url'";
                                    }
                                    if (isset($cam['type']) && !in_array($cam['type'], ['rtsp', 'mjpeg', 'static_jpeg', 'static_png', 'push'])) {
                                        $errors[] = "Airport '{$aid}' webcam {$idx} has invalid type '{$cam['type']}'";
                                    }
                                }
                            }
                        }
                    }
                    
                    // Check for duplicate push usernames
                    $usernameValidation = validateUniquePushUsernames($config);
                    if (!$usernameValidation['valid']) {
                        foreach ($usernameValidation['errors'] as $err) {
                            $errors[] = $err;
                        }
                    }
                } else {
                    $errors[] = "Config missing 'airports' key or it's not an array";
                }
            }
        }
    }
}

// Check environment
$info[] = "APP_ENV: " . (getenv('APP_ENV') ?: 'not set');
$info[] = "CONFIG_PATH: " . (getenv('CONFIG_PATH') ?: 'not set');
$info[] = "Test mode: " . (isTestMode() ? 'YES' : 'NO');
$info[] = "Mock mode: " . (shouldMockExternalServices() ? 'YES' : 'NO');
$info[] = "Production: " . (isProduction() ? 'YES' : 'NO');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Config Validation</title>
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
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>🔍 Config Validation Diagnostic</h1>
    
    <?php if (empty($errors)): ?>
        <div class="success">
            <strong>✅ Configuration is valid!</strong>
        </div>
    <?php else: ?>
        <h2>❌ Errors Found</h2>
        <?php foreach ($errors as $error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <?php if (!empty($warnings)): ?>
        <h2>⚠️ Warnings</h2>
        <?php foreach ($warnings as $warning): ?>
            <div class="warning"><?= htmlspecialchars($warning) ?></div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <h2>ℹ️ Information</h2>
    <?php foreach ($info as $item): ?>
        <div class="info"><?= htmlspecialchars($item) ?></div>
    <?php endforeach; ?>
    
    <h2>🔧 Actions</h2>
    <ul>
        <li><a href="/admin/cache-clear.php">Clear Config Cache</a></li>
        <li><a href="/admin/diagnostics.php">Full Diagnostics</a></li>
        <li><a href="/admin/config-validate.php">Refresh This Page</a></li>
    </ul>
    
    <?php if (!empty($errors) && $configFilePath): ?>
    <h2>💡 Troubleshooting</h2>
    <p>If you see JSON syntax errors, try:</p>
    <ol>
        <li>Validate JSON using an online validator or <code>php -r "json_decode(file_get_contents('<?= htmlspecialchars($configFilePath) ?>')); echo json_last_error_msg();"</code></li>
        <li>Check for trailing commas in JSON objects/arrays</li>
        <li>Check for unclosed quotes or brackets</li>
        <li>Check for special characters that need escaping</li>
    </ol>
    <?php endif; ?>
</body>
</html>

