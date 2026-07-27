<?php

declare(strict_types=1);

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/config.php';

class UploadEndpointsTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    private ?string $originalConfigPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $previous = getenv('CONFIG_PATH');
        $this->originalConfigPath = $previous === false ? null : $previous;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempPaths = [];

        if ($this->originalConfigPath !== null && $this->originalConfigPath !== '') {
            putenv('CONFIG_PATH=' . $this->originalConfigPath);
        } else {
            putenv('CONFIG_PATH');
        }
        clearConfigCache();

        parent::tearDown();
    }

    public function testGetDefaultUploadCapabilities_AllEnabled(): void
    {
        $caps = getDefaultUploadCapabilities();
        $this->assertTrue($caps['plain_ftp']);
        $this->assertTrue($caps['ftps']);
        $this->assertTrue($caps['sftp']);
        $this->assertTrue($caps['ipv4']);
        $this->assertTrue($caps['ipv6']);
    }

    public function testBuildProftpdMasqueradeConf_SingleIpv4(): void
    {
        $conf = buildProftpdMasqueradeConf([
            'hostname' => 'upload.example.com',
            'ipv4' => '51.81.243.160',
            'ipv6' => null,
        ]);

        $this->assertStringContainsString('MasqueradeAddress               51.81.243.160', $conf);
        $this->assertStringNotContainsString('mod_ifsession', $conf);
    }

    public function testBuildProftpdMasqueradeConf_DualStackUsesIfsession(): void
    {
        $conf = buildProftpdMasqueradeConf([
            'hostname' => 'upload.example.com',
            'ipv4' => '51.81.243.160',
            'ipv6' => '2001:db8::1',
        ]);

        $this->assertStringContainsString('mod_ifsession', $conf);
        $this->assertStringContainsString('51.81.243.160', $conf);
        $this->assertStringContainsString('2001:db8::1', $conf);
        $this->assertStringContainsString('upload_ipv4_mapped', $conf);
    }

    public function testWriteUploadEndpointsCache_AtomicRoundTrip(): void
    {
        $path = $this->trackTempFile(sys_get_temp_dir() . '/upload-endpoints-' . uniqid('', true) . '.json');
        $state = [
            'hostname' => 'upload.example.com',
            'ipv4' => '51.81.243.160',
            'ipv6' => null,
            'resolved_at' => '2026-07-26T12:00:00Z',
            'source' => ['ipv4' => 'dns', 'ipv6' => 'disabled'],
        ];

        $this->assertTrue(writeUploadEndpointsCache($state, $path));
        $this->assertSame(0640, fileperms($path) & 0777);

        $read = readUploadEndpointsCache($path);
        $this->assertIsArray($read);
        $this->assertSame('51.81.243.160', $read['ipv4']);
    }

    public function testUploadEndpointsStateChanged_DetectsIpv4Change(): void
    {
        $previous = ['hostname' => 'upload.example.com', 'ipv4' => '1.2.3.4', 'ipv6' => null];
        $current = ['hostname' => 'upload.example.com', 'ipv4' => '5.6.7.8', 'ipv6' => null];

        $this->assertTrue(uploadEndpointsStateChanged($previous, $current));
        $this->assertFalse(uploadEndpointsStateChanged($current, $current));
    }

    public function testValidateUploadCapabilitiesConfig_RejectsUnknownKey(): void
    {
        $errors = validateUploadCapabilitiesConfig([
            'upload_capabilities' => [
                'plain_ftp' => true,
                'unknown_toggle' => true,
            ],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('unknown key', $errors[0]);
    }

    public function testIsUploadEndpointFullyStatic_WhenBothFamiliesDisabled(): void
    {
        $configPath = $this->trackTempFile(sys_get_temp_dir() . '/airports-' . uniqid('', true) . '.json');
        file_put_contents($configPath, json_encode([
            'config' => [
                'base_domain' => 'example.com',
                'dynamic_dns_refresh_seconds' => 3600,
                'upload_capabilities' => [
                    'plain_ftp' => false,
                    'ftps' => false,
                    'sftp' => false,
                    'ipv4' => false,
                    'ipv6' => false,
                ],
            ],
            'airports' => [],
        ], JSON_THROW_ON_ERROR));

        putenv('CONFIG_PATH=' . $configPath);
        clearConfigCache();

        $this->assertTrue(isUploadEndpointFullyStatic());
        $this->assertSame(0, getDynamicDnsRefreshSeconds());
    }

    public function testValidateUploadEndpointsForCapabilities_FailsWhenIpv6OnlyAndMissing(): void
    {
        $configPath = $this->trackTempFile(sys_get_temp_dir() . '/airports-' . uniqid('', true) . '.json');
        file_put_contents($configPath, json_encode([
            'config' => [
                'base_domain' => 'example.com',
                'upload_capabilities' => [
                    'plain_ftp' => true,
                    'ftps' => true,
                    'sftp' => true,
                    'ipv4' => false,
                    'ipv6' => true,
                ],
            ],
            'airports' => [],
        ], JSON_THROW_ON_ERROR));

        putenv('CONFIG_PATH=' . $configPath);
        clearConfigCache();

        $error = validateUploadEndpointsForCapabilities([
            'hostname' => 'upload.example.com',
            'ipv4' => null,
            'ipv6' => null,
        ]);

        $this->assertSame(
            'IPv6 upload endpoint unavailable (IPv6-only capability enabled)',
            $error
        );
    }

    public function testValidateUploadEndpointsForCapabilities_WarnsOnMissingIpv6WhenDualStack(): void
    {
        $error = validateUploadEndpointsForCapabilities([
            'hostname' => 'upload.example.com',
            'ipv4' => '51.81.243.160',
            'ipv6' => null,
        ]);

        $this->assertNull($error);
        $warnings = collectUploadEndpointWarnings([
            'hostname' => 'upload.example.com',
            'ipv4' => '51.81.243.160',
            'ipv6' => null,
        ]);
        $this->assertContains('IPv6 upload endpoint unavailable (capability enabled)', $warnings);
    }

    public function testGetDynamicDnsAcceleratedRefreshSeconds_UsesBaselineWhenUnset(): void
    {
        $configPath = $this->trackTempFile(sys_get_temp_dir() . '/airports-' . uniqid('', true) . '.json');
        file_put_contents($configPath, json_encode([
            'config' => [
                'base_domain' => 'example.com',
                'dynamic_dns_refresh_seconds' => 3600,
            ],
            'airports' => [],
        ], JSON_THROW_ON_ERROR));

        putenv('CONFIG_PATH=' . $configPath);
        clearConfigCache();

        $this->assertSame(3600, getDynamicDnsAcceleratedRefreshSeconds());
    }

    public function testShouldAccelerateUploadEndpointRefresh_WhenFtpsProbeFails(): void
    {
        $path = $this->trackTempFile(sys_get_temp_dir() . '/upload-probe-' . uniqid('', true) . '.json');
        file_put_contents($path, json_encode([
            'ftps' => ['ok' => false, 'skipped' => false],
            'sftp' => ['ok' => true, 'skipped' => true],
        ], JSON_THROW_ON_ERROR));

        $configPath = $this->trackTempFile(sys_get_temp_dir() . '/airports-' . uniqid('', true) . '.json');
        file_put_contents($configPath, json_encode([
            'config' => [
                'base_domain' => 'example.com',
                'dynamic_dns_refresh_seconds' => 3600,
            ],
            'airports' => [],
        ], JSON_THROW_ON_ERROR));

        putenv('CONFIG_PATH=' . $configPath);
        $this->assertTrue(shouldAccelerateUploadEndpointRefresh($path));
    }

    public function testBuildProftpdTlsCapabilityConf_RequiresTlsWhenPlainFtpDisabled(): void
    {
        $cert = $this->trackTempFile(sys_get_temp_dir() . '/upload-tls-cert-' . uniqid('', true) . '.pem');
        $key = $this->trackTempFile(sys_get_temp_dir() . '/upload-tls-key-' . uniqid('', true) . '.pem');
        file_put_contents($cert, "cert\n");
        file_put_contents($key, "key\n");

        $conf = buildProftpdTlsCapabilityConf(
            $cert,
            $key,
            ['plain_ftp' => false, 'ftps' => true, 'sftp' => true, 'ipv4' => true, 'ipv6' => true]
        );

        $this->assertStringContainsString('TLSRequired                    on', $conf);
    }

    public function testBuildProftpdListenersConf_Ipv4OnlyDisablesIpv6(): void
    {
        $conf = buildProftpdListenersConf([
            'plain_ftp' => true,
            'ftps' => true,
            'sftp' => true,
            'ipv4' => true,
            'ipv6' => false,
        ]);

        $this->assertStringContainsString('UseIPv6                         off', $conf);
    }

    /**
     * @param string $path
     */
    private function trackTempFile(string $path): string
    {
        $this->tempPaths[] = $path;

        return $path;
    }
}
