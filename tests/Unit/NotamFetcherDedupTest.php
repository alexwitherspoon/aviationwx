<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/fetcher.php';

final class NotamFetcherDedupTest extends TestCase
{
    public function testBuildLocationQueryParams_CallerLocationOverridesExtras(): void
    {
        $params = notamBuildLocationQueryParams('KSPB', ['location' => 'KPDX', 'feature' => 'RWY']);

        self::assertSame('KSPB', $params['location']);
        self::assertSame('RWY', $params['feature']);
    }

    public function testDeduplicateNotams_RetainsRowsWithDomStyleId(): void
    {
        $notam = [
            'id' => '06/001/2026',
            'location' => 'KSPB',
            'text' => 'RWY 15/33 CLSD',
            'start_time_utc' => '2026-06-08T14:00:00.000Z',
        ];

        $result = deduplicateNotams([$notam]);

        self::assertCount(1, $result);
        self::assertSame('06/001/2026', $result[0]['id']);
    }

    public function testDeduplicateNotams_FallbackKeyWhenIdMissing(): void
    {
        $notam = [
            'id' => '',
            'location' => 'KSPB',
            'text' => 'RWY 15/33 CLSD',
            'start_time_utc' => '2026-06-08T14:00:00.000Z',
        ];

        $result = deduplicateNotams([$notam]);

        self::assertCount(1, $result);
        self::assertSame('RWY 15/33 CLSD', $result[0]['text']);
    }

    public function testSummarizeFetchQueryOutcomes_PartialSuccessWhenLocationOkGeoFails(): void
    {
        $summary = notamSummarizeFetchQueryOutcomes([true, false]);

        self::assertTrue($summary['attempted']);
        self::assertTrue($summary['fetchSucceeded']);
    }

    public function testSummarizeFetchQueryOutcomes_FailsWhenEveryQueryFails(): void
    {
        $summary = notamSummarizeFetchQueryOutcomes([false, false]);

        self::assertTrue($summary['attempted']);
        self::assertFalse($summary['fetchSucceeded']);
    }

    public function testSummarizeFetchQueryOutcomes_FailsWhenNoQueryAttempted(): void
    {
        $summary = notamSummarizeFetchQueryOutcomes([]);

        self::assertFalse($summary['attempted']);
        self::assertFalse($summary['fetchSucceeded']);
    }

    public function testSummarizeFetchQueryOutcomes_SucceedsWhenOnlyGeoQuerySucceeds(): void
    {
        $summary = notamSummarizeFetchQueryOutcomes([false, true]);

        self::assertTrue($summary['attempted']);
        self::assertTrue($summary['fetchSucceeded']);
        self::assertFalse($summary['allDeferred']);
    }

    public function testSummarizeFetchQueryOutcomes_AllDeferredWhenEveryQueryDeferred(): void
    {
        $summary = notamSummarizeFetchQueryOutcomes([null, null]);

        self::assertTrue($summary['attempted']);
        self::assertFalse($summary['fetchSucceeded']);
        self::assertTrue($summary['allDeferred']);
    }

    public function testSummarizeFetchQueryOutcomes_NotAllDeferredWhenMixedWithHardFailure(): void
    {
        $summary = notamSummarizeFetchQueryOutcomes([null, false]);

        self::assertTrue($summary['attempted']);
        self::assertFalse($summary['fetchSucceeded']);
        self::assertFalse($summary['allDeferred']);
    }

    public function testNotamCanonicalDedupKey_StableForIdenticalRows(): void
    {
        $a = [
            'id' => '',
            'location' => 'KSPB',
            'text' => 'RWY 15/33 CLSD',
            'start_time_utc' => '2026-06-08T14:00:00.000Z',
        ];

        self::assertSame(notamCanonicalDedupKey($a), notamCanonicalDedupKey($a));
    }
}
