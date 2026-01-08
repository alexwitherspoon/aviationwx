<?php
/**
 * Unit Tests for Webcam Upload Metrics Tracking
 * 
 * Tests the APCu-based upload metrics tracking system for push webcams.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/webcam-upload-metrics.php';

class WebcamUploadMetricsTest extends TestCase
{
    private $apcuAvailable;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Check if APCu is available
        $this->apcuAvailable = function_exists('apcu_enabled') && apcu_enabled();
        
        if ($this->apcuAvailable) {
            // Clear any existing test data
            apcu_clear_cache();
        }
    }
    
    protected function tearDown(): void
    {
        if ($this->apcuAvailable) {
            // Clean up test data
            apcu_clear_cache();
        }
        
        parent::tearDown();
    }
    
    public function testTrackWebcamUploadAccepted_StoresTimestamp(): void
    {
        if (!$this->apcuAvailable) {
            $this->markTestSkipped('APCu not available');
        }
        
        // Track an accepted upload
        trackWebcamUploadAccepted('kspb', 0);
        
        // Verify it was stored
        $metrics = getWebcamUploadMetrics('kspb', 0);
        
        $this->assertEquals(1, $metrics['accepted'], 'Should track 1 accepted upload');
        $this->assertEquals(0, $metrics['rejected'], 'Should have 0 rejected uploads');
        $this->assertEmpty($metrics['rejection_reasons'], 'Should have no rejection reasons');
    }
    
    public function testTrackWebcamUploadAccepted_MultipleUploads_IncreasesCount(): void
    {
        if (!$this->apcuAvailable) {
            $this->markTestSkipped('APCu not available');
        }
        
        // Track 3 accepted uploads
        trackWebcamUploadAccepted('kspb', 0);
        trackWebcamUploadAccepted('kspb', 0);
        trackWebcamUploadAccepted('kspb', 0);
        
        $metrics = getWebcamUploadMetrics('kspb', 0);
        
        $this->assertEquals(3, $metrics['accepted'], 'Should track 3 accepted uploads');
    }
    
    public function testTrackWebcamUploadRejected_StoresReason(): void
    {
        if (!$this->apcuAvailable) {
            $this->markTestSkipped('APCu not available');
        }
        
        // Track a rejected upload
        trackWebcamUploadRejected('kspb', 0, 'error_frame');
        
        $metrics = getWebcamUploadMetrics('kspb', 0);
        
        $this->assertEquals(0, $metrics['accepted'], 'Should have 0 accepted uploads');
        $this->assertEquals(1, $metrics['rejected'], 'Should track 1 rejected upload');
        $this->assertArrayHasKey('error_frame', $metrics['rejection_reasons']);
        $this->assertEquals(1, $metrics['rejection_reasons']['error_frame']);
    }
    
    public function testTrackWebcamUploadRejected_MultipleReasons_CountsEach(): void
    {
        if (!$this->apcuAvailable) {
            $this->markTestSkipped('APCu not available');
        }
        
        // Track multiple rejected uploads with different reasons
        trackWebcamUploadRejected('kspb', 0, 'error_frame');
        trackWebcamUploadRejected('kspb', 0, 'error_frame');
        trackWebcamUploadRejected('kspb', 0, 'exif_invalid');
        
        $metrics = getWebcamUploadMetrics('kspb', 0);
        
        $this->assertEquals(3, $metrics['rejected'], 'Should track 3 rejected uploads');
        $this->assertEquals(2, $metrics['rejection_reasons']['error_frame']);
        $this->assertEquals(1, $metrics['rejection_reasons']['exif_invalid']);
    }
    
    public function testGetWebcamUploadMetrics_DifferentCameras_IsolatesCounts(): void
    {
        if (!$this->apcuAvailable) {
            $this->markTestSkipped('APCu not available');
        }
        
        // Track uploads for different cameras
        trackWebcamUploadAccepted('kspb', 0);
        trackWebcamUploadAccepted('kspb', 0);
        trackWebcamUploadAccepted('kspb', 1);
        
        $metrics0 = getWebcamUploadMetrics('kspb', 0);
        $metrics1 = getWebcamUploadMetrics('kspb', 1);
        
        $this->assertEquals(2, $metrics0['accepted'], 'Camera 0 should have 2 uploads');
        $this->assertEquals(1, $metrics1['accepted'], 'Camera 1 should have 1 upload');
    }
    
    public function testGetWebcamUploadMetrics_DifferentAirports_IsolatesCounts(): void
    {
        if (!$this->apcuAvailable) {
            $this->markTestSkipped('APCu not available');
        }
        
        // Track uploads for different airports
        trackWebcamUploadAccepted('kspb', 0);
        trackWebcamUploadAccepted('kpfc', 0);
        trackWebcamUploadAccepted('kpfc', 0);
        
        $metricsKspb = getWebcamUploadMetrics('kspb', 0);
        $metricsKpfc = getWebcamUploadMetrics('kpfc', 0);
        
        $this->assertEquals(1, $metricsKspb['accepted'], 'KSPB should have 1 upload');
        $this->assertEquals(2, $metricsKpfc['accepted'], 'KPFC should have 2 uploads');
    }
    
    public function testGetWebcamUploadMetrics_NoData_ReturnsZeros(): void
    {
        $metrics = getWebcamUploadMetrics('nonexistent', 99);
        
        $this->assertEquals(0, $metrics['accepted']);
        $this->assertEquals(0, $metrics['rejected']);
        $this->assertEmpty($metrics['rejection_reasons']);
    }
    
    public function testFormatUploadMetrics_NoData_ReturnsNoUploads(): void
    {
        $metrics = ['accepted' => 0, 'rejected' => 0, 'rejection_reasons' => []];
        
        $formatted = formatUploadMetrics($metrics);
        
        $this->assertEquals('No uploads (last 1h)', $formatted);
    }
    
    public function testFormatUploadMetrics_OnlyAccepted_FormatsCorrectly(): void
    {
        $metrics = ['accepted' => 15, 'rejected' => 0, 'rejection_reasons' => []];
        
        $formatted = formatUploadMetrics($metrics);
        
        $this->assertEquals('Accepted: 15 (last 1h)', $formatted);
    }
    
    public function testFormatUploadMetrics_OnlyRejected_FormatsCorrectly(): void
    {
        $metrics = ['accepted' => 0, 'rejected' => 3, 'rejection_reasons' => ['error_frame' => 3]];
        
        $formatted = formatUploadMetrics($metrics);
        
        $this->assertEquals('Rejected: 3 (last 1h)', $formatted);
    }
    
    public function testFormatUploadMetrics_Mixed_ShowsBoth(): void
    {
        $metrics = ['accepted' => 15, 'rejected' => 2, 'rejection_reasons' => ['error_frame' => 2]];
        
        $formatted = formatUploadMetrics($metrics);
        
        $this->assertEquals('Accepted: 15 • Rejected: 2 (last 1h)', $formatted);
    }
    
    public function testGetAirportUploadMetrics_AggregatesAllCameras(): void
    {
        if (!$this->apcuAvailable) {
            $this->markTestSkipped('APCu not available');
        }
        
        // Track uploads for multiple cameras at same airport
        trackWebcamUploadAccepted('kspb', 0);
        trackWebcamUploadAccepted('kspb', 0);
        trackWebcamUploadAccepted('kspb', 1);
        trackWebcamUploadRejected('kspb', 1, 'error_frame');
        
        $allMetrics = getAirportUploadMetrics('kspb', 2);
        
        $this->assertCount(2, $allMetrics, 'Should return metrics for 2 cameras');
        $this->assertEquals(2, $allMetrics[0]['accepted'], 'Camera 0 should have 2 accepted');
        $this->assertEquals(1, $allMetrics[1]['accepted'], 'Camera 1 should have 1 accepted');
        $this->assertEquals(1, $allMetrics[1]['rejected'], 'Camera 1 should have 1 rejected');
    }
    
    public function testTrackWebcamUploadAccepted_WithoutAPCu_DoesNotFail(): void
    {
        if ($this->apcuAvailable) {
            $this->markTestSkipped('Test requires APCu to be disabled');
        }
        
        // Should not throw exception when APCu not available
        trackWebcamUploadAccepted('kspb', 0);
        
        $metrics = getWebcamUploadMetrics('kspb', 0);
        
        // Should return empty metrics gracefully
        $this->assertEquals(0, $metrics['accepted']);
        $this->assertEquals(0, $metrics['rejected']);
    }
}
