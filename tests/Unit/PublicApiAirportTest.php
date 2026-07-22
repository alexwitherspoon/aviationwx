<?php
/**
 * Unit tests for Public API airport endpoint
 *
 * Tests formatAirportDetails and buildResolvedExternalLinks to ensure
 * API response matches dashboard data (access_type, tower_status,
 * partners, custom_links, external_links, iso_country).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/LoadsNasrFrqFixtureCacheTrait.php';

class PublicApiAirportTest extends TestCase
{
    use LoadsNasrFrqFixtureCacheTrait;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../lib/airport-frequencies.php';
        setOurAirportsFrequenciesCacheForTesting([]);
    }

    protected function tearDown(): void
    {
        $this->tearDownNasrFrqFixtureCache();
        require_once __DIR__ . '/../../lib/airport-frequencies.php';
        resetOurAirportsFrequenciesCacheMemo();
        parent::tearDown();
    }

    private static function loadFormatAirportDetails(): void
    {
        static $loaded = false;
        if (!$loaded) {
            require_once __DIR__ . '/../../lib/config.php';
            require_once __DIR__ . '/../../lib/weather/utils.php';
            require_once __DIR__ . '/../../api/v1/airport.php';
            $loaded = true;
        }
    }

    /**
     * Get airport from test fixture
     *
     * @param string $airportId Airport ID (e.g., 'kspb', '03s')
     * @return array|null Airport config or null if not found
     */
    private function getTestAirport(string $airportId): ?array
    {
        $config = loadConfig();
        return $config['airports'][$airportId] ?? null;
    }

    public function testFormatAirportDetails_IncludesIsoCountry(): void
    {
        self::loadFormatAirportDetails();
        $airport = $this->getTestAirport('kspb');
        $this->assertNotNull($airport, 'kspb should exist in test fixture');

        $formatted = formatAirportDetails('kspb', $airport);

        $this->assertArrayHasKey('iso_country', $formatted);
        $this->assertSame('US', $formatted['iso_country']);
    }

    public function testFormatAirportDetails_IncludesAccessTypeAndPermissionRequired(): void
    {
        self::loadFormatAirportDetails();
        $airport = $this->getTestAirport('kspb');
        $this->assertNotNull($airport, 'kspb should exist in test fixture');

        $formatted = formatAirportDetails('kspb', $airport);

        $this->assertArrayHasKey('access_type', $formatted);
        $this->assertSame('public', $formatted['access_type']);
        $this->assertArrayHasKey('permission_required', $formatted);
        $this->assertFalse($formatted['permission_required']);
    }

    public function testFormatAirportDetails_PrivateAirportWithPermissionRequired(): void
    {
        self::loadFormatAirportDetails();
        $airport = $this->getTestAirport('03s');
        $this->assertNotNull($airport, '03s should exist in test fixture');

        $formatted = formatAirportDetails('03s', $airport);

        $this->assertSame('private', $formatted['access_type']);
        $this->assertTrue($formatted['permission_required']);
    }

    public function testFormatAirportDetails_IncludesTowerStatus(): void
    {
        self::loadFormatAirportDetails();
        $airport = $this->getTestAirport('kspb');
        $formatted = formatAirportDetails('kspb', $airport);

        $this->assertArrayHasKey('tower_status', $formatted);
        $this->assertSame('non_towered', $formatted['tower_status']);
    }

    public function testFormatAirportDetails_ToweredAirport(): void
    {
        self::loadFormatAirportDetails();
        $airport = $this->getTestAirport('pdx');
        $formatted = formatAirportDetails('pdx', $airport);

        $this->assertSame('towered', $formatted['tower_status']);
    }

    public function testFormatAirportDetails_IncludesPartners(): void
    {
        self::loadFormatAirportDetails();
        $airport = $this->getTestAirport('kspb');
        $formatted = formatAirportDetails('kspb', $airport);

        $this->assertArrayHasKey('partners', $formatted);
        $this->assertIsArray($formatted['partners']);
        $this->assertCount(2, $formatted['partners']);

        $first = $formatted['partners'][0];
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('url', $first);
        $this->assertArrayHasKey('logo', $first);
        $this->assertArrayHasKey('description', $first);
        $this->assertSame('Test Partner Organization', $first['name']);
        $this->assertSame('https://example.com/partner', $first['url']);

        $second = $formatted['partners'][1];
        $this->assertArrayNotHasKey('logo', $second);
        $this->assertArrayNotHasKey('description', $second);
    }

    public function testFormatAirportDetails_IncludesCustomLinks(): void
    {
        self::loadFormatAirportDetails();
        $airport = $this->getTestAirport('kspb');
        $formatted = formatAirportDetails('kspb', $airport);

        $this->assertArrayHasKey('custom_links', $formatted);
        $this->assertIsArray($formatted['custom_links']);
        $this->assertCount(2, $formatted['custom_links']);
        $this->assertSame('Airport Website', $formatted['custom_links'][0]['label']);
        $this->assertSame('https://example.com/airport', $formatted['custom_links'][0]['url']);
    }

    public function testFormatAirportDetails_IncludesTimezoneDisplay(): void
    {
        self::loadFormatAirportDetails();
        $airport = $this->getTestAirport('kspb');
        $formatted = formatAirportDetails('kspb', $airport);

        $this->assertArrayHasKey('timezone', $formatted);
        $this->assertArrayHasKey('timezone_abbreviation', $formatted);
        $this->assertArrayHasKey('timezone_offset_hours', $formatted);
        $this->assertIsString($formatted['timezone_abbreviation']);
        $this->assertIsInt($formatted['timezone_offset_hours']);
        $this->assertContains($formatted['timezone_abbreviation'], ['PST', 'PDT'], 'America/Los_Angeles must return PST or PDT');
    }

    public function testFormatAirportDetails_IncludesExternalLinks(): void
    {
        self::loadFormatAirportDetails();
        $airport = $this->getTestAirport('kspb');
        $formatted = formatAirportDetails('kspb', $airport);

        $this->assertArrayHasKey('external_links', $formatted);
        $this->assertIsArray($formatted['external_links']);

        $labels = array_column($formatted['external_links'], 'label');
        $this->assertContains('AirNav', $labels);
        $this->assertContains('FAA Weather', $labels);
        $this->assertContains('ForeFlight', $labels);

        $airnav = null;
        foreach ($formatted['external_links'] as $link) {
            if ($link['label'] === 'AirNav') {
                $airnav = $link;
                break;
            }
        }
        $this->assertNotNull($airnav);
        $this->assertSame('https://www.airnav.com/airport/KSPB', $airnav['url']);
    }

    public function testFormatAirportDetails_CanadianAirportIncludesRegionalWeatherLink(): void
    {
        self::loadFormatAirportDetails();
        $airport = $this->getTestAirport('cyav');
        $formatted = formatAirportDetails('cyav', $airport);

        $labels = array_column($formatted['external_links'], 'label');
        $this->assertContains('NAV Canada Weather', $labels);
        $this->assertContains('SkyVector', $labels);
        $this->assertContains('ForeFlight', $labels);
    }

    public function testFormatAirportDetails_AirportWithoutPartnersReturnsEmptyArray(): void
    {
        self::loadFormatAirportDetails();
        $airport = $this->getTestAirport('pdx');
        $formatted = formatAirportDetails('pdx', $airport);

        $this->assertArrayHasKey('partners', $formatted);
        $this->assertSame([], $formatted['partners']);
    }

    public function testFormatAirportDetails_AirportWithoutCustomLinksReturnsEmptyArray(): void
    {
        self::loadFormatAirportDetails();
        $airport = $this->getTestAirport('pdx');
        $formatted = formatAirportDetails('pdx', $airport);

        $this->assertArrayHasKey('custom_links', $formatted);
        $this->assertSame([], $formatted['custom_links']);
    }

    public function testFormatAirportDetails_ServicesAndFrequenciesReturnObjectWhenEmpty(): void
    {
        self::loadFormatAirportDetails();
        $airport = $this->getTestAirport('pdx');
        $formatted = formatAirportDetails('pdx', $airport);

        $this->assertArrayHasKey('services', $formatted);
        $this->assertIsObject($formatted['services']);
        $this->assertEmpty((array) $formatted['services']);

        $this->assertArrayHasKey('frequencies', $formatted);
        $this->assertIsObject($formatted['frequencies']);
        $this->assertEmpty((array) $formatted['frequencies']);
    }

    public function testFormatAirportDetails_ServicesAndFrequenciesReturnObjectWhenPopulated(): void
    {
        self::loadFormatAirportDetails();
        $airport = $this->getTestAirport('kspb');
        $formatted = formatAirportDetails('kspb', $airport);

        $this->assertArrayHasKey('services', $formatted);
        $this->assertIsArray($formatted['services']);
        $this->assertArrayHasKey('fuel', $formatted['services']);
        $this->assertSame('100LL, Jet-A', $formatted['services']['fuel']);
    }

    public function testFormatAirportDetails_MergedFrequenciesFromNasrWhenConfigMissing(): void
    {
        self::loadFormatAirportDetails();
        $this->loadNasrFrqFixtureCache();

        $formatted = formatAirportDetails('69v', [
            'faa' => '69V',
            'name' => 'Huntington Muni',
        ]);

        $this->assertIsArray($formatted['frequencies']);
        $this->assertSame(['ctaf' => '122.8'], $formatted['frequencies']);
    }

    public function testFormatAirportDetails_MergedFrequenciesPreferNasrOverOurAirports(): void
    {
        self::loadFormatAirportDetails();
        $this->loadNasrFrqFixtureCache();

        setOurAirportsFrequenciesCacheForTesting([
            'CYVR' => [
                'tower' => '119.0',
                'ground' => '121.0',
                'atis' => '127.0',
            ],
        ]);

        $formatted = formatAirportDetails('cyvr', [
            'icao' => 'CYVR',
            'name' => 'Vancouver Intl',
        ]);

        $this->assertSame([
            'tower' => '118.7',
            'ground' => '121.7',
            'atis' => '124.6',
        ], $formatted['frequencies']);
    }

    public function testFormatAirportDetails_ConfigFrequencyOverridesNasrPerField(): void
    {
        self::loadFormatAirportDetails();
        $this->loadNasrFrqFixtureCache();

        $formatted = formatAirportDetails('69v', [
            'faa' => '69V',
            'name' => 'Huntington Muni',
            'frequencies' => [
                'ctaf' => '123.0',
            ],
        ]);

        $this->assertSame('123', $formatted['frequencies']['ctaf']);
        $this->assertArrayNotHasKey('unicom', $formatted['frequencies']);
    }
}
