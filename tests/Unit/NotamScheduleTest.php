<?php
/**
 * NOTAM schedule parsing and segment-based status (FAA EFFECTIVE ... UTC UNTIL ... text).
 *
 * Examples mirror FAA prose (10-digit UTC groups); times are built relative to a fixed anchor
 * so tests stay deterministic.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/schedule.php';
require_once __DIR__ . '/../../lib/notam/filter.php';
require_once __DIR__ . '/../../lib/constants.php';

class NotamScheduleTest extends TestCase {
    /**
     * FAA-style YYMMDDHHMM from a UTC Unix instant.
     *
     * @param int $unix UTC Unix timestamp
     * @return string Ten-digit group
     */
    private function faaTenDigitFromUnix(int $unix): string {
        return gmdate('ymdHi', $unix);
    }

    /**
     * @param int $startUnix UTC start
     * @param int $endUnix UTC end
     * @return array{start_time_utc: string, end_time_utc: string}
     */
    private function segmentFromUnix(int $startUnix, int $endUnix): array {
        $s = notamTimestampToIsoUtc($startUnix);
        $e = notamTimestampToIsoUtc($endUnix);
        $this->assertNotNull($s);
        $this->assertNotNull($e);
        return ['start_time_utc' => $s, 'end_time_utc' => $e];
    }

    public function testFaaTenDigitUtcGroupToTimestamp_RejectsNonDigit(): void {
        $this->assertNull(faaTenDigitUtcGroupToTimestamp('260515180a'));
        $this->assertNull(faaTenDigitUtcGroupToTimestamp('123456789'));
    }

    public function testParseFaaEffectiveUtcSegmentsFromText_RealisticTfrProse(): void {
        // Anchor: 2026-05-15 12:00 UTC (matches user session date); two disjunct windows like Hillsboro TFR lists.
        $anchor = gmmktime(12, 0, 0, 5, 15, 2026);
        $w1a = $anchor - 7200;
        $w1b = $anchor - 3600;
        $w2a = $anchor + 3600;
        $w2b = $anchor + 7200;
        $g1a = $this->faaTenDigitFromUnix($w1a);
        $g1b = $this->faaTenDigitFromUnix($w1b);
        $g2a = $this->faaTenDigitFromUnix($w2a);
        $g2b = $this->faaTenDigitFromUnix($w2b);
        $text = 'TEMPORARY FLIGHT RESTRICTIONS. KHIO AREA. '
            . "EFFECTIVE {$g1a} UTC UNTIL {$g1b} UTC AND {$g2a} UTC UNTIL {$g2b} UTC "
            . 'SEE FDC 6/9458 FOR FULL TEXT.';
        $segs = parseFaaEffectiveUtcSegmentsFromText($text);
        $this->assertCount(2, $segs);
        $this->assertEquals($this->segmentFromUnix($w1a, $w1b), $segs[0]);
        $this->assertEquals($this->segmentFromUnix($w2a, $w2b), $segs[1]);
    }

    public function testNotamNormalizeEffectiveSegments_MergesOverlapping(): void {
        $anchor = gmmktime(0, 0, 0, 6, 1, 2026);
        $a = $this->segmentFromUnix($anchor, $anchor + 3600);
        $b = $this->segmentFromUnix($anchor + 1800, $anchor + 5400);
        $out = notamNormalizeEffectiveSegments([$a, $b]);
        $this->assertCount(1, $out);
        $this->assertEquals($this->segmentFromUnix($anchor, $anchor + 5400), $out[0]);
    }

    public function testEnrichParsedNotamWithSchedule_TextWinsOverEnvelope(): void {
        $anchor = gmmktime(10, 0, 0, 7, 4, 2026);
        $ga = $this->faaTenDigitFromUnix($anchor);
        $gb = $this->faaTenDigitFromUnix($anchor + 3600);
        $notam = [
            'text' => "EFFECTIVE {$ga} UTC UNTIL {$gb} UTC",
            'start_time_utc' => notamTimestampToIsoUtc($anchor - 86400),
            'end_time_utc' => notamTimestampToIsoUtc($anchor + 86400 * 2),
        ];
        enrichParsedNotamWithSchedule($notam);
        $this->assertSame('text_effective', $notam['schedule_source']);
        $this->assertCount(1, $notam['effective_segments']);
    }

    public function testEnrichParsedNotamWithSchedule_EnvelopeWhenNoTextPairs(): void {
        $s = gmmktime(8, 0, 0, 8, 1, 2026);
        $e = gmmktime(20, 0, 0, 8, 1, 2026);
        $notam = [
            'text' => 'RWY 09/27 CLSD',
            'start_time_utc' => notamTimestampToIsoUtc($s),
            'end_time_utc' => notamTimestampToIsoUtc($e),
        ];
        enrichParsedNotamWithSchedule($notam);
        $this->assertSame('envelope', $notam['schedule_source']);
        $this->assertCount(1, $notam['effective_segments']);
    }

    public function testClassifyNotamDisplayStatusAt_InGapBetweenWindows(): void {
        $anchor = gmmktime(12, 0, 0, 9, 10, 2026);
        $segA = $this->segmentFromUnix($anchor - 7200, $anchor - 3600);
        $segB = $this->segmentFromUnix($anchor + 3600, $anchor + 7200);
        $notam = [
            'start_time_utc' => notamTimestampToIsoUtc($anchor - 86400),
            'end_time_utc' => notamTimestampToIsoUtc($anchor + 86400),
            'text' => '',
            'effective_segments' => [$segA, $segB],
        ];
        $this->assertSame(
            'inactive_scheduled',
            classifyNotamDisplayStatusAt($notam, 'UTC', $anchor)
        );
    }

    public function testClassifyNotamDisplayStatusAt_ActiveInsideSecondWindow(): void {
        $anchor = gmmktime(12, 0, 0, 10, 1, 2026);
        $segA = $this->segmentFromUnix($anchor - 7200, $anchor - 3600);
        $segB = $this->segmentFromUnix($anchor + 3600, $anchor + 7200);
        $now = $anchor + 5400;
        $notam = [
            'start_time_utc' => notamTimestampToIsoUtc($anchor - 86400),
            'end_time_utc' => notamTimestampToIsoUtc($anchor + 86400 * 2),
            'text' => '',
            'effective_segments' => [$segA, $segB],
        ];
        $this->assertSame('active', classifyNotamDisplayStatusAt($notam, 'UTC', $now));
        $this->assertEquals(
            $segB['end_time_utc'],
            notamCurrentRestrictionEndUtc($notam, $now)
        );
        $this->assertNull(notamNextRestrictionStartUtc($notam, $now));
    }

    public function testNotamNextRestrictionStartUtc_InGap(): void {
        $anchor = gmmktime(12, 0, 0, 11, 1, 2026);
        $segA = $this->segmentFromUnix($anchor - 7200, $anchor - 3600);
        $segB = $this->segmentFromUnix($anchor + 3600, $anchor + 7200);
        $notam = [
            'start_time_utc' => notamTimestampToIsoUtc($anchor - 86400),
            'end_time_utc' => notamTimestampToIsoUtc($anchor + 86400),
            'effective_segments' => [$segA, $segB],
        ];
        $this->assertEquals(
            $segB['start_time_utc'],
            notamNextRestrictionStartUtc($notam, $anchor)
        );
    }

    public function testNotamIsBannerRelevantStatus_UpcomingFutureWithinHorizon(): void {
        $start = time() + (int)(NOTAM_BANNER_UPCOMING_FUTURE_HORIZON_SECONDS / 2);
        $end = $start + 3600;
        $notam = [
            'start_time_utc' => notamTimestampToIsoUtc($start),
            'end_time_utc' => notamTimestampToIsoUtc($end),
            'text' => '',
        ];
        enrichParsedNotamWithSchedule($notam);
        $this->assertTrue(notamIsBannerRelevantStatus('upcoming_future', $notam));
    }

    public function testNotamIsBannerRelevantStatus_UpcomingFutureBeyondHorizon(): void {
        $start = time() + NOTAM_BANNER_UPCOMING_FUTURE_HORIZON_SECONDS + 3600;
        $end = $start + 3600;
        $notam = [
            'start_time_utc' => notamTimestampToIsoUtc($start),
            'end_time_utc' => notamTimestampToIsoUtc($end),
            'text' => '',
        ];
        enrichParsedNotamWithSchedule($notam);
        $this->assertFalse(notamIsBannerRelevantStatus('upcoming_future', $notam));
    }

    public function testMergeParsedNotamDuplicates_PrefersLongerTextAndWidensEnvelope(): void {
        $s = gmmktime(0, 0, 0, 12, 1, 2026);
        $e = gmmktime(23, 59, 0, 12, 1, 2026);
        $isoS = notamTimestampToIsoUtc($s);
        $isoE = notamTimestampToIsoUtc($e);
        $this->assertNotNull($isoS);
        $this->assertNotNull($isoE);
        $thin = [
            'id' => 'FDC 0/0000',
            'text' => 'TFR',
            'start_time_utc' => $isoS,
            'end_time_utc' => $isoE,
        ];
        $ga = $this->faaTenDigitFromUnix($s + 3600);
        $gb = $this->faaTenDigitFromUnix($s + 7200);
        $rich = [
            'id' => 'FDC 0/0000',
            'text' => "TEMPORARY FLIGHT RESTRICTIONS. FULL POLYGON TEXT. EFFECTIVE {$ga} UTC UNTIL {$gb} UTC",
            'start_time_utc' => $isoS,
            'end_time_utc' => $isoE,
        ];
        $merged = mergeParsedNotamDuplicates($thin, $rich);
        $this->assertStringContainsString('EFFECTIVE', $merged['text']);
        $this->assertSame('text_effective', $merged['schedule_source']);
    }
}
