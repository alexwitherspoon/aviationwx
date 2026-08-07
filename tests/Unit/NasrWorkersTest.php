<?php

/**
 * Unit tests for NASR worker scheduling helpers.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/nasr/workers.php';

class NasrWorkersTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $backupFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([CACHE_NASR_APT_DATA_FILE, CACHE_NASR_FRQ_DATA_FILE, CACHE_NASR_APT_META_FILE] as $path) {
            $this->backupFiles[$path] = file_exists($path) ? (string) file_get_contents($path) : null;
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        resetNasrAptCacheMemo();
        resetNasrFrqCacheMemo();
    }

    protected function tearDown(): void
    {
        foreach ($this->backupFiles as $path => $contents) {
            if ($contents !== null) {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                file_put_contents($path, $contents);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
        resetNasrAptCacheMemo();
        resetNasrFrqCacheMemo();
        parent::tearDown();
    }

    public function testFrqWorkerWaitsForAptFetchLock(): void
    {
        $this->writeMinimalAptCache();

        $lockPath = getNasrAptFetchLockPath();
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fp = fopen($lockPath, 'c+');
        $this->assertIsResource($fp);
        $this->assertTrue(flock($fp, LOCK_EX | LOCK_NB));

        try {
            $this->assertFalse(nasrFrqWorkerShouldRun());
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function testFrqWorkerWaitsUntilAptCachePresent(): void
    {
        $this->assertFalse(nasrAptCacheDataPresent());
        $this->assertFalse(nasrFrqWorkerShouldRun());
        $this->assertFalse(nasrFrqSchedulerShouldEnqueue(1_700_000_000, 0));
    }

    public function testAptWorkerSkipsWhenLockHeld(): void
    {
        $lockPath = getNasrAptFetchLockPath();
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fp = fopen($lockPath, 'c+');
        $this->assertIsResource($fp);
        $this->assertTrue(flock($fp, LOCK_EX | LOCK_NB));

        try {
            $this->assertFalse(nasrAptWorkerShouldRun());
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function testMissingCache_UsesShortRetryInterval(): void
    {
        $this->assertFalse(nasrAptCacheDataPresent());
        $this->assertFalse(nasrFrqCacheDataPresent());
        $this->assertSame(NASR_MISSING_RETRY_INTERVAL, nasrAptSchedulerRetryInterval());
        $this->assertSame(NASR_MISSING_RETRY_INTERVAL, nasrFrqSchedulerRetryInterval());
    }

    public function testPresentCache_UsesWeeklyRetryInterval(): void
    {
        $this->writeMinimalAptCache();
        $this->writeMinimalFrqCache();

        $this->assertTrue(nasrAptCacheDataPresent());
        $this->assertTrue(nasrFrqCacheDataPresent());
        $this->assertSame(NASR_FETCH_CHECK_INTERVAL, nasrAptSchedulerRetryInterval());
        $this->assertSame(NASR_FETCH_CHECK_INTERVAL, nasrFrqSchedulerRetryInterval());
    }

    public function testMissingCache_ColdStartEnqueuesImmediately(): void
    {
        $now = 1_700_000_000;
        $this->assertTrue(nasrAptSchedulerShouldEnqueue($now, 0));
        $this->assertFalse(nasrFrqSchedulerShouldEnqueue($now, 0));
    }

    public function testMissingCache_RespectsShortBackoffAfterFailedAttempt(): void
    {
        $now = 1_700_000_000;
        $lastAttempt = $now - (NASR_MISSING_RETRY_INTERVAL - 1);

        $this->assertFalse(nasrAptSchedulerShouldEnqueue($now, $lastAttempt));
        $this->assertFalse(nasrFrqSchedulerShouldEnqueue($now, $lastAttempt));
    }

    public function testMissingCache_RetriesAfterShortBackoff(): void
    {
        $now = 1_700_000_000;
        $lastAttempt = $now - NASR_MISSING_RETRY_INTERVAL;

        $this->assertTrue(nasrAptSchedulerShouldEnqueue($now, $lastAttempt));
        $this->assertFalse(
            nasrFrqSchedulerShouldEnqueue($now, $lastAttempt),
            'FRQ stays gated until APT JSON exists'
        );

        $this->writeMinimalAptCache();
        $this->assertTrue(nasrFrqSchedulerShouldEnqueue($now, $lastAttempt));
    }

    public function testPresentFreshCache_DoesNotEnqueue(): void
    {
        $this->writeMinimalAptCache();
        $this->writeMinimalFrqCache();
        $this->writeFreshMeta();

        $now = time();
        $this->assertFalse(nasrAptCacheNeedsRefresh());
        $this->assertFalse(nasrFrqCacheNeedsRefresh());
        $this->assertFalse(nasrAptSchedulerShouldEnqueue($now, 0));
        $this->assertFalse(nasrFrqSchedulerShouldEnqueue($now, 0));
    }

    public function testPresentStaleCache_UsesWeeklyGateNotMissingRetry(): void
    {
        $this->writeMinimalAptCache();
        $this->writeMinimalFrqCache();
        // Old mtime forces APT age refresh; FRQ uses meta frq_fetched_at
        touch(CACHE_NASR_APT_DATA_FILE, time() - NASR_CACHE_MAX_AGE - 10);
        $this->writeStaleFrqMeta();

        $this->assertTrue(nasrAptCacheDataPresent());
        $this->assertTrue(nasrAptCacheNeedsRefresh());
        $this->assertSame(NASR_FETCH_CHECK_INTERVAL, nasrAptSchedulerRetryInterval());

        $now = 1_700_000_000;
        $this->assertFalse(
            nasrAptSchedulerShouldEnqueue($now, $now - NASR_MISSING_RETRY_INTERVAL),
            'Present-but-stale must not use the missing-cache short retry'
        );
        $this->assertTrue(
            nasrAptSchedulerShouldEnqueue($now, $now - NASR_FETCH_CHECK_INTERVAL),
            'Present-but-stale should enqueue after the weekly gate'
        );
    }

    public function testFormerWeeklyHole_NoLongerBlocksMissingCache(): void
    {
        // Regression: stamping lastAttempt at startup used to block retries for a full week
        // while the cache file was still missing.
        $now = 1_700_000_000;
        $lastAttempt = $now - (60 * 60); // one hour ago (failed/aborted cold start)

        $this->assertLessThan(NASR_FETCH_CHECK_INTERVAL, 60 * 60);
        $this->assertTrue(nasrAptSchedulerShouldEnqueue($now, $lastAttempt));
        $this->assertFalse(nasrFrqSchedulerShouldEnqueue($now, $lastAttempt));
    }

    private function writeMinimalAptCache(): void
    {
        $dir = dirname(CACHE_NASR_APT_DATA_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(CACHE_NASR_APT_DATA_FILE, json_encode([
            'schema_version' => NASR_APT_SCHEMA_VERSION,
            'airports' => [],
        ], JSON_UNESCAPED_SLASHES));
        touch(CACHE_NASR_APT_DATA_FILE, time());
    }

    private function writeMinimalFrqCache(): void
    {
        $dir = dirname(CACHE_NASR_FRQ_DATA_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(CACHE_NASR_FRQ_DATA_FILE, json_encode([
            'schema_version' => NASR_FRQ_SCHEMA_VERSION,
            'airports' => [],
        ], JSON_UNESCAPED_SLASHES));
        touch(CACHE_NASR_FRQ_DATA_FILE, time());
    }

    private function writeFreshMeta(): void
    {
        $dir = dirname(CACHE_NASR_APT_META_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $cycle = gmdate('Y-m-d');
        $next = gmdate('Y-m-d', time() + (NASR_CYCLE_PERIOD_DAYS * 86400));
        file_put_contents(CACHE_NASR_APT_META_FILE, json_encode([
            'schema_version' => NASR_APT_SCHEMA_VERSION,
            'effective_date' => $cycle,
            'tracked_current_cycle_date' => $cycle,
            'tracked_next_cycle_date' => $next,
            'airport_count' => 0,
            'fetched_at' => gmdate('c'),
            'frq_fetched_at' => gmdate('c'),
            'frq_airport_count' => 0,
            'frq_effective_date' => $cycle,
        ], JSON_UNESCAPED_SLASHES));
        resetNasrAptCacheMemo();
        resetNasrFrqCacheMemo();
    }

    private function writeStaleFrqMeta(): void
    {
        $dir = dirname(CACHE_NASR_APT_META_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $old = gmdate('c', time() - NASR_CACHE_MAX_AGE - 100);
        file_put_contents(CACHE_NASR_APT_META_FILE, json_encode([
            'schema_version' => NASR_APT_SCHEMA_VERSION,
            'effective_date' => '2020-01-01',
            'tracked_current_cycle_date' => '2020-01-01',
            'tracked_next_cycle_date' => '2020-01-29',
            'airport_count' => 0,
            'fetched_at' => $old,
            'frq_fetched_at' => $old,
            'frq_airport_count' => 0,
            'frq_effective_date' => '2020-01-01',
        ], JSON_UNESCAPED_SLASHES));
        resetNasrAptCacheMemo();
        resetNasrFrqCacheMemo();
    }
}
