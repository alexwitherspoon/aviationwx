<?php

declare(strict_types=1);

namespace AviationWx\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Scheduler/CD wiring contracts for drain correctness (source-level TDD guards).
 */
final class SchedulerDrainWiringContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testScheduler_PreservesLastConfigShaWhenCurrentShaUnknown(): void
    {
        $scheduler = (string) file_get_contents($this->root . '/scripts/scheduler.php');
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$currentSha\s*!==\s*null\s*\)\s*\{\s*\$lastConfigSha\s*=\s*\$currentSha;/s',
            $scheduler
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$lastConfigMtime\s*=\s*\$currentMtime;[^\n]*\n\s*\$lastConfigSha\s*=\s*\$currentSha;/s',
            $scheduler
        );
    }

    public function testScheduler_LogsWhenDeployDrainMarkerUpdateFails(): void
    {
        $scheduler = (string) file_get_contents($this->root . '/scripts/scheduler.php');
        $this->assertStringContainsString('$drainApplied = deploy_drain_apply_scheduler_action(', $scheduler);
        $this->assertStringContainsString('deploy drain marker update failed', $scheduler);
    }

    public function testScheduler_RecreatesPoolsOnlyWhenConfigChangedOrFirstInit(): void
    {
        $scheduler = (string) file_get_contents($this->root . '/scripts/scheduler.php');

        // Reload path must not rebuild pools on every successful loadConfig tick.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$configChanged\s*\|\|\s*\$weatherPool\s*===\s*null\s*\)/',
            $scheduler,
            'ProcessPools must recreate only on config change or first init'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$lastConfigSha\s*=\s*\$currentSha;\s*\/\/ Reinitialize ProcessPools/s',
            $scheduler
        );
    }

    public function testScheduler_DailyWeeklyPassTargetIdAndRetryUntilArtifactExists(): void
    {
        $scheduler = (string) file_get_contents($this->root . '/scripts/scheduler.php');
        $this->assertStringContainsString('--date=', $scheduler);
        $this->assertStringContainsString('--week=', $scheduler);
        $this->assertStringContainsString('getMetricsDailyPath', $scheduler);
        $this->assertStringContainsString('getMetricsWeeklyPath', $scheduler);
        $this->assertStringContainsString('lastDailySpawnAttempt', $scheduler);
        $this->assertStringNotContainsString('$lastDailyAggregation = $yesterdayId', $scheduler);
    }

    public function testDailyWeeklyWorkers_AcceptExplicitTargetArgs(): void
    {
        $daily = (string) file_get_contents($this->root . '/scripts/aggregate-metrics-daily.php');
        $weekly = (string) file_get_contents($this->root . '/scripts/aggregate-metrics-weekly.php');
        $this->assertStringContainsString('--date=', $daily);
        $this->assertStringContainsString('--week=', $weekly);
    }

    public function testWorkflow_DrainsImmediatelyBeforeComposeUpAndClearsOnFailure(): void
    {
        $workflow = (string) file_get_contents($this->root . '/.github/workflows/deploy-docker.yml');

        // Drain must follow FORCE_REBUILD probes and precede compose recreate.
        $drainPos = strpos($workflow, 'scripts/deploy-drain-workers.sh');
        $forceProbePos = strpos($workflow, 'Verifying Docker config files on server');
        $upPos = strpos($workflow, 'docker compose -f docker/docker-compose.prod.yml up -d --build --pull');
        $this->assertNotFalse($drainPos);
        $this->assertNotFalse($forceProbePos);
        $this->assertNotFalse($upPos);
        $this->assertTrue($forceProbePos < $drainPos, 'Docker config probe must run before drain');
        $this->assertTrue($drainPos < $upPos, 'Drain must run before compose up --build --pull');
        $this->assertStringContainsString(
            'Drain in-flight workers immediately before recreate',
            $workflow
        );
        $this->assertStringContainsString('deploy-drain.php clear', $workflow);
        $this->assertStringContainsString(
            'Deploy drain markers still present after compose',
            $workflow
        );
    }

    public function testHealthCheck_LogsDuplicateDaemonsBeforeDrainSuppressExit(): void
    {
        $health = (string) file_get_contents($this->root . '/scripts/scheduler-health-check.php');
        $dupPos = strpos($health, 'multiple scheduler daemons detected');
        $suppressPos = strpos($health, 'deploy_drain_should_suppress_scheduler_restart');
        $this->assertNotFalse($dupPos);
        $this->assertNotFalse($suppressPos);
        $this->assertLessThan($suppressPos, $dupPos, 'Duplicate daemon log must run before drain suppress exit');
    }

    public function testArchitecture_DocumentsForceTerminateUsesSigkillFallback(): void
    {
        $arch = (string) file_get_contents($this->root . '/docs/ARCHITECTURE.md');
        $this->assertMatchesRegularExpression('/SIGTERM.*SIGKILL|SIGKILL.*SIGTERM/i', $arch);
    }

    public function testScheduler_CountryResolutionSpawnsNonBlocking(): void
    {
        $scheduler = (string) file_get_contents($this->root . '/scripts/scheduler.php');
        $this->assertStringContainsString('refresh-airport-country-resolution.php', $scheduler);
        $this->assertMatchesRegularExpression(
            '/escapeshellarg\(\$countryResolutionScript\)\s*\.\s*[\'"]\s*>\s*\/dev\/null\s*2>&1\s*&/',
            $scheduler,
            'Country resolution must be fire-and-forget so the dispatcher tick cannot block on geometry I/O'
        );
        $this->assertStringNotContainsString(
            ", \$output, \$exitCode)",
            $scheduler
        );
    }

    public function testScheduler_MetricsMissingScriptsLogWarning(): void
    {
        $scheduler = (string) file_get_contents($this->root . '/scripts/scheduler.php');
        foreach ([
            'aggregate-metrics-spills.php missing',
            'cleanup-metrics.php missing',
            'check-metrics-health.php missing',
        ] as $needle) {
            $this->assertStringContainsString($needle, $scheduler);
        }
    }
}
