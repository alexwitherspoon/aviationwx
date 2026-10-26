<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for upload health probe watchdog helpers in upload-daemon-common.sh.
 */
class UploadHealthProbeWatchdogTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('probeHeartbeatEpochProvider')]
    public function testProbeHeartbeatEpochIsValid_VariousEpochs_MatchesExitCode(string $epoch, int $expectedExitCode): void
    {
        $common = dirname(__DIR__, 2) . '/scripts/upload-daemon-common.sh';
        $cmd = sprintf(
            'bash -c %s',
            escapeshellarg('. ' . $common . ' && probe_heartbeat_epoch_is_valid ' . escapeshellarg($epoch))
        );
        exec($cmd, $output, $code);
        $this->assertSame($expectedExitCode, $code, implode("\n", $output));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function probeHeartbeatEpochProvider(): array
    {
        return [
            'positive integer' => ['1730123456', 0],
            'zero' => ['0', 1],
            'empty' => ['', 1],
            'non-numeric' => ['oops', 1],
            'float string' => ['1730123456.5', 1],
        ];
    }

    /**
     * The watchdog loop calls this gate once per cycle before it decides whether
     * to monitor and restart ProFTPD/sshd. Cron monitoring is outside the gate.
     *
     * @return array<string, array{0: string|null, 1: int}>
     */
    public static function uploadMonitoringEnabledProvider(): array
    {
        return [
            'disabled (no push camera)' => ['0', 1],
            'enabled (push camera)' => ['1', 0],
            'unset (fail closed)' => [null, 1],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('uploadMonitoringEnabledProvider')]
    public function testUploadMonitoringEnabled_FollowsMasterGate(?string $value, int $expectedExitCode): void
    {
        $common = dirname(__DIR__, 2) . '/scripts/upload-daemon-common.sh';
        $set = $value === null ? '' : ('export UPLOAD_IN_USE=' . escapeshellarg($value) . ' && ');
        $cmd = sprintf(
            'bash -c %s',
            escapeshellarg('. ' . $common . ' && ' . $set . 'upload_monitoring_enabled')
        );
        exec($cmd, $output, $code);
        $this->assertSame($expectedExitCode, $code, implode("\n", $output));
    }
}
