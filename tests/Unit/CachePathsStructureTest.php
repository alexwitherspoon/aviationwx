<?php
/**
 * Unit Tests for Cache Path Structure (Date/Hour Webcams, Hierarchical Map Tiles, Rate Limit Prefix)
 *
 * TDD tests for the new cache directory structure that limits files per directory.
 *
 * Structure:
 * - Webcams: {airport}/{cam}/{YYYY-MM-DD}/{HH}/{timestamp}_{variant}.{format}
 * - Map tiles: {layer}/{z}/{x}/{y}.png
 * - Rate limits: {prefix}/{hash}.json where prefix = first 2 chars of hash
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';

class CachePathsStructureTest extends TestCase
{
    /**
     * Test webcam frames subdir generates correct date/hour path
     */
    public function testGetWebcamFramesSubdir_Timestamp_ReturnsDateHourPath(): void
    {
        $this->assertTrue(function_exists('getWebcamFramesSubdir'));
        $ts = strtotime('2026-02-24 14:30:00 UTC');
        $subdir = getWebcamFramesSubdir($ts);
        $this->assertEquals('2026-02-24/14', $subdir);
    }

    /**
     * Test webcam original path includes date/hour subdir
     */
    public function testGetWebcamOriginalTimestampedPath_IncludesDateHourSubdir(): void
    {
        $ts = strtotime('2026-02-24 09:15:00 UTC');
        $path = getWebcamOriginalTimestampedPath('kspb', 0, $ts, 'jpg');
        $this->assertStringContainsString('2026-02-24/09', $path);
        $this->assertStringEndsWith('_original.jpg', $path);
        $this->assertStringContainsString((string)$ts, $path);
    }

    /**
     * Test webcam variant path includes date/hour subdir
     */
    public function testGetWebcamVariantPath_IncludesDateHourSubdir(): void
    {
        $ts = strtotime('2026-02-24 23:45:00 UTC');
        $path = getWebcamVariantPath('kspb', 1, $ts, 720, 'webp');
        $this->assertStringContainsString('2026-02-24/23', $path);
        $this->assertStringEndsWith('_720.webp', $path);
    }

    /**
     * Test map tile path uses hierarchical z/x/y structure
     */
    public function testGetMapTileCachePath_UsesHierarchicalStructure(): void
    {
        $path = getMapTileCachePath('clouds_new', 5, 10, 12);
        $this->assertStringContainsString('map_tiles/clouds_new/5/10/12.png', $path);
        $this->assertStringEndsWith('12.png', $path);
    }

    /**
     * Test map tile paths are unique for different coordinates
     */
    public function testGetMapTileCachePath_DifferentTiles_UniquePaths(): void
    {
        $path1 = getMapTileCachePath('clouds_new', 5, 10, 12);
        $path2 = getMapTileCachePath('clouds_new', 5, 10, 13);
        $path3 = getMapTileCachePath('clouds_new', 6, 10, 12);
        $this->assertNotEquals($path1, $path2);
        $this->assertNotEquals($path1, $path3);
    }

    /**
     * Test rate limit path uses prefix subdir
     */
    public function testGetRateLimitPath_UsesPrefixSubdir(): void
    {
        $hash = 'a3f2b1c4d5e6f7a8b9c0d1e2f3a4b5c6';
        $path = getRateLimitPath($hash);
        $this->assertStringContainsString('rate_limits/a3/', $path);
        $this->assertStringEndsWith('.json', $path);
        $this->assertStringContainsString($hash, $path);
    }

    /**
     * Test rate limit path with short hash (edge case)
     */
    public function testGetRateLimitPath_ShortHash_StillWorks(): void
    {
        $hash = 'ab';
        $path = getRateLimitPath($hash);
        $this->assertStringContainsString('rate_limits/ab/', $path);
        $this->assertStringEndsWith('ab.json', $path);
    }
}
