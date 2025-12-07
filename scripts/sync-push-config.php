<?php
/**
 * Push Webcam Configuration Synchronizer
 * Watches airports.json for changes and syncs directories/users
 * 
 * Runs:
 * - On container startup via docker-entrypoint.sh
 * - During deployment via GitHub Actions workflow
 * 
 * This is more durable than cron and ensures immediate sync after deployments.
 * The script is idempotent and checks for config changes before syncing.
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';

$invocationId = aviationwx_get_invocation_id();
$triggerInfo = aviationwx_detect_trigger_type();

aviationwx_log('info', 'push-config sync started', [
    'invocation_id' => $invocationId,
    'trigger' => $triggerInfo['trigger'],
    'context' => $triggerInfo['context']
], 'app');

/**
 * Check if script is running as root (required for system operations)
 */
function checkRootPermissions() {
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    $isRoot = ($uid === 0);
    
    if (!$isRoot) {
        $username = 'unknown';
        if ($uid !== null && function_exists('posix_getpwuid')) {
            $userInfo = @posix_getpwuid($uid);
            if ($userInfo !== false && isset($userInfo['name'])) {
                $username = $userInfo['name'];
            }
        }
        aviationwx_log('error', 'sync-push-config: must run as root', [
            'current_uid' => $uid,
            'current_user' => $username,
            'required' => 'root (UID 0)'
        ], 'app');
        return false;
    }
    
    return true;
}

/**
 * Verify directory permissions and ownership
 * Returns array with 'success' boolean and 'issues' array
 */
function verifyDirectoryPermissions($path, $expectedOwner, $expectedGroup, $expectedPerms) {
    $issues = [];
    
    if (!is_dir($path)) {
        return ['success' => false, 'issues' => ["Directory does not exist: $path"]];
    }
    
    $stat = @stat($path);
    if (!$stat) {
        return ['success' => false, 'issues' => ["Cannot stat directory: $path"]];
    }
    
    if ($expectedOwner !== null) {
        $expectedOwnerUid = is_numeric($expectedOwner) ? intval($expectedOwner) : null;
        if ($expectedOwnerUid === null && function_exists('posix_getpwnam')) {
            $ownerInfo = @posix_getpwnam($expectedOwner);
            $expectedOwnerUid = $ownerInfo ? $ownerInfo['uid'] : null;
        }
        
        if ($expectedOwnerUid !== null && $stat['uid'] !== $expectedOwnerUid) {
            $actualOwner = function_exists('posix_getpwuid') ? @posix_getpwuid($stat['uid'])['name'] : $stat['uid'];
            $issues[] = "Ownership mismatch: expected UID $expectedOwnerUid ($expectedOwner), got UID {$stat['uid']} ($actualOwner)";
        }
    }
    
    if ($expectedGroup !== null) {
        $expectedGroupGid = is_numeric($expectedGroup) ? intval($expectedGroup) : null;
        if ($expectedGroupGid === null && function_exists('posix_getgrnam')) {
            $groupInfo = @posix_getgrnam($expectedGroup);
            $expectedGroupGid = $groupInfo ? $groupInfo['gid'] : null;
        }
        
        if ($expectedGroupGid !== null && $stat['gid'] !== $expectedGroupGid) {
            $actualGroup = function_exists('posix_getgrgid') ? @posix_getgrgid($stat['gid'])['name'] : $stat['gid'];
            $issues[] = "Group mismatch: expected GID $expectedGroupGid ($expectedGroup), got GID {$stat['gid']} ($actualGroup)";
        }
    }
    
    if ($expectedPerms !== null) {
        $actualPerms = substr(sprintf('%o', $stat['mode']), -4);
        $expectedPermsStr = is_numeric($expectedPerms) ? sprintf('%04o', $expectedPerms) : $expectedPerms;
        
        // Normalize to 4-digit format
        if (strlen($actualPerms) === 3) {
            $actualPerms = '0' . $actualPerms;
        }
        if (strlen($expectedPermsStr) === 3) {
            $expectedPermsStr = '0' . $expectedPermsStr;
        }
        
        // Compare last 3 digits (permissions, ignoring file type)
        if (substr($actualPerms, -3) !== substr($expectedPermsStr, -3)) {
            $issues[] = "Permissions mismatch: expected $expectedPermsStr, got $actualPerms";
        }
    }
    
    return [
        'success' => empty($issues),
        'issues' => $issues
    ];
}

