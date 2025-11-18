<?php
/**
 * Push Webcam Configuration Synchronizer
 * Watches airports.json for changes and syncs directories/users
 * Runs via cron every minute
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';

// Set up invocation tracking
$invocationId = aviationwx_get_invocation_id();
$triggerInfo = aviationwx_detect_trigger_type();

aviationwx_log('info', 'push-config sync started', [
    'invocation_id' => $invocationId,
    'trigger' => $triggerInfo['trigger'],
    'context' => $triggerInfo['context']
], 'app');

/**
 * Get config file path
 */
function getConfigFilePath() {
    $envConfigPath = getenv('CONFIG_PATH');
    if ($envConfigPath && file_exists($envConfigPath) && is_file($envConfigPath)) {
        return $envConfigPath;
    }
    
    $configFile = __DIR__ . '/../config/airports.json';
    if (!file_exists($configFile) || is_dir($configFile)) {
        $configFile = '/var/www/html/airports.json';
    }
    
    return $configFile;
}

/**
 * Get last sync timestamp
 */
function getLastSyncTimestamp() {
    $trackFile = __DIR__ . '/../cache/push_webcams/last_sync.json';
    if (!file_exists($trackFile)) {
        return 0;
    }
    
    $data = @json_decode(@file_get_contents($trackFile), true);
    if (!is_array($data)) {
        return 0;
    }
    
    return isset($data['timestamp']) ? intval($data['timestamp']) : 0;
}

/**
 * Update last sync timestamp
 */
function updateLastSyncTimestamp() {
    $trackDir = __DIR__ . '/../cache/push_webcams';
    $trackFile = $trackDir . '/last_sync.json';
    
    if (!is_dir($trackDir)) {
        @mkdir($trackDir, 0755, true);
    }
    
    $data = ['timestamp' => time()];
    @file_put_contents($trackFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Backup config file
 */
function backupConfigFile($configFile) {
    $backupDir = '/var/backups/aviationwx';
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0755, true);
    }
    
    $backupFile = $backupDir . '/airports_' . date('Y-m-d_His') . '.json';
    @copy($configFile, $backupFile);
    
    // Keep only last 5 backups
    $backups = glob($backupDir . '/airports_*.json');
    if (count($backups) > 5) {
        usort($backups, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        foreach (array_slice($backups, 5) as $oldBackup) {
            @unlink($oldBackup);
        }
    }
    
    return $backupFile;
}

/**
 * Validate config before applying
 */
function validateConfigBeforeApply($configFile) {
    $content = @file_get_contents($configFile);
    if ($content === false) {
        return ['valid' => false, 'error' => 'Cannot read config file'];
    }
    
    $config = @json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['valid' => false, 'error' => json_last_error_msg()];
    }
    
    // Basic structure validation
    if (!is_array($config) || !isset($config['airports'])) {
        return ['valid' => false, 'error' => 'Invalid config structure'];
    }
    
    return ['valid' => true];
}

/**
 * Create upload directory for camera
 */
function createCameraDirectory($airportId, $camIndex) {
    $uploadDir = __DIR__ . '/../uploads/webcams/' . $airportId . '_' . $camIndex . '/incoming/';
    
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    
    // Set permissions
    @chmod($uploadDir, 0755);
    
    // Set ownership (if possible)
    $wwwDataUid = @fileowner(__DIR__ . '/../cache');
    if ($wwwDataUid !== false && function_exists('chown')) {
        @chown($uploadDir, $wwwDataUid);
    }
    
    return $uploadDir;
}

/**
 * Remove camera directory
 */
function removeCameraDirectory($airportId, $camIndex) {
    $uploadDir = __DIR__ . '/../uploads/webcams/' . $airportId . '_' . $camIndex;
    
    if (is_dir($uploadDir)) {
        // Remove recursively
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        
        @rmdir($uploadDir);
    }
}

/**
 * Get existing push cameras from filesystem
 */
function getExistingPushCameras() {
    $uploadBaseDir = __DIR__ . '/../uploads/webcams';
    $cameras = [];
    
    if (!is_dir($uploadBaseDir)) {
        return $cameras;
    }
    
    $dirs = glob($uploadBaseDir . '/*', GLOB_ONLYDIR);
    foreach ($dirs as $dir) {
        $basename = basename($dir);
        // Format: airportId_camIndex
        if (preg_match('/^([a-z0-9]{3,4})_(\d+)$/', $basename, $matches)) {
            $cameras[] = [
                'airport' => $matches[1],
                'cam' => intval($matches[2])
            ];
        }
    }
    
    return $cameras;
}

/**
 * Check if user exists
 */
function userExists($username) {
    if (function_exists('posix_getpwnam')) {
        return posix_getpwnam($username) !== false;
    }
    // Fallback: check /etc/passwd
    $passwd = @file_get_contents('/etc/passwd');
    if ($passwd) {
        return strpos($passwd, $username . ':') !== false;
    }
    return false;
}

