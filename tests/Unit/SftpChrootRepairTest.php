<?php
/**
 * SFTP chroot permission repair and sync-push-config integration.
 */

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies repair-sftp-chroot-permissions.sh and that sync-push-config repairs before skip.
 */
class SftpChrootRepairTest extends TestCase
{
    private string $sftpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sftpRoot = sys_get_temp_dir() . '/sftp_repair_test_' . uniqid();
        mkdir($this->sftpRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->sftpRoot);
        parent::tearDown();
    }

    /**
     * Repair script restores root-owned chroot after www-data ownership (production failure mode).
     */
    public function testRepairScript_FixesWwwDataOwnedChroot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            $this->markTestSkipped('repair-sftp-chroot-permissions.sh requires root to chown chroot dirs');
        }

        $user = 'testcam1';
        $chroot = $this->sftpRoot . '/' . $user;
        $files = $chroot . '/files';
        mkdir($files, 0775, true);
        chown($chroot, posix_getuid());
        chgrp($chroot, posix_getgid());
        chmod($chroot, 0755);

        $script = dirname(__DIR__, 2) . '/scripts/repair-sftp-chroot-permissions.sh';
        $this->assertTrue(is_executable($script), 'repair script must be executable');

        $cmd = sprintf(
            'SFTP_DIR=%s %s %s 2>&1',
            escapeshellarg($this->sftpRoot),
            escapeshellarg($script),
            escapeshellarg($user)
        );
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));

        $chrootStat = stat($chroot);
        $this->assertNotFalse($chrootStat);
        $this->assertSame(0, $chrootStat['uid'], 'chroot must be root-owned');
        $this->assertSame(0, $chrootStat['gid'], 'chroot must be root group');

        $filesStat = stat($files);
        $this->assertNotFalse($filesStat);
        $ftpUid = $this->resolveUid('ftp');
        $wwwGid = $this->resolveGid('www-data');
        if ($ftpUid !== null) {
            $this->assertSame($ftpUid, $filesStat['uid']);
        }
        if ($wwwGid !== null) {
            $this->assertSame($wwwGid, $filesStat['gid']);
        }
        $this->assertSame(02775, $filesStat['mode'] & 07777);
    }

    /**
     * Repair script rejects invalid usernames (matches create-sftp-user.sh rules).
     */
    public function testRepairScript_RejectsInvalidUsername(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            $this->markTestSkipped('repair script username check runs after root check');
        }

        $script = dirname(__DIR__, 2) . '/scripts/repair-sftp-chroot-permissions.sh';
        $cmd = sprintf('%s %s 2>&1', escapeshellarg($script), escapeshellarg('../bad'));
        exec($cmd, $output, $code);
        $this->assertSame(1, $code, implode("\n", $output));
        $this->assertStringContainsString('invalid username', implode("\n", $output));
    }

    /**
     * sync-push-config must repair SFTP chroots before config skip logic and exit on repair failure.
     */
    public function testSyncPushConfig_InvokesRepairBeforeSkipCheck(): void
    {
        $path = dirname(__DIR__, 2) . '/scripts/sync-push-config.php';
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        $repairPos = strpos($contents, 'if (!repairAllSftpChrootPermissions())');
        $skipPos = strpos($contents, 'config unchanged since last sync, skipping');
        $this->assertNotFalse($repairPos, 'syncPushConfig must call repair before skip');
        $this->assertNotFalse($skipPos);
        $this->assertLessThan($skipPos, $repairPos, 'repair must run before config-unchanged skip');

        $exitPos = strpos($contents, 'exiting because SFTP chroot repair failed');
        $this->assertNotFalse($exitPos, 'syncPushConfig must exit when repair fails');
        $this->assertLessThan($skipPos, $exitPos, 'repair failure exit must precede skip path');
    }

    private function resolveUid(string $name): ?int
    {
        if (!function_exists('posix_getpwnam')) {
            return null;
        }
        $info = @posix_getpwnam($name);
        return ($info !== false && isset($info['uid'])) ? (int) $info['uid'] : null;
    }

    private function resolveGid(string $name): ?int
    {
        if (!function_exists('posix_getgrnam')) {
            return null;
        }
        $info = @posix_getgrnam($name);
        return ($info !== false && isset($info['gid'])) ? (int) $info['gid'] : null;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
