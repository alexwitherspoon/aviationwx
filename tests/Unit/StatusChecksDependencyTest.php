<?php
/**
 * Regression: status-checks.php must load process-utils.php so CLI callers
 * (e.g. fetch-status-health.php) have isProcessRunning() without loading pages/status.php.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/status-checks.php';

final class StatusChecksDependencyTest extends TestCase
{
    /**
     * fetch-status-health and similar scripts require only status-checks; scheduler
     * PID checks must not fatal with undefined isProcessRunning().
     */
    public function testStatusChecks_LoadsIsProcessRunningForSchedulerChecks(): void
    {
        $this->assertTrue(
            function_exists('isProcessRunning'),
            'status-checks.php must require process-utils.php so getSchedulerStatus() can call isProcessRunning()'
        );

        $status = getSchedulerStatus();
        $this->assertIsArray($status);
        $this->assertArrayHasKey('running', $status);
        $this->assertArrayHasKey('error', $status);
    }
}
