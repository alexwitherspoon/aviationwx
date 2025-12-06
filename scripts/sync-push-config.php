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
 * Creates both parent directory and incoming subdirectory with correct permissions
 */
function createCameraDirectory($airportId, $camIndex, $protocol = null) {
    $webcamsBaseDir = __DIR__ . '/../uploads/webcams';
    $baseDir = $webcamsBaseDir . '/' . $airportId . '_' . $camIndex;
    $incomingDir = $baseDir . '/incoming';
    
    // Ensure parent directories exist and are root-owned (required for SFTP chroot)
    if (!is_dir($webcamsBaseDir)) {
        @mkdir($webcamsBaseDir, 0755, true);
    }
    // Ensure webcams directory is root:root (critical for SFTP chroot)
    if (function_exists('chown') && is_dir($webcamsBaseDir)) {
        @chown($webcamsBaseDir, 0); // root UID
        $rootGroup = @posix_getgrnam('root');
        if ($rootGroup !== false) {
            @chgrp($webcamsBaseDir, $rootGroup['gid']);
        }
        @chmod($webcamsBaseDir, 0755);
    }
    
    // Create parent directory if it doesn't exist
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0755, true);
    }
    
    // Create incoming directory if it doesn't exist
    if (!is_dir($incomingDir)) {
        @mkdir($incomingDir, 0755, true);
    }
    
    // Set permissions on parent directory (must be root:root for chroot)
    @chmod($baseDir, 0755);
    if (function_exists('chown')) {
        // Try to set to root, but don't fail if we can't
        @chown($baseDir, 0); // 0 = root UID
        $groupInfo = @posix_getgrnam('root');
        if ($groupInfo !== false) {
            @chgrp($baseDir, $groupInfo['gid']);
        }
    }
    
    // Set permissions on incoming directory based on protocol
    @chmod($incomingDir, 0755);
    if (function_exists('chown')) {
        if (in_array(strtolower($protocol ?? ''), ['ftp', 'ftps'])) {
            // For FTP/FTPS: incoming must be owned by www-data (guest user)
            $wwwDataInfo = @posix_getpwnam('www-data');
            if ($wwwDataInfo !== false) {
                @chown($incomingDir, $wwwDataInfo['uid']);
                $wwwDataGroup = @posix_getgrnam('www-data');
                if ($wwwDataGroup !== false) {
                    @chgrp($incomingDir, $wwwDataGroup['gid']);
                }
            }
        } else {
            // For SFTP: incoming will be owned by the user (set by create-sftp-user.sh)
            // We'll let the SFTP user creation script handle it
        }
    }
    
    return $incomingDir;
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
 * Get username tracking file path
 */
function getUsernameTrackingFile() {
    $trackDir = __DIR__ . '/../cache/push_webcams';
    if (!is_dir($trackDir)) {
        @mkdir($trackDir, 0755, true);
    }
    return $trackDir . '/username_mapping.json';
}

/**
 * Load username-to-camera mapping
 */
function loadUsernameMapping() {
    $trackFile = getUsernameTrackingFile();
    if (!file_exists($trackFile)) {
        return [];
    }
    
    $data = @json_decode(@file_get_contents($trackFile), true);
    if (!is_array($data)) {
        return [];
    }
    
    return $data;
}

/**
 * Save username-to-camera mapping
 */
function saveUsernameMapping($mapping) {
    $trackFile = getUsernameTrackingFile();
    $fp = @fopen($trackFile, 'c+');
    if (!$fp) {
        return false;
    }
    
    if (@flock($fp, LOCK_EX)) {
        @ftruncate($fp, 0);
        @rewind($fp);
        @fwrite($fp, json_encode($mapping, JSON_PRETTY_PRINT));
        @fflush($fp);
        @flock($fp, LOCK_UN);
    }
    
    @fclose($fp);
    return true;
}

/**
 * Validate username format (14 alphanumeric characters)
 */
function validateUsername($username) {
    if (strlen($username) !== 14) {
        return false;
    }
    return preg_match('/^[a-zA-Z0-9]{14}$/', $username) === 1;
}

/**
 * Validate password format (14 alphanumeric characters)
 */
