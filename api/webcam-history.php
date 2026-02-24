<?php
/**
 * Webcam History API
 * 
 * Returns manifest of available historical frames for a camera,
 * or serves individual frames when timestamp is specified.
 * 
 * Storage: History frames are stored directly in the camera cache directory
 * (unified storage with current images). Retention is controlled by
 * webcam_history_max_frames config.
 * 
 * History Behavior:
 * - max_frames < 2: History disabled, returns enabled=false
 * - max_frames >= 2 but frames < 2: History enabled but not yet available
 * - max_frames >= 2 and frames >= 2: History fully available
 * 
 * Endpoints:
 *   GET /api/webcam-history.php?id={airport}&cam={index}
 *     Returns JSON manifest of available frames with format availability
 * 
 *   GET /api/webcam-history.php?id={airport}&cam={index}&ts={timestamp}&fmt={format}
 *     Returns the image for that timestamp in requested format
 *     fmt: 'jpg' (default) or 'webp'
 *     Falls back to JPG if requested format not available
 * 
 * Response (manifest):
 * {
 *   "enabled": true,
 *   "available": true,
 *   "airport": "kspb",
 *   "cam": 0,
 *   "frames": [
 *     { 
 *       "timestamp": 1735084200, 
 *       "url": "/api/webcam-history.php?id=kspb&cam=0&ts=1735084200",
 *       "formats": ["jpg", "webp"]
 *     },
 *     ...
 *   ],
 *   "current_index": 11,
 *   "timezone": "America/Los_Angeles"
 * }
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/webcam-history.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/webcam-metadata.php';
require_once __DIR__ . '/../lib/metrics.php';

// Supported image formats with MIME types
$supportedFormats = [
    'jpg' => 'image/jpeg',
    'webp' => 'image/webp'
];

// Get parameters
$rawIdentifier = isset($_GET['id']) ? trim($_GET['id']) : '';
$camIndex = isset($_GET['cam']) ? intval($_GET['cam']) : 0;
$timestamp = isset($_GET['ts']) ? intval($_GET['ts']) : null;
$requestedFormat = isset($_GET['fmt']) ? strtolower(trim($_GET['fmt'])) : 'jpg';
$requestedSize = isset($_GET['size']) ? trim($_GET['size']) : 'original';

// Validate airport ID parameter exists
if (empty($rawIdentifier)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'Missing airport id parameter']);
    exit;
}

// Find airport by any identifier type (ICAO, IATA, FAA, or airport ID)
// This handles case-insensitive lookups properly
$result = findAirportByIdentifier($rawIdentifier);
if ($result === null || !isset($result['airport']) || !isset($result['airportId'])) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'Airport not found']);
    exit;
}

$airport = $result['airport'];
$airportId = $result['airportId'];

// Load config for later use (history settings, etc.)
$config = loadConfig();

// Validate camera index exists
if (!isset($airport['webcams']) || !isset($airport['webcams'][$camIndex])) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'Camera not found']);
    exit;
}

// If timestamp provided, serve the actual image
if ($timestamp !== null) {
    // History images are stored in the camera cache directory (unified storage)
    $cacheDir = getWebcamCameraDir($airportId, $camIndex);
    
    // Validate requested format
    if (!isset($supportedFormats[$requestedFormat])) {
        $requestedFormat = 'jpg';
    }
    
    $size = $requestedSize;
    if ($size !== 'original' && is_numeric($size)) {
        $size = (int)$size;
        if ($size < 1 || $size > 5000) {
            $size = 'original';
        }
    } elseif ($size !== 'original') {
        $size = 'original';
    }
    
    require_once __DIR__ . '/../lib/webcam-metadata.php';
    $imageFile = getImagePathForSize($airportId, $camIndex, $timestamp, $size, $requestedFormat);
    $servedFormat = $requestedFormat;
    $servedSize = $size;
    
    if ($imageFile === null) {
        $enabledFormats = getEnabledWebcamFormats();
        foreach ($enabledFormats as $format) {
            if ($format === $requestedFormat) {
                continue;
            }
            $imageFile = getImagePathForSize($airportId, $camIndex, $timestamp, $size, $format);
            if ($imageFile !== null) {
                $servedFormat = $format;
                break;
            }
        }
    }
    
    if ($imageFile === null || !file_exists($imageFile)) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode([
            'error' => 'Frame not found',
            'timestamp' => $timestamp,
            'requested_size' => $size,
            'requested_format' => $requestedFormat
        ]);
        exit;
    }
    
    // Security check: ensure file is within cache directory
    $realPath = realpath($imageFile);
    $realCacheDir = realpath($cacheDir);
    if ($realPath === false || $realCacheDir === false || strpos($realPath, $realCacheDir) !== 0) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    // Track webcam serve (history player traffic)
    metrics_track_webcam_request($airportId, $camIndex);
    $sizeForMetrics = ($servedSize === 'original' || $servedSize === '') ? 'original' : (string)(int)$servedSize;
    metrics_track_webcam_serve($airportId, $camIndex, $servedFormat, $sizeForMetrics);

    // Build filename matching server naming convention: {timestamp}_{variant}.{ext}
    $variant = ($servedSize === 'original') ? 'original' : (int)$servedSize;
    $filename = $timestamp . '_' . $variant . '.' . $servedFormat;
    
    // Cache images for the retention period (when they'll be deleted anyway)
    // This prevents accumulating unused images in browser/CDN caches
    // Minimum 24 hours (86400s), maximum 30 days (2592000s)
    $retentionHours = getWebcamHistoryRetentionHours($airportId);
    $retentionSeconds = $retentionHours * 3600;
    $cacheMaxAge = max(86400, min(2592000, $retentionSeconds));
    
    require_once __DIR__ . '/../lib/http-integrity.php';
    $mtime = filemtime($imageFile);
    if (addIntegrityHeadersForFile($imageFile, $mtime)) {
        exit;
    }
    header('Content-Type: ' . $supportedFormats[$servedFormat]);
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header("Cache-Control: public, max-age={$cacheMaxAge}, immutable");
    header('Content-Length: ' . filesize($imageFile));
    header('X-Content-Type-Options: nosniff');
    
    if ($servedFormat !== $requestedFormat) {
        header('X-Served-Format: ' . $servedFormat);
    }
    
    readfile($imageFile);
    exit;
}

// Return JSON manifest
header('Content-Type: application/json');

// Get history status (enabled, available, frame count)
$historyStatus = getHistoryStatus($airportId, $camIndex);

// History disabled by config (max_frames < 2)
if (!$historyStatus['enabled']) {
    // Config-based response - cache for longer (1 hour) since it rarely changes
    header('Cache-Control: public, max-age=3600, s-maxage=3600, stale-while-revalidate=3600');
    echo json_encode([
        'enabled' => false,
        'available' => false,
        'airport' => $airportId,
        'cam' => $camIndex,
        'frames' => [],
        'max_frames' => $historyStatus['max_frames'],
        'message' => 'Webcam history not configured for this airport'
    ]);
    exit;
}

// Get available frames (includes format availability per frame)
$frames = getHistoryFrames($airportId, $camIndex);
$baseUrl = '/api/webcam-history.php?id=' . urlencode($airportId) . '&cam=' . $camIndex;

$frameList = [];
foreach ($frames as $frame) {
    $frameList[] = [
        'timestamp' => $frame['timestamp'],
        'url' => $baseUrl . '&ts=' . $frame['timestamp'],
        'formats' => $frame['formats'] ?? ['jpg'],
        'variants' => $frame['variants'] ?? [] // Variant manifest: {variant: [formats]}
    ];
}

// Calculate refresh interval for this camera (per-cam, airport-level, or global default)
$defaultWebcamRefresh = getDefaultWebcamRefresh();
$airportWebcamRefresh = isset($airport['webcam_refresh_seconds']) 
    ? intval($airport['webcam_refresh_seconds']) 
    : $defaultWebcamRefresh;
$cam = $airport['webcams'][$camIndex];
$perCamRefresh = isset($cam['refresh_seconds']) 
    ? intval($cam['refresh_seconds']) 
    : $airportWebcamRefresh;
$refreshInterval = max(60, $perCamRefresh); // Enforce minimum 60 seconds

// Set cache headers based on refresh interval
// Frame data changes every refresh_interval, so cache for that duration
// - max-age: browser cache duration
// - s-maxage: CDN/proxy cache duration  
// - stale-while-revalidate: serve stale content while fetching fresh in background
header("Cache-Control: public, max-age={$refreshInterval}, s-maxage={$refreshInterval}, stale-while-revalidate={$refreshInterval}");

// Get variant heights from config (for reference)
require_once __DIR__ . '/../lib/webcam-metadata.php';
$variantHeights = getVariantHeights($airportId, $camIndex);

// Get history UI configuration
$historyUIConfig = getWebcamHistoryUIConfig($airportId);

// Build response with status information
$response = [
    'enabled' => true,
    'available' => $historyStatus['available'],
    'airport' => $airportId,
    'cam' => $camIndex,
    'frames' => $frameList,
    'frame_count' => count($frameList),
    'current_index' => count($frameList) > 0 ? count($frameList) - 1 : 0,
    'timezone' => $airport['timezone'] ?? 'UTC',
    'max_frames' => $historyStatus['max_frames'],
    'enabledFormats' => getEnabledWebcamFormats(),
    'variantHeights' => $variantHeights,
    'refresh_interval' => $refreshInterval,
    'history_ui' => $historyUIConfig
];

// Add message if history enabled but not yet available (insufficient frames)
if (!$historyStatus['available']) {
    $response['message'] = 'History not available for this camera, come back later.';
}

echo json_encode($response);

