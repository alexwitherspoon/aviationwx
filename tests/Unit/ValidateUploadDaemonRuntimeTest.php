<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests ProFTPD runtime.conf directive parsing used by validate-upload-daemon.sh.
 */
class ValidateUploadDaemonRuntimeTest extends TestCase
{
    private string $validateScript;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validateScript = dirname(__DIR__, 2) . '/scripts/validate-upload-daemon.sh';
        $this->assertFileExists($this->validateScript);
    }

    public function testRuntimeDirectiveParsing_ReadsProftpdSpacing(): void
    {
        $runtime = $this->createRuntimeFixture(
            "Port                            2121\n"
            . "PassivePorts                    49152 65535\n"
            . "MaxInstances                    200\n"
            . "MaxClients                      150\n"
            . "MaxClientsPerUser               2\n"
        );

        $this->assertSame('2121', $this->readDirective($runtime, 'Port'));
        $this->assertSame('49152 65535', $this->readDirective($runtime, 'PassivePorts'));
        $this->assertSame('200', $this->readDirective($runtime, 'MaxInstances'));
        $this->assertSame('150', $this->readDirective($runtime, 'MaxClients'));
        $this->assertSame('2', $this->readDirective($runtime, 'MaxClientsPerUser'));
    }

    public function testValidateScript_AssertRuntimeConfMatchesConfigPresent(): void
    {
        $contents = file_get_contents($this->validateScript);
        $this->assertIsString($contents);
        $this->assertStringContainsString('assert_runtime_conf_matches_config', $contents);
        $this->assertStringContainsString('read_proftpd_runtime_directive', $contents);
        $this->assertStringContainsString('cannot read ${PROFTPD_RUNTIME_CONF}', $contents);
    }

    public function testValidateScript_SkipsEndpointCachePasvCheckForLoopbackHost(): void
    {
        $contents = file_get_contents($this->validateScript);
        $this->assertIsString($contents);
        $this->assertStringContainsString('is_loopback_probe_host', $contents);
        $this->assertStringContainsString('does not match endpoint cache', $contents);
    }

    public function testDeployFirewallScript_SkipsDuplicateFtpsExplicitTlsAllow(): void
    {
        $script = dirname(__DIR__, 2) . '/scripts/deploy-configure-firewall.sh';
        $this->assertFileExists($script);
        $contents = file_get_contents($script);
        $this->assertIsString($contents);
        $this->assertStringContainsString('if [ "$FTPS_EXPLICIT_TLS" -ne "$FTP_CONTROL" ]; then', $contents);
        $this->assertStringContainsString('FTP/FTPS (Push webcams)', $contents);
    }

    private function createRuntimeFixture(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'proftpd-runtime-');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);
        $this->addToAssertionCount(0);
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });

        return $path;
    }

    private function readDirective(string $runtimePath, string $key): string
    {
        $cmd = sprintf(
            "awk -v k=%s '\$1 == k { \$1=\"\"; sub(/^ +/, \"\"); print; exit }' %s",
            escapeshellarg($key),
            escapeshellarg($runtimePath)
        );
        exec($cmd, $output, $code);
        $this->assertSame(0, $code);

        return trim(implode("\n", $output));
    }
}
