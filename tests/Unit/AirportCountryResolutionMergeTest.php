<?php
/**
 * Unit tests for airport country resolution merge helpers.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/airport-country-resolution-merge.php';

class AirportCountryResolutionMergeTest extends TestCase
{
    public function testCountryResolutionClearMergedGeometryIso_RemovesKey(): void
    {
        $config = [
            'airports' => [
                'kabc' => [
                    'name' => 'Test',
                    '_country_resolution_geo_iso' => 'US',
                ],
            ],
        ];
        countryResolutionClearMergedGeometryIso($config);
        $this->assertArrayNotHasKey('_country_resolution_geo_iso', $config['airports']['kabc']);
    }

    public function testCountryResolutionClearMergedGeometryIso_EmptyAirportsNoError(): void
    {
        $config = ['airports' => []];
        countryResolutionClearMergedGeometryIso($config);
        $this->assertSame([], $config['airports']);
    }
}
