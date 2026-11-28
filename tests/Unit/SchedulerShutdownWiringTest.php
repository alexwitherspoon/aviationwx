<?php

declare(strict_types=1);

namespace AviationWx\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Scheduler shutdown must terminate pool workers instead of orphaning them.
 */
final class SchedulerShutdownWiringTest extends TestCase
{
    public function testWiring_ShutdownFunctionTerminatesPools(): void
    {
        $scheduler = file_get_contents(dirname(__DIR__, 2) . '/scripts/scheduler.php');
        $this->assertIsString($scheduler);

        // The registry must be captured by reference so the shutdown closure
        // sees the instance created later in the script.
        $this->assertStringContainsString('&$workRegistry', $scheduler);

        // Reap finished workers first, then terminate the running ones, and
        // do it before the final 'scheduler: shutdown' log line.
        $reapPos = strpos($scheduler, '$workRegistry->cleanupFinishedAll()');
        $terminatePos = strpos($scheduler, '$workRegistry->terminateAll()');
        $shutdownLogPos = strpos($scheduler, "aviationwx_log('info', 'scheduler: shutdown'");
        $this->assertNotFalse($reapPos, 'shutdown function must reap finished pool workers');
        $this->assertNotFalse($terminatePos, 'shutdown function must terminate running pool workers');
        $this->assertNotFalse($shutdownLogPos);
        $this->assertLessThan($terminatePos, $reapPos);
        $this->assertLessThan($shutdownLogPos, $terminatePos);
    }
}
