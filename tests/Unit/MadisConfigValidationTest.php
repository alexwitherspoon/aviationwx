<?php
/**
 * Unit Tests for MADIS HFMETAR config validation
 *
 * Operator policy: MADIS is only used when the ASOS is co-located with the
 * airfield, so a madis_hfmetar source's station_id must match the airport
 * icao, and airports without an icao cannot use it at all.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/weather/utils.php';

class MadisConfigValidationTest extends TestCase
{
    private function airportConfig(array $weatherSources, ?string $icao = 'KSPB'): array
    {
        $airport = [
            'name' => 'Test Airport',
            'lat' => 45.0,
            'lon' => -122.0,
            'access_type' => 'public',
            'tower_status' => 'non_towered',
            'weather_sources' => $weatherSources,
        ];
        if ($icao !== null) {
            $airport['icao'] = $icao;
        }

        return ['airports' => ['test' => $airport]];
    }

    /**
     * Co-located station (matches airport icao) validates
     */
    public function testMadisSource_CoLocatedStation_Valid(): void
    {
        $config = $this->airportConfig([['type' => 'madis_hfmetar', 'station_id' => 'KSPB']]);
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'co-located MADIS source should validate');
    }

    /**
     * Lowercase station_id normalizes to the airport icao
     */
    public function testMadisSource_LowercaseStationId_Valid(): void
    {
        $config = $this->airportConfig([['type' => 'madis_hfmetar', 'station_id' => 'kspb']]);
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'lowercase station_id should normalize to airport icao');
    }

    /**
     * Off-field station is rejected (co-located ASOS only policy)
     */
    public function testMadisSource_OffFieldStation_Rejected(): void
    {
        $config = $this->airportConfig([['type' => 'madis_hfmetar', 'station_id' => 'KUAO']]);
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'off-field MADIS station must be rejected');
        $joined = implode(' ', $result['errors']);
        $this->assertStringContainsString('must match airport icao', $joined);
    }

    /**
     * Airport without an icao cannot use MADIS (stations are ICAO-keyed)
     */
    public function testMadisSource_NoAirportIcao_Rejected(): void
    {
        $config = $this->airportConfig([['type' => 'madis_hfmetar', 'station_id' => 'KSPB']], null);
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'MADIS source without airport icao must be rejected');
        $joined = implode(' ', $result['errors']);
        $this->assertStringContainsString("requires airport 'icao'", $joined);
    }

    /**
     * Missing station_id is rejected
     */
    public function testMadisSource_MissingStationId_Rejected(): void
    {
        $config = $this->airportConfig([['type' => 'madis_hfmetar']]);
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'MADIS source without station_id must be rejected');
        $joined = implode(' ', $result['errors']);
        $this->assertStringContainsString("missing 'station_id'", $joined);
    }

    /**
     * Source display name and provider info resolve for the status page
     */
    public function testMadisSource_DisplayName(): void
    {
        $info = getWeatherSourceInfo('madis_hfmetar');
        $this->assertNotNull($info);
        $this->assertSame('MADIS HFMETAR', $info['name']);
        $this->assertSame(
            'MADIS HFMETAR (KSPB)',
            getWeatherSourceDisplayName('madis_hfmetar', 'kspb')
        );
    }

    /**
     * Co-located Federal ASOS defaults to the faa operator like METAR
     */
    public function testMadisSource_DefaultOperator_IsFaa(): void
    {
        require_once __DIR__ . '/../../lib/operator.php';
        $this->assertSame('faa', defaultOperatorForWeatherSourceType('madis_hfmetar'));
    }

    /**
     * Rate-limit identity uses station_id so airports share no per-source bucket
     */
    public function testMadisSource_RateLimitIdentity_IncludesStationId(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';
        $this->assertContains('station_id', upstreamRateLimitIdentityFieldNames('madis_hfmetar'));
    }
}
