<?php

/**
 * Unit tests for OurAirports identity ingest from disk.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/IsolatesOurAirportsCacheTrait.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/ourairports/ingest-airports.php';

class OurAirportsIngestTest extends TestCase
{
    use IsolatesOurAirportsCacheTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetOurAirportsTestCacheState();
    }

    public function testIngestOurAirportsIdentityFromDiskWritesCodes(): void
    {
        $fixture = file_get_contents(__DIR__ . '/../Fixtures/ourairports/airports.csv');
        $this->assertNotFalse($fixture);
        file_put_contents(CACHE_OURAIRPORTS_AIRPORTS_CSV, $fixture, LOCK_EX);

        $this->assertTrue(ingestOurAirportsIdentityFromDisk());

        $decoded = json_decode((string) file_get_contents(CACHE_OURAIRPORTS_FILE), true);
        $this->assertIsArray($decoded);
        $this->assertContains('KPDX', $decoded['icao']);
        $this->assertContains('PDX', $decoded['iata']);
        $this->assertContains('HIO', $decoded['faa']);
    }

    public function testIdentityCacheStaleWhenCsvIsNewer(): void
    {
        $fixture = file_get_contents(__DIR__ . '/../Fixtures/ourairports/airports.csv');
        $this->assertNotFalse($fixture);
        file_put_contents(CACHE_OURAIRPORTS_AIRPORTS_CSV, $fixture, LOCK_EX);
        touch(CACHE_OURAIRPORTS_AIRPORTS_CSV, time());

        file_put_contents(CACHE_OURAIRPORTS_FILE, '{"icao":[],"iata":[],"faa":[]}', LOCK_EX);
        touch(CACHE_OURAIRPORTS_FILE, time() - 3600);

        $this->assertTrue(ourAirportsIdentityCacheIsStale());
    }
}
