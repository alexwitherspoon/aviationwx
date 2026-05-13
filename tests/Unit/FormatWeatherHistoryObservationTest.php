<?php
/**
 * Public API: formatWeatherHistoryObservationForApi (history observations)
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/public-api/weather-format.php';

class FormatWeatherHistoryObservationTest extends TestCase
{
    /**
     * Stored DA/PA are rounded to integers like the live weather payload
     */
    public function testDensityAndPressureAltitude_RoundedIntegers(): void
    {
        $obs = [
            'obs_time' => 1700000000,
            'obs_time_iso' => gmdate('c', 1700000000),
            'wind_direction' => 270,
            'wind_speed' => 10,
            'temperature' => 15.0,
            'pressure' => 30.12,
            'density_altitude' => 1234.6,
            'pressure_altitude' => 99.4,
        ];

        $out = formatWeatherHistoryObservationForApi($obs, 0.0);

        $this->assertSame(1235, $out['density_altitude']);
        $this->assertSame(99, $out['pressure_altitude']);
    }

    /**
     * Missing or invalid stored values fail closed to null
     */
    public function testDensityPressureAltitude_InvalidOrMissing_ReturnsNull(): void
    {
        $base = [
            'obs_time' => 1700000000,
            'obs_time_iso' => gmdate('c', 1700000000),
            'wind_direction' => 360,
            'wind_speed' => 0,
        ];

        $outMissing = formatWeatherHistoryObservationForApi($base, 0.0);
        $this->assertNull($outMissing['density_altitude']);
        $this->assertNull($outMissing['pressure_altitude']);

        $base['density_altitude'] = 'n/a';
        $base['pressure_altitude'] = null;
        $outBad = formatWeatherHistoryObservationForApi($base, 0.0);
        $this->assertNull($outBad['density_altitude']);
        $this->assertNull($outBad['pressure_altitude']);
    }

    /**
     * VRB wind: variable true, headings null (unchanged from prior history behavior)
     */
    public function testVRBWind_VariableTrue(): void
    {
        $obs = [
            'obs_time' => 1700000000,
            'obs_time_iso' => gmdate('c', 1700000000),
            'wind_direction' => 'VRB',
            'wind_speed' => 3,
        ];

        $out = formatWeatherHistoryObservationForApi($obs, 14.0);

        $this->assertTrue($out['wind_direction']['variable']);
        $this->assertNull($out['wind_direction']['true_north']);
        $this->assertNull($out['wind_direction']['magnetic_north']);
    }

    /**
     * field_sources and sources pass through when present
     */
    public function testFieldSourcesAndSources_Preserved(): void
    {
        $obs = [
            'obs_time' => 1700000000,
            'obs_time_iso' => gmdate('c', 1700000000),
            'wind_direction' => 180,
            'wind_speed' => 5,
            'field_sources' => ['temperature' => 'tempest'],
            'sources' => ['tempest'],
        ];

        $out = formatWeatherHistoryObservationForApi($obs, 0.0);

        $this->assertSame(['temperature' => 'tempest'], $out['field_sources']);
        $this->assertSame(['tempest'], $out['sources']);
    }
}
