<?php
/**
 * Manual Test Script for METAR Fallback Logic
 * 
 * This script tests the METAR fallback functionality by simulating
 * different scenarios with airport configurations.
 */

require_once __DIR__ . '/../api/weather.php';
require_once __DIR__ . '/Helpers/TestHelper.php';

echo "=== METAR Fallback Logic Test ===\n\n";

// Test 1: Airport without metar_station
echo "Test 1: Airport without metar_station\n";
$airport1 = createTestAirport([
    'icao' => 'KABC',
    'weather_source' => ['type' => 'tempest']
]);
unset($airport1['metar_station']);
$result1 = fetchMETAR($airport1);
echo "  Result: " . ($result1 === null ? "✓ NULL (correct - no fetch attempted)" : "✗ NOT NULL (unexpected)") . "\n";
echo "  Expected: NULL\n";
echo "  Actual: " . ($result1 === null ? "NULL" : "NOT NULL") . "\n\n";

// Test 2: Airport with metar_station but no nearby stations
echo "Test 2: Airport with metar_station, no nearby stations\n";
$airport2 = createTestAirport([
    'icao' => 'KSPB',
    'metar_station' => 'KSPB'
]);
$result2 = fetchMETAR($airport2);
echo "  Result: " . ($result2 !== null ? "✓ Data fetched (or attempted)" : "✗ NULL (may be API failure)") . "\n";
if ($result2 !== null && isset($result2['_metar_station_used'])) {
    echo "  Station used: " . $result2['_metar_station_used'] . "\n";
}
echo "\n";

// Test 3: Airport with metar_station and nearby stations
echo "Test 3: Airport with metar_station and nearby stations\n";
$airport3 = createTestAirport([
    'icao' => 'KABC',
    'metar_station' => 'KPRIMARY',
    'nearby_metar_stations' => ['KNEARBY1', 'KNEARBY2']
]);
echo "  Primary station: KPRIMARY\n";
echo "  Nearby stations: KNEARBY1, KNEARBY2\n";
$result3 = fetchMETAR($airport3);
echo "  Result: " . ($result3 !== null ? "✓ Data fetched (or attempted)" : "✗ NULL (may be API failure)") . "\n";
if ($result3 !== null && isset($result3['_metar_station_used'])) {
    echo "  Station used: " . $result3['_metar_station_used'] . "\n";
}
echo "\n";

// Test 4: Airport with empty metar_station
echo "Test 4: Airport with empty metar_station string\n";
$airport4 = createTestAirport([
    'icao' => 'KABC',
    'metar_station' => '',
    'nearby_metar_stations' => ['KNEARBY1']
]);
$result4 = fetchMETAR($airport4);
echo "  Result: " . ($result4 === null ? "✓ NULL (correct - empty string rejected)" : "✗ NOT NULL (unexpected)") . "\n";
echo "  Expected: NULL\n";
echo "  Actual: " . ($result4 === null ? "NULL" : "NOT NULL") . "\n\n";

// Test 5: Airport with invalid nearby station IDs
echo "Test 5: Airport with invalid nearby station IDs (empty strings)\n";
$airport5 = createTestAirport([
    'icao' => 'KABC',
    'metar_station' => 'KPRIMARY',
    'nearby_metar_stations' => ['', 'KVALID', null, 'KANOTHER']
]);
echo "  Nearby stations array: ['', 'KVALID', null, 'KANOTHER']\n";
echo "  Expected: Should skip empty strings and null, try KVALID and KANOTHER\n";
$result5 = fetchMETAR($airport5);
echo "  Result: " . ($result5 !== null ? "✓ Data fetched (or attempted)" : "✗ NULL (may be API failure)") . "\n";
if ($result5 !== null && isset($result5['_metar_station_used'])) {
    echo "  Station used: " . $result5['_metar_station_used'] . "\n";
}
echo "\n";

echo "=== Test Summary ===\n";
echo "All tests completed. Check results above.\n";
echo "Note: Tests that attempt actual API calls may fail if the API is unreachable,\n";
echo "but the important thing is that the logic correctly skips fetches when\n";
echo "metar_station is not configured.\n";

