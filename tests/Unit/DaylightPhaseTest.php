<?php
/**
 * Daylight phase and airport location helpers used by webcam quality checks.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/weather/utils.php';

class DaylightPhaseTest extends TestCase
{
    public function testGetDaylightPhase_NoLocation_ReturnsDay(): void
    {
        $airport = []; // No lat/lon

        $phase = getDaylightPhase($airport);

        $this->assertEquals(DAYLIGHT_PHASE_DAY, $phase,
            'Without location data, should default to day (fail safe)');
    }

    public function testGetDaylightPhase_ValidLocation_ReturnsPhase(): void
    {
        // Portland, OR
        $airport = [
            'lat' => 45.5898,
            'lon' => -122.5951,
        ];

        $phase = getDaylightPhase($airport);

        $validPhases = [
            DAYLIGHT_PHASE_DAY,
            DAYLIGHT_PHASE_CIVIL_TWILIGHT,
            DAYLIGHT_PHASE_NAUTICAL_TWILIGHT,
            DAYLIGHT_PHASE_NIGHT,
        ];

        $this->assertContains($phase, $validPhases,
            'Should return a valid daylight phase');
    }

    /**
     * Polar night with twilight: sun never rises but reaches ~-4.7° at noon (above -6° civil threshold).
     * getDaylightPhase uses getSunAltitude for polar regions; at noon returns civil twilight.
     */
    public function testGetDaylightPhase_PolarNightWithTwilight_ReturnsCivilTwilightAtNoon(): void
    {
        $airport = [
            'lat' => 71.2906,
            'lon' => -156.7886,
            'timezone' => 'America/Anchorage',
        ];
        $winterSolsticeNoon = strtotime('2025-12-21 12:00:00 America/Anchorage');

        $phase = getDaylightPhase($airport, $winterSolsticeNoon);

        $this->assertEquals(DAYLIGHT_PHASE_CIVIL_TWILIGHT, $phase,
            'Utqiaġvik AK winter solstice noon: sun ~-4.7° (civil twilight), not full night');
    }

    /**
     * Midnight sun: sun never sets. getDaylightPhase uses getSunAltitude for polar regions.
     */
    public function testGetDaylightPhase_MidnightSun_ReturnsDay(): void
    {
        $airport = [
            'lat' => 66.5039,
            'lon' => 25.7294,
            'timezone' => 'Europe/Helsinki',
        ];
        $summerSolsticeNoon = strtotime('2025-06-21 12:00:00 Europe/Helsinki');

        $phase = getDaylightPhase($airport, $summerSolsticeNoon);

        $this->assertEquals(DAYLIGHT_PHASE_DAY, $phase,
            'Rovaniemi on summer solstice noon: sun never sets, should be day');
    }

    public function testIsDaytime_ReturnsBoolean(): void
    {
        $airport = [
            'lat' => 45.5898,
            'lon' => -122.5951,
        ];

        $result = isDaytime($airport);

        $this->assertIsBool($result);
    }

    public function testIsNighttime_ReturnsBoolean(): void
    {
        $airport = [
            'lat' => 45.5898,
            'lon' => -122.5951,
        ];

        $result = isNighttime($airport);

        $this->assertIsBool($result);
    }

    public function testGetAirportLocation_ValidAirport_ReturnsLocation(): void
    {
        $airport = [
            'lat' => 45.5898,
            'lon' => -122.5951,
        ];

        $location = getAirportLocation($airport);

        $this->assertNotNull($location);
        $this->assertEquals(45.5898, $location['lat']);
        $this->assertEquals(-122.5951, $location['lon']);
    }

    public function testGetAirportLocation_NoLocation_ReturnsNull(): void
    {
        $airport = [];

        $location = getAirportLocation($airport);

        $this->assertNull($location);
    }
}
