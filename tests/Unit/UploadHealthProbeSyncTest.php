<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../scripts/sync-push-config.php';

/**
 * Upload health probe account provisioning during push-config sync.
 */
class UploadHealthProbeSyncTest extends TestCase
{
    private string $originalAppEnv;

    private string $originalConfigPath;

    /** @var list<string> */
    private array $tempConfigPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalAppEnv = getenv('APP_ENV') ?: '';
        $this->originalConfigPath = getenv('CONFIG_PATH') ?: '';
        $this->clearConfigCache();
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV=' . $this->originalAppEnv);
        $_ENV['APP_ENV'] = $this->originalAppEnv;
        $_SERVER['APP_ENV'] = $this->originalAppEnv;
        putenv('CONFIG_PATH=' . $this->originalConfigPath);
        if ($this->originalConfigPath !== '') {
            $_ENV['CONFIG_PATH'] = $this->originalConfigPath;
            $_SERVER['CONFIG_PATH'] = $this->originalConfigPath;
        } else {
            unset($_ENV['CONFIG_PATH'], $_SERVER['CONFIG_PATH']);
        }
        foreach ($this->tempConfigPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempConfigPaths = [];
        $this->clearConfigCache();
        parent::tearDown();
    }

    public function testGetUploadHealthProbeFtpDir_IsolatedFromCameraInboxes(): void
    {
        $probeDir = getUploadHealthProbeFtpDir('awxprobeftps');
        $cameraDir = getWebcamFtpUploadDir('kspb', 'awxprobeftps');

        $this->assertStringContainsString('/' . UPLOAD_HEALTH_PROBE_FTP_NAMESPACE . '/awxprobeftps', $probeDir);
        $this->assertNotSame($cameraDir, $probeDir);
        $this->assertStringNotContainsString('/kspb/', $probeDir);
    }

    public function testIsUploadHealthProbeFtpCacheNamespace_MatchesReservedTopLevelDir(): void
    {
        $this->assertTrue(isUploadHealthProbeFtpCacheNamespace('_probe'));
        $this->assertTrue(isUploadHealthProbeFtpCacheNamespace('_PROBE'));
        $this->assertFalse(isUploadHealthProbeFtpCacheNamespace('keul'));
        $this->assertFalse(isUploadHealthProbeFtpCacheNamespace('kspb'));
    }

    public function testGetUploadHealthProbeSyncPlan_ReturnsEmptyWhenDisabled(): void
    {
        $this->useProductionConfig([
            'config' => [
                'base_domain' => 'example.org',
                'upload_health_probe' => [
                    'enabled' => false,
                    'ftps' => ['username' => 'probeftps', 'password' => 'abcdefghijklmn'],
                ],
            ],
            'airports' => [],
        ]);

        $this->assertSame([], getUploadHealthProbeSyncPlan());
    }

    public function testGetUploadHealthProbeSyncPlan_ReturnsFtpsAndSftpWhenEnabled(): void
    {
        $this->useProductionConfig([
            'config' => [
                'base_domain' => 'example.org',
                'upload_health_probe' => [
                    'enabled' => true,
                    'probe_connect_host' => '127.0.0.1',
                    'ftps' => ['username' => 'probeftps', 'password' => 'abcdefghijklmn'],
                    'sftp' => ['username' => 'probesftp', 'password' => 'opqrstuvwxyz12'],
                ],
            ],
            'airports' => [],
        ]);

        $plan = getUploadHealthProbeSyncPlan();

        $this->assertCount(2, $plan);
        $this->assertSame('ftps', $plan[0]['protocol']);
        $this->assertSame('probeftps', $plan[0]['username']);
        $this->assertSame('abcdefghijklmn', $plan[0]['password']);
        $this->assertSame(getUploadHealthProbeFtpDir('probeftps'), $plan[0]['ftp_local_root']);

        $this->assertSame('sftp', $plan[1]['protocol']);
        $this->assertSame('probesftp', $plan[1]['username']);
        $this->assertSame('opqrstuvwxyz12', $plan[1]['password']);
    }

