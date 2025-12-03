<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../scripts/fetch-webcam.php';

class WebcamBackoffTest extends TestCase
{
    private $backoffFile;
    private $testAirportId = 'test_airport';
    private $testCamIndex = 0;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->backoffFile = __DIR__ . '/../../cache/backoff.json';
        
        // Ensure cache directory exists
        $cacheDir = dirname($this->backoffFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        // Clean up backoff file before each test
        if (file_exists($this->backoffFile)) {
            @unlink($this->backoffFile);
        }
    }
    
    protected function tearDown(): void
    {
        // Clean up after each test
        if (file_exists($this->backoffFile)) {
            @unlink($this->backoffFile);
        }
        parent::tearDown();
    }
    
    /**
     * Test checkCircuitBreaker - Should not skip when no backoff exists
     */
    public function testCheckCircuitBreaker_NoBackoff()
    {
        $result = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        
        $this->assertFalse($result['skip'], 'Should not skip when no backoff exists');
        $this->assertEquals(0, $result['backoff_remaining']);
    }
    
    /**
     * Test recordFailure - Should create backoff state
     */
    public function testRecordFailure_CreatesBackoff()
    {
        recordFailure($this->testAirportId, $this->testCamIndex, 'transient');
        
        $result = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        
        $this->assertTrue($result['skip'], 'Should skip after recording failure');
        $this->assertEquals('circuit_open', $result['reason']);
        $this->assertGreaterThan(0, $result['backoff_remaining']);
        $this->assertEquals(1, $result['failures']);
    }
    
    /**
     * Test recordFailure - Exponential backoff increases with failures
     */
    public function testRecordFailure_ExponentialBackoff()
    {
        // First failure
        recordFailure($this->testAirportId, $this->testCamIndex, 'transient');
        $result1 = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        $backoff1 = $result1['backoff_remaining'];
        
        // Wait for backoff to expire
        $backoffData = json_decode(file_get_contents($this->backoffFile), true);
        $key = $this->testAirportId . '_' . $this->testCamIndex;
        $backoffData[$key]['next_allowed_time'] = time() - 1; // Expire backoff
        file_put_contents($this->backoffFile, json_encode($backoffData, JSON_PRETTY_PRINT), LOCK_EX);
        
        // Second failure
        recordFailure($this->testAirportId, $this->testCamIndex, 'transient');
        $result2 = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        $backoff2 = $result2['backoff_remaining'];
        
        // Backoff should increase with more failures
        $this->assertGreaterThan($backoff1, $backoff2, 'Backoff should increase with more failures');
    }
    
    /**
     * Test recordFailure - Permanent errors have longer backoff
     */
    public function testRecordFailure_PermanentError()
    {
        recordFailure($this->testAirportId, $this->testCamIndex, 'permanent');
        $result = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        
        // Permanent errors should have longer backoff (2x multiplier)
        $this->assertTrue($result['skip']);
        $this->assertGreaterThan(60, $result['backoff_remaining'], 'Permanent error should have longer backoff');
    }
    
    /**
     * Test recordSuccess - Should reset backoff state
     */
    public function testRecordSuccess_ResetsBackoff()
    {
        // Record a failure first
        recordFailure($this->testAirportId, $this->testCamIndex, 'transient');
        $resultBefore = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        $this->assertTrue($resultBefore['skip'], 'Should skip after failure');
        
        // Record success
        recordSuccess($this->testAirportId, $this->testCamIndex);
        
        // Should reset backoff
        $resultAfter = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        $this->assertFalse($resultAfter['skip'], 'Should not skip after success');
        $this->assertEquals(0, $resultAfter['backoff_remaining']);
    }
    