/**
 * Set directory permissions with verification
 * Returns true if successful, false otherwise
 */
function setDirectoryPermissions($path, $owner, $group, $perms, $description = '') {
    $success = true;
    $errors = [];
    
    if ($owner !== null && function_exists('chown')) {
        $ownerUid = is_numeric($owner) ? intval($owner) : null;
        if ($ownerUid === null && function_exists('posix_getpwnam')) {
            $ownerInfo = @posix_getpwnam($owner);
            $ownerUid = $ownerInfo ? $ownerInfo['uid'] : null;
        }
        
        if ($ownerUid !== null) {
            if (!@chown($path, $ownerUid)) {
                $errors[] = "Failed to set owner to UID $ownerUid ($owner)";
                $success = false;
            }
        } else {
            $errors[] = "Cannot resolve owner: $owner";
            $success = false;
        }
    }
    
    if ($group !== null && function_exists('chgrp')) {
        $groupGid = is_numeric($group) ? intval($group) : null;
        if ($groupGid === null && function_exists('posix_getgrnam')) {
            $groupInfo = @posix_getgrnam($group);
            $groupGid = $groupInfo ? $groupInfo['gid'] : null;
        }
        
        if ($groupGid !== null) {
            if (!@chgrp($path, $groupGid)) {
                $errors[] = "Failed to set group to GID $groupGid ($group)";
                $success = false;
            }
        } else {
            $errors[] = "Cannot resolve group: $group";
            $success = false;
        }
    }
    
    if ($perms !== null) {
        $permsInt = is_numeric($perms) ? intval($perms) : octdec($perms);
        if (!@chmod($path, $permsInt)) {
            $errors[] = "Failed to set permissions to " . sprintf('%04o', $permsInt);
            $success = false;
        }
    }
    
    if ($success) {
        $verification = verifyDirectoryPermissions($path, $owner, $group, $perms);
        if (!$verification['success']) {
            $errors = array_merge($errors, $verification['issues']);
            $success = false;
        }
    }
    
    if (!$success && !empty($errors)) {
        aviationwx_log('warning', 'sync-push-config: permission setting failed', [
            'path' => $path,
            'description' => $description ?: 'directory',
            'errors' => $errors
        ], 'app');
    }
    
    return $success;
}

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
        @mkdir($trackDir, 0775, true);
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
        @mkdir($backupDir, 0775, true);
    }
    
    $backupFile = $backupDir . '/airports_' . date('Y-m-d_His') . '.json';
    if (!@copy($configFile, $backupFile)) {
        aviationwx_log('warning', 'sync-push-config: failed to create config backup', [
            'backup_file' => $backupFile,
            'source_file' => $configFile
        ], 'app');
        // Continue anyway - backup failure shouldn't block sync
    }
    
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
 * Check if vsftpd database is corrupted or invalid
 */
