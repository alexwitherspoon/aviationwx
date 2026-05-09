<?php
/**
 * Unit tests for hasWebcamAcquisitionConfigured()
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/webcam-source-validation.php';

class WebcamSourceValidationTest extends TestCase
{
    public function testPullRequiresNonEmptyUrl(): void
    {
        $this->assertFalse(hasWebcamAcquisitionConfigured(['name' => 'Cam']));
        $this->assertTrue(hasWebcamAcquisitionConfigured(['url' => 'https://example.com/x.mjpg']));
    }

    public function testExplicitDisabledSkips(): void
    {
        $cam = ['url' => 'https://example.com/x.mjpg', 'enabled' => false];
        $this->assertFalse(hasWebcamAcquisitionConfigured($cam));
    }

    public function testPushRequiresUsername(): void
    {
        $this->assertFalse(hasWebcamAcquisitionConfigured([
            'type' => 'push',
            'push_config' => [],
        ]));
        $this->assertTrue(hasWebcamAcquisitionConfigured([
            'type' => 'push',
            'push_config' => ['username' => 'camuser'],
        ]));
    }

    public function testPushWithoutPushConfigArrayIsNotConfigured(): void
    {
        $this->assertFalse(hasWebcamAcquisitionConfigured([
            'type' => 'push',
            'name' => 'Incomplete push slot',
        ]));
    }

    /**
     * Usernames are strings; "0" must not be treated as missing (PHP falsy pitfall).
     */
    public function testPushUsernameStringZeroIsConfigured(): void
    {
        $this->assertTrue(hasWebcamAcquisitionConfigured([
            'type' => 'push',
            'push_config' => ['username' => '0'],
        ]));
    }

    public function testAviationwxApiRequiresBaseUrl(): void
    {
        $this->assertFalse(hasWebcamAcquisitionConfigured([
            'type' => 'aviationwx_api',
        ]));
        $this->assertTrue(hasWebcamAcquisitionConfigured([
            'type' => 'aviationwx_api',
            'base_url' => 'https://partner.example.com',
        ]));
    }
}
