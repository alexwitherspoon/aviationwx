<?php

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/webcam-image-metrics.php';
require_once __DIR__ . '/../../lib/push-webcam-validator.php';
// Note: Push webcam processing is now handled by unified-webcam-worker.php via scheduler
// The adaptive stability functions are now in webcam-image-metrics.php

class AdaptiveStabilityTest extends TestCase
{
    protected function setUp(): void
    {
        // Skip tests that require APCu if not available
        if (!extension_loaded('apcu') || !ini_get('apc.enabled') || !ini_get('apc.enable_cli')) {
            $this->markTestSkipped('APCu extension not available or not enabled for CLI');
        }
        
        // Clear APCu cache before each test
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }
    
    public function testGetUploadFileMaxAge_Default()
    {
        $cam = null;
        $maxAge = getUploadFileMaxAge($cam);
        
        $this->assertEquals(UPLOAD_FILE_MAX_AGE_SECONDS, $maxAge);
        $this->assertEquals(1800, $maxAge); // 30 minutes
    }
    
    public function testGetUploadFileMaxAge_CustomValue()
    {
        $cam = [
            'push_config' => [
                'upload_file_max_age_seconds' => 3600
            ]
        ];
        
        $maxAge = getUploadFileMaxAge($cam);
        
        $this->assertEquals(3600, $maxAge); // 1 hour
    }
    
    public function testGetUploadFileMaxAge_EnforcesMinimum()
    {
        $cam = [
            'push_config' => [
                'upload_file_max_age_seconds' => 300 // Too low
            ]
        ];
        
        $maxAge = getUploadFileMaxAge($cam);
        
        $this->assertEquals(MIN_UPLOAD_FILE_MAX_AGE_SECONDS, $maxAge);
        $this->assertEquals(600, $maxAge); // 10 minutes minimum
    }
    
    public function testGetUploadFileMaxAge_EnforcesMaximum()
    {
        $cam = [
            'push_config' => [
                'upload_file_max_age_seconds' => 10000 // Too high
            ]
        ];
        
        $maxAge = getUploadFileMaxAge($cam);
        
        $this->assertEquals(MAX_UPLOAD_FILE_MAX_AGE_SECONDS, $maxAge);
        $this->assertEquals(7200, $maxAge); // 2 hours maximum
    }
    
    public function testGetStabilityCheckTimeout_Default()
    {
        $cam = null;
        $timeout = getStabilityCheckTimeout($cam);
        
        $this->assertEquals(DEFAULT_STABILITY_CHECK_TIMEOUT_SECONDS, $timeout);
        $this->assertEquals(15, $timeout); // 15 seconds
    }
    
    public function testGetStabilityCheckTimeout_CustomValue()
    {
        $cam = [
            'push_config' => [
                'stability_check_timeout_seconds' => 20
            ]
        ];
        
        $timeout = getStabilityCheckTimeout($cam);
        
        $this->assertEquals(20, $timeout);
    }
    
    public function testGetStabilityCheckTimeout_EnforcesBounds()
    {
        // Test minimum
        $cam = ['push_config' => ['stability_check_timeout_seconds' => 5]];
        $this->assertEquals(MIN_STABILITY_CHECK_TIMEOUT_SECONDS, getStabilityCheckTimeout($cam));
        
        // Test maximum
        $cam = ['push_config' => ['stability_check_timeout_seconds' => 100]];
        $this->assertEquals(MAX_STABILITY_CHECK_TIMEOUT_SECONDS, getStabilityCheckTimeout($cam));
    }
    
    public function testGetRequiredStableChecks_ColdStart()
    {
        $airportId = 'ktest';
        $camIndex = 0;
        
        // No metrics stored yet
        $requiredChecks = getRequiredStableChecks($airportId, $camIndex);
        
        $this->assertEquals(DEFAULT_STABLE_CHECKS, $requiredChecks);
        $this->assertEquals(20, $requiredChecks); // Conservative default
    }
    