function validatePassword($password) {
    if (strlen($password) !== 14) {
        return false;
    }
    return preg_match('/^[a-zA-Z0-9]{14}$/', $password) === 1;
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
    
    // Remove old database if it exists (in case it's corrupted)
    if (file_exists($vsftpdDbFile)) {
        @unlink($vsftpdDbFile);
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
    
    $webcamsBaseDir = __DIR__ . '/../uploads/webcams';
    $chrootDir = $webcamsBaseDir . '/' . $airportId . '_' . $camIndex;
    $incomingDir = $chrootDir . '/incoming';
    
    // Ensure parent directories exist and are root-owned (required for chroot)
    if (!is_dir($webcamsBaseDir)) {
        @mkdir($webcamsBaseDir, 0755, true);
    }
    // Ensure webcams directory is root:root (critical for SFTP chroot, also good for FTP)
    if (function_exists('chown') && is_dir($webcamsBaseDir)) {
        @chown($webcamsBaseDir, 0); // root UID
        $rootGroup = @posix_getgrnam('root');
        if ($rootGroup !== false) {
            @chgrp($webcamsBaseDir, $rootGroup['gid']);
        }
        @chmod($webcamsBaseDir, 0755);
    }
    
    // Ensure directories exist with correct permissions
    if (!is_dir($chrootDir)) {
        @mkdir($chrootDir, 0755, true);
    }
    if (!is_dir($incomingDir)) {
        @mkdir($incomingDir, 0755, true);
    }
    
    // Set parent directory to root:root (required for chroot)
    if (function_exists('chown')) {
        @chown($chrootDir, 0); // root UID
        $rootGroup = @posix_getgrnam('root');
        if ($rootGroup !== false) {
            @chgrp($chrootDir, $rootGroup['gid']);
        }
        @chmod($chrootDir, 0755);
    }
    
    // Set incoming directory to www-data:www-data (guest user for vsftpd)
    if (function_exists('chown')) {
        $wwwDataInfo = @posix_getpwnam('www-data');
        if ($wwwDataInfo !== false) {
            @chown($incomingDir, $wwwDataInfo['uid']);
            $wwwDataGroup = @posix_getgrnam('www-data');
            if ($wwwDataGroup !== false) {
                @chgrp($incomingDir, $wwwDataGroup['gid']);
            }
            @chmod($incomingDir, 0755);
        }
    }
    
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
    
    if (@file_put_contents($vsftpdUserFile, $content) === false) {
        aviationwx_log('warning', 'sync-push-config: Cannot write vsftpd users file during removal', [
            'file' => $vsftpdUserFile,
            'username' => $username
        ], 'app');
    }
    
    // Rebuild database
    if (count($users) > 0) {
        // Remove old database if it exists (in case it's corrupted)
        if (file_exists($vsftpdDbFile)) {
            @unlink($vsftpdDbFile);
        }
        
        exec('db_load -T -t hash -f ' . escapeshellarg($vsftpdUserFile) . ' ' . escapeshellarg($vsftpdDbFile) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            aviationwx_log('warning', 'sync-push-config: db_load failed during user removal', [
                'output' => implode("\n", $output),
                'username' => $username
            ], 'app');
        }
    } else {
        // No users left, remove database
        @unlink($vsftpdDbFile);
    }
    
    // Remove user config
    if (file_exists($userConfigFile)) {
        @unlink($userConfigFile);
    }
    
    aviationwx_log('info', 'sync-push-config: FTP user removed', [
        'username' => $username
    ], 'app');
}

/**
 * Remove SFTP user
 */
function removeSftpUser($username) {
    // Remove system user
    exec('userdel ' . escapeshellarg($username) . ' 2>&1', $output, $code);
    
    if ($code === 0) {
        aviationwx_log('info', 'sync-push-config: SFTP user removed', [
            'username' => $username
        ], 'app');
    } else {
        // User might not exist, which is OK
        aviationwx_log('debug', 'sync-push-config: SFTP user removal attempted', [
            'username' => $username,
            'output' => implode("\n", $output),
            'code' => $code
        ], 'app');
    }
}

/**
 * Sync camera user
 */
function syncCameraUser($airportId, $camIndex, $pushConfig, &$usernameMapping) {
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
    
    // Validate username and password format
    if (!validateUsername($username)) {
        aviationwx_log('error', 'sync-push-config: invalid username format', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'username' => $username,
            'expected' => '14 alphanumeric characters'
        ], 'app');
        return false;
    }
    
    if (!validatePassword($password)) {
        aviationwx_log('error', 'sync-push-config: invalid password format', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'expected' => '14 alphanumeric characters'
        ], 'app');
        return false;
    }
    
    $cameraKey = $airportId . '_' . $camIndex;
    
    // Check if username is already assigned to a different camera
    if (isset($usernameMapping[$username])) {
        $existingKey = $usernameMapping[$username]['camera'];
        if ($existingKey !== $cameraKey) {
            aviationwx_log('error', 'sync-push-config: username already assigned to different camera', [
                'username' => $username,
                'existing_camera' => $existingKey,
                'new_camera' => $cameraKey
            ], 'app');
            return false;
        }
        
        // Check if protocol changed
        $existingProtocol = $usernameMapping[$username]['protocol'];
        if ($existingProtocol !== $protocol) {
            aviationwx_log('info', 'sync-push-config: protocol changed, removing old user', [
                'username' => $username,
                'old_protocol' => $existingProtocol,
                'new_protocol' => $protocol,
                'camera' => $cameraKey
            ], 'app');
            
            // Remove old user
            removeCameraUser($username, $existingProtocol);
        }
    }
    
    // Create/update user
    $success = false;
    if ($protocol === 'sftp') {
        $success = createSftpUser($airportId, $camIndex, $username, $password);
    } elseif (in_array($protocol, ['ftp', 'ftps'])) {
        $success = createFtpUser($airportId, $camIndex, $username, $password);
    } else {
        aviationwx_log('error', 'sync-push-config: invalid protocol', [
            'protocol' => $protocol,
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return false;
    }
    
    if ($success) {
        // Update username mapping
        $usernameMapping[$username] = [
            'camera' => $cameraKey,
            'airport' => $airportId,
            'cam' => $camIndex,
            'protocol' => $protocol
        ];
    }
    
    return $success;
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
    $usernameMapping = loadUsernameMapping();
    $newUsernameMapping = [];
    
    // Build list of configured cameras
    foreach ($config['airports'] ?? [] as $airportId => $airport) {
        if (!isset($airport['webcams']) || !is_array($airport['webcams'])) {
            continue;
        }
        
        foreach ($airport['webcams'] as $camIndex => $cam) {
            $isPush = (isset($cam['type']) && $cam['type'] === 'push') 
                   || isset($cam['push_config']);
            
            if ($isPush && isset($cam['push_config'])) {
                $cameraKey = $airportId . '_' . $camIndex;
                $username = $cam['push_config']['username'] ?? null;
                $protocol = $cam['push_config']['protocol'] ?? 'sftp';
                
                $configured[] = [
                    'airport' => $airportId,
                    'cam' => $camIndex,
                    'username' => $username,
                    'protocol' => $protocol,
                    'key' => $cameraKey
                ];
                
                // Create directory with protocol-specific permissions
                createCameraDirectory($airportId, $camIndex, $protocol);
                
                // Create/update FTP/SFTP user
                if ($username && isset($cam['push_config']['password'])) {
                    if (syncCameraUser($airportId, $camIndex, $cam['push_config'], $newUsernameMapping)) {
                        // Success - mapping already updated in syncCameraUser
                    }
                }
            }
        }
    }
    
    // Remove orphaned directories and users
    foreach ($existing as $existingCam) {
        $found = false;
        $cameraKey = $existingCam['airport'] . '_' . $existingCam['cam'];
        
        foreach ($configured as $configCam) {
            if ($existingCam['airport'] === $configCam['airport'] && 
                $existingCam['cam'] === $configCam['cam']) {
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            // Find username from tracking file (not from config, since camera was removed)
            $username = null;
            $protocol = null;
            
            foreach ($usernameMapping as $user => $info) {
                if ($info['camera'] === $cameraKey) {
                    $username = $user;
                    $protocol = $info['protocol'] ?? 'sftp';
                    break;
                }
            }
            
            aviationwx_log('info', 'removing orphaned camera', [
                'airport' => $existingCam['airport'],
                'cam' => $existingCam['cam'],
                'username' => $username,
                'protocol' => $protocol
            ], 'app');
            
            // Remove user
            if ($username && $protocol) {
                removeCameraUser($username, $protocol);
            }
            
            // Remove directory
            removeCameraDirectory($existingCam['airport'], $existingCam['cam']);
        }
    }
    
    // Clean up username mapping - remove entries for cameras that no longer exist
    foreach ($usernameMapping as $user => $info) {
        $found = false;
        foreach ($configured as $configCam) {
            if ($info['camera'] === $configCam['key']) {
                $found = true;
                break;
            }
        }
        if ($found) {
            // Keep this entry (will be updated in newUsernameMapping if user was synced)
            if (!isset($newUsernameMapping[$user])) {
                $newUsernameMapping[$user] = $info;
            }
        }
    }
    
    // Save updated username mapping
    saveUsernameMapping($newUsernameMapping);
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
if (php_sapi_name() === 'cli') {
    $scriptName = $_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
    if (basename($scriptName) === basename(__FILE__) || $scriptName === __FILE__) {
        syncPushConfig();
    }
}

