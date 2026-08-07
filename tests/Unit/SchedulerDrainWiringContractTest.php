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
        // Clear markers on the shared host cache mount (no host PHP).
        $clearPos = strpos($workflow, 'clear_deploy_drain_markers()');
        $this->assertNotFalse($clearPos);
        $clearChunk = substr($workflow, $clearPos, 400);
        $this->assertStringContainsString('rm -f', $clearChunk);
        $this->assertStringContainsString('deploy-drain.flag', $clearChunk);
        $this->assertStringNotContainsString('command -v php', $clearChunk);
        $this->assertStringContainsString(
            'Deploy drain markers still present after compose',
            $workflow
        );
    }

    public function testWorkflow_DeployComposeDoesNotRedirectSshHeredocStdin(): void
    {
        $workflow = (string) file_get_contents($this->root . '/.github/workflows/deploy-docker.yml');
        $composeStepPos = strpos($workflow, '- name: Deploy via Docker Compose');
        $this->assertNotFalse($composeStepPos);
        $composeStepEnd = strpos($workflow, '- name: Restart Nginx container', $composeStepPos);
        $this->assertNotFalse($composeStepEnd, 'Deploy via Docker Compose step must be followed by Nginx restart step');
        $composeChunk = substr($workflow, $composeStepPos, $composeStepEnd - $composeStepPos);
        $heredocStart = strpos($composeChunk, 'ssh ${{ secrets.USER }}@${{ secrets.HOST }} << EOF');
        $this->assertNotFalse($heredocStart, 'Deploy step must use SSH heredoc');
        $heredocEnd = strpos($composeChunk, "\n          EOF", $heredocStart);
        $this->assertNotFalse($heredocEnd, 'Deploy SSH heredoc must close with EOF');
        $heredocBody = substr($composeChunk, $heredocStart, $heredocEnd - $heredocStart);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*exec\s+<\/dev\/null\s*$/m',
            $heredocBody,
            'Global stdin redirect truncates the SSH heredoc before docker compose build/up'
        );
        $this->assertStringContainsString('scripts/deploy-drain-workers.sh', $heredocBody);
        $this->assertStringContainsString('sync-push-config.php < /dev/null', $heredocBody);
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

    public function testScheduler_CountryResolutionUsesReferenceProcessPool(): void
    {
        $scheduler = (string) file_get_contents($this->root . '/scripts/scheduler.php');
        $jobs = (string) file_get_contents($this->root . '/lib/reference-data/jobs.php');
        $this->assertStringContainsString('refresh-airport-country-resolution.php', $jobs);
        $this->assertStringContainsString('country_resolution', $jobs);
        $this->assertStringContainsString('referenceDataEnqueueDueJobs', $scheduler);
        $this->assertStringContainsString('SCHEDULER_POOL_CLASS_REFERENCE', $scheduler);
        $this->assertStringNotContainsString(
            'escapeshellarg($countryResolutionScript)',
            $scheduler,
            'Country resolution must use ProcessPool enqueue, not fire-and-forget exec'
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
