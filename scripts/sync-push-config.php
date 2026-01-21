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
require_once __DIR__ . '/../lib/cache-paths.php';

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
 * Check if vsftpd database is corrupted or invalid
 * 
 * Performs multiple checks to detect vsftpd Berkeley DB corruption:
 * - Missing database with non-empty users file
 * - Zero-size database file
 * - Invalid magic bytes
 * - db_verify and db_dump validation
 * - Size heuristics
 * 
 * @return bool True if database appears corrupted, false otherwise
 */
function isVsftpdDatabaseCorrupted() {
    $vsftpdDbFile = '/etc/vsftpd/virtual_users.db';
    $vsftpdUserFile = '/etc/vsftpd/virtual_users.txt';
    
    // Check if users file exists and has content
    $usersFileExists = file_exists($vsftpdUserFile);
    $usersFileHasContent = false;
    if ($usersFileExists) {
        $content = @file_get_contents($vsftpdUserFile);
        $usersFileHasContent = ($content && trim($content) !== '');
    }
    
    // If no users are configured, there's no corruption - just a clean/initial state
    // Delete any stale database from a previous run and return false
    if (!$usersFileHasContent) {
        if (file_exists($vsftpdDbFile)) {
            aviationwx_log('debug', 'sync-push-config: removing stale vsftpd database (no users configured)', [
                'db_file' => $vsftpdDbFile
            ], 'app');
            @unlink($vsftpdDbFile);
        }
        return false;
    }
    
    // Missing database with non-empty users file indicates corruption
    if (!file_exists($vsftpdDbFile)) {
        return true;
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
 * 
 * Validates JSON syntax and basic structure of configuration file before
 * applying changes. Prevents applying invalid configurations that could
 * break the system.
 * 
 * @param string $configFile Path to config file to validate
 * @return array {
 *   'valid' => bool,    // True if config is valid
 *   'error' => string   // Error message if invalid (optional)
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
 * Create upload directory for camera with chroot structure
 * 
 * Creates airport-scoped upload directory with SFTP chroot support:
 *   /uploads/{airport}/{username}/       ← chroot (root:root 755)
 *   /uploads/{airport}/{username}/files/ ← upload dir (ftp:www-data 2775)
 * 
 * - FTP: vsftpd local_root points to files/, users upload to /
 * - SFTP: Users chroot to parent, upload to /files/
 * - Setgid ensures uploaded files inherit www-data group for processor access
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string|null $username Username (required)
 * @return string|null Path to upload directory (files/), or null on error
 */
function createCameraDirectory($airportId, $camIndex, $username = null) {
    $webcamsBaseDir = ensureWebcamsBaseDirectory();
    // Normalize to lowercase - must match getWebcamUploadDir() path generation
    $airportId = strtolower($airportId);
    
    if (!$username) {
        aviationwx_log('warning', 'createCameraDirectory: no username provided', [
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
    
    // Airport directory: /uploads/{airport}/
    $airportDir = $webcamsBaseDir . '/' . $airportId;
    if (!is_dir($airportDir)) {
        @mkdir($airportDir, 0755, true);
    }
    
    // Chroot directory: /uploads/{airport}/{username}/
    // Must be root-owned for SFTP chroot security
    $chrootDir = $airportDir . '/' . $username;
    if (!is_dir($chrootDir)) {
        @mkdir($chrootDir, 0755, true);
    }
    @chown($chrootDir, 0);  // root
    @chgrp($chrootDir, 0);  // root
    @chmod($chrootDir, 0755);
    
    // Upload directory: /uploads/{airport}/{username}/files/
    // ftp:www-data with setgid so both FTP and SFTP users can write
    $filesDir = $chrootDir . '/files';
    if (!is_dir($filesDir)) {
        @mkdir($filesDir, 02775, true);
    }
    @chown($filesDir, $ftpUid);
    @chgrp($filesDir, $wwwDataGid);
    @chmod($filesDir, 02775);
    
    aviationwx_log('debug', 'createCameraDirectory: directory structure created', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'chroot' => $chrootDir,
        'upload_dir' => $filesDir
    ], 'app');
    
    return $filesDir;
}

/**
 * Remove camera directory
 * 
 * Recursively removes upload directory for a camera when it's no longer configured.
 * Handles current chroot structure and legacy directory structures.
 * 
 * Current structure:
 *   /uploads/{airport}/{username}/       ← chroot (removed)
 *   /uploads/{airport}/{username}/files/ ← upload dir (removed)
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string|null $username Username for the camera
 * @return void
 */
function removeCameraDirectory($airportId, $camIndex, $username = null) {
    // Normalize to lowercase - must match directory paths created by createCameraDirectory()
    $airportId = strtolower($airportId);
    $baseDir = CACHE_UPLOADS_DIR . '/';
    
    // Remove all possible directory locations (current + legacy)
    $dirsToRemove = [
        $baseDir . $airportId . '_' . $camIndex,  // Legacy: airportId_camIndex
    ];
    if ($username) {
        $dirsToRemove[] = $baseDir . $username;                               // Legacy: username only
        $dirsToRemove[] = $baseDir . $airportId . '/' . $username . '/files'; // Current: files subdir
        $dirsToRemove[] = $baseDir . $airportId . '/' . $username;            // Current: chroot dir
    }
    
    foreach ($dirsToRemove as $uploadDir) {
        if (!is_dir($uploadDir)) {
            continue;
        }
        removeDirectoryRecursive($uploadDir, $airportId, $camIndex);
    }
    
    // Clean up empty airport directory if it exists
    if ($username) {
        $airportDir = $baseDir . $airportId;
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
 * Validate username format (14 characters or less, alphanumeric, no spaces)
 * 
 * Validates that a username matches the required format for push webcam accounts.
 * 
 * @param string $username Username to validate
 * @return bool True if username is valid, false otherwise
 */
function validateUsername($username) {
    if (strlen($username) > 14) {
        return false;
    }
    if (preg_match('/\s/', $username)) {
        return false;
    }
    return preg_match('/^[a-zA-Z0-9]+$/', $username) === 1;
}

/**
 * Validate password format (14 alphanumeric characters)
 * 
 * Validates that a password matches the required format for push webcam accounts.
 * 
 * @param string $password Password to validate
 * @return bool True if password is valid, false otherwise
 */
function validatePassword($password) {
    if (strlen($password) !== 14) {
        return false;
    }
    return preg_match('/^[a-zA-Z0-9]{14}$/', $password) === 1;
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
 * Directory structure:
 *   {chroot}/       ← root:root 755 (SFTP chroot)
 *   {chroot}/files/ ← ftp:www-data 2775 (upload directory)
 * 
 * SFTP users are chrooted and must upload to /files/
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $username Username (up to 14 alphanumeric characters)
 * @param string $password Password (14 alphanumeric characters)
 * @return bool True on success, false on failure
 */
function createSftpUser($airportId, $camIndex, $username, $password) {
    // Get chroot directory (parent of files/)
    $chrootDir = getWebcamChrootDir($airportId, $username);
    
    // Ensure parent directories exist
    $airportDir = dirname($chrootDir);
    if (!is_dir($airportDir)) {
        @mkdir($airportDir, 0755, true);
    }
    
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
        'cam' => $camIndex,
        'chroot' => $chrootDir
    ], 'app');
    
    return true;
}

/**
 * Rebuild vsftpd database from users file
 * 
 * Rebuilds the vsftpd Berkeley DB database from the virtual_users.txt file.
 * Called when database corruption is detected. Verifies database after rebuild.
 * 
 * @return bool True on success, false on failure
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
        // Empty users file is normal during startup before sync completes
        aviationwx_log('debug', 'sync-push-config: No users to rebuild database from (users file empty)', [
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
 * 
 * Creates a new vsftpd virtual user account for FTP/FTPS access.
 * Updates virtual_users.txt, rebuilds database, and creates user config.
 * 
 * vsftpd local_root points to files/ so FTP users land directly in the
 * writable upload directory (same location SFTP users upload to via /files/).
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $username Username (up to 14 alphanumeric characters)
 * @param string $password Password (14 alphanumeric characters)
 * @return bool True on success, false on failure
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
    
    // Get directories
    $chrootDir = getWebcamChrootDir($airportId, $username);
    $filesDir = getWebcamUploadDir($airportId, $username);  // Returns {chroot}/files
    
    // Get user/group info
    $ftpInfo = @posix_getpwnam('ftp');
    $ftpUid = $ftpInfo ? $ftpInfo['uid'] : 101;
    $wwwDataInfo = @posix_getpwnam('www-data');
    $wwwDataGid = $wwwDataInfo ? $wwwDataInfo['gid'] : 33;
    
    // Ensure directory structure exists
    ensureWebcamsBaseDirectory();
    
    // Chroot directory (root-owned)
    if (!is_dir($chrootDir)) {
        @mkdir($chrootDir, 0755, true);
    }
    @chown($chrootDir, 0);
    @chgrp($chrootDir, 0);
    @chmod($chrootDir, 0755);
    
    // Files directory (ftp:www-data with setgid)
    if (!is_dir($filesDir)) {
        @mkdir($filesDir, 02775, true);
    }
    @chown($filesDir, $ftpUid);
    @chgrp($filesDir, $wwwDataGid);
    @chmod($filesDir, 02775);
    
    // Create per-user vsftpd config - local_root points to files/
    $userConfigDir = '/etc/vsftpd/users';
    if (!is_dir($userConfigDir)) {
        @mkdir($userConfigDir, 0755, true);
    }
    $userConfigFile = $userConfigDir . '/' . $username;
    $userConfig = "local_root={$filesDir}\n";
    
    if (@file_put_contents($userConfigFile, $userConfig) === false) {
        aviationwx_log('error', 'sync-push-config: Cannot write user config file', [
            'file' => $userConfigFile
        ], 'app');
        return false;
    }
    
    aviationwx_log('info', 'sync-push-config: FTP user created/updated', [
        'username' => $username,
        'airport' => $airportId,
        'cam' => $camIndex,
        'local_root' => $filesDir
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
 * Sync camera user credentials and create system accounts
 * 
 * Creates or updates BOTH FTP and SFTP user accounts for unified access.
 * Both protocols share the same username/password and upload to the same directory.
 * 
 * The 'protocol' field in push_config is now optional/ignored - both protocols
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
    
    if (!validateUsername($username)) {
        aviationwx_log('error', 'sync-push-config: invalid username format', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'username' => $username,
            'expected' => '14 characters or less, alphanumeric, no spaces'
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
                        // Verify files/ directory permissions
                        $filesDir = getWebcamUploadDir($airportId, $username);
                        if (is_dir($filesDir)) {
                            $ftpInfo = @posix_getpwnam('ftp');
                            $wwwDataInfo = @posix_getpwnam('www-data');
                            if ($ftpInfo !== false && $wwwDataInfo !== false) {
                                $verification = verifyDirectoryPermissions(
                                    $filesDir,
                                    $ftpInfo['uid'],
                                    'www-data',
                                    02775
                                );
                                if (!$verification['success']) {
                                    @chown($filesDir, $ftpInfo['uid']);
                                    @chgrp($filesDir, $wwwDataInfo['gid']);
                                    @chmod($filesDir, 02775);
                                }
                            }
                        }
                    }
                }
            }
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
                if ($info['camera'] === $cameraKey) {
                    $username = $user;
                    break;
                }
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
    $databaseMissing = !file_exists('/etc/vsftpd/virtual_users.db');
    
    if ($databaseCorrupted) {
        aviationwx_log('warning', 'sync-push-config: vsftpd database appears corrupted, attempting rebuild', [
            'db_file' => '/etc/vsftpd/virtual_users.db',
            'user_file' => '/etc/vsftpd/virtual_users.txt'
        ], 'app');
        
        if (rebuildVsftpdDatabase()) {
            aviationwx_log('info', 'sync-push-config: Database rebuilt successfully, continuing with sync', [], 'app');
        } else {
            aviationwx_log('warning', 'sync-push-config: Database rebuild failed, will rebuild during full sync', [], 'app');
        }
    } else {
        // Skip sync if config hasn't changed AND database exists
        // Force sync if database is missing (first run or after deletion)
        if ($configMtime <= $lastSync && !$databaseMissing) {
            aviationwx_log('debug', 'sync-push-config: config unchanged since last sync, skipping', [
                'last_sync' => $lastSync,
                'config_mtime' => $configMtime
            ], 'app');
            return;
        }
        
        if ($databaseMissing) {
            aviationwx_log('info', 'sync-push-config: database missing, forcing sync', [], 'app');
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

