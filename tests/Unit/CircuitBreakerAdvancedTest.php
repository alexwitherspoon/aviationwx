<?php
/**
 * Advanced Circuit Breaker Tests
 * 
 * Tests the enhanced circuit breaker features:
 * - Failure threshold (circuit opens after N failures, not immediately)
 * - Error-type-specific backoff strategies (429, transient, permanent)
 * - HTTP code passing and failure reason generation
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/circuit-breaker.php';
require_once __DIR__ . '/../../lib/constants.php';

class CircuitBreakerAdvancedTest extends TestCase
{
    private $backoffFile;
    private $testAirportId = 'test_airport';
    private $testSourceType = 'primary';
    
    protected function setUp(): void
    {
        parent::setUp();
        
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
     * Test failure threshold - circuit should NOT open on first failure
     */
    public function testFailureThreshold_FirstFailureDoesNotOpen()
    {
        // Record first failure
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 503);
        
        $result = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        
        // Circuit should NOT be open after first failure (threshold is 2)
        $this->assertFalse($result['skip'], 'Circuit should not open after first failure');
        $this->assertEquals(1, $result['failures'], 'Should record 1 failure');
        
        // When circuit is not open (below threshold), failure reason is not returned
        // but the failure is still recorded. Verify by checking the backoff file directly
        $backoffData = json_decode(file_get_contents($this->backoffFile), true);
        $key = $this->testAirportId . '_weather_' . $this->testSourceType;
        $this->assertArrayHasKey($key, $backoffData, 'Backoff state should exist');
        $this->assertEquals('HTTP 503', $backoffData[$key]['last_failure_reason'] ?? null, 'Failure reason should be stored');
    }
    
    /**
     * Test failure threshold - circuit opens after threshold is reached
     */
    public function testFailureThreshold_OpensAfterThreshold()
    {
        // Record failures up to threshold
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 503);
        }
        
        $result = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        
        // Circuit should be open after threshold failures
        $this->assertTrue($result['skip'], 'Circuit should open after threshold failures');
        $this->assertEquals('circuit_open', $result['reason']);
        $this->assertEquals(CIRCUIT_BREAKER_FAILURE_THRESHOLD, $result['failures']);
        $this->assertGreaterThan(0, $result['backoff_remaining']);
    }
    
    /**
     * Test rate limit (429) backoff strategy - short backoff, minimal growth
     */
    public function testRateLimitBackoff_ShortBackoff()
    {
        // Record 429 rate limit errors
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 429);
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 429);
        
        $result = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        
        $this->assertTrue($result['skip'], 'Circuit should be open');
        
        // Check backoff is short (should be around 2-3 seconds for 2 failures)
        // Rate limit: base=2s, +1s per failure, capped at 10s
        $this->assertLessThanOrEqual(10, $result['backoff_remaining'], 'Rate limit backoff should be capped at 10s');
        $this->assertGreaterThanOrEqual(2, $result['backoff_remaining'], 'Rate limit backoff should be at least 2s');
    }
    
    /**
     * Test transient error backoff strategy - 10s base, exponential growth
     */
    public function testTransientErrorBackoff_ExponentialGrowth()
    {
        // Record transient errors (5xx)
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 503);
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 503);
        
        $result = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        
        $this->assertTrue($result['skip'], 'Circuit should be open');
        
        // Transient: 10s base, exponential growth, capped at 600s (10 min)
        // For 2 failures: 10s base
        $this->assertLessThanOrEqual(600, $result['backoff_remaining'], 'Transient backoff should be capped at 600s');
        $this->assertGreaterThanOrEqual(10, $result['backoff_remaining'], 'Transient backoff should be at least 10s');
    }
    
    /**
     * Test permanent error backoff strategy - 2x multiplier, 30 min cap
     */
    public function testPermanentErrorBackoff_LongBackoff()
    {
        // Record permanent errors
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'permanent', 401);
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'permanent', 401);
        
        $result = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        
        $this->assertTrue($result['skip'], 'Circuit should be open');
        
        // Permanent: 2x multiplier, capped at 1800s (30 min)
        $this->assertLessThanOrEqual(1800, $result['backoff_remaining'], 'Permanent backoff should be capped at 1800s');
        $this->assertGreaterThan(60, $result['backoff_remaining'], 'Permanent backoff should be longer than transient');
    }
    
    /**
     * Test HTTP code passing - should generate failure reason from HTTP code
     */
    public function testHttpCodePassing_GeneratesFailureReason()
    {
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 503);
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 503);
        
        $result = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        
        $this->assertEquals('HTTP 503', $result['last_failure_reason'], 'Should generate failure reason from HTTP code');
    }
    
    /**
     * Test explicit failure reason - should use provided reason over HTTP code
     */
    public function testExplicitFailureReason_PreferredOverHttpCode()
    {
        $explicitReason = 'API rate limit exceeded';
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 429, $explicitReason);
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 429, $explicitReason);
        
        $result = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        
        $this->assertEquals($explicitReason, $result['last_failure_reason'], 'Should use explicit failure reason');
    }
    
    /**
     * Test backoff growth - rate limit errors grow slowly
     */
    public function testRateLimitBackoff_GrowsSlowly()
    {
        // Record multiple 429 errors
        for ($i = 0; $i < 5; $i++) {
            recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 429);
        }
        
        // Manually expire backoff to allow more failures
        $backoffData = json_decode(file_get_contents($this->backoffFile), true);
        $key = $this->testAirportId . '_weather_' . $this->testSourceType;
        $backoffData[$key]['next_allowed_time'] = time() - 1;
        file_put_contents($this->backoffFile, json_encode($backoffData, JSON_PRETTY_PRINT), LOCK_EX);
        
        // Record more failures
        for ($i = 0; $i < 2; $i++) {
            recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 429);
        }
        
        $result = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        
        // Rate limit backoff should be capped at 10s even with many failures
        $this->assertLessThanOrEqual(10, $result['backoff_remaining'], 'Rate limit backoff should cap at 10s');
    }
    
    /**
     * Test backoff growth - transient errors grow exponentially
     */
    public function testTransientBackoff_GrowsExponentially()
    {
        // Record first set of failures
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 503);
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 503);
        
        $result1 = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        $backoff1 = $result1['backoff_remaining'];
        
        // Expire backoff
        $backoffData = json_decode(file_get_contents($this->backoffFile), true);
        $key = $this->testAirportId . '_weather_' . $this->testSourceType;
        $backoffData[$key]['next_allowed_time'] = time() - 1;
        file_put_contents($this->backoffFile, json_encode($backoffData, JSON_PRETTY_PRINT), LOCK_EX);
        
        // Record more failures (should increase backoff)
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 503);
        
        $result2 = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        $backoff2 = $result2['backoff_remaining'];
        
        // Backoff should increase with more failures (exponential growth)
        $this->assertGreaterThan($backoff1, $backoff2, 'Transient backoff should grow with more failures');
    }
    
    /**
     * Test success resets failure count below threshold
     */
    public function testSuccess_ResetsFailureCount()
    {
        // Record one failure (below threshold)
        recordWeatherFailure($this->testAirportId, $this->testSourceType, 'transient', 503);
        
        $resultBefore = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        $this->assertEquals(1, $resultBefore['failures'], 'Should have 1 failure');
        
        // Record success
        recordWeatherSuccess($this->testAirportId, $this->testSourceType);
        
        $resultAfter = checkWeatherCircuitBreaker($this->testAirportId, $this->testSourceType);
        $this->assertEquals(0, $resultAfter['failures'], 'Failures should be reset to 0');
        $this->assertFalse($resultAfter['skip'], 'Circuit should not be open');
    }
}

