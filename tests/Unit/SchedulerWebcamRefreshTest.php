<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/webcam-schedule-queue.php';

/**
 * The scheduler must not refresh webcams while integration tests run.
 *
 * A concurrent webcam pull re-points original.jpg to a fresh frame mid-test,
 * flipping the over-age no-store staleness assertion to "fresh". Gating webcam
 * refresh in test mode keeps the integration cache deterministic.
 */
class SchedulerWebcamRefreshTest extends TestCase
{
    public function testWebcamRefreshIsDisabledInTestMode(): void
    {
        $this->assertTrue(isTestMode(), 'unit suite must run under test mode for this guard');
        $this->assertFalse(schedulerShouldRefreshWebcams(), 'scheduler must not refresh webcams in test mode');
    }

    public function testWebcamRefreshDecisionInvertsTestMode(): void
    {
        $this->assertSame(
            !isTestMode(),
            schedulerShouldRefreshWebcams(),
            'webcam refresh must be enabled exactly when not in test mode'
        );
    }
}