<?php
/**
 * Unit Tests for Webcam Image Metrics Tracking
 * 
 * Tests the APCu-based image metrics tracking system for webcams.
 * Applies to all webcam types (push uploads and pull fetches).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/webcam-image-metrics.php';

class WebcamImageMetricsTest extends TestCase
{
    private $apcuAvailable;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->apcuAvailable = function_exists('apcu_enabled') && apcu_enabled();
        
        if ($this->apcuAvailable) {
            apcu_clear_cache();
        }
    }
    
    protected function tearDown(): void
    {
        if ($this->apcuAvailable) {
            apcu_clear_cache();
        }
        
        parent::tearDown();
    }
    
    public function testTrackWebcamImageVerified_StoresCount(): void
    {
        if (!$this->apcuAvailable) {
            $this->markTestSkipped('APCu not available');
        }
        
        trackWebcamImageVerified('kspb', 0);
        
        $metrics = getWebcamImageMetrics('kspb', 0);
        
        $this->assertEquals(1, $metrics['verified']);
        $this->assertEquals(0, $metrics['rejected']);
        $this->assertEmpty($metrics['rejection_reasons']);
    }
    
    public function testTrackWebcamImageVerified_MultipleImages_IncreasesCount(): void
    {
        if (!$this->apcuAvailable) {
            $this->markTestSkipped('APCu not available');
        }
        
        trackWebcamImageVerified('kspb', 0);
        trackWebcamImageVerified('kspb', 0);
        trackWebcamImageVerified('kspb', 0);
        
        $metrics = getWebcamImageMetrics('kspb', 0);
        
        $this->assertEquals(3, $metrics['verified']);
    }
    
    public function testTrackWebcamImageRejected_StoresReason(): void
    {
        if (!$this->apcuAvailable) {
            $this->markTestSkipped('APCu not available');
        }
        
        trackWebcamImageRejected('kspb', 0, 'error_frame');
        
        $metrics = getWebcamImageMetrics('kspb', 0);
        
        $this->assertEquals(0, $metrics['verified']);
        $this->assertEquals(1, $metrics['rejected']);
        $this->assertArrayHasKey('error_frame', $metrics['rejection_reasons']);
        $this->assertEquals(1, $metrics['rejection_reasons']['error_frame']);
    }
    
    public function testTrackWebcamImageRejected_MultipleReasons_CountsEach(): void
    {
        if (!$this->apcuAvailable) {
            $this->markTestSkipped('APCu not available');
        }
        
        trackWebcamImageRejected('kspb', 0, 'error_frame');
        trackWebcamImageRejected('kspb', 0, 'error_frame');
        trackWebcamImageRejected('kspb', 0, 'exif_invalid');
        
        $metrics = getWebcamImageMetrics('kspb', 0);
        
        $this->assertEquals(3, $metrics['rejected']);
        $this->assertEquals(2, $metrics['rejection_reasons']['error_frame']);
        $this->assertEquals(1, $metrics['rejection_reasons']['exif_invalid']);
    }
    
    public function testGetWebcamImageMetrics_DifferentCameras_IsolatesCounts(): void
    {
        if (!$this->apcuAvailable) {
            $this->markTestSkipped('APCu not available');
        }
        
        trackWebcamImageVerified('kspb', 0);
        trackWebcamImageVerified('kspb', 0);
        trackWebcamImageVerified('kspb', 1);
        
        $metrics0 = getWebcamImageMetrics('kspb', 0);
        $metrics1 = getWebcamImageMetrics('kspb', 1);
        
        $this->assertEquals(2, $metrics0['verified']);
        $this->assertEquals(1, $metrics1['verified']);
    }
    
    public function testGetWebcamImageMetrics_DifferentAirports_IsolatesCounts(): void
    {
        if (!$this->apcuAvailable) {
            $this->markTestSkipped('APCu not available');
        }
        
        trackWebcamImageVerified('kspb', 0);
        trackWebcamImageVerified('kpfc', 0);
        trackWebcamImageVerified('kpfc', 0);
        
        $metricsKspb = getWebcamImageMetrics('kspb', 0);
        $metricsKpfc = getWebcamImageMetrics('kpfc', 0);
        
        $this->assertEquals(1, $metricsKspb['verified']);
        $this->assertEquals(2, $metricsKpfc['verified']);
    }
    
    public function testGetWebcamImageMetrics_NoData_ReturnsZeros(): void
    {
        $metrics = getWebcamImageMetrics('nonexistent', 99);
        
        $this->assertEquals(0, $metrics['verified']);
        $this->assertEquals(0, $metrics['rejected']);
        $this->assertEmpty($metrics['rejection_reasons']);
        $this->assertEquals(0.0, $metrics['rejection_rate']);
    }
    
    public function testGetWebcamImageMetrics_CalculatesRejectionRate(): void
    {
        if (!$this->apcuAvailable) {
            $this->markTestSkipped('APCu not available');
        }
        
        trackWebcamImageVerified('kspb', 0);
        trackWebcamImageVerified('kspb', 0);
        trackWebcamImageVerified('kspb', 0);
        trackWebcamImageVerified('kspb', 0);
        trackWebcamImageRejected('kspb', 0, 'error_frame');
        
        $metrics = getWebcamImageMetrics('kspb', 0);
        
        // 1 rejected out of 5 total = 20% rejection rate
        $this->assertEquals(0.2, $metrics['rejection_rate']);
    }
    
    public function testFormatImageMetrics_NoData_ReturnsNoImages(): void
    {
        $metrics = ['verified' => 0, 'rejected' => 0, 'rejection_reasons' => []];
        
        $formatted = formatImageMetrics($metrics);
        
        $this->assertEquals('No images (24h)', $formatted);
    }
    
    public function testFormatImageMetrics_OnlyVerified_FormatsCorrectly(): void
    {
        $metrics = ['verified' => 15, 'rejected' => 0, 'rejection_reasons' => []];
        
        $formatted = formatImageMetrics($metrics);
        
        $this->assertEquals('Verified: 15 (24h)', $formatted);
    }
    
    public function testFormatImageMetrics_OnlyRejected_FormatsCorrectly(): void
    {
        $metrics = ['verified' => 0, 'rejected' => 3, 'rejection_reasons' => ['error_frame' => 3]];
        
        $formatted = formatImageMetrics($metrics);
        
        $this->assertEquals('Rejected: 3 (24h)', $formatted);
    }
    
    public function testFormatImageMetrics_Mixed_ShowsBoth(): void
    {
        $metrics = ['verified' => 15, 'rejected' => 2, 'rejection_reasons' => ['error_frame' => 2]];
        
        $formatted = formatImageMetrics($metrics);
        
        $this->assertEquals('Verified: 15 • Rejected: 2 (24h)', $formatted);
    }
    
    public function testGetAirportImageMetrics_ReturnsAllCameras(): void
    {
        if (!$this->apcuAvailable) {
            $this->markTestSkipped('APCu not available');
        }
        
        trackWebcamImageVerified('kspb', 0);
        trackWebcamImageVerified('kspb', 0);
        trackWebcamImageVerified('kspb', 1);
        trackWebcamImageRejected('kspb', 1, 'error_frame');
        
        $allMetrics = getAirportImageMetrics('kspb', 2);
        
        $this->assertCount(2, $allMetrics);
        $this->assertEquals(2, $allMetrics[0]['verified']);
        $this->assertEquals(1, $allMetrics[1]['verified']);
        $this->assertEquals(1, $allMetrics[1]['rejected']);
    }
    
    public function testTrackWebcamImageVerified_WithoutAPCu_DoesNotFail(): void
    {
        if ($this->apcuAvailable) {
            $this->markTestSkipped('Test requires APCu to be disabled');
        }
        
        // Should not throw exception
        trackWebcamImageVerified('kspb', 0);
        
        $metrics = getWebcamImageMetrics('kspb', 0);
        
        $this->assertEquals(0, $metrics['verified']);
        $this->assertEquals(0, $metrics['rejected']);
    }
}
