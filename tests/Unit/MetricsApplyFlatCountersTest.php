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
}