/**
 * Create SFTP user
 */
function createSftpUser($airportId, $camIndex, $username, $password) {
    $chrootDir = __DIR__ . "/../uploads/webcams/{$airportId}_{$camIndex}";
    
    // Use helper script
    $cmd = sprintf(
        '/usr/local/bin/create-sftp-user.sh %s %s %s 2>&1',
        escapeshellarg($username),
        escapeshellarg($password),
        escapeshellarg($chrootDir)
    );
    
    exec($cmd, $output, $code);
    
    if ($code !== 0) {
        aviationwx_log('error', 'sync-push-config: SFTP user creation failed', [
            'username' => $username,
            'airport' => $airportId,
            'cam' => $camIndex,
            'output' => implode("\n", $output)
        ], 'app');
        return false;
    }
    
    aviationwx_log('info', 'sync-push-config: SFTP user created/updated', [
        'username' => $username,
        'airport' => $airportId,
        'cam' => $camIndex
    ], 'app');
    
    return true;
}

/**
 * Create FTP user (vsftpd virtual user)
 */
function createFtpUser($airportId, $camIndex, $username, $password) {
    $vsftpdUserFile = '/etc/vsftpd/virtual_users.txt';
    $vsftpdDbFile = '/etc/vsftpd/virtual_users.db';
    
    // Read existing users
    $users = [];
    if (file_exists($vsftpdUserFile)) {
        $lines = @file($vsftpdUserFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            for ($i = 0; $i < count($lines); $i += 2) {
                if (isset($lines[$i + 1])) {
                    $users[$lines[$i]] = $lines[$i + 1];
                }
            }
        }
    }
    
    // Add/update user
    $users[$username] = $password;
    
    // Write users file
    $content = '';
    foreach ($users as $user => $pass) {
        $content .= $user . "\n" . $pass . "\n";
    }
    
    if (@file_put_contents($vsftpdUserFile, $content) === false) {
        aviationwx_log('error', 'sync-push-config: Cannot write vsftpd users file', [
            'file' => $vsftpdUserFile
        ], 'app');
        return false;
    }
    
    // Rebuild database
    exec('db_load -T -t hash -f ' . escapeshellarg($vsftpdUserFile) . ' ' . escapeshellarg($vsftpdDbFile) . ' 2>&1', $output, $code);
    
    if ($code !== 0) {
        aviationwx_log('error', 'sync-push-config: db_load failed', [
            'output' => implode("\n", $output)
        ], 'app');
        return false;
    }
    
    // Create per-user config
    $userConfigDir = '/etc/vsftpd/users';
    if (!is_dir($userConfigDir)) {
        @mkdir($userConfigDir, 0755, true);
    }
    
    $chrootDir = __DIR__ . "/../uploads/webcams/{$airportId}_{$camIndex}";
    $userConfigFile = $userConfigDir . '/' . $username;
    $config = "local_root={$chrootDir}\n";
    
    if (@file_put_contents($userConfigFile, $config) === false) {
        aviationwx_log('error', 'sync-push-config: Cannot write user config file', [
            'file' => $userConfigFile
        ], 'app');
        return false;
    }
    
    aviationwx_log('info', 'sync-push-config: FTP user created/updated', [
        'username' => $username,
        'airport' => $airportId,
        'cam' => $camIndex
    ], 'app');
    
    return true;
}

/**
 * Remove FTP user
 */
function removeFtpUser($username) {
    $vsftpdUserFile = '/etc/vsftpd/virtual_users.txt';
    $vsftpdDbFile = '/etc/vsftpd/virtual_users.db';
    $userConfigFile = '/etc/vsftpd/users/' . $username;
    
    // Read existing users
    $users = [];
    if (file_exists($vsftpdUserFile)) {
        $lines = @file($vsftpdUserFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            for ($i = 0; $i < count($lines); $i += 2) {
                if (isset($lines[$i + 1]) && $lines[$i] !== $username) {
                    $users[$lines[$i]] = $lines[$i + 1];
                }
            }
        }
    }
    
    // Write users file
    $content = '';
    foreach ($users as $user => $pass) {
        $content .= $user . "\n" . $pass . "\n";
    }
    
    @file_put_contents($vsftpdUserFile, $content);
    
    // Rebuild database
    if (count($users) > 0) {
        exec('db_load -T -t hash -f ' . escapeshellarg($vsftpdUserFile) . ' ' . escapeshellarg($vsftpdDbFile) . ' 2>&1', $output, $code);
    } else {
        // No users left, remove database
        @unlink($vsftpdDbFile);
    }
    
    // Remove user config
    @unlink($userConfigFile);
}

/**
 * Remove SFTP user
 */
function removeSftpUser($username) {
    // Remove system user
    exec('userdel ' . escapeshellarg($username) . ' 2>&1', $output, $code);
    // Ignore errors (user might not exist)
}

/**
 * Sync camera user
 */
