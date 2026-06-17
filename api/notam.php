<?php
/**
 * NOTAM API Endpoint
 * 
 * Serves NOTAM data to frontend with stale-while-revalidate caching.
 * Safety-critical: Re-validates NOTAM expiration at serve time and
 * implements failclosed when cache exceeds staleness threshold.
 * JSON NOTAM entries may include schedule_source, effective_segments, current/next restriction window times,
 * and banner_headline / banner_schedule_line (visual cues; full message text remains authoritative).
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/weather/utils.php';
require_once __DIR__ . '/../lib/notam/fetcher.php';
require_once __DIR__ . '/../lib/notam/filter.php';
require_once __DIR__ . '/../lib/notam/schedule.php';
require_once __DIR__ . '/../lib/notam/cache.php';
require_once __DIR__ . '/../lib/notam/banner.php';

// Start output buffering
ob_start();

// Set JSON header
header('Content-Type: application/json');

/**
 * Send cache headers for successful NOTAM responses
 *
 * Browsers and the CDN may share a response for NOTAM_API_CACHE_TTL_SECONDS,
 * with stale-while-revalidate letting the edge refresh in the background.
 * The window is small against the hourly upstream NMS refresh and the
 * 3 minute dashboard poll, so a new restriction is never delayed
 * meaningfully by the cache. Error responses carry no cache headers and
 * stay uncached at the edge.
 *
 * @return void
 */
function notamApiSendCacheHeaders(): void
{
    header(
        'Cache-Control: public'
        . ', max-age=' . NOTAM_API_CACHE_TTL_SECONDS
        . ', s-maxage=' . NOTAM_API_CACHE_TTL_SECONDS
        . ', stale-while-revalidate=' . NOTAM_API_CACHE_SWR_SECONDS
    );
}

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
$cacheFile = notamCacheFilePath($airportId);
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
    
    notamApiSendCacheHeaders();
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

// Re-validate and collect banner-eligible rows before selection
$bannerCandidates = [];
$timezone = getAirportTimezone($airportId, $airport);
$nowUnix = time();

foreach ($notams as $notam) {
    if (!is_array($notam)) {
        continue;
    }
    notamEnsureEffectiveSegments($notam);
    $currentStatus = revalidateNotamStatus($notam, $timezone);
    if (!notamIsBannerRelevantStatus($currentStatus, $notam)) {
        continue;
    }
    $notam['status'] = $currentStatus;
    $bannerCandidates[] = $notam;
}

$selectedForBanner = notamPrepareDashboardBannerRows($bannerCandidates, $airport, $timezone, $nowUnix);

// Format selected NOTAMs for frontend (add local times, official links, banner headlines)
$formattedNotams = [];

foreach ($selectedForBanner as $notam) {
    $currentStatus = (string) ($notam['status'] ?? revalidateNotamStatus($notam, $timezone));
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
    
    $effectiveSegmentsOut = [];
    foreach ($notam['effective_segments'] ?? [] as $seg) {
        $su = $seg['start_time_utc'] ?? '';
        $eu = $seg['end_time_utc'] ?? '';
        $sl = '';
        $el = '';
        if ($su !== '') {
            try {
                $dt = new DateTime($su, new DateTimeZone('UTC'));
                $dt->setTimezone(new DateTimeZone($timezone));
                $sl = $dt->format('Y-m-d H:i:s T');
            } catch (Exception $e) {
                $sl = $su;
            }
        }
        if ($eu !== '') {
            try {
                $dt = new DateTime($eu, new DateTimeZone('UTC'));
                $dt->setTimezone(new DateTimeZone($timezone));
                $el = $dt->format('Y-m-d H:i:s T');
            } catch (Exception $e) {
                $el = $eu;
            }
        }
        $effectiveSegmentsOut[] = [
            'start_time_utc' => $su,
            'end_time_utc' => $eu,
            'start_time_local' => $sl,
            'end_time_local' => $el,
        ];
    }
    
    $currentWindowEndUtc = notamCurrentRestrictionEndUtc($notam, $nowUnix);
    $nextWindowStartUtc = notamNextRestrictionStartUtc($notam, $nowUnix);
    $currentWindowEndLocal = '';
    $nextWindowStartLocal = '';
    if ($currentWindowEndUtc !== null && $currentWindowEndUtc !== '') {
        try {
            $dt = new DateTime($currentWindowEndUtc, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($timezone));
            $currentWindowEndLocal = $dt->format('Y-m-d H:i:s T');
        } catch (Exception $e) {
            $currentWindowEndLocal = $currentWindowEndUtc;
        }
    }
    if ($nextWindowStartUtc !== null && $nextWindowStartUtc !== '') {
        try {
            $dt = new DateTime($nextWindowStartUtc, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($timezone));
            $nextWindowStartLocal = $dt->format('Y-m-d H:i:s T');
        } catch (Exception $e) {
            $nextWindowStartLocal = $nextWindowStartUtc;
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
        'banner_scope' => $notam['banner_scope'] ?? null,
        'banner_category' => $notam['banner_category'] ?? null,
        'banner_headline' => $notam['banner_headline'] ?? null,
        'banner_schedule_line' => $notam['banner_schedule_line'] ?? null,
        'start_time_utc' => $startTimeUtc,
        'end_time_utc' => $endTimeUtc,
        'start_time_local' => $startTimeLocal,
        'end_time_local' => $endTimeLocal,
        'message' => $notam['text'] ?? '',
        'official_link' => $officialLink,
        'schedule_source' => $notam['schedule_source'] ?? 'none',
        'effective_segments' => $effectiveSegmentsOut,
        'current_restriction_end_time_utc' => $currentWindowEndUtc,
        'current_restriction_end_time_local' => $currentWindowEndLocal,
        'next_restriction_start_time_utc' => $nextWindowStartUtc,
        'next_restriction_start_time_local' => $nextWindowStartLocal,
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

notamApiSendCacheHeaders();
echo json_encode($response);
ob_end_flush();


