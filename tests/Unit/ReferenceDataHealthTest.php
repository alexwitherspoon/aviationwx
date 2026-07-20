<?php

/**
 * Unit tests for reference catalog health model.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/IsolatesOurAirportsCacheTrait.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/ourairports/meta.php';
require_once __DIR__ . '/../../lib/reference-data-health.php';
require_once __DIR__ . '/../../lib/reference-data-sources.php';

class ReferenceDataHealthTest extends TestCase
{
    use IsolatesOurAirportsCacheTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetOurAirportsTestCacheState();
    }

    public function testStatusWorstPicksDownOverDegraded(): void
    {
        $this->assertSame('down', reference_data_status_worst('degraded', 'down'));
        $this->assertSame('degraded', reference_data_status_worst('operational', 'degraded'));
    }

    public function testRollupStatusUsesWorstChild(): void
    {
        $status = reference_data_rollup_status([
            ['status' => 'operational'],
            ['status' => 'degraded'],
            ['status' => 'operational'],
        ]);

        $this->assertSame('degraded', $status);
    }

    public function testRunwayGeometryConsumerIncludesThreeSources(): void
    {
        $component = reference_data_health_build(null, null);
        $runwayGeometry = null;
        foreach ($component['consumers'] as $consumer) {
            if (($consumer['slug'] ?? '') === 'runway_geometry') {
                $runwayGeometry = $consumer;
                break;
            }
        }

        $this->assertIsArray($runwayGeometry);
        $slugs = array_column($runwayGeometry['sources'], 'slug');
        $this->assertContains('runways_merged', $slugs);
        $this->assertContains('faa_ngda_runways', $slugs);
        $this->assertContains('ourairports_runways', $slugs);
    }

    public function testRunwayPerformanceConsumer_IncludesAllPrecedenceSources(): void
    {
        $component = reference_data_health_build(null, null);
        $consumer = null;
        foreach ($component['consumers'] as $row) {
            if (($row['slug'] ?? '') === 'runway_performance') {
                $consumer = $row;
                break;
            }
        }

        $this->assertIsArray($consumer);
        $slugs = array_column($consumer['sources'], 'slug');
        $this->assertContains('nasr_apt', $slugs);
        $this->assertContains('ourairports_runways', $slugs);
        $this->assertContains('airports_config', $slugs);
    }

    public function testAirportIdentityAndComms_IncludeConfigSource(): void
    {
        $component = reference_data_health_build(null, null);
        $bySlug = [];
        foreach ($component['consumers'] as $consumer) {
            $bySlug[$consumer['slug'] ?? ''] = $consumer;
        }

        $this->assertArrayHasKey('airport_identity', $bySlug);
        $this->assertArrayHasKey('airport_comms', $bySlug);
        $this->assertContains('airports_config', array_column($bySlug['airport_identity']['sources'], 'slug'));
        $this->assertContains('airports_config', array_column($bySlug['airport_comms']['sources'], 'slug'));
    }

    public function testConfigSource_ReportsOverrideCounts(): void
    {
        $config = [
            'airports' => [
                'ktest' => [
                    'icao' => 'KTEST',
                    'runway_length_ft' => 3200,
                    'frequencies' => ['ctaf' => '122.8'],
                ],
            ],
        ];

        $leaf = reference_data_config_source_health($config, 'abc123');

        $this->assertSame('config', $leaf['kind']);
        $this->assertSame('operational', $leaf['status']);
        $this->assertSame(1, $leaf['details']['runway_override_count'] ?? null);
        $this->assertSame(1, $leaf['details']['frequencies_override_count'] ?? null);
    }

    public function testMissingRunwaysMergedMarksConsumerDown(): void
    {
        $component = reference_data_health_build(null, null);

        $this->assertSame('down', $component['status']);
        $this->assertStringContainsString('down', $component['message']);
    }

    public function testOurAirportsBulkLastChangedUsesProbeTimestamp(): void
    {
        file_put_contents(CACHE_OURAIRPORTS_RUNWAYS_CSV, "id,airport_ident\n1,TEST\n", LOCK_EX);
        touch(CACHE_OURAIRPORTS_RUNWAYS_CSV, time() - 7200);

        ourAirportsUpdateFileMeta('runways', [
            'last_probe_at' => time() - 60,
        ]);

        $leaf = reference_data_ourairports_bulk_source_health(
            'runways',
            'ourairports_runways',
            'OurAirports runways'
        );

        $this->assertGreaterThan((int) filemtime(CACHE_OURAIRPORTS_RUNWAYS_CSV), $leaf['lastChanged']);
    }

    public function testPublicSerializerUsesSnakeCase(): void
    {
        $component = reference_data_health_build(null, null);
        $public = reference_data_health_to_public($component);

        $this->assertArrayHasKey('last_changed', $public);
        $this->assertArrayNotHasKey('lastChanged', $public);
        $this->assertIsArray($public['consumers']);
        $this->assertNotEmpty($public['consumers']);
        $this->assertArrayHasKey('last_changed', $public['consumers'][0]);
        $this->assertArrayHasKey('sources', $public['consumers'][0]);
        $this->assertArrayHasKey('local_age_seconds', $public['consumers'][0]['sources'][0]);
        $this->assertArrayHasKey('needs_fetch', $public['consumers'][0]['sources'][0]);
    }

    public function testPublicSerializerPreservesNullLocalAgeSeconds(): void
    {
        $public = reference_data_health_to_public([
            'consumers' => [[
                'slug' => 'test',
                'name' => 'Test',
                'status' => 'down',
                'message' => 'missing',
                'lastChanged' => 0,
                'sources' => [[
                    'slug' => 'missing',
                    'name' => 'Missing',
                    'kind' => 'bulk',
                    'status' => 'down',
                    'message' => 'CSV missing',
                    'details' => [
                        'local_age_seconds' => null,
                        'needs_fetch' => true,
                    ],
                ]],
            ]],
        ]);

        $this->assertArrayHasKey('local_age_seconds', $public['consumers'][0]['sources'][0]);
        $this->assertNull($public['consumers'][0]['sources'][0]['local_age_seconds']);
    }

    public function testCheckSystemHealthExposesReferenceDataComponent(): void
    {
        require_once __DIR__ . '/../../lib/status-checks.php';

        $health = checkSystemHealth();

        $this->assertArrayHasKey('reference_data', $health['components']);
        $this->assertArrayNotHasKey('runway_cache', $health['components']);
        $this->assertArrayNotHasKey('nasr_apt_cache', $health['components']);
        $this->assertArrayNotHasKey('airport_country_resolution', $health['components']);
        $this->assertArrayNotHasKey('magnetic_declination', $health['components']);
        $this->assertArrayHasKey('consumers', $health['components']['reference_data']);
    }
}
