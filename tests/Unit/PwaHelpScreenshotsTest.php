<?php

declare(strict_types=1);

/**
 * Unit tests for PWA help screenshot path resolution (airport map page).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/pwa-help-screenshots.php';

class PwaHelpScreenshotsTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/pwa-help-test-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->tempDir, 0700, true));
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*') ?: [];
            foreach ($files as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testGetPwaHelpScreenshotSet_ReturnsNullWhenNoFiles(): void
    {
        $this->assertNull(getPwaHelpScreenshotSet($this->tempDir, 'pwa-add-to-home-screen-android'));
    }

    public function testGetPwaHelpScreenshotSet_ReturnsJpgOnly(): void
    {
        $base = 'pwa-add-to-home-screen-android';
        touch($this->tempDir . '/' . $base . '.jpg');
        $set = getPwaHelpScreenshotSet($this->tempDir, $base);
        $this->assertIsArray($set);
        $this->assertNull($set['webp']);
        $this->assertSame('/public/images/' . $base . '.jpg', $set['jpg']);
    }

    public function testGetPwaHelpScreenshotSet_PrefersJpgOverJpegExtension(): void
    {
        $base = 'pwa-add-to-home-screen-ios';
        touch($this->tempDir . '/' . $base . '.jpg');
        touch($this->tempDir . '/' . $base . '.jpeg');
        $set = getPwaHelpScreenshotSet($this->tempDir, $base);
        $this->assertIsArray($set);
        $this->assertSame('/public/images/' . $base . '.jpg', $set['jpg']);
    }

    public function testGetPwaHelpScreenshotSet_UsesJpegWhenNoJpg(): void
    {
        $base = 'pwa-add-to-home-screen-ios';
        touch($this->tempDir . '/' . $base . '.jpeg');
        $set = getPwaHelpScreenshotSet($this->tempDir, $base);
        $this->assertIsArray($set);
        $this->assertSame('/public/images/' . $base . '.jpeg', $set['jpg']);
    }

    public function testGetPwaHelpScreenshotSet_WebpAndJpg(): void
    {
        $base = 'pwa-add-to-home-screen-android';
        touch($this->tempDir . '/' . $base . '.webp');
        touch($this->tempDir . '/' . $base . '.jpg');
        $set = getPwaHelpScreenshotSet($this->tempDir, $base);
        $this->assertIsArray($set);
        $this->assertSame('/public/images/' . $base . '.webp', $set['webp']);
        $this->assertSame('/public/images/' . $base . '.jpg', $set['jpg']);
    }

    public function testGetPwaHelpScreenshotImgFallback_PrefersJpgThenWebp(): void
    {
        $this->assertSame(
            '/public/images/x.jpg',
            getPwaHelpScreenshotImgFallback([
                'webp' => '/public/images/x.webp',
                'jpg' => '/public/images/x.jpg',
            ])
        );
        $this->assertSame(
            '/public/images/x.webp',
            getPwaHelpScreenshotImgFallback([
                'webp' => '/public/images/x.webp',
                'jpg' => null,
            ])
        );
    }

    public function testGetPwaHelpScreenshotImgFallback_ReturnsEmptyWhenAllNull(): void
    {
        $this->assertSame(
            '',
            getPwaHelpScreenshotImgFallback(['webp' => null, 'jpg' => null])
        );
    }
}