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

    public function testValidateScript_MatchesConfigureProftpdLegacyUploadDaemonFallback(): void
    {
        $contents = file_get_contents($this->validateScript);
        $this->assertIsString($contents);
        $this->assertStringContainsString(
            '$config["config"]["upload_daemon"] ?? $config["upload_daemon"] ?? []',
            $contents
        );
    }

    public function testValidateScript_RequiresRuntimeDirectivesBeforeComparingValues(): void
    {
        $contents = file_get_contents($this->validateScript);
        $this->assertIsString($contents);
        $this->assertStringContainsString('require_runtime_directive', $contents);
        $this->assertStringContainsString('runtime.conf missing ${key} directive', $contents);
        $this->assertStringContainsString('PassivePorts must include min and max port', $contents);
    }

    public function testRuntimeExpectations_ReadLegacyTopLevelUploadDaemon(): void
    {
        $configPath = $this->createConfigFixture([
            'upload_daemon' => [
                'max_instances' => 175,
                'max_clients' => 125,
                'max_clients_per_user' => 3,
            ],
            'config' => [
                'base_domain' => 'example.com',
                'network_ports' => [
                    'ftp_control' => 2121,
                    'ftp_passive_min' => 49152,
                    'ftp_passive_max' => 65535,
                ],
            ],
            'airports' => [],
        ]);

        $json = $this->readRuntimeExpectationsFromConfig($configPath);
        $this->assertSame(175, $json['max_instances']);
        $this->assertSame(125, $json['max_clients']);
        $this->assertSame(3, $json['max_clients_per_user']);
        $this->assertSame(49152, $json['passive_min']);
    }

    public function testAssertRuntimeConf_FailsClearlyWhenDirectiveMissing(): void
    {
        $runtime = $this->createRuntimeFixture("Port                            2121\n");
        $result = $this->runAssertRuntimeConfMatchesConfig($runtime);
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('missing PassivePorts directive', $result['stderr']);
    }

    public function testValidateScript_GuardsEmbeddedPhpFailuresUnderSetE(): void
    {
        $contents = file_get_contents($this->validateScript);
        $this->assertIsString($contents);
        $this->assertStringContainsString('read_proftpd_runtime_expectations || true', $contents);
        $this->assertStringContainsString('read_probe_settings || true', $contents);
    }

    public function testDeployFirewallScript_SkipsDuplicateFtpsExplicitTlsAllow(): void
    {
        $script = dirname(__DIR__, 2) . '/scripts/deploy-configure-firewall.sh';
        $this->assertFileExists($script);
        $contents = file_get_contents($script);
        $this->assertIsString($contents);
        $this->assertStringContainsString('if [ "$FTPS_EXPLICIT_TLS" -ne "$FTP_CONTROL" ]; then', $contents);
        $this->assertStringContainsString('FTP_CONTROL_LABEL="FTP/FTPS (Push webcams)"', $contents);
        $this->assertStringContainsString('FTP_CONTROL_LABEL="FTP (Push webcams)"', $contents);
        $this->assertStringContainsString('${FTP_CONTROL}:tcp:${FTP_CONTROL_LABEL}', $contents);
    }

    private function createRuntimeFixture(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'proftpd-runtime-');
        $this->assertNotFalse($path);
        $written = file_put_contents($path, $contents);
        $this->assertNotFalse($written);
        $this->addToAssertionCount(0);
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });

        return $path;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createConfigFixture(array $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'upload-config-');
        $this->assertNotFalse($path);
        $written = file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
        $this->assertNotFalse($written);
        $this->addToAssertionCount(0);
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });

        return $path;
    }

    /**
     * @return array<string, int>
     */
    private function readRuntimeExpectationsFromConfig(string $configPath): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = sprintf(
            'cd %s && CONFIG_PATH=%s VALIDATE_UPLOAD_FUNCTIONS_ONLY=1 bash -c %s',
            escapeshellarg($repoRoot),
            escapeshellarg($configPath),
            escapeshellarg('. scripts/validate-upload-daemon.sh; read_proftpd_runtime_expectations')
        );
        $output = shell_exec($cmd);
        $this->assertIsString($output);
        $this->assertNotSame('', trim($output));

        /** @var array<string, int> $decoded */
        $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array{exit: int, stderr: string}
     */
    private function runAssertRuntimeConfMatchesConfig(string $runtimePath): array
    {
        $configPath = $this->createConfigFixture([
            'config' => [
                'base_domain' => 'example.com',
                'network_ports' => [
                    'ftp_control' => 2121,
                    'ftp_passive_min' => 49152,
                    'ftp_passive_max' => 65535,
                ],
                'upload_daemon' => [
                    'max_instances' => 200,
                    'max_clients' => 150,
                    'max_clients_per_user' => 2,
                ],
            ],
            'airports' => [],
        ]);

        $repoRoot = dirname(__DIR__, 2);
        $cmd = sprintf(
            'cd %s && CONFIG_PATH=%s PROFTPD_RUNTIME_CONF=%s VALIDATE_UPLOAD_FUNCTIONS_ONLY=1 bash -c %s',
            escapeshellarg($repoRoot),
            escapeshellarg($configPath),
            escapeshellarg($runtimePath),
            escapeshellarg('. scripts/validate-upload-daemon.sh; assert_runtime_conf_matches_config')
        );
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        unset($stdout);

        return [
            'exit' => $exit,
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
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
