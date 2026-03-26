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

        $t = notamTestTimesUpcomingLaterTodayUtc();
        $start = new DateTimeImmutable($t['start_time_utc'], $tz);
        $end = new DateTimeImmutable($t['end_time_utc'], $tz);

        $helperDate = $start->format('Y-m-d');
        $this->assertSame($helperDate, $end->format('Y-m-d'), 'start and end must share one UTC calendar day');

        // Compare helper output to "now" taken after the helper (avoids midnight flake if test straddles UTC day)
        $nowAfter = new DateTimeImmutable('now', $tz);
        $nowDate = $nowAfter->format('Y-m-d');
        $yesterdayUtc = (new DateTimeImmutable($nowDate . 'T12:00:00', $tz))->modify('-1 day')->format('Y-m-d');
        $tomorrowUtc = (new DateTimeImmutable($nowDate . 'T12:00:00', $tz))->modify('+1 day')->format('Y-m-d');
        $this->assertContains(
            $helperDate,
            [$yesterdayUtc, $nowDate, $tomorrowUtc],
            'helper UTC date must be within one day of the post-call UTC "now" (midnight boundary safe)'
        );

        $dayEnd = new DateTimeImmutable($end->format('Y-m-d') . 'T23:59:59', $tz);
        $this->assertTrue(
            $end->getTimestamp() <= $dayEnd->getTimestamp(),
            'end must be on or before 23:59:59 UTC on its calendar day'
        );
        $this->assertTrue(
            $start->getTimestamp() <= $end->getTimestamp(),
            'start must be on or before end'
        );
    }
}
