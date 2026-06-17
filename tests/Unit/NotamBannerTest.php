<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/filter.php';
require_once __DIR__ . '/../../lib/notam/banner.php';

/**
 * Dashboard NOTAM banner taxonomy, deduplication, and display ordering.
 */
final class NotamBannerTest extends TestCase
{
    private function kspbAirport(): array
    {
        return [
            'icao' => 'KSPB',
            'faa' => 'SPB',
            'name' => 'Scappoose Industrial Airpark',
            'timezone' => 'America/Los_Angeles',
        ];
    }

    public function testClassifyScope_RunwayClosure(): void
    {
        $airport = $this->kspbAirport();
        $notam = [
            'notam_type' => 'aerodrome_closure',
            'code' => '',
            'text' => 'RWY 15/33 CLSD',
            'location' => 'KSPB',
            'scenario' => '86',
            'aixm_runway_event' => true,
        ];

        $this->assertSame('runway', notamBannerClassifyScope($notam, $airport));
        $this->assertSame('full_closure', notamBannerClassifyCategory('runway', $notam));
        $this->assertSame('RWY 15/33 closed', notamBannerBuildHeadline([
            'banner_scope' => 'runway',
            'banner_category' => 'full_closure',
            'text' => $notam['text'],
        ]));
    }

    public function testClassifyScope_AerodromeClosure(): void
    {
        $airport = $this->kspbAirport();
        $notam = [
            'notam_type' => 'aerodrome_closure',
            'code' => 'QFALC',
            'text' => 'AD AP CLSD',
            'location' => 'KSPB',
        ];

        $this->assertSame('aerodrome', notamBannerClassifyScope($notam, $airport));
        $this->assertSame('Airport closed', notamBannerBuildHeadline([
            'banner_scope' => 'aerodrome',
            'banner_category' => 'full_closure',
            'text' => $notam['text'],
        ]));
    }

    public function testClassifyScope_PartialRunwayRestrictionHeadline(): void
    {
        $text = 'DFW RWY 13L/31R CLSD TO ACFT WINGSPAN MORE THAN 214FT';
        $this->assertTrue(notamBannerTextIndicatesPartialRunwayRestriction($text));
        $this->assertSame('partial_restriction', notamBannerClassifyCategory('runway', ['text' => $text]));
        $this->assertSame(
            'RWY 13L/31R restricted - wingspan over 214 ft',
            notamBannerBuildHeadline([
                'banner_scope' => 'runway',
                'banner_category' => 'partial_restriction',
                'text' => $text,
            ])
        );
    }

    public function testClassifyAirspace_FireTfrHeadline(): void
    {
        $text = 'ID..AIRSPACE 34NM SE COEUR D\'ALENE, ID..TEMPORARY FLIGHT RESTRICTIONS. '
            . 'PURSUANT TO 14 CFR SECTION 91.137(A)(2) WI AN AREA DEFINED AS 7NM RADIUS OF '
            . '473130N1160445W SFC-7500FT GOLD RUN FIRE';
        $this->assertSame('fire', notamBannerClassifyAirspaceCategory($text));
        $headline = notamBannerBuildAirspaceHeadline('fire', $text);
        $this->assertStringContainsString('Fire TFR', $headline);
        $this->assertStringContainsString('7 NM radius', $headline);
    }

    public function testClassifyAirspace_RocketTestCategory(): void
    {
        $text = 'ZLC UT..AIRSPACE OGDEN, UT..TEMPORARY FLIGHT RESTRICTIONS WITHIN AN AREA '
            . 'DEFINED AS 5NM RADIUS OF 413900N1122300W STATIC GROUND BASED ROCKET ENGINE TEST.';
        $this->assertSame('space_launch', notamBannerClassifyAirspaceCategory($text));
        $this->assertStringContainsString('Rocket test TFR', notamBannerBuildAirspaceHeadline('space_launch', $text));
    }

    public function testDeduplicateBannerNotams_MergesPairedFireTfrIds(): void
    {
        $airport = ['icao' => 'KS83', 'faa' => 'S83', 'name' => 'Shoshone County'];
        $text = 'ID..AIRSPACE 34NM SE COEUR D\'ALENE, ID..TEMPORARY FLIGHT RESTRICTIONS. '
            . 'PURSUANT TO 14 CFR SECTION 91.137(A)(2) WI AN AREA DEFINED AS 7NM RADIUS OF '
            . '473130N1160445W SFC-7500FT';
        $base = [
            'notam_type' => 'tfr',
            'text' => $text,
            'start_time_utc' => '2026-06-17T14:00:00Z',
            'end_time_utc' => '2026-06-18T05:00:00Z',
            'status' => 'upcoming_today',
            'banner_scope' => 'airspace',
            'banner_category' => 'fire',
        ];
        $a = $base + ['id' => 'A3389/2026', 'banner_event_fingerprint' => notamBannerEventFingerprint($base + ['id' => 'A3389/2026'], $airport)];
        $b = $base + ['id' => '8838/2026', 'banner_event_fingerprint' => $a['banner_event_fingerprint']];

        $deduped = deduplicateBannerNotams([$b, $a]);

        $this->assertCount(1, $deduped);
        $this->assertSame('A3389/2026', $deduped[0]['id']);
    }

