<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for webcam history retention resolution from airports.json config.
 */
class WebcamHistoryRetentionConfigTest extends TestCase
{
    /** @var string|false */
    private $originalConfigPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigPath = getenv('CONFIG_PATH');
        $this->clearConfigCache();
    }

    protected function tearDown(): void
    {
        if ($this->originalConfigPath !== false) {
            putenv('CONFIG_PATH=' . $this->originalConfigPath);
            $_ENV['CONFIG_PATH'] = $this->originalConfigPath;
            $_SERVER['CONFIG_PATH'] = $this->originalConfigPath;
        } else {
            putenv('CONFIG_PATH');
            unset($_ENV['CONFIG_PATH'], $_SERVER['CONFIG_PATH']);
        }
        $this->clearConfigCache();
        parent::tearDown();
    }

    public function testGetWebcamHistoryRetentionHours_HonorsGlobalConfigBlock(): void
    {
        $tmp = $this->writeTempConfig([
            'config' => [
                'base_domain' => 'example.org',
                'webcam_history_retention_hours' => 0,
            ],
            'airports' => [
                'kspb' => [
                    'enabled' => true,
                    'webcams' => [['name' => 'Cam', 'url' => 'https://example.com/cam.jpg']],
                ],
            ],
        ]);

        try {
            $this->assertSame(0.0, getWebcamHistoryRetentionHours('kspb'));
            $this->assertFalse(isWebcamHistoryEnabledForAirport('kspb'));
        } finally {
            @unlink($tmp);
        }
    }

    public function testGetWebcamHistoryRetentionHours_AirportOverrideTakesPrecedence(): void
    {
        $tmp = $this->writeTempConfig([
            'config' => [
                'base_domain' => 'example.org',
                'webcam_history_retention_hours' => 24,
            ],
            'airports' => [
                'kspb' => [
                    'enabled' => true,
                    'webcam_history_retention_hours' => 6,
                    'webcams' => [['name' => 'Cam', 'url' => 'https://example.com/cam.jpg']],
                ],
            ],
        ]);

        try {
            $this->assertSame(6.0, getWebcamHistoryRetentionHours('kspb'));
            $this->assertTrue(isWebcamHistoryEnabledForAirport('kspb'));
        } finally {
            @unlink($tmp);
        }
    }

    private function clearConfigCache(): void
    {
        if (function_exists('clearConfigCache')) {
            clearConfigCache();
        }
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeTempConfig(array $config): string
    {
        $tmp = sys_get_temp_dir() . '/webcam-history-config-' . uniqid('', true) . '.json';
        file_put_contents($tmp, json_encode($config, JSON_THROW_ON_ERROR));
        putenv('CONFIG_PATH=' . $tmp);
        $_ENV['CONFIG_PATH'] = $tmp;
        $_SERVER['CONFIG_PATH'] = $tmp;
        $this->clearConfigCache();

        return $tmp;
    }
}
