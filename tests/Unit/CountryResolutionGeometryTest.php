<?php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/country-resolution.php';

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for native country polygon hit testing (fixture GeoJSON only).
 */
final class CountryResolutionGeometryTest extends TestCase
{
    private static string $fixturePath;

    public static function setUpBeforeClass(): void
    {
        self::$fixturePath = __DIR__ . '/../Fixtures/country-boundary-vancouver-test.geojson';
    }

    public function testFixtureKBLI_IsUnitedStates(): void
    {
        $features = countryResolutionLoadAdmin0FeaturesFromGeoJson(self::$fixturePath);
        $this->assertNotEmpty($features);
        $this->assertArrayHasKey('_bbox', $features[0]['parts'][0]);
        // Bellingham Intl (public reference coordinates)
        $iso = countryResolutionFindIsoAlpha2AtLatLon(48.7927, -122.5375, $features);
        $this->assertSame('US', $iso);
    }

    public function testFixtureCYVR_IsCanada(): void
    {
        $features = countryResolutionLoadAdmin0FeaturesFromGeoJson(self::$fixturePath);
        $iso = countryResolutionFindIsoAlpha2AtLatLon(49.1947, -123.1839, $features);
        $this->assertSame('CA', $iso);
    }

    public function testFixtureCYXXAbbotsford_IsCanada(): void
    {
        $features = countryResolutionLoadAdmin0FeaturesFromGeoJson(self::$fixturePath);
        $iso = countryResolutionFindIsoAlpha2AtLatLon(49.0253, -122.3600, $features);
        $this->assertSame('CA', $iso);
    }

    public function testFixtureLynden38W_IsUnitedStates(): void
    {
        $features = countryResolutionLoadAdmin0FeaturesFromGeoJson(self::$fixturePath);
        $iso = countryResolutionFindIsoAlpha2AtLatLon(48.9559, -122.4581, $features);
        $this->assertSame('US', $iso);
    }

    public function testFixtureMidAtlantic_ReturnsNull(): void
    {
        $features = countryResolutionLoadAdmin0FeaturesFromGeoJson(self::$fixturePath);
        $iso = countryResolutionFindIsoAlpha2AtLatLon(40.0, -40.0, $features);
        $this->assertNull($iso);
    }

    public function testIso3166Alpha2Validation_AcceptsFrance(): void
    {
        $this->assertTrue(countryResolutionIsValidIso3166Alpha2('FR'));
        $this->assertTrue(countryResolutionIsValidIso3166Alpha2('fr'));
    }

    public function testIso3166Alpha2Validation_RejectsGarbage(): void
    {
        $this->assertFalse(countryResolutionIsValidIso3166Alpha2(''));
        $this->assertFalse(countryResolutionIsValidIso3166Alpha2('USA'));
        $this->assertFalse(countryResolutionIsValidIso3166Alpha2('Z9'));
        $this->assertFalse(countryResolutionIsValidIso3166Alpha2('ZZ'));
    }
}
