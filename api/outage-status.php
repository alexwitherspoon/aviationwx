<?php
/**
 * Outage Status API Endpoint
 * 
 * Returns current outage status for an airport, including source timestamps.
 * Used by frontend to periodically check and update outage banner state.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../pages/airport.php';

// Get airport parameter
$airportId = isset($_GET['airport']) ? trim($_GET['airport']) : '';

if (empty($airportId)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing airport parameter'
    ]);
    exit;
}

// Load configuration
$config = loadConfig();
if ($config === null) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'Configuration error'
    ]);
    exit;
}

// Get airport configuration
if (!isset($config['airports'][$airportId])) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => 'Airport not found'
    ]);
    exit;
}

$airport = $config['airports'][$airportId];

// Check outage status
$outageStatus = checkDataOutageStatus($airportId, $airport);

// Get source timestamps for detailed response
$sourceTimestamps = getSourceTimestamps($airportId, $airport);
$outageThresholdSeconds = DATA_OUTAGE_BANNER_HOURS * 3600;
$now = time();

// Build response with source details
$response = [
    'success' => true,
    'in_outage' => $outageStatus !== null,
    'newest_timestamp' => $outageStatus !== null ? $outageStatus['newest_timestamp'] : 0,
    'sources' => []
];

// Add primary source info
if ($sourceTimestamps['primary']['available']) {
    $primaryTimestamp = $sourceTimestamps['primary']['timestamp'];
    $primaryAge = $sourceTimestamps['primary']['age'];
    $isStale = ($primaryTimestamp === 0) || ($primaryAge >= $outageThresholdSeconds);
    
    $response['sources']['primary'] = [
        'timestamp' => $primaryTimestamp,
        'stale' => $isStale,
        'age' => $primaryAge
    ];
}

// Add METAR source info
if ($sourceTimestamps['metar']['available']) {
    $metarTimestamp = $sourceTimestamps['metar']['timestamp'];
    $metarAge = $sourceTimestamps['metar']['age'];
    $isStale = ($metarTimestamp === 0) || ($metarAge >= $outageThresholdSeconds);
    
    $response['sources']['metar'] = [
        'timestamp' => $metarTimestamp,
        'stale' => $isStale,
        'age' => $metarAge
    ];
}

// Add webcam source info
if ($sourceTimestamps['webcams']['available']) {
    $webcamNewestTimestamp = $sourceTimestamps['webcams']['newest_timestamp'];
    $webcamStaleCount = $sourceTimestamps['webcams']['stale_count'];
    $webcamTotal = $sourceTimestamps['webcams']['total'];
    
    // Determine if all webcams are stale
    $allWebcamsStale = false;
    if ($webcamStaleCount === $webcamTotal) {
        $allWebcamsStale = true;
    } elseif ($webcamNewestTimestamp > 0) {
        $webcamAge = $now - $webcamNewestTimestamp;
        $allWebcamsStale = ($webcamAge >= $outageThresholdSeconds);
    } else {
        $allWebcamsStale = true;
    }
    
    $response['sources']['webcams'] = [
        'all_stale' => $allWebcamsStale,
        'newest_timestamp' => $webcamNewestTimestamp,
        'total' => $webcamTotal,
        'stale_count' => $webcamStaleCount
    ];
}

echo json_encode($response);

