<?php

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';

/**
 * Push webcam adaptive-stability constants.
 *
 * Runtime helpers live on PushAcquisitionStrategy (private); coverage for that
 * behavior belongs with webcam-acquisition tests, not these globals.
 */
class AdaptiveStabilityTest extends TestCase
{
    public function testConstants_Defined(): void
    {
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

    public function testConstants_Values(): void
    {
        $this->assertSame(1800, UPLOAD_FILE_MAX_AGE_SECONDS);
        $this->assertSame(600, MIN_UPLOAD_FILE_MAX_AGE_SECONDS);
        $this->assertSame(7200, MAX_UPLOAD_FILE_MAX_AGE_SECONDS);
        $this->assertSame(15, DEFAULT_STABILITY_CHECK_TIMEOUT_SECONDS);
        $this->assertSame(10, MIN_STABILITY_CHECK_TIMEOUT_SECONDS);
        $this->assertSame(30, MAX_STABILITY_CHECK_TIMEOUT_SECONDS);
        $this->assertSame(5, MIN_STABLE_CHECKS);
        $this->assertSame(20, MAX_STABLE_CHECKS);
        $this->assertSame(20, DEFAULT_STABLE_CHECKS);
        $this->assertSame(500, STABILITY_CHECK_INTERVAL_MS);
        $this->assertSame(100, STABILITY_SAMPLES_TO_KEEP);
        $this->assertSame(0.05, REJECTION_RATE_THRESHOLD_HIGH);
        $this->assertSame(0.02, REJECTION_RATE_THRESHOLD_LOW);
        $this->assertSame(1.5, P95_SAFETY_MARGIN);
        $this->assertSame(20, MIN_SAMPLES_FOR_OPTIMIZATION);
    }
}
