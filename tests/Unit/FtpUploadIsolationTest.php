<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

/**
 * FTP upload containment: each camera credential must be jailed to its own inbox,
 * matching the SFTP chroot goal (one compromised camera cannot reach other pipelines).
 */
class FtpUploadIsolationTest extends TestCase
{
    /**
     * ProFTPD must chroot sessions to the AuthUserFile homedir, not only chdir on login.
     *
     * DefaultChdir lands in the inbox but leaves the full ftp uid filesystem visible.
     * DefaultRoot ~ jails the session (same security goal as SFTP chroot + files/).
     */
    public function testProftpdConfig_UsesDefaultRootForSessionContainment(): void
    {
        $configPath = __DIR__ . '/../../docker/proftpd.conf';
        if (!file_exists($configPath)) {
            $this->markTestSkipped('proftpd.conf not found');
        }

        $contents = file_get_contents($configPath);
        $this->assertIsString($contents);
        $this->assertMatchesRegularExpression(
            '/^DefaultRoot\s+~/m',
            $contents,
            'FTP sessions must be chrooted to the user homedir (DefaultRoot ~)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^DefaultChdir\s+~/m',
            $contents,
            'DefaultChdir alone does not contain sessions; use DefaultRoot ~ instead'
        );
    }

    /**
     * Auth file entries must map each username to a distinct inbox homedir.
     */
    public function testAuthUserFile_AssignsDistinctHomedirPerUser(): void
    {
        require_once __DIR__ . '/../../lib/proftpd-auth.php';

        $path = $this->trackTempFile(sys_get_temp_dir() . '/proftpd-isolation-' . uniqid('', true));
        $this->assertTrue(writeProftpdPasswdFile([
            'camalpha' => [
                'password' => '$6$salt$hash',
                'home' => '/var/www/html/cache/ftp/kabc/camalpha',
            ],
            'cambeta' => [
                'password' => '$6$salt$hash2',
                'home' => '/var/www/html/cache/ftp/kabc/cambeta',
            ],
        ], $path));

        $parsed = parseProftpdPasswdFile($path);
        $this->assertSame([], $parsed['errors']);
        $this->assertSame(
            '/var/www/html/cache/ftp/kabc/camalpha',
            $parsed['users']['camalpha']['home']
        );
        $this->assertSame(
            '/var/www/html/cache/ftp/kabc/cambeta',
            $parsed['users']['cambeta']['home']
        );
        $this->assertNotSame(
            $parsed['users']['camalpha']['home'],
            $parsed['users']['cambeta']['home']
        );
    }

    /**
     * sync-push-config must write per-camera homedirs into the ProFTPD auth file.
     */
    public function testSyncPushConfig_ProvisionsPerCameraFtpHomedir(): void
    {
        $path = __DIR__ . '/../../scripts/sync-push-config.php';
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('function upsertFtpVirtualUser', $contents);
        $this->assertStringContainsString("'home' => \$ftpDir", $contents);
        $this->assertStringContainsString('writeProftpdPasswdFile', $contents);
    }

    /**
     * SFTP containment is the reference model documented for push uploads.
     */
    public function testConfigurationDocs_DescribeSftpChrootContainment(): void
    {
        $path = __DIR__ . '/../../docs/CONFIGURATION.md';
        if (!file_exists($path)) {
            $this->markTestSkipped('CONFIGURATION.md not found');
        }

        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('SFTP chroot', $contents);
        $this->assertStringContainsString('/var/sftp/{username}/', $contents);
    }

    /**
     * Isolation probe script exists for validate-upload and integration checks.
     */
    public function testFtpIsolationProbeScript_IsPresentAndValidPython(): void
    {
        $script = __DIR__ . '/../../scripts/ftp-isolation-probe.py';
        $this->assertFileExists($script);
        exec('python3 -m py_compile ' . escapeshellarg($script) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    /**
     * @return non-empty-string
     */
    private function trackTempFile(string $path): string
    {
        $this->assertNotSame('', $path);
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return $path;
    }
}
