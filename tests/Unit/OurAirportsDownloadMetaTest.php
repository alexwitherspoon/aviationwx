<?php

/**
 * Unit tests for OurAirports download meta updates.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/IsolatesOurAirportsCacheTrait.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/ourairports/meta.php';
require_once __DIR__ . '/../../lib/ourairports/probe.php';

class OurAirportsDownloadMetaTest extends TestCase
{
    use IsolatesOurAirportsCacheTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetOurAirportsTestCacheState();
    }

    public function testProbeDoesNotOverwriteFetchEtag(): void
    {
        ourAirportsUpdateFileMeta('airports', [
            'etag' => 'stored-fetch-etag',
            'last_probe_result' => null,
        ]);

        $result = ourAirportsResolveProbeResult(true, 'stored-fetch-etag', 'upstream-new-etag', false);
        $this->assertSame('changed', $result);

        ourAirportsUpdateFileMeta('airports', [
            'last_probe_result' => $result,
            'upstream_etag' => 'upstream-new-etag',
        ]);

        $meta = ourAirportsGetFileMeta('airports');
        $this->assertSame('stored-fetch-etag', $meta['etag']);
        $this->assertSame('upstream-new-etag', $meta['upstream_etag']);
    }

    public function testSuccessfulFetchUpdatesFetchEtag(): void
    {
        ourAirportsUpdateFileMeta('airports', [
            'etag' => 'old',
            'last_probe_result' => 'changed',
            'upstream_etag' => 'new-upstream',
        ]);

        ourAirportsUpdateFileMeta('airports', [
            'etag' => 'new-upstream',
            'upstream_etag' => 'new-upstream',
            'last_probe_result' => 'unchanged',
            'last_fetch_at' => time(),
        ]);

        $meta = ourAirportsGetFileMeta('airports');
        $this->assertSame('new-upstream', $meta['etag']);
        $this->assertSame('unchanged', $meta['last_probe_result']);
    }
}
