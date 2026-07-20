<?php

/**
 * Unit tests for OurAirports refresh policy helpers.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/IsolatesOurAirportsCacheTrait.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/ourairports/meta.php';
require_once __DIR__ . '/../../lib/ourairports/refresh.php';

class OurAirportsRefreshTest extends TestCase
{
    use IsolatesOurAirportsCacheTrait;

    private mixed $bulkLockHandle = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetOurAirportsTestCacheState();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->bulkLockHandle)) {
            @flock($this->bulkLockHandle, LOCK_UN);
            fclose($this->bulkLockHandle);
            $this->bulkLockHandle = null;
        }

        parent::tearDown();
    }

    public function testOurAirportsFileNeedsFetchWhenMissing(): void
    {
        $this->assertTrue(ourAirportsFileNeedsFetch('airports'));
    }

    public function testOurAirportsFileNeedsFetchWhenProbeChanged(): void
    {
        file_put_contents(CACHE_OURAIRPORTS_RUNWAYS_CSV, "id,airport_ident\n", LOCK_EX);
        touch(CACHE_OURAIRPORTS_RUNWAYS_CSV, time());

        ourAirportsUpdateFileMeta('runways', [
            'last_probe_result' => 'changed',
        ]);

        $this->assertTrue(ourAirportsFileNeedsFetch('runways'));
        $this->assertTrue(ourAirportsFileBlocksRunwayMerge('runways'));
    }

    public function testOurAirportsFileNeedsFetchWhenProbeErrors(): void
    {
        file_put_contents(CACHE_OURAIRPORTS_RUNWAYS_CSV, "id,airport_ident\n", LOCK_EX);
        touch(CACHE_OURAIRPORTS_RUNWAYS_CSV, time());

        ourAirportsUpdateFileMeta('runways', [
            'last_probe_result' => 'error',
        ]);

        $this->assertTrue(ourAirportsFileNeedsFetch('runways'));
        $this->assertFalse(ourAirportsFileBlocksRunwayMerge('runways'));
    }

    public function testOurAirportsBulkNeedsFetchWhenAnyFileDue(): void
    {
        ourAirportsUpdateFileMeta('airport_frequencies', [
            'last_probe_result' => 'changed',
        ]);

        $this->assertTrue(ourAirportsBulkNeedsFetch());
    }

    public function testFaaNgdaRunwayCsvNeedsRefreshWhenMissing(): void
    {
        $this->assertTrue(faaNgdaRunwayCsvNeedsRefresh());
    }

    public function testFaaNgdaRunwayCsvBodyRejectsInvalidPayload(): void
    {
        $this->assertFalse(faaNgdaRunwayCsvBodyIsValid('<html><body>error</body></html>'));
        $this->assertFalse(faaNgdaRunwayCsvBodyIsValid(''));
        $this->assertTrue(faaNgdaRunwayCsvBodyIsValid("ARPT_ID,RWY_ID\nKTEST,18/36\n"));
    }

    public function testFaaNgdaOverdueRefreshRespectsFetchBackoff(): void
    {
        file_put_contents(CACHE_RUNWAYS_DATA_FILE, '{}', LOCK_EX);
        touch(CACHE_RUNWAYS_DATA_FILE, time());

        file_put_contents(CACHE_FAA_NGDA_RUNWAYS_CSV, 'ARPT_ID', LOCK_EX);
        touch(CACHE_FAA_NGDA_RUNWAYS_CSV, time() - FAA_NGDA_RUNWAY_REFRESH_MAX_AGE - 60);

        faaNgdaRecordFetchAttempt(false);

        $this->assertTrue(faaNgdaRunwayCsvNeedsRefresh());
        $this->assertFalse(faaNgdaOverdueRefreshShouldTriggerMerge());
        $this->assertFalse(runwaysCacheNeedsRefresh());
    }

    public function testFaaNgdaOverdueRefreshRetriesAfterBackoffExpires(): void
    {
        file_put_contents(CACHE_RUNWAYS_DATA_FILE, '{}', LOCK_EX);
        touch(CACHE_RUNWAYS_DATA_FILE, time());

        file_put_contents(CACHE_FAA_NGDA_RUNWAYS_CSV, 'ARPT_ID', LOCK_EX);
        touch(CACHE_FAA_NGDA_RUNWAYS_CSV, time() - FAA_NGDA_RUNWAY_REFRESH_MAX_AGE - 60);

        $meta = ourAirportsLoadMeta();
        $meta['faa_ngda'] = [
            'last_fetch_attempt_at' => time() - FAA_NGDA_FETCH_RETRY_INTERVAL - 60,
            'last_fetch_error' => 'download_failed',
        ];
        ourAirportsSaveMeta($meta);

        $this->assertTrue(faaNgdaOverdueRefreshShouldTriggerMerge());
        $this->assertTrue(runwaysCacheNeedsRefresh());
    }

    public function testRunwaySourcesProbeChangedIgnoresFrequenciesAndErrors(): void
    {
        ourAirportsUpdateFileMeta('runways', [
            'last_probe_result' => 'unchanged',
        ]);
        ourAirportsUpdateFileMeta('airports', [
            'last_probe_result' => 'error',
        ]);
        ourAirportsUpdateFileMeta('airport_frequencies', [
            'last_probe_result' => 'changed',
        ]);

        $this->assertFalse(ourAirportsRunwaySourcesProbeChanged());

        ourAirportsUpdateFileMeta('runways', [
            'last_probe_result' => 'changed',
        ]);

        $this->assertTrue(ourAirportsRunwaySourcesProbeChanged());
    }

    public function testRunwaySourcesProbeNeedsActionIncludesErrors(): void
    {
        ourAirportsUpdateFileMeta('airports', [
            'last_probe_result' => 'error',
        ]);
        ourAirportsUpdateFileMeta('runways', [
            'last_probe_result' => 'unchanged',
        ]);

        $this->assertTrue(ourAirportsRunwaySourcesProbeNeedsAction());
        $this->assertTrue(runwaysCacheNeedsRefresh());
    }

    public function testRunwaySourcesNewerThanMergeTriggersRefresh(): void
    {
        file_put_contents(CACHE_RUNWAYS_DATA_FILE, '{}', LOCK_EX);
        touch(CACHE_RUNWAYS_DATA_FILE, time() - 3600);

        file_put_contents(CACHE_OURAIRPORTS_RUNWAYS_CSV, "id,airport_ident\n", LOCK_EX);
        touch(CACHE_OURAIRPORTS_RUNWAYS_CSV, time());

        $this->assertTrue(ourAirportsRunwaySourcesNewerThanMerge());
        $this->assertTrue(runwaysCacheNeedsRefresh());
    }

    public function testFaaCsvNewerThanMergeTriggersRefresh(): void
    {
        file_put_contents(CACHE_RUNWAYS_DATA_FILE, '{}', LOCK_EX);
        touch(CACHE_RUNWAYS_DATA_FILE, time() - 3600);

        file_put_contents(CACHE_FAA_NGDA_RUNWAYS_CSV, 'ARPT_ID', LOCK_EX);
        touch(CACHE_FAA_NGDA_RUNWAYS_CSV, time());

        $this->assertTrue(faaNgdaRunwayCsvNewerThanMerge());
        $this->assertTrue(runwaysCacheNeedsRefresh());
    }

    public function testMergeWorkerWaitsForBulkCsvsOnColdStart(): void
    {
        $this->assertTrue(runwaysCacheNeedsRefresh());
        $this->assertFalse(runwaysMergeWorkerShouldRun());
        $this->assertSame(
            'waiting for OurAirports runway CSV inputs',
            runwaysMergeWaitingReason()
        );
    }

    public function testMergeWorkerRunsWhenInputsReadyAndCacheMissing(): void
    {
        file_put_contents(CACHE_OURAIRPORTS_AIRPORTS_CSV, "id,ident\n", LOCK_EX);
        file_put_contents(CACHE_OURAIRPORTS_RUNWAYS_CSV, "id,airport_ident\n", LOCK_EX);
        touch(CACHE_OURAIRPORTS_AIRPORTS_CSV, time());
        touch(CACHE_OURAIRPORTS_RUNWAYS_CSV, time());

        ourAirportsUpdateFileMeta('airports', [
            'last_probe_result' => 'unchanged',
            'last_fetch_at' => time(),
        ]);
        ourAirportsUpdateFileMeta('runways', [
            'last_probe_result' => 'unchanged',
            'last_fetch_at' => time(),
        ]);

        $this->assertTrue(runwaysMergeWorkerShouldRun());
    }

    public function testMergeWorkerRunsWhenProbeErrorsButCsvIsCurrent(): void
    {
        file_put_contents(CACHE_RUNWAYS_DATA_FILE, '{}', LOCK_EX);
        touch(CACHE_RUNWAYS_DATA_FILE, time() - 3600);
        file_put_contents(CACHE_OURAIRPORTS_AIRPORTS_CSV, "id,ident\n", LOCK_EX);
        file_put_contents(CACHE_OURAIRPORTS_RUNWAYS_CSV, "id,airport_ident\n", LOCK_EX);
        touch(CACHE_OURAIRPORTS_AIRPORTS_CSV, time() - 120);
        touch(CACHE_OURAIRPORTS_RUNWAYS_CSV, time() - 120);

        ourAirportsUpdateFileMeta('airports', [
            'last_probe_result' => 'error',
        ]);
        ourAirportsUpdateFileMeta('runways', [
            'last_probe_result' => 'error',
        ]);

        file_put_contents(CACHE_FAA_NGDA_RUNWAYS_CSV, 'ARPT_ID', LOCK_EX);
        touch(CACHE_FAA_NGDA_RUNWAYS_CSV, time());

        $this->assertTrue(runwaysCacheNeedsRefresh());
        $this->assertTrue(ourAirportsRunwayMergeInputsCurrent());
        $this->assertTrue(runwaysMergeWorkerShouldRun());
    }

    public function testMergeWorkerWaitsWhileBulkFetchPendingForRunwayCsvs(): void
    {
        file_put_contents(CACHE_RUNWAYS_DATA_FILE, '{}', LOCK_EX);
        file_put_contents(CACHE_OURAIRPORTS_AIRPORTS_CSV, "id,ident\n", LOCK_EX);
        file_put_contents(CACHE_OURAIRPORTS_RUNWAYS_CSV, "id,airport_ident\n", LOCK_EX);

        ourAirportsUpdateFileMeta('runways', [
            'last_probe_result' => 'changed',
        ]);

        $this->assertTrue(runwaysCacheNeedsRefresh());
        $this->assertFalse(ourAirportsRunwayMergeInputsCurrent());
        $this->assertFalse(runwaysMergeWorkerShouldRun());
        $this->assertSame(
            'waiting for OurAirports bulk fetch on runways',
            runwaysMergeWaitingReason()
        );
    }

    public function testMergeWorkerRunsForFaaOnlyRefreshWithoutPendingBulk(): void
    {
        file_put_contents(CACHE_RUNWAYS_DATA_FILE, '{}', LOCK_EX);
        touch(CACHE_RUNWAYS_DATA_FILE, time() - 3600);
        file_put_contents(CACHE_OURAIRPORTS_AIRPORTS_CSV, "id,ident\n", LOCK_EX);
        file_put_contents(CACHE_OURAIRPORTS_RUNWAYS_CSV, "id,airport_ident\n", LOCK_EX);
        touch(CACHE_OURAIRPORTS_AIRPORTS_CSV, time() - 7200);
        touch(CACHE_OURAIRPORTS_RUNWAYS_CSV, time() - 7200);

        ourAirportsUpdateFileMeta('airports', [
            'last_probe_result' => 'unchanged',
        ]);
        ourAirportsUpdateFileMeta('runways', [
            'last_probe_result' => 'unchanged',
        ]);

        file_put_contents(CACHE_FAA_NGDA_RUNWAYS_CSV, 'ARPT_ID', LOCK_EX);
        touch(CACHE_FAA_NGDA_RUNWAYS_CSV, time());

        $this->assertTrue(runwaysCacheNeedsRefresh());
        $this->assertFalse(ourAirportsRunwayMergeDependsOnBulkFetch());
        $this->assertTrue(runwaysMergeWorkerShouldRun());
    }

    public function testBulkWorkerShouldNotRunWhileLockHeld(): void
    {
        ourAirportsUpdateFileMeta('airports', [
            'last_probe_result' => 'changed',
        ]);

        $this->assertTrue(ourAirportsBulkNeedsFetch());

        $this->bulkLockHandle = fopen(CACHE_OURAIRPORTS_BULK_LOCK, 'c+');
        $this->assertIsResource($this->bulkLockHandle);
        $this->assertTrue(flock($this->bulkLockHandle, LOCK_EX | LOCK_NB));

        $this->assertTrue(ourAirportsBulkFetchInProgress());
        $this->assertFalse(ourAirportsBulkWorkerShouldRun());
    }

    public function testProbeWorkerSkipsWhenBulkFetchInProgress(): void
    {
        $this->bulkLockHandle = fopen(CACHE_OURAIRPORTS_BULK_LOCK, 'c+');
        $this->assertIsResource($this->bulkLockHandle);
        $this->assertTrue(flock($this->bulkLockHandle, LOCK_EX | LOCK_NB));

        $this->assertFalse(ourAirportsProbeWorkerShouldRun());
    }
}
