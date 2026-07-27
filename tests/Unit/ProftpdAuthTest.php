<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ProftpdAuthTest extends TestCase
{
    public function testParseProftpdPasswdFile_DetectsDuplicateUsernames(): void
    {
        require_once __DIR__ . '/../../lib/proftpd-auth.php';

        $path = $this->trackTempFile(sys_get_temp_dir() . '/proftpd-passwd-' . uniqid('', true));
        file_put_contents(
            $path,
            "dupuser14chars:\$1\$abc:101:33::/tmp/dup:/usr/sbin/nologin\n"
            . "dupuser14chars:\$1\$def:101:33::/tmp/dup2:/usr/sbin/nologin\n"
        );

        $parsed = parseProftpdPasswdFile($path);

        $this->assertNotEmpty($parsed['errors']);
        $this->assertStringContainsString("Duplicate ProFTPD username 'dupuser14chars'", $parsed['errors'][0]);
        $this->assertCount(1, $parsed['users']);
    }

    public function testParseProftpdPasswdFile_DetectsMalformedLines(): void
    {
        require_once __DIR__ . '/../../lib/proftpd-auth.php';

        $path = $this->trackTempFile(sys_get_temp_dir() . '/proftpd-passwd-' . uniqid('', true));
        file_put_contents($path, "onlyuser14char\n");

        $parsed = parseProftpdPasswdFile($path);

        $this->assertNotEmpty($parsed['errors']);
        $this->assertStringContainsString('malformed', $parsed['errors'][0]);
        $this->assertSame([], $parsed['users']);
    }

    public function testWriteProftpdPasswdFile_SetsRestrictivePermissions(): void
    {
        require_once __DIR__ . '/../../lib/proftpd-auth.php';

        $path = $this->trackTempFile(sys_get_temp_dir() . '/proftpd-passwd-' . uniqid('', true));
        $this->assertTrue(writeProftpdPasswdFile([
            'userone14chars' => [
                'password' => 'passone14chars',
                'home' => '/tmp/userone14chars',
            ],
        ], $path));
        $this->assertSame(0600, fileperms($path) & 0777);
    }

    public function testWriteProftpdPasswdFile_PreservesExistingPasswordHash(): void
    {
        require_once __DIR__ . '/../../lib/proftpd-auth.php';

        $path = $this->trackTempFile(sys_get_temp_dir() . '/proftpd-passwd-' . uniqid('', true));
        $hash = '$1$fixedhashvalue';
        $this->assertTrue(writeProftpdPasswdFile([
            'userone14chars' => [
                'password' => $hash,
                'home' => '/tmp/userone14chars',
            ],
        ], $path));

        $parsed = parseProftpdPasswdFile($path);
        $this->assertSame($hash, $parsed['users']['userone14chars']['password_hash']);
    }

    public function testWriteProftpdPasswdFile_PreservesSha512CryptHash(): void
    {
        require_once __DIR__ . '/../../lib/proftpd-auth.php';

        $path = $this->trackTempFile(sys_get_temp_dir() . '/proftpd-passwd-' . uniqid('', true));
        $hash = '$6$rounds=5000$saltsaltsalt$abcdefghijklmnopqrstuvwx';
        $this->assertTrue(writeProftpdPasswdFile([
            'userone14chars' => [
                'password' => $hash,
                'home' => '/tmp/userone14chars',
            ],
        ], $path));

        $parsed = parseProftpdPasswdFile($path);
        $this->assertSame($hash, $parsed['users']['userone14chars']['password_hash']);
    }

    public function testIsProftpdTlsEnabled_DetectsTlsEngineOnWithVariableSpacing(): void
    {
        require_once __DIR__ . '/../../lib/proftpd-auth.php';

        $path = $this->trackTempFile(sys_get_temp_dir() . '/proftpd-tls-' . uniqid('', true) . '.conf');
        file_put_contents($path, "<IfModule mod_tls.c>\n  TLSEngine on\n</IfModule>\n");

        $this->assertTrue(isProftpdTlsEnabled($path));
    }

    public function testIsProftpdAuthFileMissing_TreatsEmptyFileAsMissing(): void
    {
        require_once __DIR__ . '/../../lib/proftpd-auth.php';

        $path = $this->trackTempFile(sys_get_temp_dir() . '/proftpd-passwd-' . uniqid('', true));
        touch($path);

        $this->assertTrue(isProftpdAuthFileMissingAtPath($path));

        writeProftpdPasswdFile([
            'userone14chars' => [
                'password' => 'passone14chars',
                'home' => '/tmp/userone14chars',
            ],
        ], $path);

        $this->assertFalse(isProftpdAuthFileMissingAtPath($path));
    }

    public function testIsProftpdAuthFileCorrupted_DetectsParseErrors(): void
    {
        require_once __DIR__ . '/../../lib/proftpd-auth.php';

        $path = $this->trackTempFile(sys_get_temp_dir() . '/proftpd-passwd-' . uniqid('', true));
        file_put_contents($path, "broken-line\n");

        $this->assertTrue(isProftpdAuthFileCorruptedAtPath($path));
    }

    /**
     * @param string $path
     */
    private function trackTempFile(string $path): string
    {
        $this->assertIsString($path);
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });

        return $path;
    }
}
