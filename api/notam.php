<?php
/**
 * NOTAM API Endpoint
 * Serves NOTAM data to frontend with stale-while-revalidate caching
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/weather/utils.php';
require_once __DIR__ . '/../lib/notam/fetcher.php';
require_once __DIR__ . '/../lib/notam/filter.php';

// Start output buffering
ob_start();

// Set JSON header
header('Content-Type: application/json');

// Get airport ID
$airportId = isset($_GET['airport']) ? trim($_GET['airport']) : '';

if (empty($airportId) || !validateAirportId($airportId)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'error' => 'Invalid airport ID'
    ]);
    ob_end_flush();
    exit;
}

// Load config
$config = loadConfig();
if ($config === null || !isset($config['airports'][$airportId])) {
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'error' => 'Airport not found'
    ]);
    ob_end_flush();
    exit;
}

$airport = $config['airports'][$airportId];

// Check cache
$cacheFile = __DIR__ . '/../cache/notam/' . $airportId . '.json';
$cacheExists = file_exists($cacheFile);
$cacheAge = $cacheExists ? (time() - filemtime($cacheFile)) : PHP_INT_MAX;
$cacheTtl = getNotamCacheTtlSeconds();

// 3-tier staleness model for NOTAMs
$notamWarningThreshold = getNotamStaleWarningSeconds();
$notamErrorThreshold = getNotamStaleErrorSeconds();
$notamFailclosedThreshold = getNotamStaleFailclosedSeconds();

// Trigger refresh when data reaches warning tier
$isStale = $cacheAge > $notamWarningThreshold;

// Load cached data if available
$cachedData = null;
if ($cacheExists) {
    $cacheContent = @file_get_contents($cacheFile);
    if ($cacheContent !== false) {
        $cachedData = json_decode($cacheContent, true);
    }
}

// If cache is stale, trigger background refresh (non-blocking)
$refreshing = false;
if ($isStale && $cacheAge > $cacheTtl) {
    // Trigger background refresh (don't wait for it)
    $refreshing = true;
    
    // Use process pool to refresh in background
    // For now, we'll just log that refresh is needed
    // The scheduler will handle it automatically
    aviationwx_log('info', 'notam api: cache stale, refresh needed', [
        'airport' => $airportId,
        'cache_age' => $cacheAge
    ], 'app');
}

// Use cached data if available, otherwise return empty
$notams = $cachedData['notams'] ?? [];
$fetchedAt = $cachedData['fetched_at'] ?? 0;

// Format NOTAMs for frontend (add local times, official links)
$formattedNotams = [];
foreach ($notams as $notam) {
    // Get airport timezone
    $timezone = getAirportTimezone($airportId, $airport);
    
    // Format times
    $startTimeUtc = $notam['start_time_utc'] ?? '';
    $endTimeUtc = $notam['end_time_utc'] ?? null;
    
    $startTimeLocal = '';
    $endTimeLocal = '';
    
    if (!empty($startTimeUtc)) {
        try {
            $dt = new DateTime($startTimeUtc, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($timezone));
            $startTimeLocal = $dt->format('Y-m-d H:i:s T');
        } catch (Exception $e) {
            // Fallback
            $startTimeLocal = $startTimeUtc;
        }
    }
    
    if ($endTimeUtc !== null && !empty($endTimeUtc)) {
        try {
            $dt = new DateTime($endTimeUtc, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($timezone));
            $endTimeLocal = $dt->format('Y-m-d H:i:s T');
        } catch (Exception $e) {
            // Fallback
            $endTimeLocal = $endTimeUtc;
        }
    }
    
    // Build official NOTAM link
    $officialLink = '';
    $notamId = $notam['id'] ?? '';
    if (!empty($notamId)) {
        // Format: https://notams.aim.faa.gov/notamSearch/search?notamNumber={series}{number}/{year}
        $officialLink = 'https://notams.aim.faa.gov/notamSearch/search?notamNumber=' . urlencode($notamId);
    }
    
    $formattedNotams[] = [
        'id' => $notamId,
        'type' => $notam['notam_type'] ?? 'unknown',
        'status' => $notam['status'] ?? 'unknown',
        'start_time_utc' => $startTimeUtc,
        'end_time_utc' => $endTimeUtc,
        'start_time_local' => $startTimeLocal,
        'end_time_local' => $endTimeLocal,
        'message' => $notam['text'] ?? '',
        'official_link' => $officialLink
    ];
}

// Response
$response = [
    'status' => 'success',
    'airport' => $airportId,
    'notams' => $formattedNotams,
    'cache_age' => $cacheAge,
    'refreshing' => $refreshing
];

echo json_encode($response);
ob_end_flush();


