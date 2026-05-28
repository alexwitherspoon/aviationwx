<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for upload health probe configuration helpers.
 */
class UploadHealthProbeConfigTest extends TestCase
{
    private string $originalAppEnv;

    private string $originalConfigPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalAppEnv = getenv('APP_ENV') ?: '';
        $this->originalConfigPath = getenv('CONFIG_PATH') ?: '';
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
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
        $this->clearConfigCache();
        parent::tearDown();
    }

    private function clearConfigCache(): void
    {
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }

    public function testGetUploadHealthProbeSettings_DisabledInTesting(): void
    {
        $settings = getUploadHealthProbeSettings();
        $this->assertFalse($settings['enabled']);
        $this->assertNull($settings['ftps']);
        $this->assertNull($settings['sftp']);
    }

    public function testGetUploadHealthProbeStaleSeconds_ScalesWithInterval(): void
    {
        $stale = getUploadHealthProbeStaleSeconds(30);
        $this->assertSame(75, $stale);
        $this->assertSame(135, getUploadHealthProbeStaleSeconds(60));
    }

    public function testGetUploadHealthProbeSettings_RequiresCredentialsWhenEnabled(): void
    {
        $config = [
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
        ];

        $tmp = $this->writeTempConfig($config);
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';
        $this->clearConfigCache();

        $settings = getUploadHealthProbeSettings();
        @unlink($tmp);

        $this->assertTrue($settings['enabled']);
        $this->assertSame('probeftps', $settings['ftps']['username'] ?? '');
        $this->assertSame('probesftp', $settings['sftp']['username'] ?? '');
        $this->assertSame(30, $settings['interval_sec']);
        $this->assertSame('127.0.0.1', $settings['connect_host']);
        $this->assertSame(75, $settings['stale_sec']);
    }

    public function testGetUploadHealthProbeSettings_StrictEnabledRejectsTruthyNonBool(): void
    {
        $config = [
            'config' => [
                'base_domain' => 'example.org',
                'upload_health_probe' => [
                    'enabled' => 1,
                    'ftps' => ['username' => 'probeftps', 'password' => 'secret'],
                ],
            ],
            'airports' => [],
        ];

        $tmp = $this->writeTempConfig($config);
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';
        $this->clearConfigCache();

        $settings = getUploadHealthProbeSettings();
        @unlink($tmp);

        $this->assertFalse($settings['enabled']);
    }

    public function testValidateUploadHealthProbeConfig_RejectsProbeUserMatchingPushCamera(): void
    {
        $config = [
            'config' => [
                'upload_health_probe' => [
                    'enabled' => true,
                    'ftps' => ['username' => 'cam1user', 'password' => 'x'],
                ],
            ],
            'airports' => [
                'kspb' => [
                    'webcams' => [
                        [
                            'type' => 'push',
                            'push_config' => ['username' => 'cam1user', 'password' => 'y'],
                        ],
                    ],
                ],
            ],
        ];

        $errors = validateUploadHealthProbeConfig($config);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('must not match a push camera username', implode(' ', $errors));
    }

    public function testValidateUploadHealthProbeConfig_EnabledRequiresCredentials(): void
    {
        $config = [
            'config' => [
                'upload_health_probe' => [
                    'enabled' => true,
                ],
            ],
            'airports' => [],
        ];

        $errors = validateUploadHealthProbeConfig($config);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('valid ftps and/or sftp credentials', implode(' ', $errors));
    }

    public function testValidateUploadHealthProbeConfig_EnforcesPasswordShape(): void
    {
        $config = [
            'config' => [
                'upload_health_probe' => [
                    'enabled' => true,
                    'ftps' => ['username' => 'healthprobe1', 'password' => 'short'],
                ],
            ],
            'airports' => [],
        ];

        $errors = validateUploadHealthProbeConfig($config);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('password must be exactly 14 characters', implode(' ', $errors));
    }

    public function testValidateUploadHealthProbeConfig_RejectsUnknownFields(): void
    {
        $config = [
            'config' => [
                'upload_health_probe' => [
                    'enabled' => false,
                    'typo_field' => true,
                ],
            ],
            'airports' => [],
        ];

        $errors = validateUploadHealthProbeConfig($config);
        $this->assertStringContainsString('unknown field', implode(' ', $errors));
    }

    public function testValidateUploadHealthProbeConfig_RejectsNonStringCredentials(): void
    {
        $config = [
            'config' => [
                'upload_health_probe' => [
                    'enabled' => true,
                    'ftps' => ['username' => ['bad'], 'password' => 'abcdefghijklmn'],
                    'sftp' => ['username' => 'probesftp12', 'password' => 12345678901234],
                ],
            ],
            'airports' => [],
        ];

        $errors = validateUploadHealthProbeConfig($config);
        $this->assertNotEmpty($errors);
        $joined = implode(' ', $errors);
        $this->assertStringContainsString('config.upload_health_probe.ftps: username must be a string', $joined);
        $this->assertStringContainsString('config.upload_health_probe.sftp: password must be a string', $joined);
    }

    public function testValidateUploadHealthProbeConfig_AllowsEmptyProbeConnectHost(): void
    {
        $config = [
            'config' => [
                'upload_health_probe' => [
                    'enabled' => false,
                    'probe_connect_host' => '',
                ],
            ],
            'airports' => [],
        ];

        $errors = validateUploadHealthProbeConfig($config);
        $this->assertSame([], $errors);
    }

    public function testValidateAirportsJsonStructure_IncludesUploadHealthProbe(): void
    {
        $config = [
            'config' => [
                'upload_health_probe' => [
                    'enabled' => true,
                    'ftps' => ['username' => 'cam1user', 'password' => 'abcdefghijklmn'],
                ],
            ],
            'airports' => [
                'kspb' => [
                    'webcams' => [
                        [
                            'type' => 'push',
                            'push_config' => ['username' => 'cam1user', 'password' => 'abcdefghijklmn'],
                        ],
                    ],
                ],
            ],
        ];

        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('must not match a push camera username', implode(' ', $result['errors']));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeTempConfig(array $config): string
    {
        $tmp = sys_get_temp_dir() . '/upload-probe-config-' . uniqid('', true) . '.json';
        file_put_contents($tmp, json_encode($config, JSON_THROW_ON_ERROR));
        putenv('CONFIG_PATH=' . $tmp);
        $_ENV['CONFIG_PATH'] = $tmp;
        $_SERVER['CONFIG_PATH'] = $tmp;

        return $tmp;
    }
}
