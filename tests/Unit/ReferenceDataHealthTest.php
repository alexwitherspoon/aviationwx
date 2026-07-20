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
