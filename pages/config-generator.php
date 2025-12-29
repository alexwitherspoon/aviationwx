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
require_once __DIR__ . '/../lib/constants.php';

// Rate limiting
$rateLimitKey = 'config_generator';
if (!checkRateLimit($rateLimitKey, RATE_LIMIT_CONFIG_GENERATOR_MAX, RATE_LIMIT_CONFIG_GENERATOR_WINDOW)) {
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
 * 
 * Generates a cryptographically secure random 14-character alphanumeric string.
 * Used for generating SFTP/FTP usernames and passwords for push webcams.
 * 
 * @return string 14-character alphanumeric string (mixed case)
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
 * 
 * Checks if an airport ID exists in the configuration without exposing
 * which airports are configured. Used for validation in config generator.
 * 
 * @param string $airportId Airport ID to check
 * @return bool True if airport exists, false otherwise
 */
function airportExists($airportId) {
    $config = loadConfig();
    if (!$config) {
        return false;
    }
    
    return isset($config['airports'][strtolower($airportId)]);
}

/**
 * Validate airport configuration form data
 * 
 * Validates all required fields and format constraints for airport configuration.
 * Returns validation result with error messages.
 * 
 * @param array $formData Form submission data
 * @return array {
 *   'valid' => bool,    // True if validation passes
 *   'errors' => array   // Array of error messages
 * }
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
    
    // Access type (required)
    $accessType = $formData['access_type'] ?? '';
    if (empty($accessType)) {
        $errors[] = 'Access type is required';
    } elseif (!in_array($accessType, ['public', 'private'], true)) {
        $errors[] = 'Access type must be "public" or "private"';
    }
    
    // Permission required (required if private)
    if ($accessType === 'private') {
        if (!isset($formData['permission_required'])) {
            $errors[] = 'Permission required field is required when access type is private';
        } elseif (!is_bool($formData['permission_required']) && $formData['permission_required'] !== '0' && $formData['permission_required'] !== '1') {
            $errors[] = 'Permission required must be a boolean value';
        }
    }
    
    // Tower status (required)
    $towerStatus = $formData['tower_status'] ?? '';
    if (empty($towerStatus)) {
        $errors[] = 'Tower status is required';
    } elseif (!in_array($towerStatus, ['towered', 'non_towered'], true)) {
        $errors[] = 'Tower status must be "towered" or "non_towered"';
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
    
    // Weather source validation
    $weatherType = $formData['weather_type'] ?? '';
    if ($weatherType === 'ambient') {
        if (empty(trim($formData['ambient_api_key'] ?? ''))) {
            $errors[] = 'Ambient Weather API Key is required';
        }
        if (empty(trim($formData['ambient_application_key'] ?? ''))) {
            $errors[] = 'Ambient Weather Application Key is required';
        }
        $macAddress = trim($formData['ambient_mac_address'] ?? '');
        if (!empty($macAddress)) {
            $macAddressClean = preg_replace('/[:\-]/', '', $macAddress);
            if (!preg_match('/^[0-9A-Fa-f]{12}$/', $macAddressClean)) {
                $errors[] = 'Ambient Weather MAC Address must be a valid format (e.g., AA:BB:CC:DD:EE:FF)';
            }
        }
    } elseif ($weatherType === 'weatherlink') {
        if (empty(trim($formData['weatherlink_api_key'] ?? ''))) {
            $errors[] = 'WeatherLink API Key is required';
        }
        if (empty(trim($formData['weatherlink_api_secret'] ?? ''))) {
            $errors[] = 'WeatherLink API Secret is required';
        }
    } elseif ($weatherType === 'synopticdata') {
        if (empty(trim($formData['synopticdata_api_token'] ?? ''))) {
            $errors[] = 'SynopticData API Token is required';
        }
    } elseif ($weatherType === 'pwsweather') {
        if (empty(trim($formData['pwsweather_client_id'] ?? ''))) {
            $errors[] = 'PWSWeather Client ID is required';
        }
        if (empty(trim($formData['pwsweather_client_secret'] ?? ''))) {
            $errors[] = 'PWSWeather Client Secret is required';
        }
        if (empty(trim($formData['pwsweather_station_id'] ?? ''))) {
            $errors[] = 'PWSWeather Station ID is required';
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Generate airports.json configuration snippet from form data
 * 
 * Processes validated form data and generates a complete airport configuration
 * object ready for insertion into airports.json. Handles all airport fields
 * including identifiers, status flags, weather sources, webcams, and metadata.
 * 
 * @param array $formData Validated form submission data
 * @return string JSON-encoded airport configuration (pretty-printed)
 */
function generateConfigSnippet($formData) {
    $airportId = strtolower(trim($formData['airport_id'] ?? ''));
    
    $config = [
        'airports' => [
            $airportId => []
        ]
    ];
    
    $airport = &$config['airports'][$airportId];
    
    // ============================================================================
    // Basic Information
    // ============================================================================
    $airport['name'] = trim($formData['airport_name'] ?? '');
    $airport['icao'] = strtoupper($airportId);
    
    // Coordinates (required)
    if (!empty($formData['latitude'] ?? '')) {
        $airport['lat'] = floatval($formData['latitude']);
    }
    if (!empty($formData['longitude'] ?? '')) {
        $airport['lon'] = floatval($formData['longitude']);
    }
    
    // Location
    if (!empty($formData['elevation'] ?? '')) {
        $airport['elevation_ft'] = intval($formData['elevation']);
    }
    if (!empty($formData['timezone'] ?? '')) {
        $airport['timezone'] = trim($formData['timezone']);
    }
    if (!empty($formData['address'] ?? '')) {
        $airport['address'] = trim($formData['address']);
    }
    
    // ============================================================================
    // Identifiers
    // ============================================================================
    if (!empty($formData['iata'] ?? '')) {
        $iata = strtoupper(trim($formData['iata']));
        // Validate IATA format: exactly 3 uppercase letters
        if (preg_match('/^[A-Z]{3}$/', $iata) === 1) {
            $airport['iata'] = $iata;
        }
    }
    if (!empty($formData['faa'] ?? '')) {
        $faa = strtoupper(trim($formData['faa']));
        // Validate FAA format: 3-4 alphanumeric characters
        if (preg_match('/^[A-Z0-9]{3,4}$/', $faa) === 1) {
            $airport['faa'] = $faa;
        }
    }
    if (!empty($formData['formerly'] ?? '')) {
        $formerly = array_filter(array_map('trim', explode(',', $formData['formerly'])));
        if (!empty($formerly)) {
            // Sanitize former identifiers (uppercase, alphanumeric only, 3-4 chars)
            $sanitized = array_map(function($id) {
                $id = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($id)));
                return (strlen($id) >= 3 && strlen($id) <= 4) ? $id : null;
            }, $formerly);
            $sanitized = array_filter($sanitized, function($id) {
                return $id !== null;
            });
            if (!empty($sanitized)) {
                $airport['formerly'] = array_values($sanitized);
            }
        }
    }
    
    // ============================================================================
    // Status Flags
    // ============================================================================
    $airport['enabled'] = isset($formData['enabled']) && ($formData['enabled'] === '1' || $formData['enabled'] === true);
    $airport['maintenance'] = isset($formData['maintenance']) && ($formData['maintenance'] === '1' || $formData['maintenance'] === true);
    
    // ============================================================================
    // Access & Tower Status (Required)
    // ============================================================================
    if (!empty($formData['access_type'] ?? '')) {
        $accessType = $formData['access_type'];
        // Validate against allowed values (already validated in validateAirportConfig, but double-check)
        if (in_array($accessType, ['public', 'private'], true)) {
            $airport['access_type'] = $accessType;
        }
    }
    if (!empty($formData['tower_status'] ?? '')) {
        $towerStatus = $formData['tower_status'];
        // Validate against allowed values (already validated in validateAirportConfig, but double-check)
        if (in_array($towerStatus, ['towered', 'non_towered'], true)) {
            $airport['tower_status'] = $towerStatus;
        }
    }
    
    // Permission required (only for private airports)
    if (($formData['access_type'] ?? '') === 'private') {
        $airport['permission_required'] = isset($formData['permission_required']) && ($formData['permission_required'] === '1' || $formData['permission_required'] === true);
    }
    
    // ============================================================================
    // Refresh Overrides
    // ============================================================================
    if (!empty($formData['webcam_refresh_seconds'] ?? '')) {
        $refresh = intval($formData['webcam_refresh_seconds']);
        if ($refresh >= 5) {
            $airport['webcam_refresh_seconds'] = $refresh;
        }
    }
    if (!empty($formData['weather_refresh_seconds'] ?? '')) {
        $refresh = intval($formData['weather_refresh_seconds']);
        if ($refresh >= 5) {
            $airport['weather_refresh_seconds'] = $refresh;
        }
    }
    
    // ============================================================================
    // Feature Overrides
    // ============================================================================
    if (isset($formData['webcam_history_enabled'])) {
        $airport['webcam_history_enabled'] = ($formData['webcam_history_enabled'] === '1' || $formData['webcam_history_enabled'] === true);
    }
    if (!empty($formData['webcam_history_max_frames'] ?? '')) {
        $frames = intval($formData['webcam_history_max_frames']);
        if ($frames > 0) {
            $airport['webcam_history_max_frames'] = $frames;
        }
    }
    
    // Default preferences (validate against allowed values)
    $defaultPrefs = [];
    $validPrefs = [
        'time_format' => ['12hr', '24hr'],
        'temp_unit' => ['F', 'C'],
        'distance_unit' => ['ft', 'm'],
        'baro_unit' => ['inHg', 'hPa', 'mmHg'],
        'wind_speed_unit' => ['kts', 'mph', 'km/h', 'm/s']
    ];
    
    if (!empty($formData['pref_time_format'] ?? '')) {
        $value = $formData['pref_time_format'];
        if (in_array($value, $validPrefs['time_format'], true)) {
            $defaultPrefs['time_format'] = $value;
        }
    }
    if (!empty($formData['pref_temp_unit'] ?? '')) {
        $value = $formData['pref_temp_unit'];
        if (in_array($value, $validPrefs['temp_unit'], true)) {
            $defaultPrefs['temp_unit'] = $value;
        }
    }
    if (!empty($formData['pref_distance_unit'] ?? '')) {
        $value = $formData['pref_distance_unit'];
        if (in_array($value, $validPrefs['distance_unit'], true)) {
            $defaultPrefs['distance_unit'] = $value;
        }
    }
    if (!empty($formData['pref_baro_unit'] ?? '')) {
        $value = $formData['pref_baro_unit'];
        if (in_array($value, $validPrefs['baro_unit'], true)) {
            $defaultPrefs['baro_unit'] = $value;
        }
    }
    if (!empty($formData['pref_wind_speed_unit'] ?? '')) {
        $value = $formData['pref_wind_speed_unit'];
        if (in_array($value, $validPrefs['wind_speed_unit'], true)) {
            $defaultPrefs['wind_speed_unit'] = $value;
        }
    }
    if (!empty($defaultPrefs)) {
        $airport['default_preferences'] = $defaultPrefs;
    }
    
    // ============================================================================
    // Weather Sources
    // ============================================================================
    
    // Primary weather source
    if (!empty($formData['weather_type'] ?? '')) {
        $weatherType = $formData['weather_type'];
        $airport['weather_source'] = [];
        
        if ($weatherType === 'tempest') {
            $airport['weather_source']['type'] = 'tempest';
            if (!empty($formData['tempest_station_id'] ?? '')) {
                $airport['weather_source']['station_id'] = trim($formData['tempest_station_id']);
            }
            if (!empty($formData['tempest_api_key'] ?? '')) {
                $airport['weather_source']['api_key'] = trim($formData['tempest_api_key']);
            }
        } elseif ($weatherType === 'ambient') {
            $airport['weather_source']['type'] = 'ambient';
            $airport['weather_source']['api_key'] = trim($formData['ambient_api_key'] ?? '');
            $airport['weather_source']['application_key'] = trim($formData['ambient_application_key'] ?? '');
            $macAddress = trim($formData['ambient_mac_address'] ?? '');
            $macAddress = preg_replace('/\s+/', '', $macAddress);
            if (!empty($macAddress)) {
                $airport['weather_source']['mac_address'] = $macAddress;
            }
        } elseif ($weatherType === 'weatherlink') {
            $airport['weather_source']['type'] = 'weatherlink';
            $airport['weather_source']['api_key'] = trim($formData['weatherlink_api_key'] ?? '');
            $airport['weather_source']['api_secret'] = trim($formData['weatherlink_api_secret'] ?? '');
            if (!empty($formData['weatherlink_station_id'] ?? '')) {
                $airport['weather_source']['station_id'] = trim($formData['weatherlink_station_id']);
            }
        } elseif ($weatherType === 'synopticdata') {
            $airport['weather_source']['type'] = 'synopticdata';
            $airport['weather_source']['api_token'] = trim($formData['synopticdata_api_token'] ?? '');
            if (!empty($formData['synopticdata_station_id'] ?? '')) {
                $airport['weather_source']['station_id'] = trim($formData['synopticdata_station_id']);
            }
        } elseif ($weatherType === 'pwsweather') {
            $airport['weather_source']['type'] = 'pwsweather';
            $airport['weather_source']['client_id'] = trim($formData['pwsweather_client_id'] ?? '');
            $airport['weather_source']['client_secret'] = trim($formData['pwsweather_client_secret'] ?? '');
            if (!empty($formData['pwsweather_station_id'] ?? '')) {
                $airport['weather_source']['station_id'] = trim($formData['pwsweather_station_id']);
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
    
    // Backup weather source
    if (!empty($formData['weather_backup_type'] ?? '')) {
        $backupType = $formData['weather_backup_type'];
        $airport['weather_source_backup'] = [];
        
        if ($backupType === 'tempest') {
            $airport['weather_source_backup']['type'] = 'tempest';
            if (!empty($formData['backup_tempest_station_id'] ?? '')) {
                $airport['weather_source_backup']['station_id'] = trim($formData['backup_tempest_station_id']);
            }
            if (!empty($formData['backup_tempest_api_key'] ?? '')) {
                $airport['weather_source_backup']['api_key'] = trim($formData['backup_tempest_api_key']);
            }
        } elseif ($backupType === 'ambient') {
            $airport['weather_source_backup']['type'] = 'ambient';
            $airport['weather_source_backup']['api_key'] = trim($formData['backup_ambient_api_key'] ?? '');
            $airport['weather_source_backup']['application_key'] = trim($formData['backup_ambient_application_key'] ?? '');
            $macAddress = trim($formData['backup_ambient_mac_address'] ?? '');
            $macAddress = preg_replace('/\s+/', '', $macAddress);
            if (!empty($macAddress)) {
                $airport['weather_source_backup']['mac_address'] = $macAddress;
            }
        } elseif ($backupType === 'weatherlink') {
            $airport['weather_source_backup']['type'] = 'weatherlink';
            $airport['weather_source_backup']['api_key'] = trim($formData['backup_weatherlink_api_key'] ?? '');
            $airport['weather_source_backup']['api_secret'] = trim($formData['backup_weatherlink_api_secret'] ?? '');
            if (!empty($formData['backup_weatherlink_station_id'] ?? '')) {
                $airport['weather_source_backup']['station_id'] = trim($formData['backup_weatherlink_station_id']);
            }
        } elseif ($backupType === 'synopticdata') {
            $airport['weather_source_backup']['type'] = 'synopticdata';
            $airport['weather_source_backup']['api_token'] = trim($formData['backup_synopticdata_api_token'] ?? '');
            if (!empty($formData['backup_synopticdata_station_id'] ?? '')) {
                $airport['weather_source_backup']['station_id'] = trim($formData['backup_synopticdata_station_id']);
            }
        } elseif ($backupType === 'pwsweather') {
            $airport['weather_source_backup']['type'] = 'pwsweather';
            $airport['weather_source_backup']['client_id'] = trim($formData['backup_pwsweather_client_id'] ?? '');
            $airport['weather_source_backup']['client_secret'] = trim($formData['backup_pwsweather_client_secret'] ?? '');
            if (!empty($formData['backup_pwsweather_station_id'] ?? '')) {
                $airport['weather_source_backup']['station_id'] = trim($formData['backup_pwsweather_station_id']);
            }
        }
    }
    
    // METAR station (can be standalone or with weather_source)
    if (!empty($formData['metar_station'] ?? '')) {
        $airport['metar_station'] = strtoupper(trim($formData['metar_station']));
    }
    
    // Nearby METAR stations
    if (!empty($formData['nearby_metar_stations'] ?? '')) {
        $stations = array_filter(array_map('trim', explode(',', $formData['nearby_metar_stations'])));
        if (!empty($stations)) {
            $airport['nearby_metar_stations'] = array_map('strtoupper', array_values($stations));
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
    
    // ============================================================================
    // Services
    // ============================================================================
    if (!empty($formData['services'] ?? '')) {
        $serviceList = array_filter(array_map('trim', explode(',', $formData['services'])));
        if (!empty($serviceList)) {
            $services = [];
            $fuelTypes = [];
            $hasRepairs = false;
            
            foreach ($serviceList as $service) {
                $serviceLower = strtolower($service);
                
                if (strpos($serviceLower, 'repair') !== false || strpos($serviceLower, 'maintenance') !== false) {
                    $hasRepairs = true;
                }
                
                if (strpos($serviceLower, 'fuel') !== false || 
                    strpos($serviceLower, '100ll') !== false || 
                    strpos($serviceLower, '100 ll') !== false ||
                    strpos($serviceLower, 'jet') !== false || 
                    strpos($serviceLower, 'jeta') !== false ||
                    strpos($serviceLower, 'avgas') !== false ||
                    strpos($serviceLower, 'mogas') !== false) {
                    $fuelTypes[] = trim($service);
                }
            }
            
            if (!empty($fuelTypes)) {
                $services['fuel'] = implode(', ', $fuelTypes);
            }
            
            if ($hasRepairs) {
                $services['repairs_available'] = true;
            }
            
            if (!empty($services)) {
                $airport['services'] = $services;
            }
        }
    }
    
    // ============================================================================
    // Partners
    // ============================================================================
    if (isset($formData['partners']) && is_array($formData['partners'])) {
        $partners = [];
        foreach ($formData['partners'] as $partner) {
            if (empty($partner['name'] ?? '')) continue;
            
            $partnerData = [
                'name' => trim($partner['name'])
            ];
            
            if (!empty($partner['url'] ?? '')) {
                $partnerData['url'] = trim($partner['url']);
            }
            if (!empty($partner['logo'] ?? '')) {
                $partnerData['logo'] = trim($partner['logo']);
            }
            if (!empty($partner['description'] ?? '')) {
                $partnerData['description'] = trim($partner['description']);
            }
            
            $partners[] = $partnerData;
        }
        if (!empty($partners)) {
            $airport['partners'] = $partners;
        }
    }
    
    // ============================================================================
    // Links
    // ============================================================================
    if (isset($formData['links']) && is_array($formData['links'])) {
        $links = [];
        foreach ($formData['links'] as $link) {
            if (empty($link['label'] ?? '') || empty($link['url'] ?? '')) continue;
            
            $links[] = [
                'label' => trim($link['label']),
                'url' => trim($link['url'])
            ];
        }
        if (!empty($links)) {
            $airport['links'] = $links;
        }
    }
    
    // ============================================================================
    // Link Overrides
    // ============================================================================
    if (!empty($formData['airnav_url'] ?? '')) {
        $airport['airnav_url'] = trim($formData['airnav_url']);
    }
    if (!empty($formData['skyvector_url'] ?? '')) {
        $airport['skyvector_url'] = trim($formData['skyvector_url']);
    }
    if (!empty($formData['aopa_url'] ?? '')) {
        $airport['aopa_url'] = trim($formData['aopa_url']);
    }
    if (!empty($formData['faa_weather_url'] ?? '')) {
        $airport['faa_weather_url'] = trim($formData['faa_weather_url']);
    }
    if (!empty($formData['foreflight_url'] ?? '')) {
        $airport['foreflight_url'] = trim($formData['foreflight_url']);
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
                               value="<?= htmlspecialchars($_POST['address'] ?? '') ?>"
                               placeholder="City, State">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="access_type">Access Type <span class="required">*</span></label>
                            <select id="access_type" name="access_type" required>
                                <option value="">Select...</option>
                                <option value="public" <?= ($_POST['access_type'] ?? '') === 'public' ? 'selected' : '' ?>>Public</option>
                                <option value="private" <?= ($_POST['access_type'] ?? '') === 'private' ? 'selected' : '' ?>>Private</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="tower_status">Tower Status <span class="required">*</span></label>
                            <select id="tower_status" name="tower_status" required>
                                <option value="">Select...</option>
                                <option value="towered" <?= ($_POST['tower_status'] ?? '') === 'towered' ? 'selected' : '' ?>>Towered</option>
                                <option value="non_towered" <?= ($_POST['tower_status'] ?? '') === 'non_towered' ? 'selected' : '' ?>>Non-Towered</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group" id="permission_required_group" style="display: none;">
                        <label>
                            <input type="checkbox" id="permission_required" name="permission_required" value="1"
                                   <?= isset($_POST['permission_required']) && $_POST['permission_required'] ? 'checked' : '' ?>>
                            Permission Required to Land
                        </label>
                        <div class="help-text">Check this box if prior permission is required to land at this private airport</div>
                    </div>
                </div>
                
                <!-- Identifiers -->
                <div class="form-section">
                    <h2>Identifiers</h2>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="iata">IATA Code</label>
                            <input type="text" id="iata" name="iata" 
                                   value="<?= htmlspecialchars($_POST['iata'] ?? '') ?>"
                                   placeholder="SPB" maxlength="3" pattern="[A-Z]{3}">
                            <div class="help-text">3-letter IATA code (optional)</div>
                        </div>
                        <div class="form-group">
                            <label for="faa">FAA Identifier</label>
                            <input type="text" id="faa" name="faa" 
                                   value="<?= htmlspecialchars($_POST['faa'] ?? '') ?>"
                                   placeholder="03S" maxlength="4">
                            <div class="help-text">FAA LID (3-4 characters, optional)</div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="formerly">Former Identifiers</label>
                        <input type="text" id="formerly" name="formerly"
                               value="<?= htmlspecialchars($_POST['formerly'] ?? '') ?>"
                               placeholder="S48, OLD1, OLD2">
                        <div class="help-text">Comma-separated list of previous identifiers (for NOTAM matching)</div>
                    </div>
                </div>
                
                <!-- Status Flags -->
                <div class="form-section">
                    <h2>Status</h2>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="enabled" value="1"
                                       <?= isset($_POST['enabled']) && $_POST['enabled'] ? 'checked' : '' ?>>
                                Enabled
                            </label>
                            <div class="help-text">Airport must be enabled to be accessible</div>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="maintenance" value="1"
                                       <?= isset($_POST['maintenance']) && $_POST['maintenance'] ? 'checked' : '' ?>>
                                Maintenance Mode
                            </label>
                            <div class="help-text">Shows maintenance banner on airport page</div>
                        </div>
                    </div>
                </div>
                
                <!-- Refresh Overrides -->
                <div class="form-section">
                    <h2>Refresh Overrides</h2>
                    <div class="help-text" style="margin-bottom: 1rem;">Override global refresh intervals for this airport</div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="webcam_refresh_seconds">Webcam Refresh (seconds)</label>
                            <input type="number" id="webcam_refresh_seconds" name="webcam_refresh_seconds"
                                   value="<?= htmlspecialchars($_POST['webcam_refresh_seconds'] ?? '') ?>"
                                   min="5" step="1">
                            <div class="help-text">Minimum: 5 seconds</div>
                        </div>
                        <div class="form-group">
                            <label for="weather_refresh_seconds">Weather Refresh (seconds)</label>
                            <input type="number" id="weather_refresh_seconds" name="weather_refresh_seconds"
                                   value="<?= htmlspecialchars($_POST['weather_refresh_seconds'] ?? '') ?>"
                                   min="5" step="1">
                            <div class="help-text">Minimum: 5 seconds</div>
                        </div>
                    </div>
                </div>
                
                <!-- Feature Overrides -->
                <div class="form-section">
                    <h2>Feature Overrides</h2>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="webcam_history_enabled" value="1"
                                       <?= isset($_POST['webcam_history_enabled']) && $_POST['webcam_history_enabled'] ? 'checked' : '' ?>>
                                Enable Webcam History
                            </label>
                            <div class="help-text">Enable time-lapse for this airport</div>
                        </div>
                        <div class="form-group">
                            <label for="webcam_history_max_frames">Max History Frames</label>
                            <input type="number" id="webcam_history_max_frames" name="webcam_history_max_frames"
                                   value="<?= htmlspecialchars($_POST['webcam_history_max_frames'] ?? '') ?>"
                                   min="1" step="1">
                            <div class="help-text">Maximum frames to store for time-lapse</div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Default Unit Preferences</label>
                        <div class="form-row" style="margin-top: 0.5rem;">
                            <div class="form-group">
                                <label for="pref_time_format">Time Format</label>
                                <select id="pref_time_format" name="pref_time_format">
                                    <option value="">Default</option>
                                    <option value="12hr" <?= ($_POST['pref_time_format'] ?? '') === '12hr' ? 'selected' : '' ?>>12 Hour</option>
                                    <option value="24hr" <?= ($_POST['pref_time_format'] ?? '') === '24hr' ? 'selected' : '' ?>>24 Hour</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="pref_temp_unit">Temperature</label>
                                <select id="pref_temp_unit" name="pref_temp_unit">
                                    <option value="">Default</option>
                                    <option value="F" <?= ($_POST['pref_temp_unit'] ?? '') === 'F' ? 'selected' : '' ?>>Fahrenheit</option>
                                    <option value="C" <?= ($_POST['pref_temp_unit'] ?? '') === 'C' ? 'selected' : '' ?>>Celsius</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="pref_distance_unit">Distance</label>
                                <select id="pref_distance_unit" name="pref_distance_unit">
                                    <option value="">Default</option>
                                    <option value="ft" <?= ($_POST['pref_distance_unit'] ?? '') === 'ft' ? 'selected' : '' ?>>Feet</option>
                                    <option value="m" <?= ($_POST['pref_distance_unit'] ?? '') === 'm' ? 'selected' : '' ?>>Meters</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="pref_baro_unit">Barometric Pressure</label>
                                <select id="pref_baro_unit" name="pref_baro_unit">
                                    <option value="">Default</option>
                                    <option value="inHg" <?= ($_POST['pref_baro_unit'] ?? '') === 'inHg' ? 'selected' : '' ?>>inHg</option>
                                    <option value="hPa" <?= ($_POST['pref_baro_unit'] ?? '') === 'hPa' ? 'selected' : '' ?>>hPa</option>
                                    <option value="mmHg" <?= ($_POST['pref_baro_unit'] ?? '') === 'mmHg' ? 'selected' : '' ?>>mmHg</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="pref_wind_speed_unit">Wind Speed</label>
                                <select id="pref_wind_speed_unit" name="pref_wind_speed_unit">
                                    <option value="">Default</option>
                                    <option value="kts" <?= ($_POST['pref_wind_speed_unit'] ?? '') === 'kts' ? 'selected' : '' ?>>Knots</option>
                                    <option value="mph" <?= ($_POST['pref_wind_speed_unit'] ?? '') === 'mph' ? 'selected' : '' ?>>MPH</option>
                                    <option value="km/h" <?= ($_POST['pref_wind_speed_unit'] ?? '') === 'km/h' ? 'selected' : '' ?>>km/h</option>
                                    <option value="m/s" <?= ($_POST['pref_wind_speed_unit'] ?? '') === 'm/s' ? 'selected' : '' ?>>m/s</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Weather Source -->
                <div class="form-section">
                    <h2>Weather Source</h2>
                    
                    <div class="form-group">
                        <label for="weather_type">Primary Weather Source Type</label>
                        <select id="weather_type" name="weather_type">
                            <option value="">None</option>
                            <option value="metar" <?= ($_POST['weather_type'] ?? '') === 'metar' ? 'selected' : '' ?>>METAR (Aviation Weather)</option>
                            <option value="tempest" <?= ($_POST['weather_type'] ?? '') === 'tempest' ? 'selected' : '' ?>>Tempest Weather Station</option>
                            <option value="ambient" <?= ($_POST['weather_type'] ?? '') === 'ambient' ? 'selected' : '' ?>>Ambient Weather</option>
                            <option value="weatherlink" <?= ($_POST['weather_type'] ?? '') === 'weatherlink' ? 'selected' : '' ?>>Davis WeatherLink</option>
                            <option value="synopticdata" <?= ($_POST['weather_type'] ?? '') === 'synopticdata' ? 'selected' : '' ?>>SynopticData</option>
                            <option value="pwsweather" <?= ($_POST['weather_type'] ?? '') === 'pwsweather' ? 'selected' : '' ?>>PWSWeather (AerisWeather)</option>
                        </select>
                    </div>
                    
                    <!-- METAR Config -->
                    <div id="weather_metar" class="weather-config" style="display: none;">
                        <div class="form-group">
                            <label for="metar_station_id">Station ID</label>
                            <input type="text" id="metar_station_id" name="metar_station_id" 
                                   value="<?= htmlspecialchars($_POST['metar_station_id'] ?? '') ?>"
                                   placeholder="Auto (uses airport ICAO)">
                            <div class="help-text">Leave empty to use airport ICAO code</div>
                        </div>
                    </div>
                    
                    <!-- Tempest Config -->
                    <div id="weather_tempest" class="weather-config" style="display: none;">
                        <div class="form-group">
                            <label for="tempest_station_id">Station ID <span class="required">*</span></label>
                            <input type="text" id="tempest_station_id" name="tempest_station_id"
                                   value="<?= htmlspecialchars($_POST['tempest_station_id'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="tempest_api_key">API Key</label>
                            <input type="text" id="tempest_api_key" name="tempest_api_key"
                                   value="<?= htmlspecialchars($_POST['tempest_api_key'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <!-- Ambient Config -->
                    <div id="weather_ambient" class="weather-config" style="display: none;">
                        <div class="form-group">
                            <label for="ambient_api_key">API Key <span class="required">*</span></label>
                            <input type="text" id="ambient_api_key" name="ambient_api_key"
                                   value="<?= htmlspecialchars($_POST['ambient_api_key'] ?? '') ?>" required>
                            <small class="form-text text-muted">Get from <a href="https://dashboard.ambientweather.net/account" target="_blank">AmbientWeather.net Account Settings</a> → API Keys → Create API Key</small>
                        </div>
                        <div class="form-group">
                            <label for="ambient_application_key">Application Key <span class="required">*</span></label>
                            <input type="text" id="ambient_application_key" name="ambient_application_key"
                                   value="<?= htmlspecialchars($_POST['ambient_application_key'] ?? '') ?>" required>
                            <small class="form-text text-muted">Get from <a href="https://dashboard.ambientweather.net/account" target="_blank">AmbientWeather.net Account Settings</a> → API Keys → Create Application Key (scroll to bottom)</small>
                        </div>
                        <div class="form-group">
                            <label for="ambient_mac_address">Device MAC Address</label>
                            <input type="text" id="ambient_mac_address" name="ambient_mac_address"
                                   value="<?= htmlspecialchars($_POST['ambient_mac_address'] ?? '') ?>"
                                   placeholder="AA:BB:CC:DD:EE:FF"
                                   pattern="([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})|[0-9A-Fa-f]{12}"
                                   title="MAC address format: AA:BB:CC:DD:EE:FF or AA-BB-CC-DD-EE-FF or AABBCCDDEEFF">
                            <small class="form-text text-muted">Optional - uses first device if omitted. Find at <a href="https://dashboard.ambientweather.net/devices" target="_blank">dashboard.ambientweather.net/devices</a></small>
                        </div>
                    </div>
                    
                    <!-- WeatherLink Config -->
                    <div id="weather_weatherlink" class="weather-config" style="display: none;">
                        <div class="form-group">
                            <label for="weatherlink_api_key">API Key <span class="required">*</span></label>
                            <input type="text" id="weatherlink_api_key" name="weatherlink_api_key"
                                   value="<?= htmlspecialchars($_POST['weatherlink_api_key'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="weatherlink_api_secret">API Secret <span class="required">*</span></label>
                            <input type="text" id="weatherlink_api_secret" name="weatherlink_api_secret"
                                   value="<?= htmlspecialchars($_POST['weatherlink_api_secret'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="weatherlink_station_id">Station ID</label>
                            <input type="text" id="weatherlink_station_id" name="weatherlink_station_id"
                                   value="<?= htmlspecialchars($_POST['weatherlink_station_id'] ?? '') ?>">
                            <div class="help-text">Optional - required for some API versions</div>
                        </div>
                    </div>
                    
                    <!-- SynopticData Config -->
                    <div id="weather_synopticdata" class="weather-config" style="display: none;">
                        <div class="form-group">
                            <label for="synopticdata_api_token">API Token <span class="required">*</span></label>
                            <input type="text" id="synopticdata_api_token" name="synopticdata_api_token"
                                   value="<?= htmlspecialchars($_POST['synopticdata_api_token'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="synopticdata_station_id">Station ID</label>
                            <input type="text" id="synopticdata_station_id" name="synopticdata_station_id"
                                   value="<?= htmlspecialchars($_POST['synopticdata_station_id'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <!-- PWSWeather Config -->
                    <div id="weather_pwsweather" class="weather-config" style="display: none;">
                        <div class="form-group">
                            <label for="pwsweather_client_id">Client ID (AerisWeather) <span class="required">*</span></label>
                            <input type="text" id="pwsweather_client_id" name="pwsweather_client_id"
                                   value="<?= htmlspecialchars($_POST['pwsweather_client_id'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="pwsweather_client_secret">Client Secret (AerisWeather) <span class="required">*</span></label>
                            <input type="text" id="pwsweather_client_secret" name="pwsweather_client_secret"
                                   value="<?= htmlspecialchars($_POST['pwsweather_client_secret'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="pwsweather_station_id">Station ID <span class="required">*</span></label>
                            <input type="text" id="pwsweather_station_id" name="pwsweather_station_id"
                                   value="<?= htmlspecialchars($_POST['pwsweather_station_id'] ?? '') ?>" required>
                        </div>
                    </div>
                    
                    <!-- Backup Weather Source -->
                    <div class="form-group" style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #eee;">
                        <label for="weather_backup_type">Backup Weather Source Type</label>
                        <select id="weather_backup_type" name="weather_backup_type">
                            <option value="">None</option>
                            <option value="tempest" <?= ($_POST['weather_backup_type'] ?? '') === 'tempest' ? 'selected' : '' ?>>Tempest Weather Station</option>
                            <option value="ambient" <?= ($_POST['weather_backup_type'] ?? '') === 'ambient' ? 'selected' : '' ?>>Ambient Weather</option>
                            <option value="weatherlink" <?= ($_POST['weather_backup_type'] ?? '') === 'weatherlink' ? 'selected' : '' ?>>Davis WeatherLink</option>
                            <option value="synopticdata" <?= ($_POST['weather_backup_type'] ?? '') === 'synopticdata' ? 'selected' : '' ?>>SynopticData</option>
                            <option value="pwsweather" <?= ($_POST['weather_backup_type'] ?? '') === 'pwsweather' ? 'selected' : '' ?>>PWSWeather (AerisWeather)</option>
                        </select>
                        <div class="help-text">Activates automatically when primary source exceeds 5× refresh interval</div>
                    </div>
                    
                    <!-- Backup Weather Configs (similar structure, prefixed with backup_) -->
                    <div id="weather_backup_tempest" class="weather-config" style="display: none;">
                        <div class="form-group">
                            <label for="backup_tempest_station_id">Station ID</label>
                            <input type="text" id="backup_tempest_station_id" name="backup_tempest_station_id"
                                   value="<?= htmlspecialchars($_POST['backup_tempest_station_id'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="backup_tempest_api_key">API Key</label>
                            <input type="text" id="backup_tempest_api_key" name="backup_tempest_api_key"
                                   value="<?= htmlspecialchars($_POST['backup_tempest_api_key'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div id="weather_backup_ambient" class="weather-config" style="display: none;">
                        <div class="form-group">
                            <label for="backup_ambient_api_key">API Key</label>
                            <input type="text" id="backup_ambient_api_key" name="backup_ambient_api_key"
                                   value="<?= htmlspecialchars($_POST['backup_ambient_api_key'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="backup_ambient_application_key">Application Key</label>
                            <input type="text" id="backup_ambient_application_key" name="backup_ambient_application_key"
                                   value="<?= htmlspecialchars($_POST['backup_ambient_application_key'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="backup_ambient_mac_address">Device MAC Address</label>
                            <input type="text" id="backup_ambient_mac_address" name="backup_ambient_mac_address"
                                   value="<?= htmlspecialchars($_POST['backup_ambient_mac_address'] ?? '') ?>"
                                   placeholder="AA:BB:CC:DD:EE:FF">
                        </div>
                    </div>
                    
                    <div id="weather_backup_weatherlink" class="weather-config" style="display: none;">
                        <div class="form-group">
                            <label for="backup_weatherlink_api_key">API Key</label>
                            <input type="text" id="backup_weatherlink_api_key" name="backup_weatherlink_api_key"
                                   value="<?= htmlspecialchars($_POST['backup_weatherlink_api_key'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="backup_weatherlink_api_secret">API Secret</label>
                            <input type="text" id="backup_weatherlink_api_secret" name="backup_weatherlink_api_secret"
                                   value="<?= htmlspecialchars($_POST['backup_weatherlink_api_secret'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="backup_weatherlink_station_id">Station ID</label>
                            <input type="text" id="backup_weatherlink_station_id" name="backup_weatherlink_station_id"
                                   value="<?= htmlspecialchars($_POST['backup_weatherlink_station_id'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div id="weather_backup_synopticdata" class="weather-config" style="display: none;">
                        <div class="form-group">
                            <label for="backup_synopticdata_api_token">API Token</label>
                            <input type="text" id="backup_synopticdata_api_token" name="backup_synopticdata_api_token"
                                   value="<?= htmlspecialchars($_POST['backup_synopticdata_api_token'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="backup_synopticdata_station_id">Station ID</label>
                            <input type="text" id="backup_synopticdata_station_id" name="backup_synopticdata_station_id"
                                   value="<?= htmlspecialchars($_POST['backup_synopticdata_station_id'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div id="weather_backup_pwsweather" class="weather-config" style="display: none;">
                        <div class="form-group">
                            <label for="backup_pwsweather_client_id">Client ID (AerisWeather)</label>
                            <input type="text" id="backup_pwsweather_client_id" name="backup_pwsweather_client_id"
                                   value="<?= htmlspecialchars($_POST['backup_pwsweather_client_id'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="backup_pwsweather_client_secret">Client Secret (AerisWeather)</label>
                            <input type="text" id="backup_pwsweather_client_secret" name="backup_pwsweather_client_secret"
                                   value="<?= htmlspecialchars($_POST['backup_pwsweather_client_secret'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="backup_pwsweather_station_id">Station ID</label>
                            <input type="text" id="backup_pwsweather_station_id" name="backup_pwsweather_station_id"
                                   value="<?= htmlspecialchars($_POST['backup_pwsweather_station_id'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <!-- METAR Station (can be standalone) -->
                    <div class="form-group" style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #eee;">
                        <label for="metar_station">METAR Station ID</label>
                        <input type="text" id="metar_station" name="metar_station"
                               value="<?= htmlspecialchars($_POST['metar_station'] ?? '') ?>"
                               placeholder="KSPB">
                        <div class="help-text">Primary METAR station (can be used standalone or with weather_source)</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="nearby_metar_stations">Nearby METAR Stations</label>
                        <input type="text" id="nearby_metar_stations" name="nearby_metar_stations"
                               value="<?= htmlspecialchars($_POST['nearby_metar_stations'] ?? '') ?>"
                               placeholder="KVUO, KHIO">
                        <div class="help-text">Comma-separated list of fallback METAR stations</div>
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
                               placeholder="100LL, Jet-A, Repairs, Maintenance">
                        <small style="display: block; margin-top: 0.25rem; color: #666;">Fuel types will be combined into a fuel string. Repairs/Maintenance sets repairs_available.</small>
                    </div>
                </div>
                
                <!-- Partners -->
                <div class="form-section">
                    <h2>Partners</h2>
                    <div class="help-text" style="margin-bottom: 1rem;">Organizations that support this airport</div>
                    <div id="partners-container">
                        <?php
                        $partnerCount = 0;
                        if (isset($_POST['partners']) && is_array($_POST['partners'])) {
                            $partnerCount = count($_POST['partners']);
                            foreach ($_POST['partners'] as $idx => $partner) {
                                renderPartnerForm($idx, $partner);
                            }
                        }
                        if ($partnerCount === 0) {
                            renderPartnerForm(0, []);
                        }
                        ?>
                    </div>
                    <button type="button" class="btn btn-secondary" onclick="addPartner()">+ Add Partner</button>
                </div>
                
                <!-- Links -->
                <div class="form-section">
                    <h2>Custom Links</h2>
                    <div class="help-text" style="margin-bottom: 1rem;">External links to display on the airport page</div>
                    <div id="links-container">
                        <?php
                        $linkCount = 0;
                        if (isset($_POST['links']) && is_array($_POST['links'])) {
                            $linkCount = count($_POST['links']);
                            foreach ($_POST['links'] as $idx => $link) {
                                renderLinkForm($idx, $link);
                            }
                        }
                        if ($linkCount === 0) {
                            renderLinkForm(0, []);
                        }
                        ?>
                    </div>
                    <button type="button" class="btn btn-secondary" onclick="addLink()">+ Add Link</button>
                </div>
                
                <!-- Link Overrides -->
                <div class="form-section">
                    <h2>Link Overrides</h2>
                    <div class="help-text" style="margin-bottom: 1rem;">Override default links to aviation resources</div>
                    
                    <div class="form-group">
                        <label for="airnav_url">AirNav URL</label>
                        <input type="url" id="airnav_url" name="airnav_url"
                               value="<?= htmlspecialchars($_POST['airnav_url'] ?? '') ?>"
                               placeholder="https://www.airnav.com/airport/KSPB">
                    </div>
                    
                    <div class="form-group">
                        <label for="skyvector_url">SkyVector URL</label>
                        <input type="url" id="skyvector_url" name="skyvector_url"
                               value="<?= htmlspecialchars($_POST['skyvector_url'] ?? '') ?>"
                               placeholder="https://skyvector.com/airport/KSPB">
                    </div>
                    
                    <div class="form-group">
                        <label for="aopa_url">AOPA URL</label>
                        <input type="url" id="aopa_url" name="aopa_url"
                               value="<?= htmlspecialchars($_POST['aopa_url'] ?? '') ?>"
                               placeholder="https://www.aopa.org/destinations/airports/KSPB">
                    </div>
                    
                    <div class="form-group">
                        <label for="faa_weather_url">FAA Weather URL</label>
                        <input type="url" id="faa_weather_url" name="faa_weather_url"
                               value="<?= htmlspecialchars($_POST['faa_weather_url'] ?? '') ?>"
                               placeholder="https://weathercams.faa.gov/...">
                    </div>
                    
                    <div class="form-group">
                        <label for="foreflight_url">ForeFlight URL</label>
                        <input type="url" id="foreflight_url" name="foreflight_url"
                               value="<?= htmlspecialchars($_POST['foreflight_url'] ?? '') ?>"
                               placeholder="foreflightmobile://maps/search?q=KSPB">
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
        
        // Access type toggle for permission_required field
        document.getElementById('access_type').addEventListener('change', function() {
            const accessType = this.value;
            const permissionGroup = document.getElementById('permission_required_group');
            if (accessType === 'private') {
                permissionGroup.style.display = 'block';
            } else {
                permissionGroup.style.display = 'none';
                document.getElementById('permission_required').checked = false;
            }
        });
        
        // Initialize permission_required visibility on page load
        document.addEventListener('DOMContentLoaded', function() {
            const accessType = document.getElementById('access_type').value;
            const permissionGroup = document.getElementById('permission_required_group');
            if (accessType === 'private') {
                permissionGroup.style.display = 'block';
            }
        });
        
        // Show/hide weather config based on type
        document.getElementById('weather_type').addEventListener('change', function() {
            // Hide all primary weather configs
            document.querySelectorAll('#weather_metar, #weather_tempest, #weather_ambient, #weather_weatherlink, #weather_synopticdata, #weather_pwsweather').forEach(el => {
                if (el) el.style.display = 'none';
            });
            const type = this.value;
            if (type) {
                const el = document.getElementById('weather_' + type);
                if (el) el.style.display = 'block';
            }
        });
        
        // Show/hide backup weather config based on type
        document.getElementById('weather_backup_type').addEventListener('change', function() {
            // Hide all backup weather configs
            document.querySelectorAll('#weather_backup_tempest, #weather_backup_ambient, #weather_backup_weatherlink, #weather_backup_synopticdata, #weather_backup_pwsweather').forEach(el => {
                if (el) el.style.display = 'none';
            });
            const type = this.value;
            if (type) {
                const el = document.getElementById('weather_backup_' + type);
                if (el) el.style.display = 'block';
            }
        });
        
        // Initialize weather config display
        const weatherType = document.getElementById('weather_type').value;
        if (weatherType) {
            const el = document.getElementById('weather_' + weatherType);
            if (el) el.style.display = 'block';
        }
        
        const backupWeatherType = document.getElementById('weather_backup_type').value;
        if (backupWeatherType) {
            const el = document.getElementById('weather_backup_' + backupWeatherType);
            if (el) el.style.display = 'block';
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
        
        // Partner management
        let partnerIndex = <?= isset($_POST['partners']) && is_array($_POST['partners']) ? count($_POST['partners']) : 0 ?>;
        
        function addPartner() {
            partnerIndex++;
            const container = document.getElementById('partners-container');
            const div = document.createElement('div');
            div.className = 'camera-item';
            div.innerHTML = `
                <div class="camera-header">
                    <h4>Partner ${partnerIndex}</h4>
                    <button type="button" class="btn btn-remove" onclick="removePartner(this)">Remove</button>
                </div>
                <div class="form-group">
                    <label>Partner Name <span class="required">*</span></label>
                    <input type="text" name="partners[${partnerIndex}][name]" required>
                </div>
                <div class="form-group">
                    <label>URL</label>
                    <input type="url" name="partners[${partnerIndex}][url]">
                </div>
                <div class="form-group">
                    <label>Logo URL</label>
                    <input type="url" name="partners[${partnerIndex}][logo]">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="partners[${partnerIndex}][description]" rows="2"></textarea>
                </div>
            `;
            container.appendChild(div);
        }
        
        function removePartner(btn) {
            btn.closest('.camera-item').remove();
        }
        
        // Link management
        let linkIndex = <?= isset($_POST['links']) && is_array($_POST['links']) ? count($_POST['links']) : 0 ?>;
        
        function addLink() {
            linkIndex++;
            const container = document.getElementById('links-container');
            const div = document.createElement('div');
            div.className = 'camera-item';
            div.innerHTML = `
                <div class="camera-header">
                    <h4>Link ${linkIndex}</h4>
                    <button type="button" class="btn btn-remove" onclick="removeLink(this)">Remove</button>
                </div>
                <div class="form-group">
                    <label>Link Label <span class="required">*</span></label>
                    <input type="text" name="links[${linkIndex}][label]" required>
                </div>
                <div class="form-group">
                    <label>URL <span class="required">*</span></label>
                    <input type="url" name="links[${linkIndex}][url]" required>
                </div>
            `;
            container.appendChild(div);
        }
        
        function removeLink(btn) {
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
                        <label>Refresh Seconds</label>
                        <input type="number" name="webcams[${idx}][refresh_seconds]" 
                               value="${cam.refresh_seconds || ''}" min="60" placeholder="60">
                        <div class="help-text">Minimum 60 seconds (default: 60)</div>
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
 * Render webcam form fields (for server-side rendering)
 * 
 * Generates HTML form fields for a single webcam configuration.
 * Supports both pull (MJPEG/RTSP/static) and push (FTP/SFTP) camera types.
 * 
 * @param int $idx Zero-based camera index
 * @param array $cam Existing camera configuration data (for pre-filling form)
 * @return void Outputs HTML directly
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
                <label>Refresh Seconds</label>
                <input type="number" name="webcams[<?= $idx ?>][refresh_seconds]" 
                       value="<?= htmlspecialchars($cam['refresh_seconds'] ?? '') ?>" min="60" placeholder="60">
                <div class="help-text">Minimum 60 seconds (default: 60)</div>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
        </div>
    </div>
    <?php
}

/**
 * Render partner form fields (for server-side rendering)
 * 
 * Generates HTML form fields for a single partner organization.
 * 
 * @param int $idx Zero-based partner index
 * @param array $partner Existing partner configuration data (for pre-filling form)
 * @return void Outputs HTML directly
 */
function renderPartnerForm($idx, $partner) {
    ?>
    <div class="camera-item">
        <div class="camera-header">
            <h4>Partner <?= $idx + 1 ?></h4>
            <button type="button" class="btn btn-remove" onclick="removePartner(this)">Remove</button>
        </div>
        
        <div class="form-group">
            <label>Partner Name <span class="required">*</span></label>
            <input type="text" name="partners[<?= $idx ?>][name]" 
                   value="<?= htmlspecialchars($partner['name'] ?? '') ?>" required>
        </div>
        
        <div class="form-group">
            <label>URL</label>
            <input type="url" name="partners[<?= $idx ?>][url]" 
                   value="<?= htmlspecialchars($partner['url'] ?? '') ?>">
        </div>
        
        <div class="form-group">
            <label>Logo URL</label>
            <input type="url" name="partners[<?= $idx ?>][logo]" 
                   value="<?= htmlspecialchars($partner['logo'] ?? '') ?>">
        </div>
        
        <div class="form-group">
            <label>Description</label>
            <textarea name="partners[<?= $idx ?>][description]" rows="2"><?= htmlspecialchars($partner['description'] ?? '') ?></textarea>
        </div>
    </div>
    <?php
}

/**
 * Render link form fields (for server-side rendering)
 * 
 * Generates HTML form fields for a single custom external link.
 * 
 * @param int $idx Zero-based link index
 * @param array $link Existing link configuration data (for pre-filling form)
 * @return void Outputs HTML directly
 */
function renderLinkForm($idx, $link) {
    ?>
    <div class="camera-item">
        <div class="camera-header">
            <h4>Link <?= $idx + 1 ?></h4>
            <button type="button" class="btn btn-remove" onclick="removeLink(this)">Remove</button>
        </div>
        
        <div class="form-group">
            <label>Link Label <span class="required">*</span></label>
            <input type="text" name="links[<?= $idx ?>][label]" 
                   value="<?= htmlspecialchars($link['label'] ?? '') ?>" required>
        </div>
        
        <div class="form-group">
            <label>URL <span class="required">*</span></label>
            <input type="url" name="links[<?= $idx ?>][url]" 
                   value="<?= htmlspecialchars($link['url'] ?? '') ?>" required>
        </div>
    </div>
    <?php
}
?>

