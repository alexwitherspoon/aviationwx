<?php
/**
 * SAFETY-CRITICAL: formatWeatherResponse (Public API v1)
 *
 * Verifies formatWeatherResponse produces correct wind_direction and
 * last_hour_wind structure for API safety.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/public-api/weather-format.php';

class FormatWeatherResponseTest extends TestCase
{
    /**
     * Numeric wind: wind_direction object with true_north, magnetic_north, variable
     */
    public function testNumericWind_ReturnsNestedWindDirectionObject(): void
    {
        $weather = [
            'wind_direction' => 230,
            'wind_direction_magnetic' => 216,
            'wind_direction_text' => null,
        ];
        $airport = ['magnetic_declination' => 14.0];

        $result = formatWeatherResponse($weather, $airport);

        $this->assertIsArray($result['wind_direction']);
        $this->assertSame(230, $result['wind_direction']['true_north']);
        $this->assertSame(216, $result['wind_direction']['magnetic_north']);
        $this->assertFalse($result['wind_direction']['variable']);
        $this->assertArrayNotHasKey('wind_direction_magnetic', $result);
    }

    /**
     * VRB wind: true_north and magnetic_north null, variable true
     */
    public function testVRBWind_ReturnsVariableTrue(): void
    {
        $weather = [
            'wind_direction' => 'VRB',
            'wind_direction_text' => 'VRB',
        ];
        $airport = [];

        $result = formatWeatherResponse($weather, $airport);

        $this->assertIsArray($result['wind_direction']);
        $this->assertNull($result['wind_direction']['true_north']);
        $this->assertNull($result['wind_direction']['magnetic_north']);
        $this->assertTrue($result['wind_direction']['variable']);
    }

    /**
     * VRB detection from wind_direction string (case insensitive)
     */
    public function testVRBWind_FromWindDirectionString(): void
    {
        $weather = [
            'wind_direction' => 'vrb',
            'wind_direction_text' => null,
        ];
        $airport = [];

        $result = formatWeatherResponse($weather, $airport);

        $this->assertTrue($result['wind_direction']['variable']);
        $this->assertNull($result['wind_direction']['true_north']);
    }

    /**
     * Null wind_direction: true_north null, variable false
     */
    public function testNullWindDirection_ReturnsNullTrueNorth(): void
    {
        $weather = ['wind_speed' => 10];
        $airport = [];

        $result = formatWeatherResponse($weather, $airport);

        $this->assertNull($result['wind_direction']['true_north']);
        $this->assertNull($result['wind_direction']['magnetic_north']);
        $this->assertFalse($result['wind_direction']['variable']);
    }

    /**
     * last_hour_wind: object with sectors, sector_labels, reference, unit
     */
    public function testLastHourWind_ReturnsWrappedObject(): void
    {
        $petals = array_fill(0, 16, 0);
        $petals[0] = 5;   // N
        $petals[4] = 10;  // E
        $weather = [
            'wind_speed' => 10,
            'last_hour_wind' => $petals,
        ];
        $airport = [];

        $result = formatWeatherResponse($weather, $airport);

        $lhw = $result['last_hour_wind'];
        $this->assertIsArray($lhw);
        $this->assertArrayHasKey('sectors', $lhw);
        $this->assertArrayHasKey('sector_labels', $lhw);
        $this->assertSame('magnetic_north', $lhw['reference']);
        $this->assertSame('knots', $lhw['unit']);
        $this->assertCount(16, $lhw['sectors']);
        $this->assertSame(5, $lhw['sectors'][0]);
        $this->assertSame(10, $lhw['sectors'][4]);
    }

    /**
     * last_hour_wind null or invalid: returns null
     */
    public function testLastHourWind_InvalidReturnsNull(): void
    {
        $weather = ['wind_speed' => 10];
        $airport = [];

        $result = formatWeatherResponse($weather, $airport);

        $this->assertNull($result['last_hour_wind']);
    }
}