    public function testGetRequiredStableChecks_InsufficientSamples()
    {
        $airportId = 'ktest';
        $camIndex = 0;
        
        // Store metrics with insufficient samples
        $metrics = [
            'stability_times' => [1.0, 1.1, 1.2], // Only 3 samples, need 20
            'accepted' => 3,
            'rejected' => 0,
            'last_updated' => time()
        ];
        apcu_store("stability_metrics_{$airportId}_{$camIndex}", $metrics, 3600);
        
        $requiredChecks = getRequiredStableChecks($airportId, $camIndex);
        
        $this->assertEquals(DEFAULT_STABLE_CHECKS, $requiredChecks);
    }
    
    public function testGetRequiredStableChecks_HighRejectionRate()
    {
        $airportId = 'ktest';
        $camIndex = 0;
        
        // Store metrics with high rejection rate
        $metrics = [
            'stability_times' => array_fill(0, 30, 1.0), // 30 samples
            'accepted' => 30,
            'rejected' => 5, // >5% rejection rate
            'last_updated' => time()
        ];
        apcu_store("stability_metrics_{$airportId}_{$camIndex}", $metrics, 3600);
        
        $requiredChecks = getRequiredStableChecks($airportId, $camIndex);
        
        // High rejection rate should trigger conservative behavior
        $this->assertEquals(DEFAULT_STABLE_CHECKS, $requiredChecks);
    }
    
    public function testGetRequiredStableChecks_Optimization()
    {
        $airportId = 'ktest';
        $camIndex = 0;
        
        // Store metrics with fast, consistent uploads
        $stabTimes = array_fill(0, 50, 1.0); // 50 samples, all 1 second
        $metrics = [
            'stability_times' => $stabTimes,
            'accepted' => 50,
            'rejected' => 1, // Low rejection rate (<2%)
            'last_updated' => time()
        ];
        apcu_store("stability_metrics_{$airportId}_{$camIndex}", $metrics, 3600);
        
        $requiredChecks = getRequiredStableChecks($airportId, $camIndex);
        
        // P95 of 1.0 seconds = 1.0
        // Required checks = ceil((1.0 / 0.5) * 1.5) = ceil(3) = 3
        // But minimum is 5
        $this->assertGreaterThanOrEqual(MIN_STABLE_CHECKS, $requiredChecks);
        $this->assertLessThanOrEqual(MAX_STABLE_CHECKS, $requiredChecks);
        $this->assertEquals(5, $requiredChecks); // Should be at minimum
    }
    
    public function testGetRequiredStableChecks_SlowUploads()
    {
        $airportId = 'ktest';
        $camIndex = 0;
        
        // Store metrics with slow uploads
        $stabTimes = array_fill(0, 50, 8.0); // 50 samples, all 8 seconds
        $metrics = [
            'stability_times' => $stabTimes,
            'accepted' => 50,
            'rejected' => 0,
            'last_updated' => time()
        ];
        apcu_store("stability_metrics_{$airportId}_{$camIndex}", $metrics, 3600);
        
        $requiredChecks = getRequiredStableChecks($airportId, $camIndex);
        
        // P95 of 8.0 seconds = 8.0
        // Required checks = ceil((8.0 / 0.5) * 1.5) = ceil(24) = 24
        // But maximum is 20
        $this->assertEquals(MAX_STABLE_CHECKS, $requiredChecks);
    }
    
    public function testRecordStabilityMetrics_AcceptedUpload()
    {
        $airportId = 'ktest';
        $camIndex = 0;
        $stabilityTime = 2.5;
        
        // Record accepted upload
        recordStabilityMetrics($airportId, $camIndex, $stabilityTime, true);
        
        // Retrieve metrics
        $key = "stability_metrics_{$airportId}_{$camIndex}";
        $metrics = apcu_fetch($key);
        
        $this->assertIsArray($metrics);
        $this->assertEquals(1, $metrics['accepted']);
        $this->assertEquals(0, $metrics['rejected']);
        $this->assertCount(1, $metrics['stability_times']);
        $this->assertEquals(2.5, $metrics['stability_times'][0]);
    }
    
