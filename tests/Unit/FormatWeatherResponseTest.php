<?php
/**
 * SAFETY-CRITICAL: formatWeatherResponse and formatWindRoseForApi (Public API v1)
 *
 * Verifies formatWeatherResponse produces correct wind_direction and
 * last_hour_wind structure for API safety. formatWindRoseForApi must
 * reject invalid input to prevent malformed wind rose data reaching pilots.
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
     * Out-of-range headings: 361 and -1 return null (valid range 0-360)
     */
    public function testOutOfRangeHeadings_ReturnsNull(): void
    {
        $weather = [
            'wind_direction' => 361,
            'wind_direction_magnetic' => -1,
            'wind_direction_text' => null,
        ];
        $airport = [];

        $result = formatWeatherResponse($weather, $airport);

        $this->assertNull($result['wind_direction']['true_north']);
        $this->assertNull($result['wind_direction']['magnetic_north']);
    }

    /**
     * Boundary headings 0 and 360: accepted as valid
     */
    public function testBoundaryHeadings_Accepted(): void
    {
        $weather = [
            'wind_direction' => 0,
            'wind_direction_magnetic' => 360,
            'wind_direction_text' => null,
        ];
        $airport = [];

        $result = formatWeatherResponse($weather, $airport);

        $this->assertSame(0, $result['wind_direction']['true_north']);
        $this->assertSame(360, $result['wind_direction']['magnetic_north']);
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

    /**
     * formatWindRoseForApi: null returns null (fail closed)
     */
    public function testFormatWindRoseForApi_Null_ReturnsNull(): void
    {
        $result = formatWindRoseForApi(null);
        $this->assertNull($result, 'Null input must return null for API safety');
    }

    /**
     * formatWindRoseForApi: wrong sector count returns null (must be exactly 16)
     */
    public function testFormatWindRoseForApi_WrongCount_ReturnsNull(): void
    {
        $petals15 = array_fill(0, 15, 0);
        $this->assertNull(formatWindRoseForApi($petals15), '15 sectors must return null');

        $petals17 = array_fill(0, 17, 0);
        $this->assertNull(formatWindRoseForApi($petals17), '17 sectors must return null');
    }

    /**
     * formatWindRoseForApi: valid 16 sectors returns object with required fields
     */
    public function testFormatWindRoseForApi_Valid16Sectors_ReturnsWrappedObject(): void
    {
        $petals = array_fill(0, 16, 0);
        $petals[0] = 5.0;
        $petals[4] = 10.0;

        $result = formatWindRoseForApi($petals);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('sectors', $result);
        $this->assertArrayHasKey('sector_labels', $result);
        $this->assertArrayHasKey('reference', $result);
        $this->assertArrayHasKey('unit', $result);
        $this->assertArrayHasKey('period_label', $result);
        $this->assertSame('magnetic_north', $result['reference']);
        $this->assertSame('knots', $result['unit']);
        $this->assertCount(16, $result['sectors']);
        $this->assertSame(5.0, $result['sectors'][0]);
        $this->assertSame(10.0, $result['sectors'][4]);
        $this->assertIsString($result['period_label']);
    }

    /**
     * Float values normalized to integers per OpenAPI spec
     */
    public function testFloatValues_NormalizedToIntegers(): void
    {
        $weather = [
            'wind_speed' => 11.663066954643629,
            'gust_speed' => 16.73866090712743,
            'ceiling' => 3000.7,
            'density_altitude' => -1844.2,
            'pressure_altitude' => 710.9,
            'peak_gust_today' => 16.73866090712743,
        ];
        $airport = [];

        $result = formatWeatherResponse($weather, $airport);

        $this->assertSame(12, $result['wind_speed']);
        $this->assertSame(17, $result['gust_speed']);
        $this->assertSame(3001, $result['ceiling']);
        $this->assertSame(-1844, $result['density_altitude']);
        $this->assertSame(711, $result['pressure_altitude']);
        $this->assertSame(17, $result['daily']['peak_gust']);
    }
}
