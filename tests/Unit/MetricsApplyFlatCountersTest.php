<?php
/**
 * Counter merge into hourly disk structure (shared with spill merge and any future writers).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics.php';

class MetricsApplyFlatCountersTest extends TestCase
{
    public function testApply_IncrementsGlobalPageViews(): void
    {
        $hourId = '2026-08-10-15';
        $hourData = metrics_new_empty_hour_bucket($hourId);
        metrics_apply_flat_counters_to_hour_data($hourData, [
            'global_page_views' => 5,
        ]);

        $this->assertSame(5, $hourData['global']['page_views']);
    }

    public function testApply_AirportViews_MergesIntoAirports(): void
    {
        $hourId = '2026-08-10-15';
        $hourData = metrics_new_empty_hour_bucket($hourId);
        metrics_apply_flat_counters_to_hour_data($hourData, [
            'airport_kfoo_views' => 3,
        ]);

        $this->assertSame(3, $hourData['airports']['kfoo']['page_views']);
    }

    public function testApply_PartialLegacyHourJson_DoesNotFatalOnNestedCounters(): void
    {
        $hourId = '2026-08-10-15';
        $hourData = [
            'bucket_type' => 'hourly',
            'bucket_id' => $hourId,
            'global' => [
                'page_views' => 3,
            ],
        ];
        metrics_apply_flat_counters_to_hour_data($hourData, [
            'cache_hits' => 4,
            'browser_webp_support' => 2,
        ]);

        $this->assertSame(4, $hourData['global']['cache']['hits']);
        $this->assertSame(2, $hourData['global']['browser_support']['webp']);
        $this->assertSame(3, $hourData['global']['page_views']);
    }

    public function testFlatCounterKeyRecognition_MatchesKnownPatterns(): void
    {
        $this->assertTrue(metrics_flat_counter_key_is_recognized('global_page_views'));
        $this->assertTrue(metrics_flat_counter_key_is_recognized('airport_ksea_views'));
        $this->assertTrue(metrics_flat_counter_key_is_recognized('webcam_kspb_0_png'));
        $this->assertTrue(metrics_flat_counter_key_is_recognized('format_png_served'));
        $this->assertFalse(metrics_flat_counter_key_is_recognized('not_a_metric_key'));
    }

    public function testApply_PngFormatCounters_AreRecorded(): void
    {
        $hourId = '2026-08-10-15';
        $hourData = metrics_new_empty_hour_bucket($hourId);
        metrics_apply_flat_counters_to_hour_data($hourData, [
            'webcam_kspb_0_png' => 4,
            'format_png_served' => 4,
        ]);

        $this->assertSame(4, $hourData['webcams']['kspb_0']['by_format']['png']);
        $this->assertSame(4, $hourData['global']['format_served']['png']);
    }

    public function testSumWebcamFormatTotals_PngBucket_DoesNotWarn(): void
    {
        $totals = metricsSumWebcamFormatTotals([
            'kspb_0' => ['by_format' => ['jpg' => 1, 'png' => 2, 'webp' => 3]],
        ]);

        $this->assertSame(1, $totals['jpg']);
        $this->assertSame(2, $totals['png']);
        $this->assertSame(3, $totals['webp']);
        $this->assertSame(6, array_sum($totals));
    }

    public function testSumWebcamFormatTotals_UnknownFormatKey_IsInitialized(): void
    {
        $totals = metricsSumWebcamFormatTotals([
            'kspb_0' => ['by_format' => ['avif' => 5]],
        ]);

        $this->assertSame(5, $totals['avif']);
        $this->assertSame(0, $totals['jpg']);
        $this->assertSame(0, $totals['png']);
        $this->assertSame(0, $totals['webp']);
    }
}
