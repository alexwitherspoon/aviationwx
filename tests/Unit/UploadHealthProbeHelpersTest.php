<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Behavioral tests for upload probe helpers in upload-daemon-common.sh.
 */
class UploadHealthProbeHelpersTest extends TestCase
{
    private string $commonScript;

    protected function setUp(): void
    {
        parent::setUp();
        $this->commonScript = dirname(__DIR__, 2) . '/scripts/upload-daemon-common.sh';
        $this->assertFileExists($this->commonScript);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function tlsVerifyHostProvider(): array
    {
        return [
            'loopback ipv4' => ['127.0.0.1', 0],
            'localhost' => ['localhost', 0],
            'loopback ipv6' => ['::1', 0],
            'bare ipv4' => ['10.0.0.5', 0],
            'public hostname' => ['upload.aviationwx.org', 1],
            'empty' => ['', 1],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tlsVerifyHostProvider')]
    public function testProbeHostSkipsTlsVerify_HostCases_MatchExitCode(string $host, int $expectedExitCode): void
    {
        $cmd = sprintf(
            'bash -c %s',
            escapeshellarg('. ' . $this->commonScript . ' && probe_host_skips_tls_verify ' . escapeshellarg($host))
        );
        exec($cmd, $output, $code);
        $this->assertSame($expectedExitCode, $code, implode("\n", $output));
    }

    public function testProbeCurlFailDetail_EmptyFile_ReturnsGenericPrefix(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'probe-err-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, '');

        $detail = $this->runHelperCapture(
            'probe_curl_fail_detail ftps ' . escapeshellarg($tmp)
        );
        @unlink($tmp);

        $this->assertSame('ftps upload failed', $detail);
    }

    public function testProbeCurlFailDetail_StripsPipesPasswordsAndNewlines(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'probe-err-');
        $this->assertNotFalse($tmp);
        file_put_contents(
            $tmp,
            "curl: (60) SSL mismatch\npassword=secretvalue|extra\nPASS ignored\n"
        );

        $detail = $this->runHelperCapture(
            'probe_curl_fail_detail sftp ' . escapeshellarg($tmp)
        );
        @unlink($tmp);

        $this->assertStringStartsWith('sftp upload failed: ', $detail);
        $this->assertStringNotContainsString('|', $detail);
        $this->assertStringNotContainsString('secretvalue', $detail);
        $this->assertStringContainsString('password <redacted>', $detail);
        $this->assertStringContainsString('SSL mismatch', $detail);
    }

    public function testProbeLocalUploadPath_AlphanumericUser_ReturnsExpectedPaths(): void
    {
        $ftps = $this->runHelperCapture(
            'probe_local_upload_path ftps awxprobeftps aviationwx-probe-healthcheck.txt'
        );
        $sftp = $this->runHelperCapture(
            'probe_local_upload_path sftp awxprobesftp aviationwx-probe-healthcheck.txt'
        );

        $this->assertSame(
            '/var/www/html/cache/ftp/_probe/awxprobeftps/aviationwx-probe-healthcheck.txt',
            $ftps
        );
        $this->assertSame(
            '/var/sftp/awxprobesftp/files/aviationwx-probe-healthcheck.txt',
            $sftp
        );
    }

    public function testProbeLocalUploadPath_UnsafeUsername_FailsClosed(): void
    {
        $cmd = sprintf(
            'bash -c %s',
            escapeshellarg(
                '. ' . $this->commonScript
                . ' && probe_local_upload_path sftp "../etc" evil.txt'
            )
        );
        exec($cmd, $output, $code);
        $this->assertSame(1, $code);
        $this->assertSame('', trim(implode("\n", $output)));
    }

    public function testClearLocalProbeUploadFile_RemovesExistingFile(): void
    {
        $dir = sys_get_temp_dir() . '/probe-clear-' . uniqid('', true);
        $this->assertTrue(mkdir($dir, 0700, true));
        $path = $dir . '/aviationwx-probe-healthcheck.txt';
        file_put_contents($path, 'stale');

        $this->runHelperCapture('clear_local_probe_upload_file ' . escapeshellarg($path));

        $this->assertFileDoesNotExist($path);
        @rmdir($dir);
    }

    private function runHelperCapture(string $helperInvocation): string
    {
        $cmd = sprintf(
            'bash -c %s',
            escapeshellarg('. ' . $this->commonScript . ' && ' . $helperInvocation)
        );
        exec($cmd, $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));

        return trim(implode("\n", $output));
    }
}
