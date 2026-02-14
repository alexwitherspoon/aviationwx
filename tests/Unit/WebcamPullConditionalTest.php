<?php
/**
 * Unit Tests for Pull Webcam HTTP Conditional + Checksum Optimization
 *
 * Safety-critical: Ensures unchanged images are not misrepresented.
 * Tests metadata storage and checksum computation used by static/federated pull sources.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/webcam-pull-metadata.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/config.php';

class WebcamPullConditionalTest extends TestCase
{
    private const TEST_AIRPORT = 'unit_pull_cond_test';

    protected function setUp(): void
    {
        parent::setUp();
        $camDir = getWebcamCameraDir(self::TEST_AIRPORT, 0);
        if (!is_dir($camDir)) {
            @mkdir($camDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $metadataPath = getWebcamPullMetadataPath(self::TEST_AIRPORT, 0);
        if (file_exists($metadataPath)) {
            @unlink($metadataPath);
        }
        parent::tearDown();
    }

    public function testGetWebcamPullMetadataPath_ReturnsExpectedPath(): void
    {
        $path = getWebcamPullMetadataPath('kspb', 0);
        $this->assertStringEndsWith('/0/pull_metadata.json', $path);
        $this->assertStringContainsString('kspb', $path);
    }

    public function testGetWebcamPullMetadata_NoFile_ReturnsNulls(): void
    {
        $metadataPath = getWebcamPullMetadataPath(self::TEST_AIRPORT, 0);
        if (file_exists($metadataPath)) {
            @unlink($metadataPath);
        }

        $result = getWebcamPullMetadata(self::TEST_AIRPORT, 0);
        $this->assertNull($result['etag']);
        $this->assertNull($result['checksum']);
    }

    public function testGetWebcamPullMetadata_ValidFile_ReturnsStoredValues(): void
    {
        $metadataPath = getWebcamPullMetadataPath(self::TEST_AIRPORT, 0);
        $data = ['etag' => '"abc123"', 'checksum' => 'deadbeef' . str_repeat('0', 56)];
        file_put_contents($metadataPath, json_encode($data));

        $result = getWebcamPullMetadata(self::TEST_AIRPORT, 0);
        $this->assertSame('"abc123"', $result['etag']);
        $this->assertSame('deadbeef' . str_repeat('0', 56), $result['checksum']);
    }

    public function testGetWebcamPullMetadata_CorruptFile_ReturnsNulls(): void
    {
        $metadataPath = getWebcamPullMetadataPath(self::TEST_AIRPORT, 0);
        file_put_contents($metadataPath, 'not valid json{');

        $result = getWebcamPullMetadata(self::TEST_AIRPORT, 0);
        $this->assertNull($result['etag']);
        $this->assertNull($result['checksum']);
    }

    public function testSaveWebcamPullMetadata_WritesCorrectData(): void
    {
        $success = saveWebcamPullMetadata(self::TEST_AIRPORT, 0, '"etag-value"', 'a' . str_repeat('0', 63));
        $this->assertTrue($success);

        $metadataPath = getWebcamPullMetadataPath(self::TEST_AIRPORT, 0);
        $content = file_get_contents($metadataPath);
        $data = json_decode($content, true);
        $this->assertSame('"etag-value"', $data['etag']);
        $this->assertSame('a' . str_repeat('0', 63), $data['checksum']);
    }

    public function testSaveWebcamPullMetadata_CreatesDirectory(): void
    {
        $newAirport = 'unit_pull_new_' . uniqid();
        $success = saveWebcamPullMetadata($newAirport, 0, '"new-etag"', null);
        $this->assertTrue($success);
        $this->assertFileExists(getWebcamPullMetadataPath($newAirport, 0));

        @unlink(getWebcamPullMetadataPath($newAirport, 0));
        @rmdir(getWebcamCameraDir($newAirport, 0));
        @rmdir(getWebcamAirportDir($newAirport));
    }

    public function testComputeWebcamContentChecksum_ReturnsConsistentHash(): void
    {
        $data = "\xff\xd8\xff\xe0\x00\x10JFIF" . str_repeat("\x00", 1000) . "\xff\xd9";
        $hash1 = computeWebcamContentChecksum($data);
        $hash2 = computeWebcamContentChecksum($data);
        $this->assertSame($hash1, $hash2);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash1);
    }

    public function testComputeWebcamContentChecksum_DifferentContent_ReturnsDifferentHash(): void
    {
        $data1 = "image1";
        $data2 = "image2";
        $hash1 = computeWebcamContentChecksum($data1);
        $hash2 = computeWebcamContentChecksum($data2);
        $this->assertNotSame($hash1, $hash2);
    }

    public function testComputeWebcamContentChecksum_EmptyString_ReturnsValidHash(): void
    {
        $hash = computeWebcamContentChecksum('');
        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }
}
