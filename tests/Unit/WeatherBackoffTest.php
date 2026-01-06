<?php
/**
 * Unit Tests for Weather API Backoff Logic
 * 
 * Tests the circuit breaker and backoff logic for weather API requests
 * to prevent hammering external APIs when they fail or are rate limited
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../api/weather.php';

class WeatherBackoffTest extends TestCase
{
    private $backoffFile;
    private $testAirportId = 'test_airport';
    private $testSourceType = 'primary';
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Use the backoff file from centralized cache paths
        $this->backoffFile = CACHE_BACKOFF_FILE;
        
        // Ensure cache directory exists
        ensureCacheDir(dirname($this->backoffFile));
        
        // Clean up any existing backoff state for test airport
        $this->cleanupBackoffState();
    }
    
    protected function tearDown(): void
    {
        // Clean up after each test
        $this->cleanupBackoffState();
        parent::tearDown();
    }
    
    private function cleanupBackoffState()
    {
        if (file_exists($this->backoffFile)) {
            $backoffData = json_decode(file_get_contents($this->backoffFile), true) ?: [];
            $key = $this->testAirportId . '_weather_' . $this->testSourceType;
            if (isset($backoffData[$key])) {
                unset($backoffData[$key]);
                file_put_contents($this->backoffFile, json_encode($backoffData, JSON_PRETTY_PRINT), LOCK_EX);
            }
        }
    }
    
    /**
     * Test checkWeatherCircuitBreaker - No backoff state should allow request
     */
    public function testCheckWeatherCircuitBreaker_NoBackoff()
    {
        $result = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        
        $this->assertFalse($result['skip'], 'Should not skip when no backoff state exists');
        $this->assertEquals('', $result['reason']);
        $this->assertEquals(0, $result['backoff_remaining']);
    }
    
    /**
     * Test recordWeatherFailure - Should create backoff state
     */
    public function testRecordWeatherFailure_CreatesBackoff()
    {
        // Record failures up to threshold (circuit opens after threshold failures)
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient');
        }
        
        $result = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        
        $this->assertTrue($result['skip'], 'Should skip after recording threshold failures');
        $this->assertEquals('circuit_open', $result['reason']);
        $this->assertGreaterThan(0, $result['backoff_remaining']);
        $this->assertEquals(CIRCUIT_BREAKER_FAILURE_THRESHOLD, $result['failures']);
    }
    
    /**
     * Test recordWeatherFailure with HTTP code - Should generate failure reason from HTTP code
     */
    public function testRecordWeatherFailure_WithHttpCode()
    {
        // Record failures up to threshold
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 503);
        }
        
        $result = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        
        $this->assertTrue($result['skip'], 'Should skip after recording threshold failures');
        $this->assertEquals('HTTP 503', $result['last_failure_reason'], 'Should generate failure reason from HTTP code');
    }
    
    /**
     * Test recordWeatherFailure with explicit failure reason - Should store and return failure reason
     */
    public function testRecordWeatherFailure_WithExplicitReason()
    {
        $failureReason = 'API rate limit exceeded';
        // Record failures up to threshold
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', null, $failureReason);
        }
        
        $result = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        
        $this->assertTrue($result['skip'], 'Should skip after recording threshold failures');
        $this->assertEquals($failureReason, $result['last_failure_reason'], 'Should return stored failure reason');
    }
    
    /**
     * Test recordWeatherSuccess - Should clear failure reason
     */
    public function testRecordWeatherSuccess_ClearsFailureReason()
    {
        // Record failures up to threshold with reason
        require_once __DIR__ . '/../../lib/circuit-breaker.php';
        $key = $this->testAirportId . '_weather_' . $this->testSourceType;
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordCircuitBreakerFailureBase($key, CACHE_BACKOFF_FILE, 'transient', null, 'Connection timeout');
        }
        
        $resultBefore = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        $this->assertEquals('Connection timeout', $resultBefore['last_failure_reason'], 'Should have failure reason before success');
        
        // Record success
        recordWeatherSuccess($this->testAirportId, $this->testSourceType);
        
        $resultAfter = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        $this->assertNull($resultAfter['last_failure_reason'], 'Failure reason should be cleared after success');
    }
    
    /**
     * Test recordWeatherFailure - Exponential backoff increases with failures
     */
    public function testRecordWeatherFailure_ExponentialBackoff()
    {
        // First failure
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient');
        $result1 = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        $backoff1 = $result1['backoff_remaining'];
        
        // Wait for backoff to expire
        $backoffData = json_decode(file_get_contents($this->backoffFile), true);
        $key = $this->testAirportId . '_weather_' . $this->testSourceType;
        $backoffData[$key]['next_allowed_time'] = time() - 1; // Expire backoff
        file_put_contents($this->backoffFile, json_encode($backoffData, JSON_PRETTY_PRINT), LOCK_EX);
        
        // Second failure
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient');
        $result2 = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        $backoff2 = $result2['backoff_remaining'];
        
        // Backoff should increase with more failures
        $this->assertGreaterThan($backoff1, $backoff2, 'Backoff should increase with more failures');
    }
    
    /**
     * Test recordWeatherFailure - Permanent errors have longer backoff
     */
    public function testRecordWeatherFailure_PermanentError()
    {
        // Record failures up to threshold
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordWeatherFailure($this->testAirportId, $this->testSourceType, 'permanent');
        }
        $result = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        
        // Permanent errors should have longer backoff (2x multiplier)
        $this->assertTrue($result['skip']);
        $this->assertGreaterThan(60, $result['backoff_remaining'], 'Permanent error should have longer backoff');
    }
    
    /**
     * Test recordWeatherSuccess - Should reset backoff state
     */
    public function testRecordWeatherSuccess_ResetsBackoff()
    {
        // Record failures up to threshold
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient');
        }
        $resultBefore = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        $this->assertTrue($resultBefore['skip'], 'Should skip after threshold failures');
        
        // Record success
        recordWeatherSuccess($this->testAirportId, $this->testSourceType);
        
        // Should reset backoff
        $resultAfter = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        $this->assertFalse($resultAfter['skip'], 'Should not skip after success');
        $this->assertEquals(0, $resultAfter['backoff_remaining']);
    }
    
    /**
     * Test separate backoff for primary and METAR sources
     */
    public function testSeparateBackoffForSources()
    {
        // Record failures up to threshold for primary source
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordWeatherFailure($this->testAirportId, 'primary', 'transient');
        }
        
        // Primary should be in backoff
        $primaryResult = checkWeatherCircuitBreaker($this->testAirportId, 'primary');
        $this->assertTrue($primaryResult['skip'], 'Primary should be in backoff');
        
        // METAR should not be in backoff
        $metarResult = checkWeatherCircuitBreaker($this->testAirportId, 'metar');
        $this->assertFalse($metarResult['skip'], 'METAR should not be in backoff');
    }
    
    /**
     * Test backoff expires after time
     */
    public function testBackoffExpires()
    {
        // Record failure
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient');
        
        // Manually expire the backoff - set to a time well in the past
        $backoffData = json_decode(file_get_contents($this->backoffFile), true);
        $key = $this->testAirportId . '_weather_' . $this->testSourceType;
        $expiredTime = time() - 10; // Expired (10 seconds ago)
        $backoffData[$key]['next_allowed_time'] = $expiredTime;
        file_put_contents($this->backoffFile, json_encode($backoffData, JSON_PRETTY_PRINT), LOCK_EX);
        
        // Ensure file is flushed and stat cache is cleared
        clearstatcache(true, $this->backoffFile);
        
        // Verify the file was written correctly
        $verifyData = json_decode(file_get_contents($this->backoffFile), true);
        $this->assertEquals($expiredTime, $verifyData[$key]['next_allowed_time'], 'Backoff should be expired in file');
        
        // Should not skip after expiration
        $result = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        $this->assertFalse($result['skip'], 'Should not skip after backoff expires. next_allowed=' . ($verifyData[$key]['next_allowed_time'] ?? 'missing') . ', now=' . time());
    }
}

