<?php
/**
 * Invariants for NOTAM test time helper (UTC day clamp; avoids next-day rollover flakes).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/TestHelper.php';

class NotamTestHelperUtcTest extends TestCase
{
    public function testNotamTestTimesUpcomingLaterTodayUtc_staysWithinCurrentUtcCalendarDay(): void
    {
        $tz = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $tz);
        $today = $now->format('Y-m-d');

        $t = notamTestTimesUpcomingLaterTodayUtc();
        $start = new DateTimeImmutable($t['start_time_utc'], $tz);
        $end = new DateTimeImmutable($t['end_time_utc'], $tz);

        $this->assertSame($today, $start->format('Y-m-d'), 'start must be on the current UTC date');
        $this->assertSame($today, $end->format('Y-m-d'), 'end must not roll into the next UTC day');

        // End of the returned UTC calendar day (helper and test "now" may differ by a tick at day boundary)
        $dayEnd = new DateTimeImmutable($end->format('Y-m-d') . 'T23:59:59', $tz);
        // assertLessThanOrEqual($maximum, $actual): pass when $actual <= $maximum
        $this->assertLessThanOrEqual($dayEnd->getTimestamp(), $end->getTimestamp());
        $this->assertLessThanOrEqual($end->getTimestamp(), $start->getTimestamp(), 'start must be on or before end');
    }
}
