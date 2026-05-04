<?php
/**
 * Unit tests for aviation link region mapping and built-in link resolution.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/aviation-region-links.php';

class AviationRegionLinksTest extends TestCase
{
    public function testAviationLinkRegionFromIso_UnitedStates(): void
    {
        $this->assertSame('us', aviationLinkRegionFromIso('US'));
        $this->assertSame('us', aviationLinkRegionFromIso('PR'));
    }

    public function testAviationLinkRegionFromIso_CanadaAustralia(): void
    {
        $this->assertSame('ca', aviationLinkRegionFromIso('CA'));
        $this->assertSame('au', aviationLinkRegionFromIso('AU'));
    }

    public function testAviationLinkRegionFromIso_EuropeAndGb(): void
    {
        $this->assertSame('eu', aviationLinkRegionFromIso('DE'));
        $this->assertSame('eu', aviationLinkRegionFromIso('FR'));
        $this->assertSame('gb', aviationLinkRegionFromIso('GB'));
    }

    public function testAviationLinkRegionFromIso_OtherExamples(): void
    {
        $this->assertSame('nz', aviationLinkRegionFromIso('NZ'));
        $this->assertSame('mx', aviationLinkRegionFromIso('MX'));
        $this->assertSame('br', aviationLinkRegionFromIso('BR'));
        $this->assertSame('jp', aviationLinkRegionFromIso('JP'));
    }

    public function testAviationLinkRegionFromIso_Unknown(): void
    {
        $this->assertSame(AVIATION_LINK_REGION_UNKNOWN, aviationLinkRegionFromIso(null));
        $this->assertSame(AVIATION_LINK_REGION_UNKNOWN, aviationLinkRegionFromIso(''));
        $this->assertSame(AVIATION_LINK_REGION_UNKNOWN, aviationLinkRegionFromIso('ZZ'));
    }

    public function testUsProfileHasNoSkyVector(): void
    {
        $labels = array_column(aviationRegionBuiltinLinkDefinitions('us'), 'label');
        $this->assertContains('AirNav', $labels);
        $this->assertNotContains('SkyVector', $labels);
    }

    public function testCanadaProfileIncludesSkyVector(): void
    {
        $ids = array_column(aviationRegionBuiltinLinkDefinitions('ca'), 'id');
        $this->assertContains('skyvector', $ids);
    }

    public function testResolveUsAirportBuiltins(): void
    {
        $airport = [
            'icao' => 'KSPB',
            'lat' => 45.77,
            'lon' => -122.86,
        ];
        $links = aviationRegionResolveBuiltinExternalLinks($airport, 'us', 'KSPB');
        $labels = array_column($links, 'label');
        $this->assertContains('AirNav', $labels);
        $this->assertContains('FAA Weather', $labels);
        $this->assertContains('ForeFlight', $labels);
    }

    public function testResolveUnknownRegionOnlyOverrides(): void
    {
        $airport = [
            'icao' => 'EGLL',
            'lat' => 51.47,
            'lon' => -0.45,
            'airnav_url' => 'https://example.com/nav',
        ];
        $links = aviationRegionResolveBuiltinExternalLinks($airport, AVIATION_LINK_REGION_UNKNOWN, 'EGLL');
        $this->assertCount(1, $links);
        $this->assertSame('AirNav', $links[0]['label']);
        $this->assertSame('https://example.com/nav', $links[0]['url']);
    }

    public function testResolveUnknownRegionNoOverridesYieldsNoBuiltins(): void
    {
        $airport = [
            'icao' => 'EGLL',
            'lat' => 51.47,
            'lon' => -0.45,
        ];
        $links = aviationRegionResolveBuiltinExternalLinks($airport, AVIATION_LINK_REGION_UNKNOWN, 'EGLL');
        $this->assertSame([], $links);
    }
}
