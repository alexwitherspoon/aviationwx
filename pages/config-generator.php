<?php
/**
 * Airport Configuration Generator
 * Web-based UI for generating airports.json configuration snippets
 * Publicly accessible but intended for admins/airport owners
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/rate-limit.php';
require_once __DIR__ . '/../lib/seo.php';
require_once __DIR__ . '/../lib/push-webcam-validator.php';

// Rate limiting: 10 submissions per hour per IP
$rateLimitKey = 'config_generator';
if (!checkRateLimit($rateLimitKey, 10, 3600)) {
    http_response_code(429);
    die('Rate limit exceeded. Please try again later.');
}

$errors = [];
$success = false;
$generatedConfig = null;
$airportId = '';

// Handle form submission
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = $_POST;
    
    // Validate and generate config
    $validation = validateAirportConfig($formData);
    if ($validation['valid']) {
        $generatedConfig = generateConfigSnippet($formData);
        $airportId = strtolower(trim($formData['airport_id'] ?? ''));
        $success = true;
        
        aviationwx_log('info', 'config generator: config generated', [
            'airport_id' => $airportId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ], 'user');
    } else {
        $errors = $validation['errors'];
    }
}

/**
 * Generate random 14-character credential
 */
function generateRandomCredential() {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $credential = '';
    for ($i = 0; $i < 14; $i++) {
        $credential .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $credential;
}

/**
 * Check if airport exists (without exposing details)
 */
function airportExists($airportId) {
    $config = loadConfig();
    if (!$config) {
        return false;
    }
    
    return isset($config['airports'][strtolower($airportId)]);
}

/**
 * Validate airport configuration
 */
function validateAirportConfig($formData) {
    $errors = [];
    
    // Airport ID
    $airportId = strtolower(trim($formData['airport_id'] ?? ''));
    if (empty($airportId)) {
        $errors[] = 'Airport ID (ICAO code) is required';
    } elseif (!validateAirportId($airportId)) {
        $errors[] = 'Airport ID must be 3-4 lowercase alphanumeric characters';
    }
    
    // Airport name
    if (empty($formData['airport_name'] ?? '')) {
        $errors[] = 'Airport name is required';
    }
    
    // Coordinates
    $lat = floatval($formData['latitude'] ?? 0);
    $lon = floatval($formData['longitude'] ?? 0);
    if ($lat < -90 || $lat > 90) {
        $errors[] = 'Latitude must be between -90 and 90';
    }
    if ($lon < -180 || $lon > 180) {
        $errors[] = 'Longitude must be between -180 and 180';
    }
    
    // Webcams
    if (isset($formData['webcams']) && is_array($formData['webcams'])) {
        foreach ($formData['webcams'] as $idx => $cam) {
            if (empty($cam['name'] ?? '')) {
                $errors[] = "Webcam {$idx}: Name is required";
            }
            
            $camType = $cam['type'] ?? 'pull';
            if ($camType === 'push') {
                // Validate push config
                if (empty($cam['protocol'] ?? '')) {
                    $errors[] = "Webcam {$idx}: Protocol is required for push cameras";
                }
            } else {
                // Validate pull config
                if (empty($cam['url'] ?? '')) {
                    $errors[] = "Webcam {$idx}: URL is required for pull cameras";
                }
            }
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Generate JSON snippet
 */
function generateConfigSnippet($formData) {
    $airportId = strtolower(trim($formData['airport_id'] ?? ''));
    
    $config = [
        'airports' => [
            $airportId => []
        ]
    ];
    
    $airport = &$config['airports'][$airportId];
    
    // Basic info
    $airport['name'] = trim($formData['airport_name'] ?? '');
    $airport['icao'] = strtoupper($airportId);
    
    // Use 'lat' and 'lon' to match existing config format
    if (!empty($formData['latitude'] ?? '')) {
        $airport['lat'] = floatval($formData['latitude']);
    }
    if (!empty($formData['longitude'] ?? '')) {
        $airport['lon'] = floatval($formData['longitude']);
    }
    if (!empty($formData['elevation'] ?? '')) {
        $airport['elevation_ft'] = intval($formData['elevation']);
    }
    if (!empty($formData['timezone'] ?? '')) {
        $airport['timezone'] = trim($formData['timezone']);
    }
    if (!empty($formData['address'] ?? '')) {
        $airport['address'] = trim($formData['address']);
    }
    
    // Weather source
    if (!empty($formData['weather_type'] ?? '')) {
        $weatherType = $formData['weather_type'];
        $airport['weather_source'] = [];
        
        if ($weatherType === 'tempest') {
            $airport['weather_source']['type'] = 'tempest';
            if (!empty($formData['tempest_station_id'] ?? '')) {
                $airport['weather_source']['station_id'] = trim($formData['tempest_station_id']);
            }
        } elseif ($weatherType === 'ambient') {
            $airport['weather_source']['type'] = 'ambient';
            if (!empty($formData['ambient_api_key'] ?? '')) {
                $airport['weather_source']['api_key'] = trim($formData['ambient_api_key']);
            }
            if (!empty($formData['ambient_application_key'] ?? '')) {
                $airport['weather_source']['application_key'] = trim($formData['ambient_application_key']);
            }
        } elseif ($weatherType === 'metar') {
            $airport['weather_source']['type'] = 'metar';
            if (!empty($formData['metar_station_id'] ?? '')) {
                $airport['weather_source']['station_id'] = trim($formData['metar_station_id']);
            } else {
                $airport['weather_source']['station_id'] = strtoupper($airportId);
            }
        }
    }
    
    // Webcams
    $airport['webcams'] = [];
    if (isset($formData['webcams']) && is_array($formData['webcams'])) {
        foreach ($formData['webcams'] as $cam) {
            if (empty($cam['name'] ?? '')) {
                continue;
            }
            
            $webcam = [
                'name' => trim($cam['name'])
            ];
            
            $camType = $cam['type'] ?? 'pull';
            if ($camType === 'push') {
                // Push camera
                $webcam['type'] = 'push';
                $webcam['push_config'] = [
                    'username' => $cam['push_username'] ?? generateRandomCredential(),
                    'password' => $cam['push_password'] ?? generateRandomCredential(),
                    'protocol' => strtolower($cam['protocol'] ?? 'sftp'),
                    'port' => intval($cam['port'] ?? ($cam['protocol'] === 'sftp' ? 2222 : ($cam['protocol'] === 'ftps' ? 2122 : 2121))),
                    'max_file_size_mb' => intval($cam['max_file_size_mb'] ?? 100),
                    'allowed_extensions' => ['jpg', 'jpeg', 'png']
                ];
            } else {
                // Pull camera
                $webcam['url'] = trim($cam['url'] ?? '');
                
                if (!empty($cam['pull_type'] ?? '')) {
                    $webcam['type'] = $cam['pull_type'];
                }
                
                if (!empty($cam['rtsp_transport'] ?? '')) {
                    $webcam['rtsp_transport'] = $cam['rtsp_transport'];
                }
            }
            
            if (!empty($cam['position'] ?? '')) {
                $webcam['position'] = trim($cam['position']);
            }
            if (!empty($cam['partner_name'] ?? '')) {
                $webcam['partner_name'] = trim($cam['partner_name']);
            }
            if (!empty($cam['partner_link'] ?? '')) {
                $webcam['partner_link'] = trim($cam['partner_link']);
            }
            if (!empty($cam['refresh_seconds'] ?? '')) {
                $refresh = intval($cam['refresh_seconds']);
                if ($refresh >= 60) {
                    $webcam['refresh_seconds'] = $refresh;
                }
            }
            
            $airport['webcams'][] = $webcam;
        }
    }
    
    // Runways
    if (!empty($formData['runways'] ?? '')) {
        $runways = [];
        $runwayLines = explode("\n", $formData['runways']);
        foreach ($runwayLines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Format: "18/36" or "18/36 180/360" or "15/33 152/332"
            if (preg_match('/^(\d{1,2}[LCR]?)\/(\d{1,2}[LCR]?)(?:\s+(\d{1,3})\/(\d{1,3}))?$/', $line, $matches)) {
                $runway = [
                    'name' => $matches[1] . '/' . $matches[2]
                ];
                if (isset($matches[3]) && isset($matches[4])) {
                    $runway['heading_1'] = intval($matches[3]);
                    $runway['heading_2'] = intval($matches[4]);
                }
                $runways[] = $runway;
            }
        }
        if (!empty($runways)) {
            $airport['runways'] = $runways;
        }
    }
    
    // Frequencies
    if (!empty($formData['frequencies'] ?? '')) {
        $frequencies = [];
        $freqLines = explode("\n", $formData['frequencies']);
        foreach ($freqLines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Format: "ATIS 124.5" or "Tower 118.3" or "ctaf 122.8"
            if (preg_match('/^([A-Za-z_]+)\s+(\d+\.\d+)$/', $line, $matches)) {
                $type = strtolower(trim($matches[1]));
                $frequencies[$type] = trim($matches[2]);
            }
        }
        if (!empty($frequencies)) {
            $airport['frequencies'] = $frequencies;
        }
    }
    
    // Services - convert to object format
    if (!empty($formData['services'] ?? '')) {
        $serviceList = array_filter(array_map('trim', explode(',', $formData['services'])));
        if (!empty($serviceList)) {
            $services = [];
            foreach ($serviceList as $service) {
                $serviceLower = strtolower($service);
                // Map common service names
                if (strpos($serviceLower, 'fuel') !== false) {
                    $services['fuel_available'] = true;
                }
                if (strpos($serviceLower, 'repair') !== false || strpos($serviceLower, 'maintenance') !== false) {
                    $services['repairs_available'] = true;
                }
                if (strpos($serviceLower, '100ll') !== false || strpos($serviceLower, '100 ll') !== false) {
                    $services['100ll'] = true;
                }
                if (strpos($serviceLower, 'jet') !== false || strpos($serviceLower, 'jeta') !== false) {
                    $services['jet_a'] = true;
                }
            }
            if (!empty($services)) {
                $airport['services'] = $services;
            }
        }
    }
    
    return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

$pageTitle = 'Airport Configuration Generator - AviationWX.org';
$pageDescription = 'Generate airports.json configuration snippets for adding new airports to AviationWX.org';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    
    <?php
    echo generateFaviconTags();
    echo "\n    ";
    echo generateEnhancedMetaTags($pageDescription, 'airport configuration, aviationwx, webcam setup');
    echo "\n    ";
    ?>
    
    <link rel="stylesheet" href="/public/css/styles.css">
    <style>
        .form-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .form-section {
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid #eee;
        }
        
        .form-section:last-child {
            border-bottom: none;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
        }
        
        .required {
            color: #ef4444;
        }
        
        input[type="text"],
        input[type="number"],
        input[type="url"],
        select,
        textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
            font-family: inherit;
        }
        
        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #0066cc;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        .help-text {
            font-size: 0.875rem;
            color: #666;
            margin-top: 0.25rem;
        }
        
        .error {
            color: #ef4444;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #0066cc;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .btn:hover {
            background: #0052a3;
        }
        
        .btn-secondary {
            background: #6b7280;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .btn-success {
            background: #10b981;
        }
        
        .btn-success:hover {
            background: #059669;
        }
        
        .camera-item {
            background: #f9fafb;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            border: 1px solid #e5e7eb;
        }
        
        .camera-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .camera-header h4 {
            margin: 0;
            color: #1a1a1a;
        }
        
        .btn-remove {
            background: #ef4444;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }
        
        .btn-remove:hover {
            background: #dc2626;
        }
        
        .credential-display {
            background: #f3f4f6;
            padding: 0.75rem;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.9rem;
            margin-top: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .credential-value {
            font-weight: 600;
            color: #1a1a1a;
        }
        
        .btn-copy {
            background: #6b7280;
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
        }
        
        .preview-container {
            background: #1a1a1a;
            color: #10b981;
            padding: 1.5rem;
            border-radius: 8px;
            margin-top: 2rem;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            overflow-x: auto;
        }
        
        .preview-container pre {
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .success-message {
            background: #d1fae5;
            color: #065f46;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 2rem;
        }
        
        .alert {
            background: #fef3c7;
            color: #92400e;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        
        .rate-limit-info {
            font-size: 0.875rem;
            color: #666;
            margin-top: 1rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Airport Configuration Generator</h1>
            <p class="subtitle">Generate airports.json configuration snippets for new airports</p>
        </div>
        
        <div class="form-container">
            <?php if ($success && $generatedConfig): ?>
            <div class="success-message">
                <strong>✓ Configuration generated successfully!</strong>
                <p>Review the configuration below and download the JSON snippet.</p>
            </div>
            
            <div class="form-section">
                <h2>Generated Configuration</h2>
                <div class="preview-container">
                    <pre><?= htmlspecialchars($generatedConfig) ?></pre>
                </div>
                
                <div style="margin-top: 1rem; display: flex; gap: 1rem;">
                    <button class="btn btn-success" onclick="downloadConfig()">Download JSON</button>
                    <button class="btn btn-secondary" onclick="copyToClipboard()">Copy to Clipboard</button>
                </div>
                
                <div class="help-text" style="margin-top: 1rem;">
                    <strong>Next Steps:</strong>
                    <ol style="margin-top: 0.5rem; padding-left: 1.5rem;">
                        <li>Review the generated configuration</li>
                        <li>Download or copy the JSON snippet</li>
                        <li>Add it to your airports.json file</li>
                        <li>For push cameras: Configure your camera with the generated credentials</li>
                    </ol>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
            <div class="alert">
                <strong>Validation Errors:</strong>
                <ul style="margin-top: 0.5rem; margin-left: 1.5rem;">
                    <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <form method="POST" id="configForm">
                <!-- Airport Information -->
                <div class="form-section">
                    <h2>Airport Information</h2>
                    
                    <div class="form-group">
                        <label for="airport_id">ICAO Code <span class="required">*</span></label>
                        <input type="text" id="airport_id" name="airport_id" 
                               value="<?= htmlspecialchars($_POST['airport_id'] ?? '') ?>"
                               pattern="[a-z0-9]{3,4}" maxlength="4" required>
                        <div class="help-text">3-4 lowercase alphanumeric characters (e.g., kspb)</div>
                        <?php if (isset($_POST['airport_id'])): ?>
                        <?php 
                        $checkId = strtolower(trim($_POST['airport_id']));
                        if (!empty($checkId) && airportExists($checkId)): 
                        ?>
                        <div class="alert" style="margin-top: 0.5rem;">
                            ⚠️ This airport ID already exists. You can still generate a config, but it will overwrite the existing configuration.
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label for="airport_name">Airport Name <span class="required">*</span></label>
                        <input type="text" id="airport_name" name="airport_name" 
                               value="<?= htmlspecialchars($_POST['airport_name'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="latitude">Latitude</label>
                            <input type="number" id="latitude" name="latitude" step="any"
                                   value="<?= htmlspecialchars($_POST['latitude'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="longitude">Longitude</label>
                            <input type="number" id="longitude" name="longitude" step="any"
                                   value="<?= htmlspecialchars($_POST['longitude'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="elevation">Elevation (feet)</label>
                            <input type="number" id="elevation" name="elevation"
                                   value="<?= htmlspecialchars($_POST['elevation'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="timezone">Timezone</label>
                            <input type="text" id="timezone" name="timezone" 
                                   value="<?= htmlspecialchars($_POST['timezone'] ?? 'America/New_York') ?>"
                                   placeholder="America/New_York">
                            <div class="help-text">IANA timezone (e.g., America/New_York)</div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="address">Address</label>
                        <input type="text" id="address" name="address"
                               value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
                    </div>
                </div>
                
                <!-- Weather Source -->
                <div class="form-section">
                    <h2>Weather Source</h2>
                    
                    <div class="form-group">
                        <label for="weather_type">Weather Source Type</label>
                        <select id="weather_type" name="weather_type">
                            <option value="">None</option>
                            <option value="metar" <?= ($_POST['weather_type'] ?? '') === 'metar' ? 'selected' : '' ?>>METAR (Aviation Weather)</option>
                            <option value="tempest" <?= ($_POST['weather_type'] ?? '') === 'tempest' ? 'selected' : '' ?>>Tempest Weather Station</option>
                            <option value="ambient" <?= ($_POST['weather_type'] ?? '') === 'ambient' ? 'selected' : '' ?>>Ambient Weather</option>
                        </select>
                    </div>
                    
                    <div id="weather_metar" class="weather-config" style="display: none;">
                        <div class="form-group">
                            <label for="metar_station_id">Station ID</label>
                            <input type="text" id="metar_station_id" name="metar_station_id" 
                                   value="<?= htmlspecialchars($_POST['metar_station_id'] ?? '') ?>"
                                   placeholder="Auto (uses airport ICAO)">
                            <div class="help-text">Leave empty to use airport ICAO code</div>
                        </div>
                    </div>
                    
                    <div id="weather_tempest" class="weather-config" style="display: none;">
                        <div class="form-group">
                            <label for="tempest_station_id">Station ID <span class="required">*</span></label>
                            <input type="text" id="tempest_station_id" name="tempest_station_id"
                                   value="<?= htmlspecialchars($_POST['tempest_station_id'] ?? '') ?>" required>
                        </div>
                    </div>
                    
                    <div id="weather_ambient" class="weather-config" style="display: none;">
                        <div class="form-group">
                            <label for="ambient_api_key">API Key <span class="required">*</span></label>
                            <input type="text" id="ambient_api_key" name="ambient_api_key"
                                   value="<?= htmlspecialchars($_POST['ambient_api_key'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="ambient_application_key">Application Key <span class="required">*</span></label>
                            <input type="text" id="ambient_application_key" name="ambient_application_key"
                                   value="<?= htmlspecialchars($_POST['ambient_application_key'] ?? '') ?>" required>
                        </div>
                    </div>
                </div>
                
                <!-- Webcams -->
                <div class="form-section">
                    <h2>Webcams</h2>
                    <div id="webcams-container">
                        <?php
                        $webcamCount = 0;
                        if (isset($_POST['webcams']) && is_array($_POST['webcams'])) {
                            $webcamCount = count($_POST['webcams']);
                            foreach ($_POST['webcams'] as $idx => $cam) {
                                renderWebcamForm($idx, $cam);
                            }
                        }
                        if ($webcamCount === 0) {
                            renderWebcamForm(0, []);
                        }
                        ?>
                    </div>
                    <button type="button" class="btn btn-secondary" onclick="addWebcam()">+ Add Webcam</button>
                </div>
                
                <!-- Additional Settings -->
                <div class="form-section">
                    <h2>Additional Settings</h2>
                    
                    <div class="form-group">
                        <label for="runways">Runways (one per line)</label>
                        <textarea id="runways" name="runways" rows="4" 
                                  placeholder="18/36 180/360&#10;09/27 090/270"><?= htmlspecialchars($_POST['runways'] ?? '') ?></textarea>
                        <div class="help-text">Format: "18/36" or "18/36 180/360" (designation and optional headings)</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="frequencies">Frequencies (one per line)</label>
                        <textarea id="frequencies" name="frequencies" rows="4"
                                  placeholder="ATIS 124.5&#10;Tower 118.3"><?= htmlspecialchars($_POST['frequencies'] ?? '') ?></textarea>
                        <div class="help-text">Format: "Type Frequency" (e.g., "ATIS 124.5")</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="services">Services (comma-separated)</label>
                        <input type="text" id="services" name="services"
                               value="<?= htmlspecialchars($_POST['services'] ?? '') ?>"
                               placeholder="Fuel, Maintenance, Restaurant">
                    </div>
                </div>
                
                <div style="margin-top: 2rem; text-align: center;">
                    <button type="submit" class="btn">Generate Configuration</button>
                </div>
                
                <div class="rate-limit-info">
                    Rate limit: 10 submissions per hour per IP address
                </div>
            </form>
        </div>
    </div>
    
    <script>
        let webcamIndex = <?= $webcamCount ?>;
        
        // Show/hide weather config based on type
        document.getElementById('weather_type').addEventListener('change', function() {
            document.querySelectorAll('.weather-config').forEach(el => el.style.display = 'none');
            const type = this.value;
            if (type) {
                const el = document.getElementById('weather_' + type);
                if (el) el.style.display = 'block';
            }
        });
        
        // Initialize weather config display
        const weatherType = document.getElementById('weather_type').value;
        if (weatherType) {
            document.getElementById('weather_' + weatherType).style.display = 'block';
        }
        
        // Add webcam
        function addWebcam() {
            webcamIndex++;
            const container = document.getElementById('webcams-container');
            const div = document.createElement('div');
            div.className = 'camera-item';
            div.innerHTML = getWebcamHTML(webcamIndex, {});
            container.appendChild(div);
            attachWebcamListeners(div);
        }
        
        // Remove webcam
        function removeWebcam(btn) {
            btn.closest('.camera-item').remove();
        }
        
        // Get webcam form HTML
        function getWebcamHTML(idx, cam) {
            const type = cam.type || 'pull';
            const isPush = type === 'push';
            const protocol = cam.protocol || 'sftp';
            const username = cam.push_username || generateCredential();
            const password = cam.push_password || generateCredential();
            
            return `
                <div class="camera-header">
                    <h4>Webcam ${idx + 1}</h4>
                    <button type="button" class="btn btn-remove" onclick="removeWebcam(this)">Remove</button>
                </div>
                
                <div class="form-group">
                    <label>Camera Name <span class="required">*</span></label>
                    <input type="text" name="webcams[${idx}][name]" value="${escapeHtml(cam.name || '')}" required>
                </div>
                
                <div class="form-group">
                    <label>Camera Type <span class="required">*</span></label>
                    <select name="webcams[${idx}][type]" onchange="toggleCameraType(this, ${idx})" required>
                        <option value="pull" ${type === 'pull' ? 'selected' : ''}>Pull (MJPEG/RTSP/Static)</option>
                        <option value="push" ${type === 'push' ? 'selected' : ''}>Push (FTP/FTPS/SFTP)</option>
                    </select>
                </div>
                
                <div id="camera-pull-${idx}" style="display: ${isPush ? 'none' : 'block'}">
                    <div class="form-group">
                        <label>URL <span class="required">*</span></label>
                        <input type="url" name="webcams[${idx}][url]" value="${escapeHtml(cam.url || '')}" ${isPush ? '' : 'required'}>
                    </div>
                    
                    <div class="form-group">
                        <label>Pull Type</label>
                        <select name="webcams[${idx}][pull_type]">
                            <option value="">Auto-detect</option>
                            <option value="mjpeg" ${(cam.pull_type || '') === 'mjpeg' ? 'selected' : ''}>MJPEG Stream</option>
                            <option value="rtsp" ${(cam.pull_type || '') === 'rtsp' ? 'selected' : ''}>RTSP Stream</option>
                            <option value="static_jpeg" ${(cam.pull_type || '') === 'static_jpeg' ? 'selected' : ''}>Static JPEG</option>
                            <option value="static_png" ${(cam.pull_type || '') === 'static_png' ? 'selected' : ''}>Static PNG</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>RTSP Transport</label>
                        <select name="webcams[${idx}][rtsp_transport]">
                            <option value="">Default</option>
                            <option value="tcp" ${(cam.rtsp_transport || '') === 'tcp' ? 'selected' : ''}>TCP</option>
                            <option value="udp" ${(cam.rtsp_transport || '') === 'udp' ? 'selected' : ''}>UDP</option>
                        </select>
                    </div>
                </div>
                
                <div id="camera-push-${idx}" style="display: ${isPush ? 'block' : 'none'}">
                    <div class="form-group">
                        <label>Protocol <span class="required">*</span></label>
                        <select name="webcams[${idx}][protocol]" onchange="updatePushPort(this, ${idx})" required>
                            <option value="sftp" ${protocol === 'sftp' ? 'selected' : ''}>SFTP (Port 2222)</option>
                            <option value="ftps" ${protocol === 'ftps' ? 'selected' : ''}>FTPS (Port 2122)</option>
                            <option value="ftp" ${protocol === 'ftp' ? 'selected' : ''}>FTP (Port 2121)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Port</label>
                        <input type="number" name="webcams[${idx}][port]" id="port-${idx}" 
                               value="${cam.port || (protocol === 'sftp' ? 2222 : (protocol === 'ftps' ? 2122 : 2121))}" 
                               min="1" max="65535">
                    </div>
                    
                    <div class="form-group">
                        <label>Max File Size (MB)</label>
                        <input type="number" name="webcams[${idx}][max_file_size_mb]" 
                               value="${cam.max_file_size_mb || 100}" min="1" max="100">
                    </div>
                    
                    <div class="form-group">
                        <label>Generated Credentials</label>
                        <div class="credential-display">
                            <div>
                                <strong>Username:</strong> <span class="credential-value" id="username-${idx}">${username}</span>
                            </div>
                            <button type="button" class="btn btn-copy" onclick="copyCredential('username-${idx}')">Copy</button>
                        </div>
                        <input type="hidden" name="webcams[${idx}][push_username]" id="push_username-${idx}" value="${username}">
                    </div>
                    
                    <div class="form-group">
                        <div class="credential-display">
                            <div>
                                <strong>Password:</strong> <span class="credential-value" id="password-${idx}">${password}</span>
                            </div>
                            <button type="button" class="btn btn-copy" onclick="copyCredential('password-${idx}')">Copy</button>
                        </div>
                        <input type="hidden" name="webcams[${idx}][push_password]" id="push_password-${idx}" value="${password}">
                    </div>
                    
                    <div class="help-text">
                        <strong>Important:</strong> Save these credentials! You'll need them to configure your camera.
                        <button type="button" class="btn btn-secondary" style="margin-left: 1rem; padding: 0.25rem 0.75rem; font-size: 0.75rem;" 
                                onclick="regenerateCredentials(${idx})">Regenerate</button>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Position</label>
                        <input type="text" name="webcams[${idx}][position]" value="${escapeHtml(cam.position || '')}"
                               placeholder="north, south, east, west">
                    </div>
                    <div class="form-group">
                        <label>Refresh Seconds</label>
                        <input type="number" name="webcams[${idx}][refresh_seconds]" 
                               value="${cam.refresh_seconds || ''}" min="60" placeholder="300">
                        <div class="help-text">Minimum 60 seconds (default: 300)</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Partner Name</label>
                        <input type="text" name="webcams[${idx}][partner_name]" 
                               value="${escapeHtml(cam.partner_name || '')}">
                    </div>
                    <div class="form-group">
                        <label>Partner Link</label>
                        <input type="url" name="webcams[${idx}][partner_link]" 
                               value="${escapeHtml(cam.partner_link || '')}">
                    </div>
                </div>
            `;
        }
        
        function toggleCameraType(select, idx) {
            const isPush = select.value === 'push';
            document.getElementById('camera-pull-' + idx).style.display = isPush ? 'none' : 'block';
            document.getElementById('camera-push-' + idx).style.display = isPush ? 'block' : 'none';
            
            // Update required attributes
            const urlInput = document.querySelector(`input[name="webcams[${idx}][url]"]`);
            if (urlInput) {
                urlInput.required = !isPush;
            }
        }
        
        function updatePushPort(select, idx) {
            const protocol = select.value;
            const portMap = { sftp: 2222, ftps: 2122, ftp: 2121 };
            const portInput = document.getElementById('port-' + idx);
            if (portInput) {
                portInput.value = portMap[protocol] || 2222;
            }
        }
        
        function generateCredential() {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            let credential = '';
            for (let i = 0; i < 14; i++) {
                credential += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            return credential;
        }
        
        function regenerateCredentials(idx) {
            const username = generateCredential();
            const password = generateCredential();
            
            document.getElementById('username-' + idx).textContent = username;
            document.getElementById('password-' + idx).textContent = password;
            document.getElementById('push_username-' + idx).value = username;
            document.getElementById('push_password-' + idx).value = password;
        }
        
        function copyCredential(id) {
            const el = document.getElementById(id);
            const text = el.textContent;
            navigator.clipboard.writeText(text).then(() => {
                const btn = event.target;
                const original = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(() => btn.textContent = original, 2000);
            });
        }
        
        function attachWebcamListeners(container) {
            const typeSelect = container.querySelector('select[name*="[type]"]');
            if (typeSelect) {
                const idx = typeSelect.name.match(/\[(\d+)\]/)[1];
                typeSelect.addEventListener('change', () => toggleCameraType(typeSelect, idx));
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function downloadConfig() {
            const config = <?= $generatedConfig ? json_encode($generatedConfig) : 'null' ?>;
            if (!config) return;
            
            const blob = new Blob([config], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = '<?= htmlspecialchars($airportId ?: 'airport') ?>-config.json';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
        
        function copyToClipboard() {
            const config = <?= $generatedConfig ? json_encode($generatedConfig) : 'null' ?>;
            if (!config) return;
            
            navigator.clipboard.writeText(config).then(() => {
                alert('Configuration copied to clipboard!');
            });
        }
    </script>
</body>
</html>

<?php
/**
 * Render webcam form (for server-side rendering)
 */
function renderWebcamForm($idx, $cam) {
    $type = $cam['type'] ?? 'pull';
    $isPush = $type === 'push';
    $protocol = $cam['protocol'] ?? 'sftp';
    $username = $cam['push_username'] ?? generateRandomCredential();
    $password = $cam['push_password'] ?? generateRandomCredential();
    ?>
    <div class="camera-item">
        <div class="camera-header">
            <h4>Webcam <?= $idx + 1 ?></h4>
            <button type="button" class="btn btn-remove" onclick="removeWebcam(this)">Remove</button>
        </div>
        
        <div class="form-group">
            <label>Camera Name <span class="required">*</span></label>
            <input type="text" name="webcams[<?= $idx ?>][name]" 
                   value="<?= htmlspecialchars($cam['name'] ?? '') ?>" required>
        </div>
        
        <div class="form-group">
            <label>Camera Type <span class="required">*</span></label>
            <select name="webcams[<?= $idx ?>][type]" onchange="toggleCameraType(this, <?= $idx ?>)" required>
                <option value="pull" <?= $type === 'pull' ? 'selected' : '' ?>>Pull (MJPEG/RTSP/Static)</option>
                <option value="push" <?= $type === 'push' ? 'selected' : '' ?>>Push (FTP/FTPS/SFTP)</option>
            </select>
        </div>
        
        <div id="camera-pull-<?= $idx ?>" style="display: <?= $isPush ? 'none' : 'block' ?>">
            <div class="form-group">
                <label>URL <span class="required">*</span></label>
                <input type="url" name="webcams[<?= $idx ?>][url]" 
                       value="<?= htmlspecialchars($cam['url'] ?? '') ?>" 
                       <?= $isPush ? '' : 'required' ?>>
            </div>
            
            <div class="form-group">
                <label>Pull Type</label>
                <select name="webcams[<?= $idx ?>][pull_type]">
                    <option value="">Auto-detect</option>
                    <option value="mjpeg" <?= ($cam['pull_type'] ?? '') === 'mjpeg' ? 'selected' : '' ?>>MJPEG Stream</option>
                    <option value="rtsp" <?= ($cam['pull_type'] ?? '') === 'rtsp' ? 'selected' : '' ?>>RTSP Stream</option>
                    <option value="static_jpeg" <?= ($cam['pull_type'] ?? '') === 'static_jpeg' ? 'selected' : '' ?>>Static JPEG</option>
                    <option value="static_png" <?= ($cam['pull_type'] ?? '') === 'static_png' ? 'selected' : '' ?>>Static PNG</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>RTSP Transport</label>
                <select name="webcams[<?= $idx ?>][rtsp_transport]">
                    <option value="">Default</option>
                    <option value="tcp" <?= ($cam['rtsp_transport'] ?? '') === 'tcp' ? 'selected' : '' ?>>TCP</option>
                    <option value="udp" <?= ($cam['rtsp_transport'] ?? '') === 'udp' ? 'selected' : '' ?>>UDP</option>
                </select>
            </div>
        </div>
        
        <div id="camera-push-<?= $idx ?>" style="display: <?= $isPush ? 'block' : 'none' ?>">
            <div class="form-group">
                <label>Protocol <span class="required">*</span></label>
                <select name="webcams[<?= $idx ?>][protocol]" onchange="updatePushPort(this, <?= $idx ?>)" required>
                    <option value="sftp" <?= $protocol === 'sftp' ? 'selected' : '' ?>>SFTP (Port 2222)</option>
                    <option value="ftps" <?= $protocol === 'ftps' ? 'selected' : '' ?>>FTPS (Port 2122)</option>
                    <option value="ftp" <?= $protocol === 'ftp' ? 'selected' : '' ?>>FTP (Port 2121)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Port</label>
                <input type="number" name="webcams[<?= $idx ?>][port]" id="port-<?= $idx ?>" 
                       value="<?= htmlspecialchars($cam['port'] ?? ($protocol === 'sftp' ? 2222 : ($protocol === 'ftps' ? 2122 : 2121))) ?>" 
                       min="1" max="65535">
            </div>
            
            <div class="form-group">
                <label>Max File Size (MB)</label>
                <input type="number" name="webcams[<?= $idx ?>][max_file_size_mb]" 
                       value="<?= htmlspecialchars($cam['max_file_size_mb'] ?? 100) ?>" min="1" max="100">
            </div>
            
            <div class="form-group">
                <label>Generated Credentials</label>
                <div class="credential-display">
                    <div>
                        <strong>Username:</strong> <span class="credential-value" id="username-<?= $idx ?>"><?= htmlspecialchars($username) ?></span>
                    </div>
                    <button type="button" class="btn btn-copy" onclick="copyCredential('username-<?= $idx ?>')">Copy</button>
                </div>
                <input type="hidden" name="webcams[<?= $idx ?>][push_username]" id="push_username-<?= $idx ?>" value="<?= htmlspecialchars($username) ?>">
            </div>
            
            <div class="form-group">
                <div class="credential-display">
                    <div>
                        <strong>Password:</strong> <span class="credential-value" id="password-<?= $idx ?>"><?= htmlspecialchars($password) ?></span>
                    </div>
                    <button type="button" class="btn btn-copy" onclick="copyCredential('password-<?= $idx ?>')">Copy</button>
                </div>
                <input type="hidden" name="webcams[<?= $idx ?>][push_password]" id="push_password-<?= $idx ?>" value="<?= htmlspecialchars($password) ?>">
            </div>
            
            <div class="help-text">
                <strong>Important:</strong> Save these credentials! You'll need them to configure your camera.
                <button type="button" class="btn btn-secondary" style="margin-left: 1rem; padding: 0.25rem 0.75rem; font-size: 0.75rem;" 
                        onclick="regenerateCredentials(<?= $idx ?>)">Regenerate</button>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Position</label>
                <input type="text" name="webcams[<?= $idx ?>][position]" 
                       value="<?= htmlspecialchars($cam['position'] ?? '') ?>"
                       placeholder="north, south, east, west">
            </div>
            <div class="form-group">
                <label>Refresh Seconds</label>
                <input type="number" name="webcams[<?= $idx ?>][refresh_seconds]" 
                       value="<?= htmlspecialchars($cam['refresh_seconds'] ?? '') ?>" min="60" placeholder="300">
                <div class="help-text">Minimum 60 seconds (default: 300)</div>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Partner Name</label>
                <input type="text" name="webcams[<?= $idx ?>][partner_name]" 
                       value="<?= htmlspecialchars($cam['partner_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Partner Link</label>
                <input type="url" name="webcams[<?= $idx ?>][partner_link]" 
                       value="<?= htmlspecialchars($cam['partner_link'] ?? '') ?>">
            </div>
        </div>
    </div>
    <?php
}
?>

