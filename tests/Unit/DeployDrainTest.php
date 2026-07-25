<?php

declare(strict_types=1);

namespace AviationWx\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Deploy worker drain (scheduler pause + CD wait).
 */
final class DeployDrainTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/constants.php';
        require_once dirname(__DIR__, 2) . '/lib/deploy-drain.php';

        $this->cacheDir = sys_get_temp_dir() . '/aviationwx-deploy-drain-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($this->cacheDir, 0700, true));
        deploy_drain_set_cache_base($this->cacheDir);
        deploy_drain_clear_markers();
    }

    protected function tearDown(): void
    {
        deploy_drain_clear_markers();
        deploy_drain_set_cache_base(null);
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

    public function testConstants_DrainWindowsAreSane(): void
    {
        $this->assertGreaterThanOrEqual(90, DEPLOY_WORKER_DRAIN_MAX_SECONDS);
        $this->assertLessThanOrEqual(300, DEPLOY_WORKER_DRAIN_MAX_SECONDS);
        $this->assertGreaterThanOrEqual(60, DEPLOY_WORKER_DRAIN_ABANDON_SECONDS);
        $this->assertGreaterThanOrEqual(1, DEPLOY_WORKER_DRAIN_WAIT_GRACE_SECONDS);
        $this->assertSame(
            DEPLOY_WORKER_DRAIN_MAX_SECONDS + DEPLOY_WORKER_DRAIN_ABANDON_SECONDS,
            deploy_drain_ttl_seconds()
        );
        $this->assertSame('deploy-drain.flag', DEPLOY_DRAIN_FLAG_BASENAME);
        $this->assertSame('deploy-drain.done', DEPLOY_DRAIN_DONE_BASENAME);
    }

    public function testSetCacheBase_OverridesAndClears(): void
    {
        $this->assertSame(
            $this->cacheDir . '/' . DEPLOY_DRAIN_FLAG_BASENAME,
            deploy_drain_flag_path()
        );
        deploy_drain_set_cache_base(null);
        $this->assertStringEndsWith('/' . DEPLOY_DRAIN_FLAG_BASENAME, deploy_drain_flag_path());
        deploy_drain_set_cache_base($this->cacheDir);
    }

    public function testRequest_CreatesFlagWithStartedAtAndClearsStaleDone(): void
    {
        file_put_contents(deploy_drain_done_path(), "{\"reason\":\"stale\"}\n");
        $this->assertTrue(deploy_drain_is_complete());

        $now = 1_700_000_100;
        $this->assertTrue(deploy_drain_request($now));
        $this->assertTrue(deploy_drain_is_requested());
        $this->assertFalse(deploy_drain_is_complete());
        $this->assertFileDoesNotExist(deploy_drain_done_path());

        $payload = deploy_drain_read_flag_payload();
        $this->assertIsArray($payload);
        $this->assertSame($now, $payload['started_at']);
        $this->assertArrayNotHasKey('pid', $payload);
        $this->assertSame($now, deploy_drain_started_at());
    }

    public function testRequest_IdempotentPreservesOriginalStartedAt(): void
    {
        $first = 1_700_000_000;
        $this->assertTrue(deploy_drain_request($first));
        $this->assertTrue(deploy_drain_request($first + 30));

        $this->assertSame($first, deploy_drain_started_at());
        $this->assertSame(30, deploy_drain_elapsed_seconds($first + 30));
    }

    public function testRequest_FailsClosedWhenCacheDirMissing(): void
    {
        deploy_drain_set_cache_base($this->cacheDir . '/does-not-exist');
        $this->assertFalse(deploy_drain_request(1_700_000_000));
        $this->assertFalse(deploy_drain_is_requested());
    }

    public function testCorruptFlag_PresenceStillRequestsDrain_ElapsedFallsBackToMtime(): void
    {
        $flag = deploy_drain_flag_path();
        file_put_contents($flag, "not-json\n");
        $mtime = 1_700_000_050;
        touch($flag, $mtime);

        $this->assertTrue(deploy_drain_is_requested());
        $this->assertNull(deploy_drain_read_flag_payload());
        $this->assertSame($mtime, deploy_drain_started_at());
        $this->assertSame(10, deploy_drain_elapsed_seconds($mtime + 10));
    }

    public function testMarkComplete_WritesReasonAndIsIdempotent(): void
    {
        deploy_drain_request(1_700_000_000);
        $this->assertTrue(deploy_drain_mark_complete('idle', 1_700_000_010));
        $this->assertTrue(deploy_drain_is_complete());

        $done = deploy_drain_read_done_payload();
        $this->assertIsArray($done);
        $this->assertSame('idle', $done['reason']);
        $this->assertSame(1_700_000_010, $done['completed_at']);

        $this->assertTrue(deploy_drain_mark_complete('forced_timeout', 1_700_000_099));
        $done2 = deploy_drain_read_done_payload();
        $this->assertSame('idle', $done2['reason']);
        $this->assertSame(1_700_000_010, $done2['completed_at']);
    }

    public function testMarkComplete_WithoutRequestStillWritesDoneForCdWait(): void
    {
        $this->assertTrue(deploy_drain_mark_complete('no_scheduler', 1_700_000_000));
        $this->assertTrue(deploy_drain_is_complete());
    }

    public function testClearMarkers_RemovesFlagAndDone(): void
    {
        deploy_drain_request(1_700_000_000);
        deploy_drain_mark_complete('idle', 1_700_000_001);
        $this->assertTrue(deploy_drain_clear_markers());
        $this->assertFalse(deploy_drain_is_requested());
        $this->assertFalse(deploy_drain_is_complete());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function evaluateStateProvider(): array
    {
        $max = 120;
        $abandon = 600;
        $ttl = $max + $abandon;

        return [
            'normal_operation' => [
                [
                    'requested' => false,
                    'already_complete' => false,
                    'started_at' => null,
                    'active_workers' => 3,
                    'now' => 1000,
                    'max_seconds' => $max,
                    'abandon_seconds' => $abandon,
                ],
                [
                    'allow_new_work' => true,
                    'suppress_scheduler_restart' => false,
                    'action' => 'none',
                ],
            ],
            'drain_wait_with_active_workers' => [
                [
                    'requested' => true,
                    'already_complete' => false,
                    'started_at' => 1000,
                    'active_workers' => 2,
                    'now' => 1050,
                    'max_seconds' => $max,
                    'abandon_seconds' => $abandon,
                ],
                [
                    'allow_new_work' => false,
                    'suppress_scheduler_restart' => true,
                    'action' => 'wait',
                ],
            ],
            'drain_idle_complete' => [
                [
                    'requested' => true,
                    'already_complete' => false,
                    'started_at' => 1000,
                    'active_workers' => 0,
                    'now' => 1005,
                    'max_seconds' => $max,
                    'abandon_seconds' => $abandon,
                ],
                [
                    'allow_new_work' => false,
                    'suppress_scheduler_restart' => true,
                    'action' => 'mark_complete_idle',
                ],
            ],
            'drain_force_terminate_at_max' => [
                [
                    'requested' => true,
                    'already_complete' => false,
                    'started_at' => 1000,
                    'active_workers' => 1,
                    'now' => 1000 + $max,
                    'max_seconds' => $max,
                    'abandon_seconds' => $abandon,
                ],
                [
                    'allow_new_work' => false,
                    'suppress_scheduler_restart' => true,
                    'action' => 'force_terminate',
                ],
            ],
            'drain_force_between_max_and_ttl' => [
                [
                    'requested' => true,
                    'already_complete' => false,
                    'started_at' => 1000,
                    'active_workers' => 4,
                    'now' => 1000 + $max + 15,
                    'max_seconds' => $max,
                    'abandon_seconds' => $abandon,
                ],
                [
                    'allow_new_work' => false,
                    'suppress_scheduler_restart' => true,
                    'action' => 'force_terminate',
                ],
            ],
            'under_max_with_workers_never_force' => [
                [
                    'requested' => true,
                    'already_complete' => false,
                    'started_at' => 1000,
                    'active_workers' => 1,
                    'now' => 1000 + $max - 1,
                    'max_seconds' => $max,
                    'abandon_seconds' => $abandon,
                ],
                [
                    'allow_new_work' => false,
                    'suppress_scheduler_restart' => true,
                    'action' => 'wait',
                ],
            ],
            'already_complete_stays_paused_inside_ttl' => [
                [
                    'requested' => true,
                    'already_complete' => true,
                    'started_at' => 1000,
                    'active_workers' => 0,
                    'now' => 1000 + $max + 30,
                    'max_seconds' => $max,
                    'abandon_seconds' => $abandon,
                ],
                [
                    'allow_new_work' => false,
                    'suppress_scheduler_restart' => true,
                    'action' => 'already_complete',
                ],
            ],
            'already_complete_with_workers_inside_ttl' => [
                [
                    'requested' => true,
                    'already_complete' => true,
                    'started_at' => 1000,
                    'active_workers' => 2,
                    'now' => 1000 + 200,
                    'max_seconds' => $max,
                    'abandon_seconds' => $abandon,
                ],
                [
                    'allow_new_work' => false,
                    'suppress_scheduler_restart' => true,
                    'action' => 'already_complete',
                ],
            ],
            'ttl_abandons_and_resumes' => [
                [
                    'requested' => true,
                    'already_complete' => true,
                    'started_at' => 1000,
                    'active_workers' => 0,
                    'now' => 1000 + $ttl,
                    'max_seconds' => $max,
                    'abandon_seconds' => $abandon,
                ],
                [
                    'allow_new_work' => true,
                    'suppress_scheduler_restart' => false,
                    'action' => 'abandon_clear',
                ],
            ],
            'ttl_with_active_workers_abandons' => [
                [
                    'requested' => true,
                    'already_complete' => false,
                    'started_at' => 1000,
                    'active_workers' => 2,
                    'now' => 1000 + $ttl,
                    'max_seconds' => $max,
                    'abandon_seconds' => $abandon,
                ],
                [
                    'allow_new_work' => true,
                    'suppress_scheduler_restart' => false,
                    'action' => 'abandon_clear',
                ],
            ],
            'orphan_done_without_flag_abandons' => [
                [
                    'requested' => false,
                    'already_complete' => true,
                    'started_at' => null,
                    'active_workers' => 0,
                    'now' => 2000,
                    'max_seconds' => $max,
                    'abandon_seconds' => $abandon,
                ],
                [
                    'allow_new_work' => true,
                    'suppress_scheduler_restart' => false,
                    'action' => 'abandon_clear',
                ],
            ],
            'missing_started_at_with_active_forces_immediately' => [
                [
                    'requested' => true,
                    'already_complete' => false,
                    'started_at' => null,
                    'active_workers' => 1,
                    'now' => 1000,
                    'max_seconds' => $max,
                    'abandon_seconds' => $abandon,
                ],
                [
                    'allow_new_work' => false,
                    'suppress_scheduler_restart' => true,
                    'action' => 'force_terminate',
                ],
            ],
            'missing_started_at_idle_completes' => [
                [
                    'requested' => true,
                    'already_complete' => false,
                    'started_at' => null,
                    'active_workers' => 0,
                    'now' => 1000,
                    'max_seconds' => $max,
                    'abandon_seconds' => $abandon,
                ],
                [
                    'allow_new_work' => false,
                    'suppress_scheduler_restart' => true,
                    'action' => 'mark_complete_idle',
                ],
            ],
            'negative_active_treated_as_zero' => [
                [
                    'requested' => true,
                    'already_complete' => false,
                    'started_at' => 1000,
                    'active_workers' => -3,
                    'now' => 1001,
                    'max_seconds' => $max,
                    'abandon_seconds' => $abandon,
                ],
                [
                    'allow_new_work' => false,
                    'suppress_scheduler_restart' => true,
                    'action' => 'mark_complete_idle',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[DataProvider('evaluateStateProvider')]
    public function testEvaluateState_Matrix(array $input, array $expected): void
    {
        $result = deploy_drain_evaluate_state(
            (bool) $input['requested'],
            (bool) $input['already_complete'],
            $input['started_at'] === null ? null : (int) $input['started_at'],
            (int) $input['active_workers'],
            (int) $input['now'],
            (int) $input['max_seconds'],
            (int) $input['abandon_seconds']
        );

        $this->assertSame($expected['allow_new_work'], $result['allow_new_work'], 'allow_new_work');
        $this->assertSame($expected['suppress_scheduler_restart'], $result['suppress_scheduler_restart'], 'suppress_scheduler_restart');
        $this->assertSame($expected['action'], $result['action'], 'action');
    }

    public function testEvaluateSchedulerTick_IntegratesFilesystemAndActiveCount(): void
    {
        $now = 1_700_000_000;
        $idle = deploy_drain_evaluate_scheduler_tick(0, $now);
        $this->assertTrue($idle['allow_new_work']);
        $this->assertSame('none', $idle['action']);

        deploy_drain_request($now);
        $waiting = deploy_drain_evaluate_scheduler_tick(2, $now + 10);
        $this->assertFalse($waiting['allow_new_work']);
        $this->assertSame('wait', $waiting['action']);

        $idleDrain = deploy_drain_evaluate_scheduler_tick(0, $now + 11);
        $this->assertSame('mark_complete_idle', $idleDrain['action']);

        deploy_drain_mark_complete('idle', $now + 12);
        $done = deploy_drain_evaluate_scheduler_tick(0, $now + 13);
        $this->assertSame('already_complete', $done['action']);
        $this->assertFalse($done['allow_new_work']);

        $abandoned = deploy_drain_evaluate_scheduler_tick(0, $now + deploy_drain_ttl_seconds());
        $this->assertSame('abandon_clear', $abandoned['action']);
        $this->assertTrue($abandoned['allow_new_work']);
    }

    public function testApplySchedulerAction_MarkCompleteIdle(): void
    {
        deploy_drain_request(1_700_000_000);
        $terminated = [];
        $result = deploy_drain_apply_scheduler_action(
            'mark_complete_idle',
            1_700_000_005,
            static function () use (&$terminated): void {
                $terminated[] = 'force';
            }
        );

        $this->assertTrue($result);
        $this->assertSame([], $terminated);
        $this->assertTrue(deploy_drain_is_complete());
        $this->assertSame('idle', deploy_drain_read_done_payload()['reason']);
    }

    public function testApplySchedulerAction_ForceTerminateInvokesCallbackThenMarksDone(): void
    {
        deploy_drain_request(1_700_000_000);
        $terminated = [];
        $result = deploy_drain_apply_scheduler_action(
            'force_terminate',
            1_700_000_000 + DEPLOY_WORKER_DRAIN_MAX_SECONDS,
            static function () use (&$terminated): void {
                $terminated[] = 'force';
            }
        );

        $this->assertTrue($result);
        $this->assertSame(['force'], $terminated);
        $this->assertTrue(deploy_drain_is_complete());
        $this->assertSame('forced_timeout', deploy_drain_read_done_payload()['reason']);
    }

    public function testApplySchedulerAction_AbandonClearForcesAndClearsMarkers(): void
    {
        deploy_drain_request(1_700_000_000);
        deploy_drain_mark_complete('idle', 1_700_000_001);
        $terminated = [];
        $result = deploy_drain_apply_scheduler_action(
            'abandon_clear',
            1_700_000_000 + deploy_drain_ttl_seconds(),
            static function () use (&$terminated): void {
                $terminated[] = 'force';
            }
        );

        $this->assertTrue($result);
        $this->assertSame(['force'], $terminated);
        $this->assertFalse(deploy_drain_is_requested());
        $this->assertFalse(deploy_drain_is_complete());
    }

    public function testApplySchedulerAction_WaitAndNoneAreNoops(): void
    {
        deploy_drain_request(1_700_000_000);
        $terminated = [];
        $this->assertTrue(deploy_drain_apply_scheduler_action('wait', 1_700_000_001, static function () use (&$terminated): void {
            $terminated[] = 'force';
        }));
        $this->assertTrue(deploy_drain_apply_scheduler_action('none', 1_700_000_001, static function () use (&$terminated): void {
            $terminated[] = 'force';
        }));
        $this->assertSame([], $terminated);
        $this->assertFalse(deploy_drain_is_complete());
    }

    public function testSumActiveWorkers_NullPoolsAndNegativesSafe(): void
    {
        $poolA = new class {
            public function getActiveCount(): int
            {
                return 2;
            }
        };
        $poolB = new class {
            public function getActiveCount(): int
            {
                return 3;
            }
        };

        $this->assertSame(5, deploy_drain_sum_active_workers([$poolA, null, $poolB]));
        $this->assertSame(0, deploy_drain_sum_active_workers([]));
        $this->assertSame(0, deploy_drain_sum_active_workers([null, null]));
    }

    public function testHealthCheck_SuppressOnlyWhileActivelyDraining(): void
    {
        $now = 1_700_000_000;
        $this->assertFalse(deploy_drain_should_suppress_scheduler_restart($now));

        deploy_drain_request($now);
        $this->assertTrue(deploy_drain_should_suppress_scheduler_restart($now + 10));

        deploy_drain_mark_complete('idle', $now + 11);
        $this->assertTrue(deploy_drain_should_suppress_scheduler_restart($now + 12));

        // Orphan .done alone must not suppress forever.
        deploy_drain_clear_markers();
        deploy_drain_mark_complete('stale', $now);
        $this->assertFalse(deploy_drain_should_suppress_scheduler_restart($now + 1));

        deploy_drain_clear_markers();
        deploy_drain_request($now);
        deploy_drain_mark_complete('idle', $now + 1);
        $this->assertFalse(deploy_drain_should_suppress_scheduler_restart($now + deploy_drain_ttl_seconds()));
    }

    public function testCdWait_ReturnsTrueWhenDoneAppears(): void
    {
        deploy_drain_request(1_700_000_000);
        $checks = 0;
        $ok = deploy_drain_wait_until_complete(
            5,
            static function () use (&$checks): void {
                $checks++;
                if ($checks === 2) {
                    deploy_drain_mark_complete('idle', 1_700_000_002);
                }
            },
            static function (): void {
            }
        );
        $this->assertTrue($ok);
        $this->assertGreaterThanOrEqual(2, $checks);
    }

    public function testCdWait_ReturnsFalseWhenMaxElapsedWithoutDone(): void
    {
        deploy_drain_request(1_700_000_000);
        $ok = deploy_drain_wait_until_complete(
            2,
            null,
            static function (): void {
            }
        );
        $this->assertFalse($ok);
        $this->assertFalse(deploy_drain_is_complete());
    }

    public function testCdWait_ImmediateTrueWhenAlreadyComplete(): void
    {
        deploy_drain_mark_complete('no_scheduler', 1_700_000_000);
        $calls = 0;
        $ok = deploy_drain_wait_until_complete(5, static function () use (&$calls): void {
            $calls++;
        });
        $this->assertTrue($ok);
        $this->assertSame(0, $calls);
    }

    public function testCli_RequestWaitStatusClear_RoundTrip(): void
    {
        $php = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
        $cli = dirname(__DIR__, 2) . '/scripts/deploy-drain.php';
        $cache = $this->cacheDir;

        $out = [];
        $rc = 0;
        exec(
            escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' request --cache-dir=' . escapeshellarg($cache) . ' 2>&1',
            $out,
            $rc
        );
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertTrue(deploy_drain_is_requested());

        deploy_drain_mark_complete('idle', 1_700_000_050);
        $out = [];
        $rc = 0;
        exec(
            escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' wait --cache-dir=' . escapeshellarg($cache) . ' --max-wait=2 2>&1',
            $out,
            $rc
        );
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertStringContainsString('complete', implode("\n", $out));

        $out = [];
        $rc = 0;
        exec(
            escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' status --cache-dir=' . escapeshellarg($cache) . ' 2>&1',
            $out,
            $rc
        );
        $this->assertSame(0, $rc, implode("\n", $out));
        $decoded = json_decode(implode("\n", $out), true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['complete']);
        $this->assertArrayHasKey('ttl_seconds', $decoded);

        $out = [];
        $rc = 0;
        exec(
            escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' clear --cache-dir=' . escapeshellarg($cache) . ' 2>&1',
            $out,
            $rc
        );
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertFalse(deploy_drain_is_requested());
        $this->assertFalse(deploy_drain_is_complete());
    }

    public function testCli_WaitTimeout_ExitsTwo(): void
    {
        $php = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
        $cli = dirname(__DIR__, 2) . '/scripts/deploy-drain.php';
        $cache = $this->cacheDir;

        exec(
            escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' request --cache-dir=' . escapeshellarg($cache) . ' 2>&1',
            $out,
            $rc
        );
        $this->assertSame(0, $rc);

        $out = [];
        $rc = 0;
        exec(
            escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' wait --cache-dir=' . escapeshellarg($cache) . ' --max-wait=0 2>&1',
            $out,
            $rc
        );
        $this->assertSame(2, $rc, implode("\n", $out));
    }

    public function testWiring_SchedulerAndHealthCheckRequireDeployDrain(): void
    {
        $scheduler = file_get_contents(dirname(__DIR__, 2) . '/scripts/scheduler.php');
        $health = file_get_contents(dirname(__DIR__, 2) . '/scripts/scheduler-health-check.php');
        $entrypoint = file_get_contents(dirname(__DIR__, 2) . '/docker/docker-entrypoint.sh');
        $workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/deploy-docker.yml');

        $this->assertIsString($scheduler);
        $this->assertIsString($health);
        $this->assertIsString($entrypoint);
        $this->assertIsString($workflow);

        $this->assertStringContainsString("require_once __DIR__ . '/../lib/deploy-drain.php';", $scheduler);
        $this->assertStringContainsString('scheduler-work-registry.php', $scheduler);
        $this->assertStringContainsString('SchedulerWorkRegistry', $scheduler);
        $this->assertStringContainsString('deploy_drain_evaluate_scheduler_tick', $scheduler);
        $this->assertStringContainsString('runEnqueueTicks', $scheduler);
        foreach (['metar_bulk', 'nws_points', 'weather', 'webcam', 'notam', 'station_power', 'reference_data', 'status_prewarm', 'metrics_spill', 'metrics_variant_health', 'metrics_upstream_health', 'metrics_daily', 'metrics_weekly', 'metrics_cleanup', 'metrics_health'] as $tickName) {
            $this->assertStringContainsString("registerEnqueueTick('{$tickName}'", $scheduler);
        }
        $this->assertStringContainsString("setPool('weather'", $scheduler);
        $this->assertStringContainsString('runEnqueueTicks', $scheduler);
        $this->assertStringContainsString('deploy_drain_apply_scheduler_action', $scheduler);

        $this->assertStringContainsString("require_once __DIR__ . '/../lib/deploy-drain.php';", $health);
        $this->assertStringContainsString('deploy_drain_should_suppress_scheduler_restart', $health);
        $this->assertStringContainsString('listSchedulerDaemonPids', $health);
        // Suppress only when a live daemon exists; a dead daemon mid-drain must still be recoverable.
        $this->assertMatchesRegularExpression(
            '/deploy_drain_should_suppress_scheduler_restart\(\).*count\(\$daemonPidsSnapshot\)\s*>\s*0/s',
            $health
        );

        $this->assertStringContainsString('deploy-drain.flag', $entrypoint);
        $this->assertStringContainsString('deploy-drain.done', $entrypoint);

        $this->assertStringContainsString('deploy-drain-workers.sh', $workflow);
    }

    public function testHostDrainHelper_ReadsPhpMaxConstant(): void
    {
        $path = dirname(__DIR__, 2) . '/scripts/deploy-drain-workers.sh';
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('DEPLOY_WORKER_DRAIN_MAX_SECONDS', $contents);
        $this->assertStringContainsString('lib/constants.php', $contents);
        $this->assertStringContainsString('exit 0', $contents);
    }
}
