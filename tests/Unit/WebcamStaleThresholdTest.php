<?php
/**
 * Unit Tests for getWebcamStaleFailclosedSeconds() precedence.
 *
 * The effective webcam fail-closed threshold follows camera -> airport -> global
 * -> default precedence, matching api/webcam.php. The camera, airport, and
 * preloaded-config tiers are deterministic and covered here; the global ->
 * built-in default fallback is a one-liner also exercised by
 * FailClosedStalenessTest via getStaleFailclosedSeconds().
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/constants.php';

class WebcamStaleThresholdTest extends TestCase
{
    public function testGetWebcamStaleFailclosedSeconds_CameraOverride_WinsOverAirport(): void
    {
        $threshold = getWebcamStaleFailclosedSeconds(
            ['stale_failclosed_seconds' => 7200],
            ['stale_failclosed_seconds' => 3600]
        );
        $this->assertSame(7200, $threshold);
    }

    public function testGetWebcamStaleFailclosedSeconds_NoCameraOverride_UsesAirport(): void
    {
        $threshold = getWebcamStaleFailclosedSeconds(
            [],
            ['stale_failclosed_seconds' => 3600]
        );
        $this->assertSame(3600, $threshold);
    }

    public function testGetWebcamStaleFailclosedSeconds_NullWebcam_UsesAirport(): void
    {
        $threshold = getWebcamStaleFailclosedSeconds(
            null,
            ['stale_failclosed_seconds' => 5400]
        );
        $this->assertSame(5400, $threshold);
    }

    public function testGetWebcamStaleFailclosedSeconds_BelowMinimum_EnforcedToMinimum(): void
    {
        $threshold = getWebcamStaleFailclosedSeconds(
            ['stale_failclosed_seconds' => 1],
            []
        );
        $this->assertSame(MIN_STALE_FAILCLOSED_SECONDS, $threshold);
    }

    public function testGetWebcamStaleFailclosedSeconds_PreloadedConfig_UsesGlobalTier(): void
    {
        $threshold = getWebcamStaleFailclosedSeconds(
            [],
            [],
            ['config' => ['stale_failclosed_seconds' => 9000]]
        );
        $this->assertSame(9000, $threshold);
    }
}
