<?php
/**
 * Public API webcam query validation tests.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../lib/public-api/query.php';

class PublicApiWebcamQueryTest extends TestCase
{
    private array $previousGet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousGet = $_GET;
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = $this->previousGet;
        parent::tearDown();
    }

    public function testParsePublicApiWebcamSizeQueryFromGet_Omitted_UsesOriginal(): void
    {
        $parsed = parsePublicApiWebcamSizeQueryFromGet();

        $this->assertTrue($parsed['ok']);
        $this->assertSame('original', $parsed['size']);
    }

    public function testParsePublicApiWebcamSizeQueryFromGet_CanonicalHeight_ReturnsHeight(): void
    {
        $_GET['size'] = '720';

        $parsed = parsePublicApiWebcamSizeQueryFromGet();

        $this->assertTrue($parsed['ok']);
        $this->assertSame('720', $parsed['size']);
    }

    public function testParsePublicApiWebcamSizeQueryFromGet_PartialHeight_ReturnsError(): void
    {
        $_GET['size'] = '720px';

        $parsed = parsePublicApiWebcamSizeQueryFromGet();

        $this->assertFalse($parsed['ok']);
        $this->assertSame('size must be original or an integer from 1 to 5000', $parsed['error']);
    }

    public function testParsePublicApiWebcamSizeQueryFromGet_Array_ReturnsError(): void
    {
        $_GET['size'] = ['720'];

        $parsed = parsePublicApiWebcamSizeQueryFromGet();

        $this->assertFalse($parsed['ok']);
        $this->assertSame('size must be a single value', $parsed['error']);
    }

    public function testParsePublicApiWebcamDimensionQueryFromGet_PartialHeight_ReturnsError(): void
    {
        $_GET['height'] = '720px';

        $parsed = parsePublicApiWebcamDimensionQueryFromGet('height', 16, 2160);

        $this->assertFalse($parsed['ok']);
        $this->assertSame('height must be an integer from 16 to 2160', $parsed['error']);
    }

    public function testParsePublicApiWebcamTimestampQueryFromGet_Omitted_ReturnsNotPresent(): void
    {
        $parsed = parsePublicApiWebcamTimestampQueryFromGet();

        $this->assertTrue($parsed['ok']);
        $this->assertFalse($parsed['present']);
        $this->assertNull($parsed['timestamp']);
    }

    public function testParsePublicApiWebcamTimestampQueryFromGet_CanonicalTimestamp_ReturnsInteger(): void
    {
        $_GET['ts'] = '1704067200';

        $parsed = parsePublicApiWebcamTimestampQueryFromGet();

        $this->assertTrue($parsed['ok']);
        $this->assertTrue($parsed['present']);
        $this->assertSame(1704067200, $parsed['timestamp']);
    }

    public function testParsePublicApiWebcamTimestampQueryFromGet_Array_ReturnsError(): void
    {
        $_GET['ts'] = ['1704067200'];

        $parsed = parsePublicApiWebcamTimestampQueryFromGet();

        $this->assertFalse($parsed['ok']);
        $this->assertSame('ts must be a single value', $parsed['error']);
    }
}
