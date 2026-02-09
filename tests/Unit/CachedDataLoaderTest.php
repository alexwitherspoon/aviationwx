<?php
/**
 * Unit Tests for Cached Data Loader
 * 
 * Tests the generic caching pattern used throughout the status page.
 */

use PHPUnit\Framework\TestCase;

class CachedDataLoaderTest extends TestCase
{
    private $testCacheFile;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Use a test-specific cache file
        $this->testCacheFile = __DIR__ . '/../../cache/test_cached_data_loader.json';
        
        // Clean up any existing test cache
        if (file_exists($this->testCacheFile)) {
            @unlink($this->testCacheFile);
        }
        
        // Clear APCu cache if available
        if (function_exists('apcu_delete')) {
            @apcu_delete('cached_test_key');
        }
    }
    
    protected function tearDown(): void
    {
        // Clean up test cache file
        if (file_exists($this->testCacheFile)) {
            @unlink($this->testCacheFile);
        }
        
        // Clear APCu cache
        if (function_exists('apcu_delete')) {
            @apcu_delete('cached_test_key');
        }
        
        parent::tearDown();
    }
    
    /**
     * Test basic caching functionality
     */
    public function testGetCachedData_FirstCall_ComputesData(): void
    {
        require_once __DIR__ . '/../../lib/cached-data-loader.php';
        
        $computeCount = 0;
        $computeFunc = function() use (&$computeCount) {
            $computeCount++;
            return ['test' => 'data', 'computed_at' => time()];
        };
        
        $result = getCachedData($computeFunc, 'test_key', null, 60);
        
        $this->assertEquals(1, $computeCount, 'Should compute data on first call');
        $this->assertIsArray($result, 'Should return array');
        $this->assertEquals('data', $result['test'], 'Should return computed data');
    }
    
    /**
     * Test APCu caching when available
     */
    public function testGetCachedData_SecondCall_UsesCachedData(): void
    {
        if (!function_exists('apcu_fetch')) {
            $this->markTestSkipped('APCu not available');
        }
        
        require_once __DIR__ . '/../../lib/cached-data-loader.php';
        
        $computeCount = 0;
        $computeFunc = function() use (&$computeCount) {
            $computeCount++;
            return ['computed' => $computeCount];
        };
        
        // First call - should compute
        $result1 = getCachedData($computeFunc, 'test_key', null, 60);
        $this->assertEquals(1, $computeCount, 'Should compute on first call');
        $this->assertEquals(1, $result1['computed']);
        
        // Second call - should use cached
        $result2 = getCachedData($computeFunc, 'test_key', null, 60);
        $this->assertEquals(1, $computeCount, 'Should not recompute on second call');
        $this->assertEquals(1, $result2['computed'], 'Should return cached data');
    }
    
    /**
     * Test file persistence
     */
    public function testGetCachedData_WithFilePath_PersistsToFile(): void
    {
        require_once __DIR__ . '/../../lib/cached-data-loader.php';
        
        $data = ['test' => 'persisted', 'timestamp' => time()];
        $result = getCachedData(
            fn() => $data,
            'test_file_key',
            $this->testCacheFile,
            60
        );
        
        $this->assertEquals($data, $result, 'Should return computed data');
        $this->assertFileExists($this->testCacheFile, 'Should create cache file');
        
        // Verify file contents
        $fileContent = @file_get_contents($this->testCacheFile);
        $this->assertNotFalse($fileContent, 'Should be able to read cache file');
        
        $fileData = json_decode($fileContent, true);
        $this->assertIsArray($fileData, 'File should contain JSON array');
        $this->assertArrayHasKey('cached_at', $fileData, 'Should have cached_at timestamp');
        $this->assertArrayHasKey('data', $fileData, 'Should have data field');
        $this->assertEquals($data, $fileData['data'], 'File should contain correct data');
    }
    
    /**
     * Test file cache is used when APCu unavailable
     */
    public function testGetCachedData_FileCache_UsedAsSecondTier(): void
    {
        require_once __DIR__ . '/../../lib/cached-data-loader.php';
        
        // First call - compute and persist
        $data = ['file_cached' => true, 'value' => 42];
        $result1 = getCachedData(
            fn() => $data,
            'test_file_tier',
            $this->testCacheFile,
            60
        );
        
        $this->assertEquals($data, $result1, 'First call should compute');
        $this->assertFileExists($this->testCacheFile, 'Should create file cache');
        
        // Clear APCu to force file cache usage
        if (function_exists('apcu_delete')) {
            @apcu_delete('cached_test_file_tier');
        }
        
        // Second call - should load from file
        $computeCalled = false;
        $result2 = getCachedData(
            function() use (&$computeCalled) {
                $computeCalled = true;
                return ['should_not_see' => 'this'];
            },
            'test_file_tier',
            $this->testCacheFile,
            60
        );
        
        $this->assertFalse($computeCalled, 'Should not compute when file cache valid');
        $this->assertEquals($data, $result2, 'Should return data from file cache');
    }
    
    /**
     * Test cache invalidation
     */
    public function testInvalidateCachedData_RemovesBothCaches(): void
    {
        if (!function_exists('apcu_fetch')) {
            $this->markTestSkipped('APCu not available');
        }
        
        require_once __DIR__ . '/../../lib/cached-data-loader.php';
        
        // Create cached data
        $data = ['test' => 'invalidate'];
        getCachedData(
            fn() => $data,
            'test_invalidate',
            $this->testCacheFile,
            60
        );
        
        $this->assertFileExists($this->testCacheFile, 'File should exist before invalidation');
        
        // Verify APCu cache exists
        $apcuData = @apcu_fetch('cached_test_invalidate', $success);
        $this->assertTrue($success, 'APCu cache should exist before invalidation');
        
        // Invalidate
        invalidateCachedData('test_invalidate', $this->testCacheFile);
        
        // Verify both caches are cleared
        $this->assertFileDoesNotExist($this->testCacheFile, 'File should be removed');
        
        $apcuData = @apcu_fetch('cached_test_invalidate', $success);
        $this->assertFalse($success, 'APCu cache should be cleared');
    }
    
    /**
     * Test expired file cache forces recomputation
     */
    public function testGetCachedData_ExpiredFileCache_Recomputes(): void
    {
        require_once __DIR__ . '/../../lib/cached-data-loader.php';
        
        // Create expired cache file manually
        $expiredData = [
            'cached_at' => time() - 120, // 2 minutes ago
            'ttl' => 60, // 1 minute TTL (expired!)
            'key' => 'test_expired',
            'data' => ['old' => 'data']
        ];
        
        $cacheDir = dirname($this->testCacheFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        file_put_contents($this->testCacheFile, json_encode($expiredData));
        
        // Clear APCu to force file cache check
        if (function_exists('apcu_delete')) {
            @apcu_delete('cached_test_expired');
        }
        
        // Call with short TTL
        $newData = ['new' => 'data'];
        $result = getCachedData(
            fn() => $newData,
            'test_expired',
            $this->testCacheFile,
            60
        );
        
        $this->assertEquals($newData, $result, 'Should recompute when cache expired');
        $this->assertNotEquals(['old' => 'data'], $result, 'Should not return expired data');
    }
}
