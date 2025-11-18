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
 * Sync all push cameras
 */
function syncAllPushCameras($config) {
    $existing = getExistingPushCameras();
    $configured = [];
    
    // Build list of configured cameras
    foreach ($config['airports'] ?? [] as $airportId => $airport) {
        if (!isset($airport['webcams']) || !is_array($airport['webcams'])) {
            continue;
        }
        
        foreach ($airport['webcams'] as $camIndex => $cam) {
            $isPush = (isset($cam['type']) && $cam['type'] === 'push') 
                   || isset($cam['push_config']);
            
            if ($isPush) {
                $configured[] = [
                    'airport' => $airportId,
                    'cam' => $camIndex
                ];
                
                // Create directory
                createCameraDirectory($airportId, $camIndex);
                
                // TODO: Create/update FTP/SFTP user (will be implemented in next commit)
            }
        }
    }
    
    // Remove orphaned directories
    foreach ($existing as $existingCam) {
        $found = false;
        foreach ($configured as $configCam) {
            if ($existingCam['airport'] === $configCam['airport'] && 
                $existingCam['cam'] === $configCam['cam']) {
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            aviationwx_log('info', 'removing orphaned camera directory', [
                'airport' => $existingCam['airport'],
                'cam' => $existingCam['cam']
            ], 'app');
            
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

// Run sync
syncPushConfig();

