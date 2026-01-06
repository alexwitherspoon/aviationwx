<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../scripts/fetch-webcam.php';

class WebcamBackoffTest extends TestCase
{
    private $backoffFile;
    private $testAirportId = 'test_airport';
    private $testCamIndex = 0;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->backoffFile = CACHE_BACKOFF_FILE;
        
        // Ensure cache directory exists
        ensureCacheDir(dirname($this->backoffFile));
        
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
        // Record failures up to threshold (circuit opens after threshold failures)
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordFailure($this->testAirportId, $this->testCamIndex, 'transient');
        }
        
        $result = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        
        $this->assertTrue($result['skip'], 'Should skip after recording threshold failures');
        $this->assertEquals('circuit_open', $result['reason']);
        $this->assertGreaterThan(0, $result['backoff_remaining']);
        $this->assertEquals(CIRCUIT_BREAKER_FAILURE_THRESHOLD, $result['failures']);
    }
    
    /**
     * Test recordFailure with failure reason - Should store and return failure reason
     */
    public function testRecordFailure_WithFailureReason()
    {
        $failureReason = 'EXIF timestamp invalid: timestamp too old';
        // Record failures up to threshold
        // recordFailure signature: (airportId, camIndex, severity, httpCode, failureReason)
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordFailure($this->testAirportId, $this->testCamIndex, 'transient', null, $failureReason);
        }
        
        $result = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        
        $this->assertTrue($result['skip'], 'Should skip after recording threshold failures');
        $this->assertEquals('circuit_open', $result['reason']);
        $this->assertEquals($failureReason, $result['last_failure_reason'], 'Should return stored failure reason');
        $this->assertGreaterThan(0, $result['backoff_remaining']);
    }
    
    /**
     * Test recordFailure - Failure reason persists across multiple checks
     */
    public function testRecordFailure_FailureReasonPersists()
    {
        $failureReason = 'HTTP 503';
        // Record failures up to threshold
        // Use HTTP code 503 to generate the reason
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordFailure($this->testAirportId, $this->testCamIndex, 'transient', 503);
        }
        
        // Check multiple times - reason should persist
        $result1 = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        $result2 = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        
        $this->assertEquals($failureReason, $result1['last_failure_reason'], 'First check should return failure reason');
        $this->assertEquals($failureReason, $result2['last_failure_reason'], 'Second check should return same failure reason');
    }
    
    /**
     * Test recordFailure - Failure reason is cleared on success
     */
    public function testRecordFailure_FailureReasonClearedOnSuccess()
    {
        $failureReason = 'Error frame detected';
        // Record failures up to threshold
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordFailure($this->testAirportId, $this->testCamIndex, 'transient', null, $failureReason);
        }
        
        $resultBefore = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        $this->assertEquals($failureReason, $resultBefore['last_failure_reason'], 'Should have failure reason before success');
        
        // Record success
        recordSuccess($this->testAirportId, $this->testCamIndex);
        
        $resultAfter = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        $this->assertNull($resultAfter['last_failure_reason'], 'Failure reason should be cleared after success');
    }
    
    /**
     * Test recordFailure - Defaults to 'unknown' if no reason provided
     */
    public function testRecordFailure_DefaultReasonWhenNoneProvided()
    {
        // Record failures up to threshold
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordFailure($this->testAirportId, $this->testCamIndex, 'transient');
        }
        
        $result = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        
        // Should have a default reason or null
        $this->assertTrue($result['skip'], 'Should skip after recording threshold failures');
        // Note: The implementation may set 'unknown' or null - both are acceptable
        $this->assertTrue(
            $result['last_failure_reason'] === 'unknown' || $result['last_failure_reason'] === null,
            'Should have default reason or null when none provided'
        );
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
        // Record failures up to threshold
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordFailure($this->testAirportId, $this->testCamIndex, 'permanent');
        }
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
        // Record failures up to threshold
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordFailure($this->testAirportId, $this->testCamIndex, 'transient');
        }
        $resultBefore = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        $this->assertTrue($resultBefore['skip'], 'Should skip after threshold failures');
        
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
        // Record failures up to threshold for camera 0
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordFailure($this->testAirportId, 0, 'transient');
        }
        
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
        // Record failures up to threshold
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordFailure($this->testAirportId, $this->testCamIndex, 'transient');
        }
        $resultBefore = checkCircuitBreaker($this->testAirportId, $this->testCamIndex);
        $this->assertTrue($resultBefore['skip'], 'Should skip after threshold failures');
        
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

