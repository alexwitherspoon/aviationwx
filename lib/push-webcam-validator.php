<?php
/**
 * Push Webcam Configuration Validator
 * Validates push webcam configurations in airports.json
 */

require_once __DIR__ . '/logger.php';

/**
 * Validate push webcam configuration
 * 
 * @param array $cam Camera configuration
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @return array ['valid' => bool, 'errors' => array]
 */
function validatePushWebcamConfig($cam, $airportId, $camIndex) {
    $errors = [];
    
    // Check if push_config exists
    if (!isset($cam['push_config']) || !is_array($cam['push_config'])) {
        $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: push_config is required for push cameras";
        return ['valid' => false, 'errors' => $errors];
    }
    
    $pushConfig = $cam['push_config'];
    
    // Define allowed push_config fields (strict validation)
    // Note: 'protocol' and 'port' are deprecated (both FTP and SFTP always enabled)
    // but still allowed for backward compatibility
    $allowedPushConfigFields = ['protocol', 'username', 'password', 'port', 'max_file_size_mb', 'allowed_extensions'];
    
    // Check for unknown fields in push_config
    foreach ($pushConfig as $key => $value) {
        if (!in_array($key, $allowedPushConfigFields)) {
            $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: push_config has unknown field '{$key}'. Allowed fields: " . implode(', ', $allowedPushConfigFields);
        }
    }
    
    // Validate username
    if (!isset($pushConfig['username']) || empty($pushConfig['username'])) {
        $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: username is required";
    } else {
        $username = $pushConfig['username'];
        // Username should be 14 characters or less, contain no spaces, and be alphanumeric (mixed case allowed)
        if (strlen($username) > 14) {
            $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: username must be 14 characters or less";
        }
        if (preg_match('/\s/', $username)) {
            $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: username must not contain spaces";
        }
        if (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
            $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: username must be alphanumeric";
        }
    }
    
    // Validate password
    if (!isset($pushConfig['password']) || empty($pushConfig['password'])) {
        $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: password is required";
    } else {
        $password = $pushConfig['password'];
        // Password should be 14 characters, alphanumeric (mixed case allowed)
        if (strlen($password) !== 14) {
            $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: password must be exactly 14 characters";
        }
        if (!preg_match('/^[a-zA-Z0-9]{14}$/', $password)) {
            $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: password must be alphanumeric (14 characters)";
        }
    }
    
    // Protocol is deprecated - both FTP and SFTP are always enabled
    // Still validate if provided for backward compatibility
    if (isset($pushConfig['protocol']) && !empty($pushConfig['protocol'])) {
        $protocol = strtolower($pushConfig['protocol']);
        $validProtocols = ['ftp', 'ftps', 'sftp'];
        if (!in_array($protocol, $validProtocols)) {
            $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: protocol must be one of: " . implode(', ', $validProtocols);
        }
    }
    
    // Port is deprecated - not used by server, only for client reference
    // Ports are fixed: SFTP=2222, FTP/FTPS=2121
    
    // Validate max_file_size_mb
    if (isset($pushConfig['max_file_size_mb'])) {
        $maxSize = intval($pushConfig['max_file_size_mb']);
        if ($maxSize < 1 || $maxSize > 100) {
            $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: max_file_size_mb must be between 1 and 100";
        }
    }
    
    // Validate allowed_extensions
    if (isset($pushConfig['allowed_extensions'])) {
        if (!is_array($pushConfig['allowed_extensions'])) {
            $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: allowed_extensions must be an array";
        } else {
            $validExtensions = ['jpg', 'jpeg', 'png'];
            foreach ($pushConfig['allowed_extensions'] as $ext) {
                if (!in_array(strtolower($ext), $validExtensions)) {
                    $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: invalid extension '{$ext}' (allowed: " . implode(', ', $validExtensions) . ")";
                }
            }
        }
    }
    
    // Validate refresh_seconds (if set)
    if (isset($cam['refresh_seconds'])) {
        $refresh = intval($cam['refresh_seconds']);
        if ($refresh < 60) {
            $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: refresh_seconds must be at least 60";
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Check for duplicate usernames across all push cameras
 * 
 * @param array $config Full configuration
 * @return array ['valid' => bool, 'errors' => array, 'duplicates' => array]
 */
function validateUniquePushUsernames($config) {
    $errors = [];
    $usernames = [];
    $duplicates = [];
    
    foreach ($config['airports'] ?? [] as $airportId => $airport) {
        if (!isset($airport['webcams']) || !is_array($airport['webcams'])) {
            continue;
        }
        
        foreach ($airport['webcams'] as $camIndex => $cam) {
            $isPush = (isset($cam['type']) && $cam['type'] === 'push') 
                   || isset($cam['push_config']);
            
            if ($isPush && isset($cam['push_config']['username'])) {
                $username = $cam['push_config']['username'];
                $key = $airportId . '_' . $camIndex;
                
                if (isset($usernames[$username])) {
                    $duplicates[$username][] = $key;
                    $duplicates[$username][] = $usernames[$username];
                } else {
                    $usernames[$username] = $key;
                }
            }
        }
    }
    
    if (!empty($duplicates)) {
        foreach ($duplicates as $username => $keys) {
            $errors[] = "Duplicate username '{$username}' found in cameras: " . implode(', ', array_unique($keys));
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'duplicates' => $duplicates
    ];
}

/**
 * Validate push_webcam_settings (global settings)
 * 
 * @param array $settings Push webcam settings
 * @return array ['valid' => bool, 'errors' => array]
 */
function validatePushWebcamSettings($settings) {
    $errors = [];
    
    if (!is_array($settings)) {
        return ['valid' => false, 'errors' => ['push_webcam_settings must be an object']];
    }
    
    // Validate global_allowed_ips
    if (isset($settings['global_allowed_ips'])) {
        if (!is_array($settings['global_allowed_ips'])) {
            $errors[] = 'global_allowed_ips must be an array';
        } else {
            foreach ($settings['global_allowed_ips'] as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    $errors[] = "Invalid IP address in global_allowed_ips: {$ip}";
                }
            }
        }
    }
    
    // Validate global_denied_ips
    if (isset($settings['global_denied_ips'])) {
        if (!is_array($settings['global_denied_ips'])) {
            $errors[] = 'global_denied_ips must be an array';
        } else {
            foreach ($settings['global_denied_ips'] as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    $errors[] = "Invalid IP address in global_denied_ips: {$ip}";
                }
            }
        }
    }
    
    // Validate enforce_ip_allowlist
    if (isset($settings['enforce_ip_allowlist'])) {
        if (!is_bool($settings['enforce_ip_allowlist'])) {
            $errors[] = 'enforce_ip_allowlist must be a boolean';
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