    /**
     * Test separate backoff for different cameras
     */
    public function testSeparateBackoffForCameras()
    {
        // Record failure for camera 0
        recordFailure($this->testAirportId, 0, 'transient');
        
        // Camera 0 should be in backoff
        $cam0Result = checkCircuitBreaker($this->testAirportId, 0);
        $this->assertTrue($cam0Result['skip'], 'Camera 0 should be in backoff');
        
        // Camera 1 should not be in backoff
        $cam1Result = checkCircuitBreaker($this->testAirportId, 1);
        $this->assertFalse($cam1Result['skip'], 'Camera 1 should not be in backoff');
    }
    
    /**
     * Test backoff expires after time
     */
    public function testBackoff_ExpiresAfterTime()
    {
        // Record failure
        recordFailure($this->testAirportId, $this->testCamIndex, 'transient');
        $resultBefore = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        $this->assertTrue($resultBefore['skip'], 'Should skip immediately after failure');
        
        // Manually expire backoff
        $backoffData = json_decode(file_get_contents($this->backoffFile), true);
        $key = $this->testAirportId . '_' . $this->testCamIndex;
        $backoffData[$key]['next_allowed_time'] = time() - 1; // Expired
        file_put_contents($this->backoffFile, json_encode($backoffData, JSON_PRETTY_PRINT), LOCK_EX);
        
        // Should not skip after expiration
        $resultAfter = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        $this->assertFalse($resultAfter['skip'], 'Should not skip after backoff expires');
    }
    
    /**
     * Test file locking - multiple concurrent writes should not corrupt data
     */
    public function testFileLocking_ConcurrentWrites()
    {
        // This test verifies that file locking prevents race conditions
        // Record multiple failures rapidly (simulating concurrent requests)
        for ($i = 0; $i < 5; $i++) {
            recordFailure($this->testAirportId, $this->testCamIndex, 'transient');
            usleep(1000); // Small delay to allow file operations
        }
        
        // Verify file is valid JSON and contains expected data
        $this->assertFileExists($this->backoffFile);
        $backoffData = json_decode(file_get_contents($this->backoffFile), true);
        $this->assertIsArray($backoffData, 'Backoff file should contain valid JSON');
        
        $key = $this->testAirportId . '_' . $this->testCamIndex;
        $this->assertArrayHasKey($key, $backoffData, 'Backoff data should contain test key');
        $this->assertGreaterThanOrEqual(5, $backoffData[$key]['failures'], 'Should record all failures');
    }
    
    /**
     * Test file locking - concurrent access from multiple processes (simulated)
     * This test verifies that file locking prevents data corruption when multiple
     * processes try to update the backoff file simultaneously
     */
    public function testFileLocking_ConcurrentAccess()
    {
        // Simulate concurrent access by rapidly calling recordFailure
        // In a real scenario, these would be from different processes
        $iterations = 10;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            recordFailure($this->testAirportId, $this->testCamIndex, 'transient');
            // Minimal delay to allow file operations
            usleep(500);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Verify file is still valid JSON after concurrent writes
        $this->assertFileExists($this->backoffFile);
        $content = file_get_contents($this->backoffFile);
        $this->assertNotEmpty($content, 'Backoff file should not be empty');
        
        $backoffData = json_decode($content, true);
        $jsonError = json_last_error();
        $this->assertIsArray($backoffData, 'Backoff file should contain valid JSON after concurrent writes');
        $this->assertEquals(JSON_ERROR_NONE, $jsonError, 'JSON should be valid (error: ' . json_last_error_msg() . ')');
        
        $key = $this->testAirportId . '_' . $this->testCamIndex;
        $this->assertArrayHasKey($key, $backoffData, 'Backoff data should contain test key');
        
        // Verify failure count is correct (should be exactly $iterations)
        $this->assertEquals($iterations, $backoffData[$key]['failures'], 'Should record all failures correctly');
        
        // Verify next_allowed_time is set and reasonable
        $this->assertArrayHasKey('next_allowed_time', $backoffData[$key], 'Should have next_allowed_time');
        $this->assertGreaterThan(time(), $backoffData[$key]['next_allowed_time'], 'next_allowed_time should be in the future');
    }
}