function isVsftpdDatabaseCorrupted() {
    $vsftpdDbFile = '/etc/vsftpd/virtual_users.db';
    $vsftpdUserFile = '/etc/vsftpd/virtual_users.txt';
    
    // Missing database with non-empty users file indicates corruption
    if (!file_exists($vsftpdDbFile) && file_exists($vsftpdUserFile)) {
        $content = @file_get_contents($vsftpdUserFile);
        if ($content && trim($content) !== '') {
            return true;
        }
    }
    
    if (file_exists($vsftpdDbFile)) {
        if (filesize($vsftpdDbFile) === 0) {
            return true;
        }
        
        // Check Berkeley DB magic number (first 4 bytes should be non-zero)
        $header = @file_get_contents($vsftpdDbFile, false, null, 0, 12);
        if ($header === false || strlen($header) < 12) {
            return true;
        }
        
        $magicBytes = unpack('C*', substr($header, 0, 4));
        // All zeros in first 4 bytes indicates corruption
        if (isset($magicBytes[1]) && $magicBytes[1] === 0 && 
            isset($magicBytes[2]) && $magicBytes[2] === 0 &&
            isset($magicBytes[3]) && $magicBytes[3] === 0 &&
            isset($magicBytes[4]) && $magicBytes[4] === 0) {
            return true;
        }
        
        // Verify using db_verify (most reliable check)
        if (function_exists('exec')) {
            $output = [];
            $returnCode = 0;
            @exec("db_verify " . escapeshellarg($vsftpdDbFile) . " 2>&1", $output, $returnCode);
            if ($returnCode !== 0) {
                aviationwx_log('warning', 'sync-push-config: db_verify failed, database corrupted', [
                    'db_file' => $vsftpdDbFile,
                    'output' => implode("\n", $output),
                    'return_code' => $returnCode
                ], 'app');
                return true;
            }
        }
        
        // Secondary check: test readability with db_dump
        if (function_exists('exec')) {
            $output = [];
            $returnCode = 0;
            @exec("db_dump -p " . escapeshellarg($vsftpdDbFile) . " 2>&1", $output, $returnCode);
            if ($returnCode !== 0) {
                $errorOutput = implode("\n", $output);
                if (preg_match('/BDB\d+|unexpected file type|corrupt|invalid/i', $errorOutput)) {
                    aviationwx_log('warning', 'sync-push-config: db_dump failed with corruption error', [
                        'db_file' => $vsftpdDbFile,
                        'output' => $errorOutput,
                        'return_code' => $returnCode
                    ], 'app');
                    return true;
                }
            }
        }
        
        // Heuristic: verify database size matches expected user count
        // Berkeley DB hash files are typically at least 8KB for small datasets
        if (file_exists($vsftpdUserFile)) {
            $userContent = @file_get_contents($vsftpdUserFile);
            if ($userContent && trim($userContent) !== '') {
                $userLines = array_filter(explode("\n", $userContent), function($line) {
                    return trim($line) !== '';
                });
                $expectedUsers = count($userLines) / 2;
                
                // Flag if database is suspiciously small (< 4KB) with users present
                if ($expectedUsers > 0 && filesize($vsftpdDbFile) < 4096) {
                    return true;
                }
            }
        }
    }
    
    return false;
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
 * Ensure base webcams directory exists with correct permissions (root:root)
 */
function ensureWebcamsBaseDirectory() {
    $webcamsBaseDir = __DIR__ . '/../uploads/webcams';
    
    if (!is_dir($webcamsBaseDir)) {
        @mkdir($webcamsBaseDir, 0775, true);
    }
    
    // Root ownership required for SFTP chroot
    setDirectoryPermissions($webcamsBaseDir, 0, 'root', 0775, 'webcams base directory');
    
    return $webcamsBaseDir;
}

/**
 * Create upload directory for camera
 * Creates both parent directory and incoming subdirectory with correct permissions
 */
function createCameraDirectory($airportId, $camIndex, $protocol = null) {
    $webcamsBaseDir = ensureWebcamsBaseDirectory();
    $baseDir = $webcamsBaseDir . '/' . $airportId . '_' . $camIndex;
    $incomingDir = $baseDir . '/incoming';
    
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0775, true);
    }
    
    if (!is_dir($incomingDir)) {
        @mkdir($incomingDir, 0775, true);
    }
    
    // Root ownership required for chroot
    setDirectoryPermissions($baseDir, 0, 'root', 0775, "camera base directory for {$airportId}_{$camIndex}");
    
    $protocol = strtolower($protocol ?? '');
    if (in_array($protocol, ['ftp', 'ftps'])) {
        // FTP/FTPS: www-data ownership required for vsftpd guest user
        $wwwDataInfo = @posix_getpwnam('www-data');
        if ($wwwDataInfo !== false) {
            setDirectoryPermissions(
                $incomingDir,
                $wwwDataInfo['uid'],
                'www-data',
                0775,
                "FTP incoming directory for {$airportId}_{$camIndex}"
            );
        } else {
            @chmod($incomingDir, 0775);
        }
    } else {
        // SFTP: ownership set by create-sftp-user.sh
        @chmod($incomingDir, 0775);
    }
    
    return $incomingDir;
}

