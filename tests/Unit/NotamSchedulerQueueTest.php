<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * NOTAM scheduler spreading (stagger + low-urgency enqueue cap).
 */
final class NotamSchedulerQueueTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/notam-sched-queue-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0755, true);
        $GLOBALS['notamCacheTestDirectory'] = $this->cacheDir;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['notamCacheTestDirectory']);
        if ($this->cacheDir !== '' && is_dir($this->cacheDir)) {
            foreach (scandir($this->cacheDir) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                @unlink($this->cacheDir . '/' . $item);
            }
            @rmdir($this->cacheDir);
        }
    }

    public function testStaggerOffset_SpreadsAcrossRefreshWindow(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/scheduling.php';

        $interval = 600;
        $maxSlot = (int) ($interval / NOTAM_SCHEDULER_STAGGER_WINDOW_FRACTION);
        $offsets = [];
        foreach (['kspb', 'kpfc', '28u', 'or81', 'cyav'] as $id) {
            $offsets[] = notamStaggerOffsetSeconds($id, $interval);
        }

        foreach ($offsets as $offset) {
            $this->assertGreaterThanOrEqual(0, $offset);
            $this->assertLessThan($maxSlot, $offset);
        }
        $this->assertGreaterThan(1, count(array_unique($offsets)));
    }

    public function testShouldEnqueueRefresh_RequiresRefreshIntervalPlusStagger(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/cache.php';

        $now = 1_700_000_000;
        $interval = 600;
        $stagger = notamStaggerOffsetSeconds('kspb', $interval);
        touch(notamCacheFilePath('kspb'), $now - $interval - $stagger + 5);

        $this->assertFalse(notamShouldEnqueueRefresh('kspb', $interval, $now));

        touch(notamCacheFilePath('kspb'), $now - $interval - $stagger - 1);
        $this->assertTrue(notamShouldEnqueueRefresh('kspb', $interval, $now));
    }

    public function testSelectAirportsToEnqueue_PrioritizesOldestCache(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/scheduler-queue.php';

        $now = 1_700_100_000;
        $interval = 60;
        touch(notamCacheFilePath('newer'), $now - 500);
        touch(notamCacheFilePath('older'), $now - 5000);

        $selected = notamSelectAirportsToEnqueue(['newer', 'older'], $interval, 1, $now);

        $this->assertSame(['older'], $selected);
    }

    public function testSelectAirportsToEnqueue_CapsBurstWhenManyAreDue(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/scheduler-queue.php';

        $now = 1_700_200_000;
        $interval = 60;
        foreach (['a', 'b', 'c', 'd'] as $id) {
            touch(notamCacheFilePath($id), $now - 10_000);
        }

        $selected = notamSelectAirportsToEnqueue(['a', 'b', 'c', 'd'], $interval, 2, $now);

        $this->assertCount(2, $selected);
    }
}