    public function testGetUploadHealthProbeSyncPlan_ReturnsEmptyOutsideProduction(): void
    {
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
        $this->clearConfigCache();

        $this->assertSame([], getUploadHealthProbeSyncPlan());
    }

    public function testSyncPushConfig_InvokesUploadHealthProbeSyncAfterPushCameras(): void
    {
        $path = __DIR__ . '/../../scripts/sync-push-config.php';
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        $pushPos = strpos($contents, 'syncAllPushCameras($config);');
        $this->assertNotFalse($pushPos, 'syncPushConfig must sync push cameras');

        $afterPush = substr($contents, $pushPos);
        $this->assertNotFalse(
            strpos($afterPush, 'syncUploadHealthProbeUsers()'),
            'full sync path must sync upload health probe users after push cameras'
        );
    }

    public function testSyncPushConfig_ReprovisionsProbeUsersWhenCameraSyncSkipped(): void
    {
        $path = __DIR__ . '/../../scripts/sync-push-config.php';
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        $skipPos = strpos($contents, 'config unchanged since last sync, skipping');
        $this->assertNotFalse($skipPos);

        $probePos = strpos($contents, 'syncUploadHealthProbeUsers()', $skipPos);
        $this->assertNotFalse($probePos, 'probe sync must run on config-unchanged early return path');
        $this->assertGreaterThan($skipPos, $probePos);
    }

    public function testUploadProbeScript_UsesExplicitFtpsUrl(): void
    {
        $path = __DIR__ . '/../../scripts/upload-probe.sh';
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('base_url="ftp://${host}:${port}/"', $contents);
        $this->assertStringNotContainsString('base_url="ftps://${host}:${port}/"', $contents);
    }

    public function testUploadProbeScript_UsesPlainFtpWhenVsftpdSslDisabled(): void
    {
        $path = __DIR__ . '/../../scripts/upload-probe.sh';
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('vsftpd_ssl_enabled', $contents);
        $this->assertStringContainsString('curl_tls_args=(--ftp-ssl-reqd)', $contents);
        $this->assertStringContainsString('ok (plain ftp, ssl_enable=NO)', $contents);
    }

    public function testUploadProbeRunner_WaitsForPushConfigSyncBeforeFirstProbe(): void
    {
        $path = __DIR__ . '/../../scripts/upload-probe-runner.sh';
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('wait_for_push_config_sync', $contents);
        $this->assertStringContainsString('FTP/SFTP/FTPS configuration synced successfully', $contents);

        $startedPos = strpos($contents, 'log_probe_runner "upload-probe-runner started"');
        $this->assertNotFalse($startedPos);

        $waitCallPos = strpos($contents, 'wait_for_push_config_sync', $startedPos);
        $this->assertNotFalse($waitCallPos, 'runner must invoke wait_for_push_config_sync after start log');
        $this->assertGreaterThan($startedPos, $waitCallPos, 'runner start log must precede sync wait');

        $mainLoopPos = strpos($contents, 'while true; do', $waitCallPos);
        $this->assertNotFalse($mainLoopPos, 'probe interval loop must follow sync wait');
        $this->assertGreaterThan($waitCallPos, $mainLoopPos, 'sync wait call must precede probe loop');
    }

    public function testUploadProbeScript_EnsuresLoopbackSftpKnownHosts(): void
    {
        $path = __DIR__ . '/../../scripts/upload-probe.sh';
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('ensure_sftp_known_hosts', $contents);
        $this->assertStringContainsString('ssh-keyscan', $contents);
        $this->assertStringContainsString('localhost|127.0.0.1|::1', $contents);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function useProductionConfig(array $config): void
    {
        $tmp = sys_get_temp_dir() . '/upload-probe-sync-' . uniqid('', true) . '.json';
        file_put_contents($tmp, json_encode($config, JSON_THROW_ON_ERROR));
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';
        putenv('CONFIG_PATH=' . $tmp);
        $_ENV['CONFIG_PATH'] = $tmp;
        $_SERVER['CONFIG_PATH'] = $tmp;
        $this->tempConfigPaths[] = $tmp;
        $this->clearConfigCache();
    }

    private function clearConfigCache(): void
    {
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }
}
