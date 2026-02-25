<?php
/**
 * Unit Tests for Map Tiles Cache Infrastructure
 * 
 * Tests the cache path generation, directory structure, and basic
 * caching logic without requiring external API calls.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';

class MapTilesCacheTest extends TestCase
{
    private $testCacheDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testCacheDir = CACHE_MAP_TILES_DIR;
        
        // Ensure cache directory exists
        if (!is_dir($this->testCacheDir)) {
            mkdir($this->testCacheDir, 0755, true);
        }
    }
    
    protected function tearDown(): void
    {
        // Clean up test files
        $this->cleanupTestFiles();
        parent::tearDown();
    }
    
    private function cleanupTestFiles(): void
    {
        $testDir = CACHE_MAP_TILES_DIR . '/test_layer';
        if (is_dir($testDir)) {
            $files = glob($testDir . '/*/*/*.png');
            foreach ($files as $file) {
                @unlink($file);
            }
            $dirs = glob($testDir . '/*/*', GLOB_ONLYDIR);
            if ($dirs !== false) {
                foreach ($dirs as $dir) {
                    @rmdir($dir);
                }
            }
            $dirs = glob($testDir . '/*', GLOB_ONLYDIR);
            if ($dirs !== false) {
                foreach ($dirs as $dir) {
                    @rmdir($dir);
                }
            }
            @rmdir($testDir);
        }
    }
    
    /**
     * Test that map tiles cache directory constant is defined
     */
    public function testMapTilesCacheDir_IsDefined()
    {
        $this->assertTrue(defined('CACHE_MAP_TILES_DIR'), 'CACHE_MAP_TILES_DIR constant should be defined');
        $this->assertStringContainsString('cache/map_tiles', CACHE_MAP_TILES_DIR);
    }
    
    /**
     * Test cache directory can be created
     */
    public function testMapTilesCacheDir_CanBeCreated()
    {
        ensureCacheDir(CACHE_MAP_TILES_DIR);
        $this->assertDirectoryExists(CACHE_MAP_TILES_DIR, 'Map tiles cache directory should exist');
        $this->assertTrue(is_writable(CACHE_MAP_TILES_DIR), 'Cache directory should be writable');
    }
    
    /**
     * Test layer directory path generation
     */
    public function testGetMapTileLayerDir_ReturnsCorrectPath()
    {
        $layerDir = getMapTileLayerDir('clouds_new');
        
        $this->assertStringContainsString('map_tiles', $layerDir);
        $this->assertStringContainsString('clouds_new', $layerDir);
        $this->assertStringEndsWith('clouds_new', $layerDir);
    }
    
    /**
     * Test tile cache path generation for various coordinates
     */
    public function testGetMapTileCachePath_ReturnsCorrectPath()
    {
        $testCases = [
            ['clouds_new', 5, 10, 12, '5/10/12.png'],
            ['precipitation_new', 3, 5, 8, '3/5/8.png'],
            ['temp_new', 10, 512, 387, '10/512/387.png'],
            ['wind_new', 0, 0, 0, '0/0/0.png'],
        ];
        
        foreach ($testCases as [$layer, $z, $x, $y, $expectedSuffix]) {
            $path = getMapTileCachePath($layer, $z, $x, $y);
            
            $this->assertStringContainsString($layer, $path, "Path should contain layer name: $layer");
            $this->assertStringEndsWith($expectedSuffix, $path, "Path should end with correct path");
            $this->assertStringContainsString('.png', $path, 'Path should have .png extension');
        }
    }
    
    /**
     * Test cache path uniqueness for different tiles
     */
    public function testGetMapTileCachePath_GeneratesUniquePaths()
    {
        $path1 = getMapTileCachePath('clouds_new', 5, 10, 12);
        $path2 = getMapTileCachePath('clouds_new', 5, 10, 13);
        $path3 = getMapTileCachePath('clouds_new', 5, 11, 12);
        $path4 = getMapTileCachePath('clouds_new', 6, 10, 12);
        $path5 = getMapTileCachePath('precipitation_new', 5, 10, 12);
        
        $paths = [$path1, $path2, $path3, $path4, $path5];
        $uniquePaths = array_unique($paths);
        
        $this->assertCount(5, $uniquePaths, 'Different tiles should have unique cache paths');
    }
    
    /**
     * Test cache file write and read operations
     */
    public function testCacheReadWrite_WorksCorrectly()
    {
        // Create a simple valid PNG (1x1 pixel)
        $mockPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        
        $cachePath = getMapTileCachePath('test_layer', 5, 10, 12);
        $cacheDir = dirname($cachePath);
        
        // Ensure directory exists
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        // Write to cache
        $written = file_put_contents($cachePath, $mockPng);
        $this->assertNotFalse($written, 'Should be able to write to cache');
        $this->assertGreaterThan(0, $written, 'Should write some bytes');
        
        // Read from cache
        $this->assertFileExists($cachePath, 'Cache file should exist');
        $readData = file_get_contents($cachePath);
        
        $this->assertEquals($mockPng, $readData, 'Read data should match written data');
        $this->assertEquals(strlen($mockPng), strlen($readData), 'Size should match');
    }
    
    /**
     * Test PNG signature validation
     */
    public function testCachedTile_HasValidPngSignature()
    {
        $mockPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        
        $cachePath = getMapTileCachePath('test_layer', 5, 10, 12);
        $cacheDir = dirname($cachePath);
        
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        file_put_contents($cachePath, $mockPng);
        $readData = file_get_contents($cachePath);
        
        // Check PNG signature (first 4 bytes should be \x89PNG)
        $signature = substr($readData, 0, 4);
        $this->assertEquals("\x89PNG", $signature, 'Should have valid PNG signature');
    }
    
    /**
     * Test cache file age detection
     */
    public function testCacheFile_AgeCanBeDetected()
    {
        $mockPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        
        $cachePath = getMapTileCachePath('test_layer', 5, 10, 12);
        $cacheDir = dirname($cachePath);
        
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        file_put_contents($cachePath, $mockPng);
        
        $mtime = filemtime($cachePath);
        $age = time() - $mtime;
        
        $this->assertIsInt($mtime, 'Modification time should be an integer');
        $this->assertGreaterThanOrEqual(0, $age, 'Age should be >= 0');
        $this->assertLessThan(10, $age, 'Age should be < 10 seconds for new file');
    }
    
    /**
     * Test cache TTL logic (1 hour)
     */
    public function testCacheTTL_IsFresh()
    {
        $mockPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        
        $cachePath = getMapTileCachePath('test_layer', 5, 10, 12);
        $cacheDir = dirname($cachePath);
        
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        file_put_contents($cachePath, $mockPng);
        
        $mtime = filemtime($cachePath);
        $age = time() - $mtime;
        $ttl = 3600; // 1 hour
        
        $isFresh = $age < $ttl;
        
        $this->assertTrue($isFresh, 'Newly created cache should be fresh (within 1 hour TTL)');
    }
    
    /**
     * Test ensureAllCacheDirs includes map_tiles directory
     */
    public function testEnsureAllCacheDirs_IncludesMapTiles()
    {
        $results = ensureAllCacheDirs();
        
        $this->assertArrayHasKey(CACHE_MAP_TILES_DIR, $results, 'Should include map_tiles directory');
        $this->assertTrue($results[CACHE_MAP_TILES_DIR], 'Map tiles directory should be created successfully');
    }
}
