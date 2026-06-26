<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-headers.php';
require_once __DIR__ . '/../../lib/upload-ssh-host-keys.php';

/**
 * Unit tests for lib/upload-ssh-host-keys.php and no-store cache headers.
 */
class UploadSshHostKeysTest extends TestCase
{
    private string $tempSshDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempSshDir = sys_get_temp_dir() . '/aviationwx_ssh_host_keys_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempSshDir, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->tempSshDir !== '' && is_dir($this->tempSshDir)) {
            foreach (glob($this->tempSshDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tempSshDir);
        }
        parent::tearDown();
    }

    public function testSshPublicKeySha256Fingerprint_MatchesSshKeygen(): void
    {
        $keyPath = $this->tempSshDir . '/test_host_key';
        $genCmd = sprintf(
            'ssh-keygen -t ed25519 -f %s -N %s -q 2>/dev/null',
            escapeshellarg($keyPath),
            escapeshellarg('')
        );
        exec($genCmd, $genOutput, $genExitCode);
        if ($genExitCode !== 0 || !is_readable($keyPath . '.pub')) {
            $this->markTestSkipped('ssh-keygen unavailable for fingerprint cross-check');
        }

        $pubLine = trim((string) file_get_contents($keyPath . '.pub'));
        $lfCmd = sprintf('ssh-keygen -lf %s -E sha256 2>/dev/null', escapeshellarg($keyPath . '.pub'));
        exec($lfCmd, $lfOutput, $lfExitCode);
        if ($lfExitCode !== 0 || ($lfOutput[0] ?? '') === '') {
            $this->markTestSkipped('ssh-keygen -lf unavailable for fingerprint cross-check');
        }

        preg_match('/SHA256:[^\s]+/', (string) $lfOutput[0], $matches);
        $this->assertNotEmpty($matches[0] ?? null, 'ssh-keygen should emit SHA256 fingerprint');

        $computed = sshPublicKeySha256Fingerprint($pubLine);
        $this->assertSame($matches[0], $computed);
    }

    public function testCollectSshHostKeySha256Fingerprints_ReadsHostKeyGlob(): void
    {
        $sourceKey = $this->tempSshDir . '/source_key';
        exec(sprintf('ssh-keygen -t ed25519 -f %s -N %s -q 2>/dev/null', escapeshellarg($sourceKey), escapeshellarg('')), $unused, $exitCode);
        if ($exitCode !== 0) {
            $this->markTestSkipped('ssh-keygen unavailable');
        }

        $hostPub = $this->tempSshDir . '/ssh_host_ed25519_key.pub';
        copy($sourceKey . '.pub', $hostPub);

        $fingerprints = collectSshHostKeySha256Fingerprints($this->tempSshDir);
        $this->assertCount(1, $fingerprints);
        $this->assertStringStartsWith('SHA256:', $fingerprints[0]);
    }

    public function testBuildUploadSshHostKeysDocument_UsesConfigHostnameAndPort(): void
    {
        $sourceKey = $this->tempSshDir . '/source_key';
        exec(sprintf('ssh-keygen -t ed25519 -f %s -N %s -q 2>/dev/null', escapeshellarg($sourceKey), escapeshellarg('')), $unused, $exitCode);
        if ($exitCode !== 0) {
            $this->markTestSkipped('ssh-keygen unavailable');
        }
        copy($sourceKey . '.pub', $this->tempSshDir . '/ssh_host_ed25519_key.pub');

        $document = buildUploadSshHostKeysDocument($this->tempSshDir);
        $this->assertIsArray($document);
        $this->assertSame(1, $document['version']);
        $this->assertSame(getUploadHostname(), $document['hostname']);
        $this->assertSame(getSftpPort(), $document['port']);
        $this->assertNotEmpty($document['sha256']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $document['updated_at']);
    }

    public function testCollectSshHostKeySha256Fingerprints_ReturnsNullWhenHostKeyUnreadable(): void
    {
        $sourceKey = $this->tempSshDir . '/source_key';
        exec(sprintf('ssh-keygen -t ed25519 -f %s -N %s -q 2>/dev/null', escapeshellarg($sourceKey), escapeshellarg('')), $unused, $exitCode);
        if ($exitCode !== 0) {
            $this->markTestSkipped('ssh-keygen unavailable');
        }

        $hostPub = $this->tempSshDir . '/ssh_host_ed25519_key.pub';
        copy($sourceKey . '.pub', $hostPub);
        chmod($hostPub, 0000);

        try {
            if (is_readable($hostPub)) {
                $this->markTestSkipped('Cannot simulate unreadable host key file (likely running as root)');
            }

            $this->assertNull(collectSshHostKeySha256Fingerprints($this->tempSshDir));
        } finally {
            chmod($hostPub, 0600);
        }
    }

    public function testBuildUploadSshHostKeysDocument_ReturnsNullWhenNoKeys(): void
    {
        $emptyDir = $this->tempSshDir . '_empty';
        mkdir($emptyDir, 0700, true);
        $this->assertNull(buildUploadSshHostKeysDocument($emptyDir));
        rmdir($emptyDir);
    }

    public function testGetNoStoreCacheHeaders_AreAggressiveWithLowTtl(): void
    {
        $headers = getNoStoreCacheHeaders(0);
        $this->assertStringContainsString('no-store', $headers['Cache-Control']);
        $this->assertStringContainsString('no-cache', $headers['Cache-Control']);
        $this->assertStringContainsString('must-revalidate', $headers['Cache-Control']);
        $this->assertStringContainsString('max-age=0', $headers['Cache-Control']);
        $this->assertStringContainsString('s-maxage=0', $headers['Cache-Control']);
        $this->assertSame('no-cache', $headers['Pragma']);
        $this->assertSame('0', $headers['Expires']);
    }
}
