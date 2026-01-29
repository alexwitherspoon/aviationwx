<?php
/**
 * NOTAM API Endpoint
 * 
 * Serves NOTAM data to frontend with stale-while-revalidate caching.
 * Safety-critical: Re-validates NOTAM expiration at serve time and
 * implements failclosed when cache exceeds staleness threshold.
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

// Determine staleness tier
$isWarning = $cacheAge > $notamWarningThreshold;
$isError = $cacheAge > $notamErrorThreshold;
$isFailclosed = $cacheAge > $notamFailclosedThreshold;

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
if ($isWarning && $cacheAge > $cacheTtl) {
    $refreshing = true;
    aviationwx_log('info', 'notam api: cache stale, refresh needed', [
        'airport' => $airportId,
        'cache_age' => $cacheAge,
        'tier' => $isFailclosed ? 'failclosed' : ($isError ? 'error' : 'warning')
    ], 'app');
}

// Failclosed: Don't show stale NOTAM data after threshold (safety-critical)
// Better to show no NOTAMs than potentially outdated restriction info
if ($isFailclosed) {
    aviationwx_log('warning', 'notam api: failclosed - cache too old', [
        'airport' => $airportId,
        'cache_age' => $cacheAge,
        'threshold' => $notamFailclosedThreshold
    ], 'app');
    
    echo json_encode([
        'status' => 'success',
        'airport' => $airportId,
        'notams' => [],
        'cache_age' => $cacheAge,
        'refreshing' => $refreshing,
        'failclosed' => true,
        'failclosed_reason' => 'NOTAM data is too old and cannot be trusted'
    ]);
    ob_end_flush();
    exit;
}

// Use cached data if available, otherwise return empty
$notams = $cachedData['notams'] ?? [];

// Format NOTAMs for frontend (add local times, official links)
// Re-validate status at serve time to catch NOTAMs that expired since caching
$formattedNotams = [];
$timezone = getAirportTimezone($airportId, $airport);

foreach ($notams as $notam) {
    // Re-validate status at serve time using airport timezone (safety-critical)
    $currentStatus = revalidateNotamStatus($notam, $timezone);
    
    // Filter out expired and future NOTAMs - only show active and upcoming_today
    if ($currentStatus !== 'active' && $currentStatus !== 'upcoming_today') {
        continue;
    }
    
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
            $startTimeLocal = $startTimeUtc;
        }
    }
    
    if ($endTimeUtc !== null && !empty($endTimeUtc)) {
        try {
            $dt = new DateTime($endTimeUtc, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($timezone));
            $endTimeLocal = $dt->format('Y-m-d H:i:s T');
        } catch (Exception $e) {
            $endTimeLocal = $endTimeUtc;
        }
    }
    
    // Build official NOTAM link
    $officialLink = '';
    $notamId = $notam['id'] ?? '';
    if (!empty($notamId)) {
        $officialLink = 'https://notams.aim.faa.gov/notamSearch/search?notamNumber=' . urlencode($notamId);
    }
    
    $formattedNotams[] = [
        'id' => $notamId,
        'type' => $notam['notam_type'] ?? 'unknown',
        'status' => $currentStatus,
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


