<?php
/**
 * Test Weather Endpoint - Mocked Version
 * Tests the weather API using mocked responses
 */

// Mock file_get_contents to return test data
function testFileGetContents($filename) {
    // Map of API URLs to mock responses
    static $mockResponses = [
        'tempest' => [
            'status' => ['status_code' => 0, 'status_message' => 'OK'],
            'obs' => [[
                'timestamp' => time(),
                'air_temperature' => 5.6,
                'relative_humidity' => 93,
                'sea_level_pressure' => 1019.2,
                'wind_avg' => 2.5,
                'wind_direction' => 89,
                'wind_gust' => 3.2,
                'precip_accum_local_day_final' => 0.47,
                'dew_point' => 4.6
            ]]
        ],
        'ambient' => [[
            'macAddress' => 'AA:BB:CC:DD:EE:FF',
            'lastData' => [
                'dateutc' => time() * 1000,
                'tempf' => 42.8,
                'humidity' => 93,
                'baromrelin' => 30.08,
                'windspeedmph' => 5,
                'winddir' => 89,
                'windgustmph' => 7,
                'dailyrainin' => 0.47,
                'dewPoint' => 40.3
            ]
        ]],
        'metar' => [[
            'rawOb' => 'KSPB 261654Z AUTO 18005KT 10SM CLR 06/04 A3012',
            'obsTime' => '2025-01-26T16:54:00Z',
            'temp' => 6.0,
            'dewp' => 4.0,
            'wdir' => 180,
            'wspd' => 5,
            'visib' => '10',
            'altim' => 30.12,
            'clouds' => [['cover' => 'CLR', 'base' => null]]
        ]]
    ];
    
    // Check if this is an API URL
    foreach ($mockResponses as $key => $data) {
        if (strpos($filename, $key) !== false || stripos($filename, 'weather') !== false) {
            return json_encode($data);
        }
    }
    
    // Try to read actual file
    if (file_exists($filename)) {
        return file_get_contents($filename);
    }
    
    return false;
}

// Test weather endpoint
header('Content-Type: application/json');

$airportId = $_GET['airport'] ?? 'kspb';

// Load test config
$configFile = __DIR__ . '/Fixtures/airports.json.test';
if (!file_exists($configFile)) {
    echo json_encode(['success' => false, 'error' => 'Test configuration not found']);
    exit;
}

$config = json_decode(testFileGetContents($configFile), true);
if (!isset($config['airports'][$airportId])) {
    echo json_encode(['success' => false, 'error' => 'Test airport not found']);
    exit;
}

$airport = $config['airports'][$airportId];

// Return mock weather data based on source type
$weatherData = [
    'temperature' => 5.6,
    'humidity' => 93,
    'pressure' => 30.08,
    'wind_speed' => 5,
    'wind_direction' => 89,
    'gust_speed' => 7,
    'precip_accum' => 0.47,
    'dewpoint' => 4.6,
    'visibility' => 10,
    'ceiling' => null,
    'temp_high' => null,
    'temp_low' => null,
    'peak_gust' => 7,
    'density_altitude' => -1948,
    'pressure_altitude' => -107,
    'sunrise' => '07:43',
    'sunset' => '18:06',
    'peak_gust_today' => 7,
    'temp_high_today' => 6.7,
    'temp_low_today' => 5.6,
    'flight_category' => 'VFR',
    'flight_category_class' => 'status-vfr',
    'temperature_f' => 42,
    'dewpoint_f' => 40,
    'gust_factor' => 2,
    'dewpoint_spread' => 1.0
];

echo json_encode(['success' => true, 'weather' => $weatherData]);