function syncCameraUser($airportId, $camIndex, $pushConfig) {
    $username = $pushConfig['username'] ?? null;
    $password = $pushConfig['password'] ?? null;
    $protocol = strtolower($pushConfig['protocol'] ?? 'sftp');
    
    if (!$username || !$password) {
        aviationwx_log('warning', 'sync-push-config: missing credentials', [
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return false;
    }
    
    if ($protocol === 'sftp') {
        return createSftpUser($airportId, $camIndex, $username, $password);
    } elseif (in_array($protocol, ['ftp', 'ftps'])) {
        return createFtpUser($airportId, $camIndex, $username, $password);
    } else {
        aviationwx_log('error', 'sync-push-config: invalid protocol', [
            'protocol' => $protocol,
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return false;
    }
}

/**
 * Remove camera user
 */
function removeCameraUser($username, $protocol) {
    $protocol = strtolower($protocol);
    
    if ($protocol === 'sftp') {
        removeSftpUser($username);
    } elseif (in_array($protocol, ['ftp', 'ftps'])) {
        removeFtpUser($username);
    }
}

/**
 * Sync all push cameras
 */
function syncAllPushCameras($config) {
    $existing = getExistingPushCameras();
    $configured = [];
    $cameraUsers = []; // Track username -> protocol mapping
    
    // Build list of configured cameras
    foreach ($config['airports'] ?? [] as $airportId => $airport) {
        if (!isset($airport['webcams']) || !is_array($airport['webcams'])) {
            continue;
        }
        
        foreach ($airport['webcams'] as $camIndex => $cam) {
            $isPush = (isset($cam['type']) && $cam['type'] === 'push') 
                   || isset($cam['push_config']);
            
            if ($isPush && isset($cam['push_config'])) {
                $configured[] = [
                    'airport' => $airportId,
                    'cam' => $camIndex,
                    'username' => $cam['push_config']['username'] ?? null,
                    'protocol' => $cam['push_config']['protocol'] ?? 'sftp'
                ];
                
                // Create directory
                createCameraDirectory($airportId, $camIndex);
                
                // Create/update FTP/SFTP user
                if (isset($cam['push_config']['username']) && isset($cam['push_config']['password'])) {
                    syncCameraUser($airportId, $camIndex, $cam['push_config']);
                    $cameraUsers[$cam['push_config']['username']] = $cam['push_config']['protocol'] ?? 'sftp';
                }
            }
        }
    }
    
    // Remove orphaned directories and users
    foreach ($existing as $existingCam) {
        $found = false;
        $username = null;
        $protocol = null;
        
        foreach ($configured as $configCam) {
            if ($existingCam['airport'] === $configCam['airport'] && 
                $existingCam['cam'] === $configCam['cam']) {
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            // Find username for this camera (from config before removal)
            // We need to check the config one more time to get username
            foreach ($config['airports'] ?? [] as $airportId => $airport) {
                if ($airportId === $existingCam['airport'] && 
                    isset($airport['webcams'][$existingCam['cam']]['push_config'])) {
                    $pushConfig = $airport['webcams'][$existingCam['cam']]['push_config'];
                    $username = $pushConfig['username'] ?? null;
                    $protocol = $pushConfig['protocol'] ?? 'sftp';
                    break;
                }
            }
            
            aviationwx_log('info', 'removing orphaned camera', [
                'airport' => $existingCam['airport'],
                'cam' => $existingCam['cam'],
                'username' => $username
            ], 'app');
            
            // Remove user
            if ($username) {
                removeCameraUser($username, $protocol);
            }
            
            // Remove directory
            removeCameraDirectory($existingCam['airport'], $existingCam['cam']);
        }
    }
}

/**
 * Main sync function
 */
function syncPushConfig() {
    $configFile = getConfigFilePath();
    
    if (!file_exists($configFile)) {
        aviationwx_log('error', 'config file not found', ['path' => $configFile], 'app');
        exit(1);
    }
    
    $configMtime = filemtime($configFile);
    $lastSync = getLastSyncTimestamp();
    
    // Check if config changed
    if ($configMtime <= $lastSync) {
        // No changes
        return;
    }
    
    // Validate config before applying
    $validation = validateConfigBeforeApply($configFile);
    if (!$validation['valid']) {
        aviationwx_log('error', 'config validation failed, skipping sync', [
            'error' => $validation['error']
        ], 'app');
        return;
    }
    
    // Backup config
    $backupFile = backupConfigFile($configFile);
    aviationwx_log('info', 'config backed up', ['backup_file' => $backupFile], 'app');
    
    // Load config
    $config = loadConfig(false);
    if (!$config) {
        aviationwx_log('error', 'config load failed', [], 'app');
        exit(1);
    }
    
    // Sync cameras
    syncAllPushCameras($config);
    
    // Update sync timestamp
    updateLastSyncTimestamp();
    
    aviationwx_log('info', 'push-config sync completed', [], 'app');
}

// Run sync (only when executed directly, not when included)
if (php_sapi_name() === 'cli' && basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    syncPushConfig();
}

