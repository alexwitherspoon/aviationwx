<?php
/**
 * Push Webcam Configuration Synchronizer
 *
 * Watches airports.json for changes and syncs FTP/SFTP users and upload directories.
 *
 * Runs on container startup (docker-entrypoint.sh) and during deployment (GitHub Actions).
 * Always repairs SFTP chroot ownership first (sshd requires root:root on /var/sftp/{user}/).
 * Reprovisions upload health probe accounts on every run (container-local /etc state).
 * Full user/directory sync is skipped when config is unchanged unless ProFTPD auth file is missing.
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/push-webcam-validator.php';
require_once __DIR__ . '/../lib/proftpd-auth.php';

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
 * Repair SFTP chroot ownership for all upload users (sshd internal-sftp).
 *
 * Runs before the config-unchanged early return so mistaken host chown on
 * /tmp/aviationwx-cache/sftp cannot block bridge uploads until airports.json changes.
 *
 * @return bool True when repair succeeded; false when the script is missing (production) or repair errors
 */
function repairAllSftpChrootPermissions(): bool {
    $candidates = [
        '/usr/local/libexec/aviationwx/repair-sftp-chroot-permissions.sh',
        __DIR__ . '/repair-sftp-chroot-permissions.sh',
    ];

    $script = null;
    foreach ($candidates as $path) {
        if (is_file($path) && is_executable($path)) {
            $script = $path;
            break;
        }
    }

    if ($script === null) {
        aviationwx_log(
            isProduction() ? 'error' : 'warning',
            'sync-push-config: SFTP chroot repair script not found',
            ['candidates' => $candidates],
            'app'
        );
        return !isProduction();
    }

    $output = [];
    $code = 0;
    exec(escapeshellarg($script) . ' 2>&1', $output, $code);

    if ($code !== 0) {
        aviationwx_log('error', 'sync-push-config: SFTP chroot repair failed', [
            'script' => $script,
            'exit_code' => $code,
            'output' => implode("\n", $output),
        ], 'app');
        return false;
    }

    aviationwx_log('debug', 'sync-push-config: SFTP chroot permissions repaired', [
        'script' => $script,
    ], 'app');

    return true;
}

/**
 * Repair FTP upload inbox ownership for all configured users.
 *
 * Runs before the config-unchanged early return so mistaken host chown on the
 * cache tree cannot leave FTP inboxes root-owned until airports.json changes.
 *
 * @return bool True when repair succeeded; false when the script is missing (production) or repair errors
 */
