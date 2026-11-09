<?php
/**
 * Unit tests for the webcam capture cache classification.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-headers.php';

class WebcamCaptureCacheClassificationTest extends TestCase
{
    private int $now = 2000000000;
    private int $failclosed = 10800;
    private int $refresh = 60;

    private function classifyAtAge(int $ageSeconds): string
    {
        return webcamCaptureCacheClassification(
            $this->now - $ageSeconds,
            $this->failclosed,
            $this->refresh,
            $this->now
        );
    }

    public function testWebcamCaptureCacheClassification_OverAge_ReturnsStale(): void
    {
        $this->assertSame('stale', $this->classifyAtAge($this->failclosed + 1));
    }

    public function testWebcamCaptureCacheClassification_AtBoundary_ReturnsNearBoundary(): void
    {
        // age === failclosed is not stale (the stale test is strict >), so the
        // frame lands in the near-boundary bucket at remaining 0.
        $this->assertSame('near_boundary', $this->classifyAtAge($this->failclosed));
    }

    public function testWebcamCaptureCacheClassification_InsideRefreshWindow_ReturnsNearBoundary(): void
    {
        $age = $this->failclosed - (STALE_WHILE_REVALIDATE_SECONDS + $this->refresh - 1);
        $this->assertSame('near_boundary', $this->classifyAtAge($age));
    }

    public function testWebcamCaptureCacheClassification_AtRefreshWindowEdge_ReturnsNearBoundary(): void
    {
        $age = $this->failclosed - (STALE_WHILE_REVALIDATE_SECONDS + $this->refresh);
        $this->assertSame('near_boundary', $this->classifyAtAge($age));
    }

    public function testWebcamCaptureCacheClassification_OutsideRefreshWindow_ReturnsFresh(): void
    {
        $age = $this->failclosed - (STALE_WHILE_REVALIDATE_SECONDS + $this->refresh + 1);
        $this->assertSame('fresh', $this->classifyAtAge($age));
    }

    public function testWebcamCaptureCacheClassification_ZeroTimestamp_ReturnsNearBoundary(): void
    {
        // A missing capture timestamp is not over-age (guarded by > 0), so it
        // falls through to the no-store near-boundary bucket defensively.
        $this->assertSame('near_boundary', webcamCaptureCacheClassification(0, $this->failclosed, $this->refresh, $this->now));
    }
}
