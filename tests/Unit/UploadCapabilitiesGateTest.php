<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the config-level predicate that tells whether the upload cluster
 * has any push camera to serve.
 */
class UploadCapabilitiesGateTest extends TestCase
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

    public function testHasPushCamerasConfigured_FalseWhenOnlyPullCameras(): void
    {
        $tmp = $this->writeTempConfig($this->airportConfig([
            ['name' => 'Pull Cam', 'url' => 'https://example.com/cam.jpg'],
        ]));

        try {
            $this->assertFalse(hasPushCamerasConfigured());
        } finally {
            @unlink($tmp);
        }
    }

    public function testHasPushCamerasConfigured_TrueWhenPushCameraByType(): void
    {
        $tmp = $this->writeTempConfig($this->airportConfig([
            $this->pushCamera(),
        ]));

        try {
            $this->assertTrue(hasPushCamerasConfigured());
        } finally {
            @unlink($tmp);
        }
    }

    public function testHasPushCamerasConfigured_TrueWhenPushCameraByPushConfig(): void
    {
        $cam = $this->pushCamera();
        unset($cam['type']);
        $tmp = $this->writeTempConfig($this->airportConfig([$cam]));

        try {
            $this->assertTrue(hasPushCamerasConfigured());
        } finally {
            @unlink($tmp);
        }
    }

    private function clearConfigCache(): void
    {
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeTempConfig(array $config): string
    {
        $tmp = sys_get_temp_dir() . '/upload-capabilities-' . uniqid('', true) . '.json';
        file_put_contents($tmp, json_encode($config, JSON_THROW_ON_ERROR));
        putenv('CONFIG_PATH=' . $tmp);
        $_ENV['CONFIG_PATH'] = $tmp;
        $_SERVER['CONFIG_PATH'] = $tmp;

        return $tmp;
    }

    /**
     * @param list<array<string, mixed>> $webcams
     * @return array<string, mixed>
     */
    private function airportConfig(array $webcams): array
    {
        return [
            'config' => ['base_domain' => 'example.org'],
            'airports' => [
                'kspb' => ['enabled' => true, 'webcams' => $webcams],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pushCamera(): array
    {
        return [
            'name' => 'Push Cam',
            'type' => 'push',
            'refresh_seconds' => 60,
            'approximate_heading' => 0,
            'push_config' => [
                'username' => 'kspbCam0Push01',
                'password' => 'SecurePass1234',
                'max_file_size_mb' => 10,
                'allowed_extensions' => ['jpg', 'jpeg'],
            ],
        ];
    }
}