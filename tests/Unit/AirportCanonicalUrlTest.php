<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/seo.php';

class AirportCanonicalUrlTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'kspb.aviationwx.org';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);
    }

    public function testGetCanonicalUrl_UsesPrimaryIdentifierForAirport(): void
    {
        // Airport config key is 'kspb' with ICAO 'KSPB'
        $this->assertSame('https://kspb.aviationwx.org', getCanonicalUrl('kspb'));
    }

    public function testGetCanonicalUrl_ResolvesConfigKeyToPrimaryIdentifier(): void
    {
        // Airport config key 'pdx' has ICAO 'KPDX'
        $this->assertSame('https://kpdx.aviationwx.org', getCanonicalUrl('pdx'));
    }

    public function testGetCanonicalUrl_GicResolvesToKgic(): void
    {
        // Airport config key 'gic' has ICAO 'KGIC'
        $this->assertSame('https://kgic.aviationwx.org', getCanonicalUrl('gic'));
    }

    public function testGenerateAirportSchema_UrlUsesPrimaryIdentifier(): void
    {
        $airport = [
            'name' => 'Portland International Airport',
            'icao' => 'KPDX',
            'iata' => 'PDX',
            'faa' => 'PDX',
            'address' => 'Portland, Oregon',
            'lat' => 45.589,
            'lon' => -122.595,
        ];
        $schema = generateAirportSchema($airport, 'pdx');
        $this->assertSame('https://kpdx.aviationwx.org', $schema['url']);
    }

    public function testGenerateAirportSchema_GicUsesKgic(): void
    {
        $airport = [
            'name' => 'Gulfport-Biloxi International Airport',
            'icao' => 'KGIC',
        ];
        $schema = generateAirportSchema($airport, 'gic');
        $this->assertSame('https://kgic.aviationwx.org', $schema['url']);
    }
}
