<?php
/**
 * Test script for WeatherLink v2 API
 * This script helps understand the API response structure
 * 
 * Usage: php scripts/test-weatherlink-api.php [station_id] [api_key] [api_secret]
 * 
 * If no credentials provided, will attempt to use demo mode (may not work)
 */

require_once __DIR__ . '/../lib/logger.php';

$stationId = $argv[1] ?? '2'; // Demo station ID
$apiKey = $argv[2] ?? 'demo';
$apiSecret = $argv[3] ?? 'demo';

echo "Testing WeatherLink v2 API\n";
echo "========================\n\n";
echo "Station ID: {$stationId}\n";
echo "API Key: " . (strlen($apiKey) > 10 ? substr($apiKey, 0, 10) . '...' : $apiKey) . "\n";
echo "API Secret: " . (strlen($apiSecret) > 10 ? substr($apiSecret, 0, 10) . '...' : $apiSecret) . "\n\n";

// Build URL with optional demo parameter
$url = "https://api.weatherlink.com/v2/current/{$stationId}?api-key=" . urlencode($apiKey);
if ($apiKey === 'demo' || $apiSecret === 'demo') {
    $url .= "&demo=true";
}

echo "Request URL: {$url}\n\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'x-api-secret: ' . $apiSecret,
        'Accept: application/json',
    ],
    CURLOPT_USERAGENT => 'AviationWX/1.0',
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Status: {$httpCode}\n";
if (!empty($error)) {
    echo "cURL Error: {$error}\n";
}
echo "\n";

if ($response === false) {
    echo "ERROR: Failed to get response\n";
    exit(1);
}

echo "Response:\n";
echo "---------\n";
echo $response . "\n\n";

$data = json_decode($response, true);
if ($data === null) {
    echo "ERROR: Invalid JSON response\n";
    exit(1);
}

echo "Parsed JSON Structure:\n";
echo "---------------------\n";
echo json_encode($data, JSON_PRETTY_PRINT) . "\n\n";

// Try to identify key fields
echo "Field Analysis:\n";
echo "--------------\n";

if (isset($data['sensors']) && is_array($data['sensors'])) {
    echo "Found " . count($data['sensors']) . " sensor(s)\n\n";
    
    foreach ($data['sensors'] as $index => $sensor) {
        echo "Sensor #{$index}:\n";
        if (isset($sensor['lsid'])) {
            echo "  LSID: {$sensor['lsid']}\n";
        }
        if (isset($sensor['sensor_type'])) {
            echo "  Type: {$sensor['sensor_type']}\n";
        }
        if (isset($sensor['data_structure_type'])) {
            echo "  Data Structure Type: {$sensor['data_structure_type']}\n";
        }
        
        if (isset($sensor['data']) && is_array($sensor['data']) && !empty($sensor['data'])) {
            $sensorData = $sensor['data'][0];
            echo "  Data fields:\n";
            if (isset($sensorData['data'])) {
                foreach ($sensorData['data'] as $key => $value) {
                    echo "    - {$key}: " . (is_numeric($value) ? $value : (is_string($value) ? "\"{$value}\"" : gettype($value))) . "\n";
                }
            }
            if (isset($sensorData['ts'])) {
                echo "  Timestamp: {$sensorData['ts']} (" . date('Y-m-d H:i:s', $sensorData['ts']) . ")\n";
            }
        }
        echo "\n";
    }
} else {
    echo "No sensors found in response\n";
    echo "Top-level keys: " . implode(', ', array_keys($data)) . "\n";
}

echo "\nDone.\n";

