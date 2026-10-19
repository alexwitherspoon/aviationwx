<?php
/**
 * Test ProFTPD configuration files for syntax validity.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/upload-endpoints.php';

class ProftpdConfigTest extends TestCase
{
    /**
     * Test ProFTPD config syntax is valid.
     */
    public function testProftpdConfig_IsValid(): void
    {
        $configPath = __DIR__ . '/../../docker/proftpd.conf';
        if (!file_exists($configPath)) {
            $this->markTestSkipped('proftpd.conf not found');
        }

        $modulesConf = '/etc/proftpd/modules.conf';
        if (!file_exists($modulesConf)) {
            $this->markTestSkipped('proftpd modules.conf not available in CI host');
        }

        $whichOutput = [];
        $whichCode = 0;
        exec('command -v proftpd 2>/dev/null', $whichOutput, $whichCode);
        if ($whichCode !== 0 || $whichOutput === []) {
            $this->markTestSkipped('proftpd binary not available in CI host');
        }

        $fragDir = sys_get_temp_dir() . '/proftpd-frag-' . uniqid('', true);
        mkdir($fragDir, 0755, true);
        // Keep the main config outside the fragment dir, otherwise the rewritten
        // Include glob below picks it up and proftpd -t recurses into itself.
        $testConf = sys_get_temp_dir() . '/proftpd-test-' . uniqid('', true) . '.conf';
        $this->addToAssertionCount(0);
        register_shutdown_function(static function () use ($fragDir, $testConf): void {
            @unlink($fragDir . '/runtime.conf');
            @unlink($fragDir . '/masquerade.conf');
            @unlink($fragDir . '/tls.conf');
            @rmdir($fragDir);
            @unlink($testConf);
        });

        file_put_contents($fragDir . '/runtime.conf', "Port 2121\nPassivePorts 49152 49160\n");
        file_put_contents(
            $fragDir . '/masquerade.conf',
            buildProftpdMasqueradeConf([
                'hostname' => 'upload.example.com',
                'ipv4' => '51.81.243.160',
                'ipv6' => '2001:db8::1',
            ])
        );
        file_put_contents($fragDir . '/tls.conf', "<IfModule mod_tls.c>\n  TLSEngine off\n</IfModule>\n");

        $base = file_get_contents($configPath);
        $base = str_replace('/etc/proftpd/conf.d/*.conf', $fragDir . '/*.conf', $base);
        file_put_contents($testConf, $base);

        exec('proftpd -t -c ' . escapeshellarg($testConf) . ' 2>&1', $output, $returnCode);
        $outputStr = implode("\n", $output);

        if ($returnCode !== 0) {
            $this->fail("ProFTPD config has syntax errors:\n" . $outputStr);
        }

        $this->assertSame(0, $returnCode);
    }

    /**
     * ProFTPD base config should enable dual-stack and per-user roots.
     */
    public function testProftpdConfig_HasDualStackSettings(): void
    {
        $configPath = __DIR__ . '/../../docker/proftpd.conf';
        if (!file_exists($configPath)) {
            $this->markTestSkipped('proftpd.conf not found');
        }

        $contents = file_get_contents($configPath);
        $this->assertIsString($contents);
        $this->assertStringContainsString('UseIPv6', $contents);
        $this->assertMatchesRegularExpression('/^UseIPv6\s+on\b/m', $contents);
        $this->assertStringContainsString('AuthUserFile', $contents);
        $this->assertStringContainsString('mod_tls.c', $contents);
        $this->assertStringContainsString('Umask', $contents);
        $this->assertStringContainsString('002', $contents);
    }
}
