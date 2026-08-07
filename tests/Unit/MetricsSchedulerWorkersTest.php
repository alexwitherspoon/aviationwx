<?php

declare(strict_types=1);

namespace AviationWx\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Metrics housekeeping must run as drain-gated background workers, not in the scheduler process.
 *
 * @coversNothing
 */
final class MetricsSchedulerWorkersTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function workerScriptProvider(): array
    {
        return [
            'spill_merge' => [
                'scripts/aggregate-metrics-spills.php',
                [
                    'metrics_run_spill_aggregator_once',
                    'metrics_status_bundle_mirror_refresh_via_http',
                ],
            ],
            'variant_health' => [
                'scripts/flush-variant-health.php',
                ['variant_health_flush_via_http'],
            ],
            'upstream_health' => [
                'scripts/flush-upstream-health.php',
                ['weatherHealthFlush', 'notamHealthFlush'],
            ],
            'daily' => [
                'scripts/aggregate-metrics-daily.php',
                ['metrics_aggregate_daily'],
            ],
            'weekly' => [
                'scripts/aggregate-metrics-weekly.php',
                ['metrics_aggregate_weekly'],
            ],
            'cleanup' => [
                'scripts/cleanup-metrics.php',
                ['metrics_cleanup'],
            ],
            'health' => [
                'scripts/check-metrics-health.php',
                ['metrics_get_health_status'],
            ],
        ];
    }

    /**
     * @param list<string> $requiredSnippets
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('workerScriptProvider')]
    public function testWorkerScript_ExistsAndDelegatesToLibrary(string $relativePath, array $requiredSnippets): void
    {
        $path = $this->root . '/' . $relativePath;
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('<?php', $contents);
        foreach ($requiredSnippets as $snippet) {
            $this->assertStringContainsString($snippet, $contents, "{$relativePath} must call {$snippet}");
        }
    }

    public function testSpillMergeWorker_RefreshesMirrorOnlyWhenSpillsMerged(): void
    {
        $path = $this->root . '/scripts/aggregate-metrics-spills.php';
        $contents = (string) file_get_contents($path);
        $this->assertMatchesRegularExpression(
            '/spills_merged.*>\s*0.*metrics_status_bundle_mirror_refresh_via_http/s',
            $contents
        );
        $this->assertStringContainsString('mirror_refreshed', $contents);
    }

    public function testDailyWorker_AggregatesYesterdayUtc(): void
    {
        $path = $this->root . '/scripts/aggregate-metrics-daily.php';
        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('--date=', $contents);
        $this->assertStringContainsString('gmdate', $contents);
        $this->assertStringContainsString('86400', $contents);
        $this->assertStringContainsString('metrics_aggregate_daily', $contents);
    }

    public function testWeeklyWorker_UsesIsoWeekId(): void
    {
        $path = $this->root . '/scripts/aggregate-metrics-weekly.php';
        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('--week=', $contents);
        $this->assertStringContainsString('Y-\\WW', $contents);
        $this->assertStringContainsString('metrics_aggregate_weekly', $contents);
    }

    public function testVariantHealthWorker_IsCliEntrypoint(): void
    {
        $script = $this->root . '/scripts/flush-variant-health.php';
        $this->assertFileExists($script);

        [$rc, $joined] = $this->runWorkerCli($script);
        $this->assertContains($rc, [0, 1], $joined);
    }

    public function testUpstreamHealthWorker_ExitsZero(): void
    {
        $script = $this->root . '/scripts/flush-upstream-health.php';
        $this->assertFileExists($script);

        [$rc, $joined] = $this->runWorkerCli($script);
        $this->assertSame(0, $rc, $joined);
    }

    public function testDailyWorker_ExitsZero(): void
    {
        $script = $this->root . '/scripts/aggregate-metrics-daily.php';
        $this->assertFileExists($script);

        [$rc, $joined] = $this->runWorkerCli($script, ['--date=2020-01-01']);
        $this->assertSame(0, $rc, $joined);
        $this->assertStringContainsString('"date":"2020-01-01"', $joined);
    }

    public function testWeeklyWorker_ExitsZero(): void
    {
        $script = $this->root . '/scripts/aggregate-metrics-weekly.php';
        $this->assertFileExists($script);

        [$rc, $joined] = $this->runWorkerCli($script, ['--week=2020-W01']);
        $this->assertSame(0, $rc, $joined);
        $this->assertStringContainsString('"week":"2020-W01"', $joined);
    }

    public function testCleanupWorker_ExitsZero(): void
    {
        $script = $this->root . '/scripts/cleanup-metrics.php';
        $this->assertFileExists($script);

        [$rc, $joined] = $this->runWorkerCli($script);
        $this->assertSame(0, $rc, $joined);
    }

    public function testHealthWorker_ExitsZero(): void
    {
        $script = $this->root . '/scripts/check-metrics-health.php';
        $this->assertFileExists($script);

        [$rc, $joined] = $this->runWorkerCli($script);
        $decoded = json_decode($joined, true);
        $this->assertIsArray($decoded, $joined);
        $this->assertArrayHasKey('healthy', $decoded);
        $this->assertArrayHasKey('disk', $decoded);
        $this->assertContains($rc, [0, 1], $joined);
        $this->assertSame($decoded['healthy'] ? 0 : 1, $rc, $joined);
    }

    public function testSpillMergeWorker_ExitsZeroWhenIdle(): void
    {
        $script = $this->root . '/scripts/aggregate-metrics-spills.php';
        $this->assertFileExists($script);

        [$rc, $joined] = $this->runWorkerCli($script);
        $this->assertSame(0, $rc, $joined);
        $this->assertStringContainsString('spills_merged', $joined);
    }

    public function testScheduler_RegistersMetricsWorkerTicksAndKeepsStuckCleanupInProcess(): void
    {
        $scheduler = (string) file_get_contents($this->root . '/scripts/scheduler.php');

        foreach ([
            'metrics_spill',
            'metrics_variant_health',
            'metrics_upstream_health',
            'metrics_daily',
            'metrics_weekly',
            'metrics_cleanup',
            'metrics_health',
        ] as $tick) {
            $this->assertStringContainsString("registerEnqueueTick('{$tick}'", $scheduler);
        }

        foreach ([
            'aggregate-metrics-spills.php',
            'flush-variant-health.php',
            'flush-upstream-health.php',
            'aggregate-metrics-daily.php',
            'aggregate-metrics-weekly.php',
            'cleanup-metrics.php',
            'check-metrics-health.php',
        ] as $script) {
            $this->assertStringContainsString($script, $scheduler);
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($script, '/') . '.*?>\s*\/dev\/null\s+2>&1\s*&/s',
                $scheduler,
                "{$script} must be spawned in the background"
            );
        }

        // Control-plane cleanup stays in the daemon.
        $this->assertStringContainsString('cleanupStaleWorkerHeartbeats', $scheduler);
        $this->assertStringContainsString('killStuckWorkers($stuckWorkers)', $scheduler);
        $this->assertStringContainsString('$killedPids', $scheduler);
        // Background APT may not hold its lock yet in the same tick.
        $this->assertStringContainsString('$nasrAptStartedThisTick', $scheduler);
        $this->assertStringContainsString(
            '!$nasrAptStartedThisTick && nasrFrqSchedulerShouldEnqueue',
            $scheduler
        );

        // Payload metrics must not run in-process after the drain gate.
        $afterGate = $scheduler;
        $gatePos = strpos($scheduler, "if (\$drainTick['allow_new_work'])");
        $this->assertNotFalse($gatePos);
        $afterGate = substr($scheduler, $gatePos);
        $this->assertStringNotContainsString('metrics_aggregate_daily(', $afterGate);
        $this->assertStringNotContainsString('metrics_aggregate_weekly(', $afterGate);
        $this->assertStringNotContainsString('metrics_cleanup(', $afterGate);
        $this->assertStringNotContainsString('metrics_get_health_status(', $afterGate);
        $this->assertStringNotContainsString('variant_health_flush_via_http(', $afterGate);
        $this->assertStringNotContainsString('weatherHealthFlush(', $afterGate);
        $this->assertStringNotContainsString('notamHealthFlush(', $afterGate);
        $this->assertStringNotContainsString('metrics_status_bundle_mirror_refresh_via_http(', $afterGate);
        $this->assertDoesNotMatchRegularExpression(
            '/aggregate-metrics-spills\.php\'\)\s*\.\s*\' 2>&1\'/',
            $afterGate,
            'Spill merge must not use blocking exec without &'
        );
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function runWorkerCli(string $script, array $args = []): array
    {
        $php = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
        $prepend = $this->root . '/tests/Fixtures/cli-temp-cache-prepend.php';
        $cmd = escapeshellarg($php)
            . ' -d auto_prepend_file=' . escapeshellarg($prepend)
            . ' ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        $cmd .= ' 2>&1';
        $out = [];
        $rc = -1;
        exec($cmd, $out, $rc);
        return [$rc, implode("\n", $out)];
    }
}
