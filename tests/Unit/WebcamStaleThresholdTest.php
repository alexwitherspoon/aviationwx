<?php
/**
 * Unit Tests for getWebcamStaleFailclosedSeconds() precedence.
 *
 * The effective webcam fail-closed threshold follows camera -> airport -> global
 * -> default precedence, matching api/webcam.php. This guards the camera-level
 * override that the Public API webcam serving paths now honor (#301).
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
            ['stale_failclosed_seconds' => 3600]
        );
        $this->assertSame(3600, $threshold);
    }

    public function testGetWebcamStaleFailclosedSeconds_NoOverrides_UsesDefault(): void
    {
        $threshold = getWebcamStaleFailclosedSeconds(null, []);
        $this->assertSame(DEFAULT_STALE_FAILCLOSED_SECONDS, $threshold);
    }

    public function testGetWebcamStaleFailclosedSeconds_BelowMinimum_EnforcedToMinimum(): void
    {
        $threshold = getWebcamStaleFailclosedSeconds(
            ['stale_failclosed_seconds' => 1],
            []
        );
        $this->assertSame(MIN_STALE_FAILCLOSED_SECONDS, $threshold);
    }
}