    public function testRecordStabilityMetrics_RejectedUpload()
    {
        $airportId = 'ktest';
        $camIndex = 0;
        $stabilityTime = 1.0;
        
        // Record rejected upload
        recordStabilityMetrics($airportId, $camIndex, $stabilityTime, false);
        
        // Retrieve metrics
        $key = "stability_metrics_{$airportId}_{$camIndex}";
        $metrics = apcu_fetch($key);
        
        $this->assertIsArray($metrics);
        $this->assertEquals(0, $metrics['accepted']);
        $this->assertEquals(1, $metrics['rejected']);
        $this->assertCount(0, $metrics['stability_times']); // Should NOT record time
    }
    
    public function testRecordStabilityMetrics_RollingWindow()
    {
        $airportId = 'ktest';
        $camIndex = 0;
        
        // Record 150 uploads (exceeds rolling window of 100)
        for ($i = 0; $i < 150; $i++) {
            recordStabilityMetrics($airportId, $camIndex, 1.0, true);
        }
        
        // Retrieve metrics
        $key = "stability_metrics_{$airportId}_{$camIndex}";
        $metrics = apcu_fetch($key);
        
        $this->assertCount(STABILITY_SAMPLES_TO_KEEP, $metrics['stability_times']);
        $this->assertCount(100, $metrics['stability_times']); // Only keeps last 100
        $this->assertEquals(150, $metrics['accepted']); // But count is cumulative
    }
    
    public function testConstants_Defined()
    {
        // Test all new constants are defined
        $this->assertTrue(defined('UPLOAD_FILE_MAX_AGE_SECONDS'));
        $this->assertTrue(defined('MIN_UPLOAD_FILE_MAX_AGE_SECONDS'));
        $this->assertTrue(defined('MAX_UPLOAD_FILE_MAX_AGE_SECONDS'));
        $this->assertTrue(defined('DEFAULT_STABILITY_CHECK_TIMEOUT_SECONDS'));
        $this->assertTrue(defined('MIN_STABILITY_CHECK_TIMEOUT_SECONDS'));
        $this->assertTrue(defined('MAX_STABILITY_CHECK_TIMEOUT_SECONDS'));
        $this->assertTrue(defined('MIN_STABLE_CHECKS'));
        $this->assertTrue(defined('MAX_STABLE_CHECKS'));
        $this->assertTrue(defined('DEFAULT_STABLE_CHECKS'));
        $this->assertTrue(defined('STABILITY_CHECK_INTERVAL_MS'));
        $this->assertTrue(defined('STABILITY_SAMPLES_TO_KEEP'));
        $this->assertTrue(defined('REJECTION_RATE_THRESHOLD_HIGH'));
        $this->assertTrue(defined('REJECTION_RATE_THRESHOLD_LOW'));
        $this->assertTrue(defined('P95_SAFETY_MARGIN'));
        $this->assertTrue(defined('MIN_SAMPLES_FOR_OPTIMIZATION'));
    }
    
    public function testConstants_Values()
    {
        // Test constant values are reasonable
        $this->assertEquals(1800, UPLOAD_FILE_MAX_AGE_SECONDS); // 30 minutes
        $this->assertEquals(600, MIN_UPLOAD_FILE_MAX_AGE_SECONDS); // 10 minutes
        $this->assertEquals(7200, MAX_UPLOAD_FILE_MAX_AGE_SECONDS); // 2 hours
        $this->assertEquals(15, DEFAULT_STABILITY_CHECK_TIMEOUT_SECONDS);
        $this->assertEquals(10, MIN_STABILITY_CHECK_TIMEOUT_SECONDS);
        $this->assertEquals(30, MAX_STABILITY_CHECK_TIMEOUT_SECONDS);
        $this->assertEquals(5, MIN_STABLE_CHECKS);
        $this->assertEquals(20, MAX_STABLE_CHECKS);
        $this->assertEquals(20, DEFAULT_STABLE_CHECKS);
        $this->assertEquals(500, STABILITY_CHECK_INTERVAL_MS); // 0.5 seconds
        $this->assertEquals(100, STABILITY_SAMPLES_TO_KEEP);
        $this->assertEquals(0.05, REJECTION_RATE_THRESHOLD_HIGH); // 5%
        $this->assertEquals(0.02, REJECTION_RATE_THRESHOLD_LOW); // 2%
        $this->assertEquals(1.5, P95_SAFETY_MARGIN);
        $this->assertEquals(20, MIN_SAMPLES_FOR_OPTIMIZATION);
    }
}
