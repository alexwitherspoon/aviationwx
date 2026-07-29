<?php

declare(strict_types=1);

use AviationWX\Notam\Airspace\Adapter\ExampleInternationalAirspaceAdapter;
use AviationWX\Notam\Airspace\UnifiedNotamFetcher;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/airspace/classification.php';
require_once __DIR__ . '/../../lib/notam/airspace/official-links.php';
require_once __DIR__ . '/../../lib/notam/airspace/adapter/ExampleInternationalAirspaceAdapter.php';
require_once __DIR__ . '/../../lib/notam/airspace/UnifiedNotamFetcher.php';
require_once __DIR__ . '/../../lib/notam/map-layer.php';

/**
 * International airspace plugin scaffolding (#249).
 */
final class InternationalAirspacePluginTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['aviationwxAirspaceAdapterPlugins']);
    }

    public function testRestrictionKindFromHints_UsesProviderMetadata(): void
    {
        $this->assertSame('fis_b', notamAirspaceRestrictionKindFromHints(['legal' => 'FIS-B']));
        $this->assertSame('airshow', notamAirspaceRestrictionKindFromHints(['legal' => 'AIR SHOWS/SPORTS']));
        $this->assertSame('security', notamAirspaceRestrictionKindFromHints(['legal' => 'SECURITY']));
        $this->assertSame('tfr', notamAirspaceRestrictionKindFromHints(['restriction_kind' => 'tfr']));
        $this->assertSame('other', notamAirspaceRestrictionKindFromHints(['title' => 'Misc advisory']));
    }

    public function testExampleAdapter_ParsesFixture_ConservativeCapabilities(): void
    {
        $raw = file_get_contents(__DIR__ . '/../Fixtures/notam/example-international-airspace.json');
        $this->assertNotFalse($raw);
        $records = ExampleInternationalAirspaceAdapter::parseResponse($raw);
        $this->assertNotNull($records);
        $this->assertCount(1, $records);
        $this->assertTrue($records[0]['capabilities']['map']);
        $this->assertFalse($records[0]['capabilities']['banner']);
        $this->assertFalse($records[0]['capabilities']['runway_closure']);
        $this->assertSame('example', $records[0]['authority']);
    }

    public function testPluginRegistry_RegistersExampleAdapter(): void
    {
        require_once __DIR__ . '/../../lib/notam/airspace/adapter/ExampleInternationalAirspaceAdapter.php';
        $GLOBALS['aviationwxAirspaceAdapterPlugins'] = [
            ExampleInternationalAirspaceAdapter::SOURCE_TYPE => ExampleInternationalAirspaceAdapter::class,
        ];

        $map = UnifiedNotamFetcher::adapterMap();
        $this->assertArrayHasKey('example_international', $map);

        $raw = file_get_contents(__DIR__ . '/../Fixtures/notam/example-international-airspace.json');
        $this->assertNotFalse($raw);
        $parsed = UnifiedNotamFetcher::parseSource('example_international', $raw);
        $this->assertNotNull($parsed);
        $this->assertNotEmpty($parsed);
    }

    public function testOfficialLink_FaaVsAuthorityUrl(): void
    {
        $faa = notamAirspaceOfficialLinkForRecord([
            'notam_id' => '6/0543',
            'record_sources' => ['faa_tfr_wfs'],
        ]);
        $this->assertStringContainsString('notams.aim.faa.gov', $faa['url']);
        $this->assertStringContainsString('FAA', $faa['label']);

        $intl = notamAirspaceOfficialLinkForRecord([
            'notam_id' => 'B1234/26',
            'authority' => 'example',
            'record_sources' => ['example_international'],
            'official_search_url' => 'https://example.com/notam-search?id=B1234%2F26',
        ]);
        $this->assertSame('https://example.com/notam-search?id=B1234%2F26', $intl['url']);
        $this->assertStringContainsString('authority', strtolower($intl['label']));
    }

    public function testMapFeature_UsesAuthorityLinkLabel(): void
    {
        $raw = file_get_contents(__DIR__ . '/../Fixtures/notam/example-international-airspace.json');
        $this->assertNotFalse($raw);
        $records = ExampleInternationalAirspaceAdapter::parseResponse($raw);
        $this->assertNotNull($records);
        $feature = notamTfrMapLayerFeatureFromAirspaceRecord($records[0], time());
        $this->assertNotNull($feature);
        $this->assertSame(
            'https://example.com/notam-search?id=B1234%2F26',
            $feature['properties']['official_link'] ?? null
        );
        $this->assertSame(
            'Details from issuing authority',
            $feature['properties']['official_link_label'] ?? null
        );
    }

    public function testCoverageScope_MultiAuthorityPartial(): void
    {
        $meta = notamTfrMapLayerResponseMetadata([
            'coverage_sources' => ['example_international'],
            'source_status' => [
                'example_international' => ['ok' => true, 'updated_at' => time()],
            ],
        ]);
        $this->assertSame('multi_authority_partial', $meta['coverage_scope']);
        $this->assertStringContainsString('partial', strtolower($meta['coverage_note']));
    }
}
