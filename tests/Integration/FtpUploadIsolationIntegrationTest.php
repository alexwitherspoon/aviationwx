<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Live FTP containment checks against a running ProFTPD instance.
 *
 * Enable with RUN_FTP_ISOLATION_INTEGRATION=1 (Docker dev container with sync-push-config).
 * Optional overrides: FTP_ISOLATION_HOST, FTP_ISOLATION_PORT, CONFIG_PATH.
 */
class FtpUploadIsolationIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_FTP_ISOLATION_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set RUN_FTP_ISOLATION_INTEGRATION=1 to run live FTP isolation probes');
        }

        if (!is_executable('/usr/bin/python3') && !is_executable('/usr/local/bin/python3')) {
            $this->markTestSkipped('python3 required for ftp-isolation-probe.py');
        }
    }

    /**
     * User A must not CWD/STOR into user B's inbox (SFTP-style containment).
     */
    public function testFtpSession_CannotReachOtherCameraInbox(): void
    {
        $pair = $this->resolveCameraCredentialPair();
        if ($pair === null) {
            $this->markTestSkipped('Need at least two push cameras with credentials in CONFIG_PATH');
        }

        [$userA, $passA, $homeA, $userB, $homeB] = $pair;
        $host = getenv('FTP_ISOLATION_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('FTP_ISOLATION_PORT') ?: '2121');
        $script = dirname(__DIR__, 2) . '/scripts/ftp-isolation-probe.py';

        $env = array_merge($_ENV, [
            'AVIATIONWX_FTP_PROBE_PASSWORD' => $passA,
        ]);
        $cmd = sprintf(
            'python3 %s --host %s --port %d --user-a %s --user-b %s --home-b %s --json',
            escapeshellarg($script),
            escapeshellarg($host),
            $port,
            escapeshellarg($userA),
            escapeshellarg($userB),
            escapeshellarg($homeB)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $this->assertSame(
            0,
            $exitCode,
            "FTP isolation probe failed for {$userA} vs {$userB} (homeA={$homeA}):\n"
            . $stdout . "\n" . $stderr
        );

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['containment_ok'] ?? false, 'Other inboxes must be unreachable');
        $this->assertTrue($decoded['own_upload_ok'] ?? false, 'Own inbox upload must still work');
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}|null
     */
    private function resolveCameraCredentialPair(): ?array
    {
        require_once dirname(__DIR__, 2) . '/lib/config.php';
        require_once dirname(__DIR__, 2) . '/lib/proftpd-auth.php';

        $parsed = parseProftpdPasswdFile();
        $cameras = [];
        $config = loadConfig();
        foreach ($config['airports'] ?? [] as $airport) {
            foreach ($airport['webcams'] ?? [] as $cam) {
                $push = $cam['push_config'] ?? null;
                if (!is_array($push)) {
                    continue;
                }
                $user = $push['username'] ?? '';
                $pass = $push['password'] ?? '';
                if ($user === '' || $pass === '') {
                    continue;
                }
                $home = $parsed['users'][$user]['home'] ?? '';
                if ($home === '') {
                    continue;
                }
                $cameras[] = [$user, $pass, $home];
            }
        }

        if (count($cameras) < 2) {
            return null;
        }

        [$userA, $passA, $homeA] = $cameras[0];
        [$userB, , $homeB] = $cameras[1];

        return [$userA, $passA, $homeA, $userB, $homeB];
    }
}