/**
 * Remove camera directory
 */
function removeCameraDirectory($airportId, $camIndex) {
    $uploadDir = __DIR__ . '/../uploads/webcams/' . $airportId . '_' . $camIndex;
    
    if (!is_dir($uploadDir)) {
        return;
    }
    
    try {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveDirectoryIterator::CHILD_FIRST
        );
        
        $errors = [];
        foreach ($files as $file) {
            $path = $file->getRealPath();
            if ($file->isDir()) {
                if (!@rmdir($path)) {
                    $errors[] = "Failed to remove directory: $path";
                }
            } else {
                if (!@unlink($path)) {
                    $errors[] = "Failed to remove file: $path";
                }
            }
        }
        
        if (!@rmdir($uploadDir)) {
            $errors[] = "Failed to remove main directory: $uploadDir";
        }
        
        if (!empty($errors)) {
            aviationwx_log('warning', 'sync-push-config: some files/directories could not be removed', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'directory' => $uploadDir,
                'errors' => $errors
            ], 'app');
        }
    } catch (Exception $e) {
        aviationwx_log('error', 'sync-push-config: exception while removing camera directory', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'directory' => $uploadDir,
            'error' => $e->getMessage()
        ], 'app');
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
        // Directory format: airportId_camIndex
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
        @mkdir($trackDir, 0775, true);
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
    // Fallback to /etc/passwd
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
    
    $cmd = sprintf(
        '/usr/local/bin/create-sftp-user.sh %s %s %s 2>&1',
        escapeshellarg($username),
        escapeshellarg($password),
        escapeshellarg($chrootDir)
    );
    
    $output = [];
    $code = 0;
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
 * Rebuild vsftpd database from users file
 * Called when database corruption is detected
 */
function rebuildVsftpdDatabase() {
    $vsftpdUserFile = '/etc/vsftpd/virtual_users.txt';
    $vsftpdDbFile = '/etc/vsftpd/virtual_users.db';
    
    if (!file_exists($vsftpdUserFile)) {
        aviationwx_log('warning', 'sync-push-config: Cannot rebuild database, users file does not exist', [
            'user_file' => $vsftpdUserFile
        ], 'app');
        return false;
    }
    
    $userContent = @file_get_contents($vsftpdUserFile);
    if (!$userContent || trim($userContent) === '') {
        aviationwx_log('warning', 'sync-push-config: Cannot rebuild database, users file is empty', [
            'user_file' => $vsftpdUserFile
        ], 'app');
        if (file_exists($vsftpdDbFile)) {
            @unlink($vsftpdDbFile);
        }
        return true;
    }
    
    if (file_exists($vsftpdDbFile)) {
        @unlink($vsftpdDbFile);
    }
    
    $output = [];
    $returnCode = 0;
    exec('db_load -T -t hash -f ' . escapeshellarg($vsftpdUserFile) . ' ' . escapeshellarg($vsftpdDbFile) . ' 2>&1', $output, $returnCode);
    
    if ($returnCode !== 0) {
        aviationwx_log('error', 'sync-push-config: db_load failed during database rebuild', [
            'output' => implode("\n", $output),
            'return_code' => $returnCode
        ], 'app');
        return false;
    }
    
    if (!file_exists($vsftpdDbFile) || filesize($vsftpdDbFile) === 0) {
        aviationwx_log('error', 'sync-push-config: database file not created or is empty after rebuild', [
            'db_file' => $vsftpdDbFile,
            'exists' => file_exists($vsftpdDbFile),
            'size' => file_exists($vsftpdDbFile) ? filesize($vsftpdDbFile) : 0
        ], 'app');
        return false;
    }
    
    $output = [];
    $returnCode = 0;
    @exec("db_verify " . escapeshellarg($vsftpdDbFile) . " 2>&1", $output, $returnCode);
    if ($returnCode !== 0) {
        aviationwx_log('error', 'sync-push-config: database verification failed after rebuild', [
            'output' => implode("\n", $output),
            'return_code' => $returnCode
        ], 'app');
        return false;
    }
    
    aviationwx_log('info', 'sync-push-config: vsftpd database rebuilt successfully', [
        'db_file' => $vsftpdDbFile,
        'size' => filesize($vsftpdDbFile)
    ], 'app');
    
    return true;
}

