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
    public function testCameraOverrideWins(): void
    {
        $threshold = getWebcamStaleFailclosedSeconds(
            ['stale_failclosed_seconds' => 7200],
            ['stale_failclosed_seconds' => 3600]
        );
        $this->assertSame(7200, $threshold);
    }

    public function testAirportOverrideUsedWhenNoCameraOverride(): void
    {
        $threshold = getWebcamStaleFailclosedSeconds(
            [],
            ['stale_failclosed_seconds' => 3600]
        );
        $this->assertSame(3600, $threshold);
    }

    public function testNullWebcamFallsBackToAirport(): void
    {
        $threshold = getWebcamStaleFailclosedSeconds(
            null,
            ['stale_failclosed_seconds' => 3600]
        );
        $this->assertSame(3600, $threshold);
    }

    public function testDefaultUsedWhenBothAbsent(): void
    {
        $threshold = getWebcamStaleFailclosedSeconds(null, []);
        $this->assertSame(DEFAULT_STALE_FAILCLOSED_SECONDS, $threshold);
    }

    public function testMinimumEnforced(): void
    {
        $threshold = getWebcamStaleFailclosedSeconds(
            ['stale_failclosed_seconds' => 1],
            []
        );
        $this->assertSame(MIN_STALE_FAILCLOSED_SECONDS, $threshold);
    }
}