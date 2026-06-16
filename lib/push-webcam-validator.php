<?php
/**
 * Push Webcam Configuration Validator
 * Validates push webcam configurations in airports.json
 */

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/constants.php';

/**
 * Validate a push FTP/SFTP username (same rules as push_config.username).
 *
 * @return array<int, string> Error messages (empty when valid)
 */
function validatePushUploadUsername(string $username, string $contextLabel): array
{
    $errors = [];
    if ($username === '') {
        $errors[] = "{$contextLabel}: username is required";

        return $errors;
    }
    if (strlen($username) > PUSH_UPLOAD_USERNAME_MAX_LENGTH) {
        $errors[] = "{$contextLabel}: username must be " . PUSH_UPLOAD_USERNAME_MAX_LENGTH . ' characters or less';
    }
    if (preg_match('/\s/', $username)) {
        $errors[] = "{$contextLabel}: username must not contain spaces";
    }
    if (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
        $errors[] = "{$contextLabel}: username must be alphanumeric";
    }

    return $errors;
}

/**
 * Validate a push FTP/SFTP password (same rules as push_config.password).
 *
 * @return array<int, string> Error messages (empty when valid)
 */
function validatePushUploadPassword(string $password, string $contextLabel): array
{
    $errors = [];
    if ($password === '') {
        $errors[] = "{$contextLabel}: password is required";

        return $errors;
    }
    if (strlen($password) !== PUSH_UPLOAD_PASSWORD_LENGTH) {
        $errors[] = "{$contextLabel}: password must be exactly " . PUSH_UPLOAD_PASSWORD_LENGTH . ' characters';
    }
    if (!preg_match('/^[a-zA-Z0-9]{' . PUSH_UPLOAD_PASSWORD_LENGTH . '}$/', $password)) {
        $errors[] = "{$contextLabel}: password must be alphanumeric (" . PUSH_UPLOAD_PASSWORD_LENGTH . ' characters)';
    }

    return $errors;
}

/**
 * Validate push webcam configuration
 *
 * @param array $cam Camera configuration
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @param int|null $globalCacheMaxMb Effective global `config.cache_file_max_size_mb` (1--100); omit in standalone tests
 * @return array ['valid' => bool, 'errors' => array]
 */
function validatePushWebcamConfig($cam, $airportId, $camIndex, ?int $globalCacheMaxMb = null) {
    $errors = [];
    
    // Check if push_config exists
    if (!isset($cam['push_config']) || !is_array($cam['push_config'])) {
        $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: push_config is required for push cameras";
        return ['valid' => false, 'errors' => $errors];
    }
    
    $pushConfig = $cam['push_config'];
    
    // Define allowed push_config fields (strict validation)
    // Note: 'port' is informational for camera UIs; server listeners come from config.network_ports (defaults: SFTP 2222, FTP control 2121)
    $allowedPushConfigFields = ['username', 'password', 'port', 'max_file_size_mb', 'allowed_extensions'];
    
    // Check for unknown fields in push_config
    foreach ($pushConfig as $key => $value) {
        if (!in_array($key, $allowedPushConfigFields)) {
            $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: push_config has unknown field '{$key}'. Allowed fields: " . implode(', ', $allowedPushConfigFields);
        }
    }
    
    $credentialContext = "Airport '{$airportId}' webcam index {$camIndex}";
    if (!isset($pushConfig['username'])) {
        $errors[] = "{$credentialContext}: username is required";
    } elseif (!is_string($pushConfig['username'])) {
        $errors[] = "{$credentialContext}: username must be a string";
    } else {
        $errors = array_merge(
            $errors,
            validatePushUploadUsername($pushConfig['username'], $credentialContext)
        );
    }

    if (!isset($pushConfig['password'])) {
        $errors[] = "{$credentialContext}: password is required";
    } elseif (!is_string($pushConfig['password'])) {
        $errors[] = "{$credentialContext}: password must be a string";
    } else {
        $errors = array_merge(
            $errors,
            validatePushUploadPassword($pushConfig['password'], $credentialContext)
        );
    }
    
    // Port is informational only - not enforced by the server (listeners are config.network_ports / defaults).
    
    // max_file_size_mb: optional (omit = inherit global cache_file_max_size_mb at runtime)
    if (isset($pushConfig['max_file_size_mb'])) {
        $maxSize = intval($pushConfig['max_file_size_mb']);
        $upper = $globalCacheMaxMb !== null ? min(100, $globalCacheMaxMb) : 100;
        if ($maxSize < 1 || $maxSize > $upper) {
            if ($globalCacheMaxMb !== null) {
                $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: max_file_size_mb must be between 1 and {$upper} (global cache_file_max_size_mb is {$globalCacheMaxMb}; omit key to inherit)";
            } else {
                $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: max_file_size_mb must be between 1 and 100";
            }
        }
    }
    
    // Validate allowed_extensions
    if (isset($pushConfig['allowed_extensions'])) {
        if (!is_array($pushConfig['allowed_extensions'])) {
            $errors[] = "Airport '{$airportId}' webcam index {$camIndex}: allowed_extensions must be an array";
        } else {
            $validExtensions = push_upload_master_image_extensions();
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
 * Check for duplicate push camera usernames (case-insensitive).
 *
 * @param array $config Full configuration
 * @return array{valid: bool, errors: list<string>, duplicates: array<string, list<string>>}
 */
function validateUniquePushUsernames($config) {
    $errors = [];
    $usernames = [];
    $usernameLabels = [];
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
                if (!is_string($username) || $username === '') {
                    continue;
                }
                $key = $airportId . '_' . $camIndex;
                $normalized = strtolower($username);
                
                if (isset($usernames[$normalized])) {
                    if (!isset($duplicates[$normalized])) {
                        $duplicates[$normalized] = [$usernames[$normalized]];
                    }
                    $duplicates[$normalized][] = $key;
                } else {
                    $usernames[$normalized] = $key;
                    $usernameLabels[$normalized] = $username;
                }
            }
        }
    }
    
    if (!empty($duplicates)) {
        foreach ($duplicates as $normalized => $keys) {
            $label = $usernameLabels[$normalized] ?? $normalized;
            $errors[] = "Duplicate username '{$label}' (case-insensitive) found in cameras: "
                . implode(', ', array_unique($keys));
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

