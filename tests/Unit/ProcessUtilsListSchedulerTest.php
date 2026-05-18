<?php

declare(strict_types=1);

namespace AviationWx\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Scheduler daemon detection and /proc enumeration (duplicate-scheduler hardening).
 */
final class ProcessUtilsListSchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../lib/process-utils.php';
    }

    public static function schedulerCmdlineCases(): array
    {
        $php = '/usr/local/bin/php';
        $sched = '/var/www/html/scripts/scheduler.php';

        return [
            'real_scheduler_proc_style' => [
                true,
                $php . "\0" . $sched . "\0",
            ],
            'health_check_excluded' => [
                false,
                $php . "\0" . '/var/www/html/scripts/scheduler-health-check.php' . "\0",
            ],
            'fake_scheduler_suffix_excluded' => [
                false,
                $php . "\0" . '/var/www/html/scripts/fake-scheduler.php' . "\0",
            ],
            'unified_webcam_worker_excluded' => [
                false,
                $php . "\0" . '/var/www/html/scripts/unified-webcam-worker.php' . "\0",
            ],
            'empty_cmdline' => [
                false,
                '',
            ],
        ];
    }

    #[DataProvider('schedulerCmdlineCases')]
    public function testSchedulerDaemonMatchesProcCmdline_ExpectedMatch(bool $expected, string $cmdline): void
    {
        $this->assertSame($expected, scheduler_daemon_matches_proc_cmdline($cmdline));
    }

    public function testListSchedulerDaemonPids_ReturnsSortedUniquePositiveInts(): void
    {
        $pids = listSchedulerDaemonPids();

        $this->assertIsArray($pids);
        $prev = -1;
        foreach ($pids as $pid) {
            $this->assertIsInt($pid);
            $this->assertGreaterThan(0, $pid);
            $this->assertGreaterThan($prev, $pid);
            $prev = $pid;
        }
    }
}
