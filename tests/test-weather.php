<?php
/**
 * Test Weather API - Uses Mocked Responses
 * This version of weather.php uses mocked API responses for testing
 */

// Include mock responses
require_once __DIR__ . '/mock-weather-responses.php';

header('Content-Type: application/json');

// Get airport ID
$airportId = $_GET['airport'] ?? '';
if (empty($airportId)) {
    echo json_encode(['success' => false, 'error' => 'Airport ID required']);
    exit;
}

// Load airport config
$configFile = __DIR__ . '/../airports.json.test';
if (!file_exists($configFile)) {
    echo json_encode(['success' => false, 'error' => 'Test configuration not found']);
    exit;
}

$config = json_decode(file_get_contents($configFile), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'error' => 'Configuration file is not valid JSON: ' . json_last_error_msg()]);
    exit;
}
if (!isset($config['airports'][$airportId])) {
    echo json_encode(['success' => false, 'error' => 'Airport not found']);
    exit;
}

$airport = $config['airports'][$airportId];

// Fetch weather using mocked data
$weatherData = fetchMockWeather($airport['weather_source']['type']);

if ($weatherData === null) {
    echo json_encode(['success' => false, 'error' => 'Failed to fetch weather data']);
    exit;
}

// Calculate additional aviation-specific metrics
$weatherData['density_altitude'] = calculateDensityAltitude($weatherData, $airport);
$weatherData['pressure_altitude'] = calculatePressureAltitude($weatherData, $airport);
$weatherData['sunrise'] = getSunriseTime($airport);
$weatherData['sunset'] = getSunsetTime($airport);

// Track and update today's peak gust
updatePeakGust($airportId, $weatherData['gust_speed'] ?? 0, $airport);
$weatherData['peak_gust_today'] = getPeakGust($airportId, $weatherData['gust_speed'] ?? 0, $airport);

// Track and update today's high and low temperatures
if ($weatherData['temperature'] !== null) {
    $currentTemp = $weatherData['temperature'];
    updateTempExtremes($airportId, $currentTemp, $airport);
    $tempExtremes = getTempExtremes($airportId, $currentTemp, $airport);
    $weatherData['temp_high_today'] = $tempExtremes['high'];
    $weatherData['temp_low_today'] = $tempExtremes['low'];
}

// Calculate VFR/IFR/MVFR status
$weatherData['flight_category'] = calculateFlightCategory($weatherData);
$weatherData['flight_category_class'] = 'status-' . strtolower($weatherData['flight_category']);

// Format temperatures to °F for display
$weatherData['temperature_f'] = $weatherData['temperature'] !== null ? round(($weatherData['temperature'] * 9/5) + 32) : null;
$weatherData['dewpoint_f'] = $weatherData['dewpoint'] !== null ? round(($weatherData['dewpoint'] * 9/5) + 32) : null;

// Calculate gust factor
$weatherData['gust_factor'] = ($weatherData['gust_speed'] && $weatherData['wind_speed']) ? 
    round($weatherData['gust_speed'] - $weatherData['wind_speed']) : 0;

// Calculate dewpoint spread
if ($weatherData['temperature'] !== null && $weatherData['dewpoint'] !== null) {
    $weatherData['dewpoint_spread'] = round($weatherData['temperature'] - $weatherData['dewpoint'], 1);
} else {
    $weatherData['dewpoint_spread'] = null;
}

echo json_encode(['success' => true, 'weather' => $weatherData]);

/**
 * Fetch mock weather data based on source type
 */
function fetchMockWeather($sourceType) {
    require_once __DIR__ . '/../api/weather.php';
    
    // Parse mocked response based on source type
    $response = null;
    switch ($sourceType) {
        case 'tempest':
            $response = json_decode(getMockTempestResponse(), true);
            if (isset($response['obs'][0])) {
                $obs = $response['obs'][0];
                $pressureInHg = $obs['sea_level_pressure'] / 33.8639;
                $windSpeedKts = round($obs['wind_avg'] * 1.943844);
                $gustSpeedKts = round($obs['wind_gust'] * 1.943844);
                
                return [
                    'temperature' => $obs['air_temperature'],
                    'humidity' => $obs['relative_humidity'],
                    'pressure' => $pressureInHg,
                    'wind_speed' => $windSpeedKts,
                    'wind_direction' => $obs['wind_direction'],
                    'gust_speed' => $gustSpeedKts,
                    'precip_accum' => $obs['precip_accum_local_day_final'] * 0.0393701,
                    'dewpoint' => $obs['dew_point'],
                    'visibility' => null,
                    'ceiling' => null,
                    'temp_high' => null,
                    'temp_low' => null,
                    'peak_gust' => $gustSpeedKts,
                ];
            }
            break;
            
        case 'ambient':
            $response = json_decode(getMockAmbientResponse(), true);
            if (isset($response[0]['lastData'])) {
                $obs = $response[0]['lastData'];
                return [
                    'temperature' => ($obs['tempf'] - 32) / 1.8,
                    'humidity' => $obs['humidity'],
                    'pressure' => $obs['baromrelin'],
                    'wind_speed' => round($obs['windspeedmph'] * 0.868976),
                    'wind_direction' => $obs['winddir'],
                    'gust_speed' => round($obs['windgustmph'] * 0.868976),
                    'precip_accum' => $obs['dailyrainin'],
                    'dewpoint' => ($obs['dewPoint'] - 32) / 1.8,
                    'visibility' => null,
                    'ceiling' => null,
                    'temp_high' => null,
                    'temp_low' => null,
                    'peak_gust' => round($obs['windgustmph'] * 0.868976),
                ];
            }
            break;
            
        case 'metar':
            $response = json_decode(getMockMETARResponse(), true);
            if (isset($response[0])) {
                $data = $response[0];
                return [
                    'temperature' => $data['temp'],
                    'humidity' => calculateHumidityFromDewpoint($data['temp'], $data['dewp']),
                    'pressure' => $data['altim'],
                    'wind_speed' => $data['wspd'],
                    'wind_direction' => $data['wdir'],
                    'gust_speed' => $data['wspd'] * 1.2,
                    'precip_accum' => 0,
                    'dewpoint' => $data['dewp'],
                    'visibility' => floatval($data['visib']),
                    'ceiling' => null,
                    'temp_high' => null,
                    'temp_low' => null,
                    'peak_gust' => $data['wspd'] * 1.2,
                ];
            }
            break;
    }
    
    return null;
}