function repairAllFtpUploadPermissions(): bool {
    $candidates = [
        '/usr/local/libexec/aviationwx/repair-ftp-upload-permissions.sh',
        __DIR__ . '/repair-ftp-upload-permissions.sh',
    ];

    $script = null;
    foreach ($candidates as $path) {
        if (is_file($path) && is_executable($path)) {
            $script = $path;
            break;
        }
    }

    if ($script === null) {
        aviationwx_log(
            isProduction() ? 'error' : 'warning',
            'sync-push-config: FTP inbox repair script not found',
            ['candidates' => $candidates],
            'app'
        );
        return !isProduction();
    }

    $output = [];
    $code = 0;
    exec(escapeshellarg($script) . ' 2>&1', $output, $code);

    if ($code !== 0) {
        aviationwx_log('error', 'sync-push-config: FTP inbox repair failed', [
            'script' => $script,
            'exit_code' => $code,
            'output' => implode("\n", $output),
        ], 'app');
        return false;
    }

    aviationwx_log('debug', 'sync-push-config: FTP inbox permissions repaired', [
        'script' => $script,
    ], 'app');

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

// getConfigFilePath() is provided by lib/config.php which is already included

/**
 * Get last sync timestamp
 * 
 * Retrieves the timestamp of the last successful configuration sync.
 * Used to prevent unnecessary re-syncing when configuration hasn't changed.
 * 
 * @return int Unix timestamp of last sync, or 0 if never synced
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
 * 
 * Updates the timestamp of the last successful configuration sync.
 * Creates tracking directory if it doesn't exist.
 * 
 * @return void
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
 * 
 * Creates a timestamped backup of the configuration file before making changes.
 * Keeps only the last 5 backups to prevent disk space issues.
 * 
 * @param string $configFile Path to config file to backup
 * @return string|false Path to backup file on success, false on failure
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
 * Validate config before applying
 *
 * Validates JSON syntax, basic structure, and runtime schema (including unique push usernames)
 * before applying changes.
 *
 * @param string $configFile Path to config file to validate
 * @return array {
 *   'valid' => bool,
 *   'error' => string
 * }
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

    $schema = validateRuntimeConfigSchema($config);
    if (!$schema['valid']) {
        return ['valid' => false, 'error' => implode('; ', $schema['errors'])];
    }
    
    return ['valid' => true];
}

/**
 * Ensure base webcams directory exists with correct permissions
 * 
 * Creates the base cache/uploads directory if it doesn't exist.
 * 
 * @return string Path to webcams base directory
 */
function ensureWebcamsBaseDirectory() {
    $webcamsBaseDir = CACHE_UPLOADS_DIR;
    
    if (!is_dir($webcamsBaseDir)) {
        @mkdir($webcamsBaseDir, 0755, true);
    }
    
    return $webcamsBaseDir;
}

/**
 * Create FTP upload directory for camera
 * 
 * Creates airport-scoped FTP upload directory:
 *   /ftp/{airport}/{username}/    ← FTP upload dir (ftp:www-data 2775)
 * 
 * FTP uses a simple flat structure. ProFTPD DefaultChdir ~ uses each user's homedir.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string|null $username Username (required)
 * @return string|null Path to FTP upload directory, or null on error
 */
function createFtpDirectory($airportId, $camIndex, $username = null) {
    $webcamsBaseDir = ensureWebcamsBaseDirectory();
    $airportId = strtolower($airportId);
    
    if (!$username) {
        aviationwx_log('warning', 'createFtpDirectory: no username provided', [
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return null;
    }
    
    // Get user/group info
    $ftpInfo = @posix_getpwnam('ftp');
    $ftpUid = $ftpInfo ? $ftpInfo['uid'] : 101;
    $wwwDataInfo = @posix_getpwnam('www-data');
    $wwwDataGid = $wwwDataInfo ? $wwwDataInfo['gid'] : 33;
    
    // Airport directory: /ftp/{airport}/
    $airportDir = $webcamsBaseDir . '/' . $airportId;
    if (!is_dir($airportDir)) {
        @mkdir($airportDir, 0755, true);
    }
    
    // FTP upload directory: /ftp/{airport}/{username}/
    // ftp:www-data with setgid
    $ftpDir = getWebcamFtpUploadDir($airportId, $username);
    if (!is_dir($ftpDir)) {
        @mkdir($ftpDir, 02775, true);
    }
    @chown($ftpDir, $ftpUid);
    @chgrp($ftpDir, $wwwDataGid);
    @chmod($ftpDir, 02775);
    
    aviationwx_log('debug', 'createFtpDirectory: FTP directory created', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'ftp_dir' => $ftpDir
    ], 'app');
    
    return $ftpDir;
}

/**
 * Create SFTP chroot directory structure for camera
 * 
 * Creates dedicated SFTP chroot structure:
 *   /sftp/{username}/       ← SFTP chroot (root:root 755)
 *   /sftp/{username}/files/ ← SFTP upload dir (ftp:www-data 2775)
 * 
 * SFTP uses a separate /cache/sftp/ hierarchy where ALL parent directories
 * are root-owned (required for SSH ChrootDirectory to work).
 * 
 * @param string $username Username (required)
 * @return string|null Path to SFTP upload directory (files/), or null on error
 */
function createSftpDirectory($username) {
    if (!$username) {
        aviationwx_log('warning', 'createSftpDirectory: no username provided', [], 'app');
        return null;
    }
    
    // Get user/group info
    $ftpInfo = @posix_getpwnam('ftp');
    $ftpUid = $ftpInfo ? $ftpInfo['uid'] : 101;
    $wwwDataInfo = @posix_getpwnam('www-data');
    $wwwDataGid = $wwwDataInfo ? $wwwDataInfo['gid'] : 33;
    
    // Ensure base SFTP directory exists (root-owned)
    if (!is_dir(CACHE_SFTP_DIR)) {
        @mkdir(CACHE_SFTP_DIR, 0755, true);
    }
    @chown(CACHE_SFTP_DIR, 0);
    @chgrp(CACHE_SFTP_DIR, 0);
    @chmod(CACHE_SFTP_DIR, 0755);
    
    // Chroot directory: /sftp/{username}/
    // Must be root-owned for SSH chroot
    $chrootDir = getWebcamSftpChrootDir($username);
    if (!is_dir($chrootDir)) {
        @mkdir($chrootDir, 0755, true);
    }
    @chown($chrootDir, 0);
    @chgrp($chrootDir, 0);
    @chmod($chrootDir, 0755);
    
    // Upload directory: /sftp/{username}/files/
    // ftp:www-data with setgid
    $filesDir = getWebcamSftpUploadDir($username);
    if (!is_dir($filesDir)) {
        @mkdir($filesDir, 02775, true);
    }
    @chown($filesDir, $ftpUid);
    @chgrp($filesDir, $wwwDataGid);
    @chmod($filesDir, 02775);
    
    aviationwx_log('debug', 'createSftpDirectory: SFTP chroot structure created', [
        'username' => $username,
        'chroot' => $chrootDir,
        'upload_dir' => $filesDir
    ], 'app');
    
    return $filesDir;
}

/**
 * Create all upload directories for a camera (FTP and SFTP)
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string|null $username Username (required)
 * @return array|null Array with 'ftp' and 'sftp' paths, or null on error
 */
function createCameraDirectory($airportId, $camIndex, $username = null) {
    if (!$username) {
        aviationwx_log('warning', 'createCameraDirectory: no username provided', [
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return null;
    }
    
    $ftpDir = createFtpDirectory($airportId, $camIndex, $username);
    $sftpDir = createSftpDirectory($username);
    
    if (!$ftpDir || !$sftpDir) {
        return null;
    }
    
    return [
        'ftp' => $ftpDir,
        'sftp' => $sftpDir
    ];
}

/**
 * Remove camera directory
 * 
 * Recursively removes upload directories for a camera when it's no longer configured.
 * Handles current FTP/SFTP structure and legacy directory structures.
 * 
 * Current structure:
 *   /ftp/{airport}/{username}/  ← FTP uploads
 *   /sftp/{username}/               ← SFTP chroot
 *   /sftp/{username}/files/         ← SFTP uploads
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string|null $username Username for the camera
 * @return void
 */
function removeCameraDirectory($airportId, $camIndex, $username = null) {
    // Normalize to lowercase - must match directory paths created by createCameraDirectory()
    $airportId = strtolower($airportId);
    $uploadsBaseDir = CACHE_UPLOADS_DIR . '/';
    $sftpBaseDir = CACHE_SFTP_DIR . '/';
    
    // Remove all possible directory locations (current + legacy)
    $dirsToRemove = [
        $uploadsBaseDir . $airportId . '_' . $camIndex,  // Legacy: airportId_camIndex
    ];
    if ($username) {
        // Current FTP structure
        $dirsToRemove[] = $uploadsBaseDir . $airportId . '/' . $username;
        
        // Current SFTP structure
        $dirsToRemove[] = $sftpBaseDir . $username . '/files';
        $dirsToRemove[] = $sftpBaseDir . $username;
        
        // Legacy structures
        $dirsToRemove[] = $uploadsBaseDir . $username;
        $dirsToRemove[] = $uploadsBaseDir . $airportId . '/' . $username . '/files';
    }
    
    foreach ($dirsToRemove as $uploadDir) {
        if (!is_dir($uploadDir)) {
            continue;
        }
        removeDirectoryRecursive($uploadDir, $airportId, $camIndex);
    }
    
    // Clean up empty airport directory if it exists
    if ($username) {
        $airportDir = $uploadsBaseDir . $airportId;
        if (is_dir($airportDir)) {
            $remaining = glob($airportDir . '/*');
            if (empty($remaining)) {
                @rmdir($airportDir);
            }
        }
    }
}

/**
 * Helper to recursively remove a directory
 */
function removeDirectoryRecursive($uploadDir, $airportId, $camIndex) {
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
 * Get existing push cameras from username mapping
 * 
 * Returns cameras that have been configured by reading the username mapping file
 * which tracks the username -> camera relationship.
 * 
 * @return array Array of camera arrays with 'airport' and 'cam' keys
 */
function getExistingPushCameras() {
    $cameras = [];
    $seen = [];
    
    $usernameMapping = loadUsernameMapping();
    foreach ($usernameMapping as $username => $info) {
        if (isset($info['airport']) && isset($info['cam'])) {
            $airport = strtolower($info['airport']);
            $key = $airport . '_' . $info['cam'];
            if (!isset($seen[$key])) {
                $cameras[] = [
                    'airport' => $airport,
                    'cam' => intval($info['cam'])
                ];
                $seen[$key] = true;
            }
        }
    }
    
    return $cameras;
}

/**
 * Get username tracking file path
 * 
 * Returns the path to the username-to-camera mapping file. Creates tracking
 * directory if it doesn't exist.
 * 
 * @return string Path to username_mapping.json file
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
 * 
 * Loads the mapping of SFTP/FTP usernames to airport/camera combinations.
 * Returns empty array if file doesn't exist or is invalid.
 * 
 * @return array Username mapping array (username => ['airport' => string, 'cam' => int])
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
 * 
 * Saves the mapping of SFTP/FTP usernames to airport/camera combinations.
 * Uses file locking to ensure atomic writes.
 * 
 * @param array $mapping Username mapping array (username => ['airport' => string, 'cam' => int])
 * @return bool True on success, false on failure
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
 * Validate push upload credentials for sync-time provisioning.
 *
 * @return list<string> Empty when both username and password are valid
 */
function validateSyncPushUploadCredentials(string $username, string $password, string $contextLabel): array {
    return array_merge(
        validatePushUploadUsername($username, $contextLabel),
        validatePushUploadPassword($password, $contextLabel)
    );
}

/**
 * Ensure a push webcam upload directory is ftp:www-data with setgid (02775).
 *
 * @param string $uploadDir Writable FTP or SFTP files/ directory
 * @return void
 */
function repairPushUploadDirectoryPermissions(string $uploadDir): void {
    if (!is_dir($uploadDir)) {
        return;
    }

    $ftpInfo = @posix_getpwnam('ftp');
    $wwwDataInfo = @posix_getpwnam('www-data');
    if ($ftpInfo === false || $wwwDataInfo === false) {
        return;
    }

    $verification = verifyDirectoryPermissions(
        $uploadDir,
        $ftpInfo['uid'],
        'www-data',
        02775
    );
    if (!$verification['success']) {
        @chown($uploadDir, $ftpInfo['uid']);
        @chgrp($uploadDir, $wwwDataInfo['gid']);
        @chmod($uploadDir, 02775);
    }
}

/**
 * Check if user exists
 * 
 * Checks if a system user exists by querying POSIX functions or /etc/passwd.
 * 
 * @param string $username Username to check
 * @return bool True if user exists, false otherwise
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
 * 
 * Creates a new SFTP user account with chroot directory restriction.
 * Calls external create-sftp-user.sh script to handle user creation.
 * 
 * Uses dedicated /cache/sftp/ hierarchy where all parent directories
 * are root-owned (required for SSH ChrootDirectory to work).
 * 
 * Directory structure:
 *   /sftp/{username}/       ← root:root 755 (SFTP chroot)
 *   /sftp/{username}/files/ ← ftp:www-data 2775 (upload directory)
 * 
 * SFTP users are chrooted and must upload to /files/
 * 
 * @param string $airportId Airport ID (e.g., 'kspb') - for logging only
 * @param int $camIndex Camera index (0-based) - for logging only
 * @param string $username Username (up to 14 alphanumeric characters)
 * @param string $password Password (14 alphanumeric characters)
 * @return bool True on success, false on failure
 */
function createSftpUser($airportId, $camIndex, $username, $password) {
    // create-sftp-user.sh now only takes username and password
    // It creates the directory structure in /cache/sftp/{username}/
    $cmd = sprintf(
        '/usr/local/bin/create-sftp-user.sh %s %s 2>&1',
        escapeshellarg($username),
        escapeshellarg($password)
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
    
    $chrootDir = getWebcamSftpChrootDir($username);
    aviationwx_log('info', 'sync-push-config: SFTP user created/updated', [
        'username' => $username,
        'airport' => $airportId,
        'cam' => $camIndex,
        'chroot' => $chrootDir
    ], 'app');
    
    return true;
}

/**
 * Build ProFTPD AuthUserFile account map from the on-disk passwd file.
 *
 * @return array<string, array{password: string, home: string}>
 */
function readProftpdAccountMap(): array
{
    $parsed = parseProftpdPasswdFile();
    $accounts = [];
    foreach ($parsed['users'] as $username => $info) {
        $accounts[$username] = [
            'password' => $info['password_hash'],
            'home' => $info['home'],
        ];
    }

    return $accounts;
}

/**
 * Upsert ProFTPD virtual user with homedir and writable upload directory.
 *
 * @param string $username Username (up to 14 alphanumeric characters)
 * @param string $password Password (14 alphanumeric characters)
 * @param string $ftpDir Absolute homedir for this user (DefaultChdir ~)
 * @param array<string, mixed> $logContext Extra fields for success log context
 * @return bool True on success, false on failure
 */
function upsertFtpVirtualUser($username, $password, $ftpDir, $logContext = []) {
    $parsed = parseProftpdPasswdFile();
    if ($parsed['errors'] !== []) {
        aviationwx_log('warning', 'sync-push-config: ProFTPD auth file parse issues', [
            'errors' => $parsed['errors'],
        ], 'app');
    }

    $accounts = readProftpdAccountMap();
    $accounts[$username] = [
        'password' => $password,
        'home' => $ftpDir,
    ];

    if (!writeProftpdPasswdFile($accounts)) {
        aviationwx_log('error', 'sync-push-config: Cannot write ProFTPD auth file', [
            'file' => PROFTPD_AUTH_FILE,
        ], 'app');
        return false;
    }

    $ftpInfo = @posix_getpwnam('ftp');
    $ftpUid = $ftpInfo ? $ftpInfo['uid'] : 101;
    $wwwDataInfo = @posix_getpwnam('www-data');
    $wwwDataGid = $wwwDataInfo ? $wwwDataInfo['gid'] : 33;

    if (!is_dir($ftpDir)) {
        @mkdir($ftpDir, 02775, true);
    }
    @chown($ftpDir, $ftpUid);
    @chgrp($ftpDir, $wwwDataGid);
    @chmod($ftpDir, 02775);

    aviationwx_log('info', 'sync-push-config: FTP user created/updated', array_merge([
        'username' => $username,
        'homedir' => $ftpDir,
    ], $logContext), 'app');

    return true;
}

/**
 * Create FTP user (ProFTPD virtual user) for a push camera inbox.
 *
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $username Username (up to 14 alphanumeric characters)
 * @param string $password Password (14 alphanumeric characters)
 * @return bool True on success, false on failure
 */
function createFtpUser($airportId, $camIndex, $username, $password) {
    $ftpDir = getWebcamFtpUploadDir($airportId, $username);

    ensureWebcamsBaseDirectory();

    $airportDir = CACHE_UPLOADS_DIR . '/' . strtolower($airportId);
    if (!is_dir($airportDir)) {
        @mkdir($airportDir, 0755, true);
    }

    return upsertFtpVirtualUser($username, $password, $ftpDir, [
        'airport' => $airportId,
        'cam' => $camIndex,
    ]);
}

/**
 * Remove FTP user from ProFTPD auth file.
 *
 * @param string $username FTP username to remove
 * @return void
 */
function removeFtpUser($username) {
    $parsed = parseProftpdPasswdFile();
    if ($parsed['errors'] !== []) {
        aviationwx_log('warning', 'sync-push-config: ProFTPD auth file parse issues during removal', [
            'errors' => $parsed['errors'],
            'username' => $username,
        ], 'app');
    }

    $accounts = readProftpdAccountMap();
    unset($accounts[$username]);

    if (!writeProftpdPasswdFile($accounts)) {
        aviationwx_log('warning', 'sync-push-config: Cannot write ProFTPD auth file during removal', [
            'file' => PROFTPD_AUTH_FILE,
            'username' => $username,
        ], 'app');
    }

    aviationwx_log('info', 'sync-push-config: FTP user removed', [
        'username' => $username,
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
 * Sync camera user credentials and create system accounts
 * 
 * Creates or updates BOTH FTP and SFTP user accounts for unified access.
 * Both protocols share the same username/password and upload to the same directory.
 * 
 * The 'protocol' field has been removed from push_config - both protocols
 * are always enabled for maximum flexibility.
 * 
 * @param string $airportId Airport identifier (will be normalized to lowercase)
 * @param int $camIndex Camera index (0-based)
 * @param array $pushConfig Push config with 'username', 'password' keys
 * @param array &$usernameMapping Reference to username mapping array to update
 * @return bool True if user sync succeeded, false on failure
 */
function syncCameraUser($airportId, $camIndex, $pushConfig, &$usernameMapping) {
    // Normalize to lowercase - mapping must match webcam worker expectations
    $airportId = strtolower($airportId);
    
    $username = $pushConfig['username'] ?? null;
    $password = $pushConfig['password'] ?? null;
    
    if (!$username || !$password) {
        aviationwx_log('warning', 'sync-push-config: missing credentials', [
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return false;
    }
    
    $credentialErrors = validateSyncPushUploadCredentials(
        $username,
        $password,
        "sync-push-config: airport '{$airportId}' webcam {$camIndex}"
    );
    if ($credentialErrors !== []) {
        aviationwx_log('error', 'sync-push-config: invalid push upload credentials', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'username' => $username,
            'errors' => $credentialErrors,
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
    }
    
    // Create both FTP and SFTP users
    // They share the same credentials and upload to the same directory
    $ftpSuccess = createFtpUser($airportId, $camIndex, $username, $password);
    $sftpSuccess = createSftpUser($airportId, $camIndex, $username, $password);
    
    if (!$ftpSuccess && !$sftpSuccess) {
        aviationwx_log('error', 'sync-push-config: failed to create both FTP and SFTP users', [
            'username' => $username,
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return false;
    }
    
    if (!$ftpSuccess) {
        aviationwx_log('warning', 'sync-push-config: FTP user creation failed, SFTP only', [
            'username' => $username,
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
    }
    
    if (!$sftpSuccess) {
        aviationwx_log('warning', 'sync-push-config: SFTP user creation failed, FTP only', [
            'username' => $username,
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
    }
    
    // Update username mapping - protocols is now 'both'
    $usernameMapping[$username] = [
        'camera' => $cameraKey,
        'airport' => $airportId,
        'cam' => $camIndex,
        'protocols' => ['ftp', 'sftp']  // Both protocols enabled
    ];
    
    aviationwx_log('info', 'sync-push-config: camera user synced (FTP + SFTP)', [
        'username' => $username,
        'airport' => $airportId,
        'cam' => $camIndex,
        'ftp_ok' => $ftpSuccess,
        'sftp_ok' => $sftpSuccess
    ], 'app');
    
    return true;
}

/**
 * Remove camera user (both FTP and SFTP)
 * 
 * @param string $username Username to remove
 * @param string|array|null $protocols Protocol(s) to remove, or null/empty for both
 */
function removeCameraUser($username, $protocols = null) {
    // Default to removing both protocols
    if (empty($protocols)) {
        $protocols = ['ftp', 'sftp'];
    } elseif (is_string($protocols)) {
        $protocols = [$protocols];
    }
    
    foreach ($protocols as $protocol) {
        $protocol = strtolower($protocol);
        if ($protocol === 'sftp') {
            removeSftpUser($username);
        } elseif (in_array($protocol, ['ftp', 'ftps'])) {
            removeFtpUser($username);
        }
    }
}

/**
 * Sync all push cameras from configuration
 * 
 * Processes all airports and webcams, creating/updating user accounts and
 * directories for push cameras. Both FTP and SFTP are enabled for each camera.
 * Cleans up removed cameras.
 * 
 * @param array $config Full configuration array with 'airports' key
 * @return void
 */
function syncAllPushCameras($config) {
    $existing = getExistingPushCameras();
    $configured = [];
    $usernameMapping = loadUsernameMapping();
    $newUsernameMapping = [];
    
    foreach ($config['airports'] ?? [] as $airportId => $airport) {
        // Normalize to lowercase - webcam worker validates against lowercase config keys
        $airportId = strtolower($airportId);
        
        if (!isset($airport['webcams']) || !is_array($airport['webcams'])) {
            continue;
        }
        
        foreach ($airport['webcams'] as $camIndex => $cam) {
            $isPush = (isset($cam['type']) && $cam['type'] === 'push') 
                   || isset($cam['push_config']);
            
            if ($isPush && isset($cam['push_config'])) {
                $cameraKey = $airportId . '_' . $camIndex;
                $username = $cam['push_config']['username'] ?? null;
                
                $configured[] = [
                    'airport' => $airportId,
                    'cam' => $camIndex,
                    'username' => $username,
                    'key' => $cameraKey
                ];
                
                // Create directory structure (handles both FTP and SFTP)
                createCameraDirectory($airportId, $camIndex, $username);
                
                // Create both FTP and SFTP users
                if ($username && isset($cam['push_config']['password'])) {
                    if (syncCameraUser($airportId, $camIndex, $cam['push_config'], $newUsernameMapping)) {
                        repairPushUploadDirectoryPermissions(getWebcamFtpUploadDir($airportId, $username));
                        repairPushUploadDirectoryPermissions(getWebcamSftpUploadDir($username));
                    }
                }
            }
        }
    }
    
    // Build set of usernames still in config (handles camera reindexing)
    $configuredUsernames = [];
    foreach ($configured as $configCam) {
        if (!empty($configCam['username'])) {
            $configuredUsernames[$configCam['username']] = true;
        }
    }

    // Remove orphaned cameras
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

            foreach ($usernameMapping as $user => $info) {
                if (isset($info['camera']) && $info['camera'] === $cameraKey) {
                    $username = $user;
                    break;
                }
            }

            // Skip removal if username is still in config (camera was reindexed)
            if ($username && isset($configuredUsernames[$username])) {
                aviationwx_log('debug', 'sync-push-config: skipping orphan removal (username still in config)', [
                    'username' => $username,
                    'old_position' => $cameraKey
                ], 'app');
                continue;
            }

            aviationwx_log('info', 'removing orphaned camera', [
                'airport' => $existingCam['airport'],
                'cam' => $existingCam['cam'],
                'username' => $username
            ], 'app');

            // Remove both FTP and SFTP users
            if ($username) {
                removeCameraUser($username);  // Removes both by default
            }

            removeCameraDirectory($existingCam['airport'], $existingCam['cam'], $username);
        }
    }
    
    // Preserve existing mappings for configured cameras
    foreach ($usernameMapping as $user => $info) {
        $found = false;
        foreach ($configured as $configCam) {
            if ($info['camera'] === $configCam['key']) {
                $found = true;
                break;
            }
        }
        if ($found && !isset($newUsernameMapping[$user])) {
            // Migrate old single-protocol mapping to new format
            if (isset($info['protocol']) && !isset($info['protocols'])) {
                $info['protocols'] = ['ftp', 'sftp'];
                unset($info['protocol']);
            }
            $newUsernameMapping[$user] = $info;
        }
    }
    
    saveUsernameMapping($newUsernameMapping);
}

/**
 * Build sync plan for upload health probe accounts (production only).
 *
 * Probe users are isolated from push camera inboxes and must not enter the webcam pipeline.
 *
 * @return list<array{protocol:string,username:string,password:string,ftp_local_root?:string}>
 */
function getUploadHealthProbeSyncPlan(): array {
    if (!isProduction()) {
        return [];
    }

    $settings = getUploadHealthProbeSettings();
    if (!($settings['enabled'] ?? false)) {
        return [];
    }

    $plan = [];

    $append = static function (string $protocol, $creds) use (&$plan): void {
        if (!is_array($creds)) {
            return;
        }
        $username = $creds['username'] ?? '';
        $password = $creds['password'] ?? '';
        if (!is_string($username) || $username === '' || !is_string($password) || $password === '') {
            return;
        }
        $entry = [
            'protocol' => $protocol,
            'username' => $username,
            'password' => $password,
        ];
        if ($protocol === 'ftps') {
            $entry['ftp_local_root'] = getUploadHealthProbeFtpDir($username);
        }
        $plan[] = $entry;
    };

    $append('ftps', $settings['ftps'] ?? null);
    $append('sftp', $settings['sftp'] ?? null);

    return $plan;
}

/**
 * Provision FTPS probe account in an isolated FTP directory.
 *
 * @param string $username Probe username
 * @param string $password Probe password
 * @return bool True on success
 */
function syncUploadHealthProbeFtpUser(string $username, string $password): bool {
    ensureWebcamsBaseDirectory();

    return upsertFtpVirtualUser($username, $password, getUploadHealthProbeFtpDir($username), [
        'purpose' => 'upload_health_probe',
    ]);
}

/**
 * Provision SFTP probe account (chroot under /var/sftp, uploads in files/).
 *
 * @param string $username Probe username
 * @param string $password Probe password
 * @return bool True on success
 */
function syncUploadHealthProbeSftpUser(string $username, string $password): bool {
    if (!createSftpUser('_probe', -1, $username, $password)) {
        aviationwx_log('error', 'sync-push-config: upload health probe SFTP user sync failed', [
            'username' => $username,
        ], 'app');
        return false;
    }

    aviationwx_log('info', 'sync-push-config: upload health probe SFTP user synced', [
        'username' => $username,
        'purpose' => 'upload_health_probe',
    ], 'app');

    return true;
}

/**
 * Sync dedicated upload health probe users (FTPS and SFTP).
 *
 * Runs on container startup with push camera sync so probe accounts survive image recreates.
 *
 * @return bool True when all configured probe accounts synced successfully
 */
function syncUploadHealthProbeUsers(): bool {
    $plan = getUploadHealthProbeSyncPlan();
    if ($plan === []) {
        return true;
    }

    $allOk = true;

    foreach ($plan as $target) {
        $protocol = $target['protocol'] ?? '';
        $username = $target['username'] ?? '';
        $password = $target['password'] ?? '';

        $credentialErrors = validateSyncPushUploadCredentials(
            $username,
            $password,
            'config.upload_health_probe.' . $protocol
        );
        if ($credentialErrors !== []) {
            aviationwx_log('error', 'sync-push-config: invalid upload health probe credentials', [
                'protocol' => $protocol,
                'username' => $username,
                'errors' => $credentialErrors,
            ], 'app');
            $allOk = false;
            continue;
        }

        if ($protocol === 'ftps') {
            if (!syncUploadHealthProbeFtpUser($username, $password)) {
                $allOk = false;
            }
            continue;
        }

        if ($protocol === 'sftp' && !syncUploadHealthProbeSftpUser($username, $password)) {
            $allOk = false;
        }
    }

    if ($allOk) {
        aviationwx_log('info', 'sync-push-config: upload health probe users synced', [
            'accounts' => count($plan),
        ], 'app');
    }

    return $allOk;
}

/**
 * Main sync function
 */
function syncPushConfig() {
    $invocationId = aviationwx_get_invocation_id();
    $triggerInfo = aviationwx_detect_trigger_type();
    aviationwx_log('info', 'push-config sync started', [
        'invocation_id' => $invocationId,
        'trigger' => $triggerInfo['trigger'],
        'context' => $triggerInfo['context'],
    ], 'app');

    if (!checkRootPermissions()) {
        aviationwx_log('error', 'sync-push-config: exiting due to insufficient permissions', [], 'app');
        exit(1);
    }

    if (!repairAllSftpChrootPermissions()) {
        aviationwx_log('error', 'sync-push-config: exiting because SFTP chroot repair failed', [], 'app');
        exit(1);
    }

    if (!repairAllFtpUploadPermissions()) {
        aviationwx_log('error', 'sync-push-config: exiting because FTP inbox repair failed', [], 'app');
        exit(1);
    }
    
    $configFile = getConfigFilePath();
    
    if (!file_exists($configFile)) {
        aviationwx_log('error', 'config file not found', ['path' => $configFile], 'app');
        exit(1);
    }
    
    $configMtime = filemtime($configFile);
    $lastSync = getLastSyncTimestamp();
    
    $authCorrupted = isProftpdAuthFileCorrupted();
    $authMissing = isProftpdAuthFileMissing();

    if ($authCorrupted && !$authMissing) {
        aviationwx_log('warning', 'sync-push-config: ProFTPD auth file appears invalid, forcing full sync', [
            'auth_file' => PROFTPD_AUTH_FILE,
        ], 'app');
    } else {
        if ($configMtime <= $lastSync && !$authMissing) {
            aviationwx_log('debug', 'sync-push-config: config unchanged since last sync, skipping', [
                'last_sync' => $lastSync,
                'config_mtime' => $configMtime,
            ], 'app');
            if (!syncUploadHealthProbeUsers()) {
                aviationwx_log('warning', 'sync-push-config: upload health probe user sync incomplete', [], 'app');
            }
            return;
        }

        if ($authMissing) {
            aviationwx_log('info', 'sync-push-config: ProFTPD auth file missing, forcing sync', [], 'app');
        }
    }
    
    $validation = validateConfigBeforeApply($configFile);
    if (!$validation['valid']) {
        aviationwx_log('error', 'config validation failed, skipping sync', [
            'error' => $validation['error']
        ], 'app');
        exit(1);
    }
    
    $backupFile = backupConfigFile($configFile);
    aviationwx_log('info', 'config backed up', ['backup_file' => $backupFile], 'app');
    
    $config = loadConfig(false);
    if (!$config) {
        aviationwx_log('error', 'config load failed', [], 'app');
        exit(1);
    }
    
    syncAllPushCameras($config);
    if (!syncUploadHealthProbeUsers()) {
        aviationwx_log('warning', 'sync-push-config: upload health probe user sync incomplete', [], 'app');
    }
    updateLastSyncTimestamp();

    if (!reloadProftpdDaemon()) {
        aviationwx_log('debug', 'sync-push-config: ProFTPD reload skipped (daemon not running yet)', [], 'app');
    } else {
        aviationwx_log('info', 'sync-push-config: ProFTPD reloaded after auth sync', [], 'app');
    }

    aviationwx_log('info', 'push-config sync completed', [], 'app');
}

if (php_sapi_name() === 'cli') {
    $scriptName = $_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
    if (basename($scriptName) === basename(__FILE__) || $scriptName === __FILE__) {
        syncPushConfig();
    }
}

