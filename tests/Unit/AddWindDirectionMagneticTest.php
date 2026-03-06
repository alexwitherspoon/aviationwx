<?php
/**
 * SAFETY-CRITICAL: addWindDirectionMagneticToWeather
 *
 * Verifies that wind_direction_magnetic is correctly computed from wind_direction
 * (true north) using per-airport declination. Display layers use wind_direction_magnetic;
 * when missing, show --- (fail closed).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/weather.php';

class AddWindDirectionMagneticTest extends TestCase
{
    /**
     * When wind_direction is valid and airport has declination, wind_direction_magnetic is set
     */
    public function testValidWindDirection_WithDeclination_SetsMagnetic(): void
    {
        $weather = ['wind_direction' => 230];
        $airport = ['magnetic_declination' => 14.0];

        addWindDirectionMagneticToWeather($weather, $airport);

        $this->assertArrayHasKey('wind_direction_magnetic', $weather);
        $this->assertSame(216, $weather['wind_direction_magnetic'], '230° true - 14°E = 216° magnetic');
    }

    /**
     * When declination is 0, wind_direction_magnetic equals wind_direction
     */
    public function testZeroDeclination_MagneticEqualsTrue(): void
    {
        $weather = ['wind_direction' => 90];
        $airport = ['magnetic_declination' => 0];

        addWindDirectionMagneticToWeather($weather, $airport);

        $this->assertSame(90, $weather['wind_direction_magnetic']);
    }

    /**
     * 14° true with 14°E declination → 0° magnetic (360° normalized to 0 in output)
     */
    public function test14TrueWith14Declination_ProducesNorthMagnetic(): void
    {
        $weather = ['wind_direction' => 14];
        $airport = ['magnetic_declination' => 14.0];

        addWindDirectionMagneticToWeather($weather, $airport);

        $this->assertArrayHasKey('wind_direction_magnetic', $weather);
        $this->assertSame(360, $weather['wind_direction_magnetic'], '14° true - 14°E = 0° = 360° magnetic');
    }

    /**
     * When wind_direction is null, wind_direction_magnetic is NOT added (fail closed at display)
     */
    public function testNullWindDirection_DoesNotAddMagnetic(): void
    {
        $weather = ['wind_speed' => 10];
        $airport = ['magnetic_declination' => 14.0];

        addWindDirectionMagneticToWeather($weather, $airport);

        $this->assertArrayNotHasKey('wind_direction_magnetic', $weather);
    }

    /**
     * When wind_direction is out of range, wind_direction_magnetic is NOT added
     */
    public function testOutOfRangeWindDirection_DoesNotAddMagnetic(): void
    {
        $weather = ['wind_direction' => 400];
        $airport = ['magnetic_declination' => 0];

        addWindDirectionMagneticToWeather($weather, $airport);

        $this->assertArrayNotHasKey('wind_direction_magnetic', $weather);
    }

    /**
     * West declination: 230° true + 14°W (-14) → 244° magnetic
     */
    public function testWestDeclination_ConvertsCorrectly(): void
    {
        $weather = ['wind_direction' => 230];
        $airport = ['magnetic_declination' => -14.0];

        addWindDirectionMagneticToWeather($weather, $airport);

        $this->assertSame(244, $weather['wind_direction_magnetic']);
    }

    /**
     * When wind_direction is VRB (variable), wind_direction_text is set for display
     * Display layers use wind_direction_text to show "VRB" instead of ---
     */
    public function testVRBWindDirection_SetsWindDirectionText(): void
    {
        $weather = ['wind_direction' => 'VRB', 'wind_speed' => 5];
        $airport = ['magnetic_declination' => 14.0];

        addWindDirectionMagneticToWeather($weather, $airport);

        $this->assertArrayHasKey('wind_direction_text', $weather);
        $this->assertSame('VRB', $weather['wind_direction_text']);
        $this->assertArrayNotHasKey('wind_direction_magnetic', $weather, 'VRB has no numeric magnetic direction');
    }

    /**
     * VRB clears stale wind_direction_magnetic from reused/merged weather array
     */
    public function testVRBWindDirection_ClearsStaleMagnetic(): void
    {
        $weather = [
            'wind_direction' => 'VRB',
            'wind_direction_magnetic' => 216,
            'wind_direction_text' => 'VRB',
        ];
        $airport = ['magnetic_declination' => 14.0];

        addWindDirectionMagneticToWeather($weather, $airport);

        $this->assertArrayNotHasKey('wind_direction_magnetic', $weather, 'VRB must clear stale magnetic');
        $this->assertSame('VRB', $weather['wind_direction_text']);
    }

    /**
     * VRB case-insensitive: "vrb" also sets wind_direction_text
     */
    public function testVRBWindDirection_CaseInsensitive(): void
    {
        $weather = ['wind_direction' => 'vrb'];
        $airport = [];

        addWindDirectionMagneticToWeather($weather, $airport);

        $this->assertSame('VRB', $weather['wind_direction_text']);
    }
}