    public function testSortBannerNotamsForDisplay_OrdersByStatusThenScope(): void
    {
        $now = strtotime('2026-06-17T12:00:00Z');
        $today = [
            'status' => 'upcoming_today',
            'banner_scope' => 'airspace',
            'banner_category' => 'fire',
            'start_time_utc' => '2026-06-17T14:00:00Z',
            'end_time_utc' => '2026-06-18T05:00:00Z',
            'text' => 'TFR today',
            'banner_event_fingerprint' => 'fp-today',
        ];
        $future = [
            'status' => 'upcoming_future',
            'banner_scope' => 'airspace',
            'banner_category' => 'fire',
            'start_time_utc' => '2026-06-18T14:00:00Z',
            'end_time_utc' => '2026-07-02T05:00:00Z',
            'text' => 'TFR DLY 1400-0500',
            'banner_event_fingerprint' => 'fp-future',
        ];

        $sorted = sortBannerNotamsForDisplay([$future, $today], $now);

        $this->assertCount(2, $sorted);
        $this->assertSame('fp-today', $sorted[0]['banner_event_fingerprint']);
        $this->assertSame('fp-future', $sorted[1]['banner_event_fingerprint']);
    }

    public function testSortBannerNotamsForDisplay_OrdersInactiveScheduledByNextWindow(): void
    {
        $now = strtotime('2026-06-17T16:00:00Z');
        $soonerGap = [
            'status' => 'inactive_scheduled',
            'banner_scope' => 'runway',
            'banner_category' => 'full_closure',
            'text' => 'RWY 15/33 CLSD',
            'effective_segments' => [
                [
                    'start_time_utc' => '2026-06-17T10:00:00Z',
                    'end_time_utc' => '2026-06-17T14:00:00Z',
                ],
                [
                    'start_time_utc' => '2026-06-17T20:00:00Z',
                    'end_time_utc' => '2026-06-18T05:00:00Z',
                ],
            ],
            'banner_event_fingerprint' => 'fp-sooner',
        ];
        $laterGap = [
            'status' => 'inactive_scheduled',
            'banner_scope' => 'runway',
            'banner_category' => 'full_closure',
            'text' => 'RWY 15/33 CLSD',
            'effective_segments' => [
                [
                    'start_time_utc' => '2026-06-17T08:00:00Z',
                    'end_time_utc' => '2026-06-17T12:00:00Z',
                ],
                [
                    'start_time_utc' => '2026-06-18T14:00:00Z',
                    'end_time_utc' => '2026-06-19T05:00:00Z',
                ],
            ],
            'banner_event_fingerprint' => 'fp-later',
        ];

        $sorted = sortBannerNotamsForDisplay([$laterGap, $soonerGap], $now);

        $this->assertSame('fp-sooner', $sorted[0]['banner_event_fingerprint']);
        $this->assertSame('fp-later', $sorted[1]['banner_event_fingerprint']);
    }

    public function testSortBannerNotamsForDisplay_ReturnsAllDistinctDedupedRows(): void
    {
        $now = time();
        $runway = [
            'status' => 'active',
            'banner_scope' => 'runway',
            'banner_category' => 'full_closure',
            'text' => 'RWY 15/33 CLSD',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 3600),
            'banner_event_fingerprint' => 'runway-fp',
        ];
        $tfr = [
            'status' => 'active',
            'banner_scope' => 'airspace',
            'banner_category' => 'fire',
            'text' => 'TEMPORARY FLIGHT RESTRICTIONS 5NM RADIUS',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 3600),
            'banner_event_fingerprint' => 'tfr-fp',
        ];

        $sorted = sortBannerNotamsForDisplay([$tfr, $runway], $now);

        $this->assertCount(2, $sorted);
        $this->assertSame('runway', $sorted[0]['banner_scope']);
        $this->assertSame('airspace', $sorted[1]['banner_scope']);
    }

    public function testPrepareDashboardBannerRows_S83LikeDedupesPairsKeepsDistinctEvents(): void
    {
        $airport = ['icao' => 'KS83', 'faa' => 'S83', 'name' => 'Shoshone County', 'lat' => 47.49, 'lon' => -115.87];
        $text = 'ID..AIRSPACE 34NM SE COEUR D\'ALENE, ID..TEMPORARY FLIGHT RESTRICTIONS. '
            . 'PURSUANT TO 14 CFR SECTION 91.137(A)(2) WI AN AREA DEFINED AS 7NM RADIUS OF '
            . '473130N1160445W (MLP268018.0) SFC-7500FT';
        $notams = [
            [
                'id' => 'A3389/2026',
                'notam_type' => 'tfr',
                'text' => $text,
                'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 7200),
                'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 36000),
                'status' => 'upcoming_today',
            ],
            [
                'id' => '8838/2026',
                'notam_type' => 'tfr',
                'text' => $text,
                'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 7200),
                'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 36000),
                'status' => 'upcoming_today',
            ],
            [
                'id' => '8821/2026',
                'notam_type' => 'tfr',
                'text' => $text . ' DLY 1400-0500',
                'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 90000),
                'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 1200000),
                'status' => 'upcoming_future',
            ],
        ];

        $rows = notamPrepareDashboardBannerRows($notams, $airport, 'America/Los_Angeles', time());

        $this->assertCount(2, $rows);
        $this->assertSame('upcoming_today', $rows[0]['status']);
        $this->assertStringContainsString('Fire TFR', $rows[0]['banner_headline']);
        $this->assertSame('upcoming_future', $rows[1]['status']);
        $this->assertStringContainsString('Daily fire TFR', $rows[1]['banner_headline']);
        $this->assertNotEmpty($rows[0]['banner_schedule_line']);
    }
}