/**
 * Create FTP user (vsftpd virtual user)
 */
function createFtpUser($airportId, $camIndex, $username, $password) {
    $vsftpdUserFile = '/etc/vsftpd/virtual_users.txt';
    $vsftpdDbFile = '/etc/vsftpd/virtual_users.db';
    
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
    
    $users[$username] = $password;
    
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
    
    if (file_exists($vsftpdDbFile)) {
        @unlink($vsftpdDbFile);
    }
    
    $output = [];
    $code = 0;
    exec('db_load -T -t hash -f ' . escapeshellarg($vsftpdUserFile) . ' ' . escapeshellarg($vsftpdDbFile) . ' 2>&1', $output, $code);
    
    if ($code !== 0) {
        aviationwx_log('error', 'sync-push-config: db_load failed', [
            'output' => implode("\n", $output)
        ], 'app');
        return false;
    }
    
    if (!file_exists($vsftpdDbFile) || filesize($vsftpdDbFile) === 0) {
        aviationwx_log('error', 'sync-push-config: database file not created or is empty after db_load', [
            'db_file' => $vsftpdDbFile,
            'exists' => file_exists($vsftpdDbFile),
            'size' => file_exists($vsftpdDbFile) ? filesize($vsftpdDbFile) : 0
        ], 'app');
        return false;
    }
    
    $userConfigDir = '/etc/vsftpd/users';
    if (!is_dir($userConfigDir)) {
        @mkdir($userConfigDir, 0775, true);
    }
    
    $webcamsBaseDir = ensureWebcamsBaseDirectory();
    $chrootDir = $webcamsBaseDir . '/' . $airportId . '_' . $camIndex;
    $incomingDir = $chrootDir . '/incoming';
    
    if (!is_dir($chrootDir)) {
        @mkdir($chrootDir, 0775, true);
    }
    if (!is_dir($incomingDir)) {
        @mkdir($incomingDir, 0775, true);
    }
    
    // Root ownership required for chroot
    setDirectoryPermissions($chrootDir, 0, 'root', 0775, "FTP chroot directory for {$airportId}_{$camIndex}");
    
    // www-data ownership required for vsftpd guest user
    $wwwDataInfo = @posix_getpwnam('www-data');
    if ($wwwDataInfo !== false) {
        setDirectoryPermissions(
            $incomingDir,
            $wwwDataInfo['uid'],
            'www-data',
            0775,
            "FTP incoming directory for {$airportId}_{$camIndex}"
            );
        } else {
            @chmod($incomingDir, 0775);
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
    
    if (count($users) > 0) {
        if (file_exists($vsftpdDbFile)) {
            @unlink($vsftpdDbFile);
        }
        
        $output = [];
        $code = 0;
        exec('db_load -T -t hash -f ' . escapeshellarg($vsftpdUserFile) . ' ' . escapeshellarg($vsftpdDbFile) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            aviationwx_log('warning', 'sync-push-config: db_load failed during user removal', [
                'output' => implode("\n", $output),
                'username' => $username
            ], 'app');
        }
    } else {
        @unlink($vsftpdDbFile);
    }
    
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
    $output = [];
    $code = 0;
    exec('userdel ' . escapeshellarg($username) . ' 2>&1', $output, $code);
    
    if ($code === 0) {
        aviationwx_log('info', 'sync-push-config: SFTP user removed', [
            'username' => $username
        ], 'app');
    } else {
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
        
        $existingProtocol = $usernameMapping[$username]['protocol'];
        if ($existingProtocol !== $protocol) {
            aviationwx_log('info', 'sync-push-config: protocol changed, removing old user', [
                'username' => $username,
                'old_protocol' => $existingProtocol,
                'new_protocol' => $protocol,
                'camera' => $cameraKey
            ], 'app');
            
            removeCameraUser($username, $existingProtocol);
        }
    }
    
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
                
                createCameraDirectory($airportId, $camIndex, $protocol);
                
                if ($username && isset($cam['push_config']['password'])) {
                    if (syncCameraUser($airportId, $camIndex, $cam['push_config'], $newUsernameMapping)) {
                        // Verify permissions for FTP/FTPS (createFtpUser sets them, but verify)
                        if (in_array(strtolower($protocol), ['ftp', 'ftps'])) {
                            $incomingDir = __DIR__ . "/../uploads/webcams/{$airportId}_{$camIndex}/incoming";
                            if (is_dir($incomingDir)) {
                                $wwwDataInfo = @posix_getpwnam('www-data');
                                if ($wwwDataInfo !== false) {
                                    $verification = verifyDirectoryPermissions(
                                        $incomingDir,
                                        $wwwDataInfo['uid'],
                                        'www-data',
                                        0775
                                    );
                                    if (!$verification['success']) {
                                        setDirectoryPermissions(
                                            $incomingDir,
                                            $wwwDataInfo['uid'],
                                            'www-data',
                                            0775,
                                            "FTP incoming directory for {$airportId}_{$camIndex}"
                                        );
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
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
            
            if ($username && $protocol) {
                removeCameraUser($username, $protocol);
            }
            
            removeCameraDirectory($existingCam['airport'], $existingCam['cam']);
        }
    }
    
    foreach ($usernameMapping as $user => $info) {
        $found = false;
        foreach ($configured as $configCam) {
            if ($info['camera'] === $configCam['key']) {
                $found = true;
                break;
            }
        }
        if ($found) {
            if (!isset($newUsernameMapping[$user])) {
                $newUsernameMapping[$user] = $info;
            }
        }
    }
    
    saveUsernameMapping($newUsernameMapping);
}

/**
 * Main sync function
 */
function syncPushConfig() {
    if (!checkRootPermissions()) {
        aviationwx_log('error', 'sync-push-config: exiting due to insufficient permissions', [], 'app');
        exit(1);
    }
    
    $configFile = getConfigFilePath();
    
    if (!file_exists($configFile)) {
        aviationwx_log('error', 'config file not found', ['path' => $configFile], 'app');
        exit(1);
    }
    
    $configMtime = filemtime($configFile);
    $lastSync = getLastSyncTimestamp();
    
    $databaseCorrupted = isVsftpdDatabaseCorrupted();
    if ($databaseCorrupted) {
        aviationwx_log('warning', 'sync-push-config: vsftpd database appears corrupted, attempting rebuild', [
            'db_file' => '/etc/vsftpd/virtual_users.db',
            'user_file' => '/etc/vsftpd/virtual_users.txt'
        ], 'app');
        
        if (rebuildVsftpdDatabase()) {
            aviationwx_log('info', 'sync-push-config: Database rebuilt successfully, continuing with sync', [], 'app');
        } else {
            aviationwx_log('warning', 'sync-push-config: Database rebuild failed, will rebuild during full sync', [            ], 'app');
        }
    } else {
        if ($configMtime <= $lastSync) {
            return;
        }
    }
    
    $validation = validateConfigBeforeApply($configFile);
    if (!$validation['valid']) {
        aviationwx_log('error', 'config validation failed, skipping sync', [
            'error' => $validation['error']
        ], 'app');
        return;
    }
    
    $backupFile = backupConfigFile($configFile);
    aviationwx_log('info', 'config backed up', ['backup_file' => $backupFile], 'app');
    
    $config = loadConfig(false);
    if (!$config) {
        aviationwx_log('error', 'config load failed', [], 'app');
        exit(1);
    }
    
    syncAllPushCameras($config);
    updateLastSyncTimestamp();
    
    aviationwx_log('info', 'push-config sync completed', [], 'app');
}

if (php_sapi_name() === 'cli') {
    $scriptName = $_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
    if (basename($scriptName) === basename(__FILE__) || $scriptName === __FILE__) {
        syncPushConfig();
    }
}

