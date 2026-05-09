<?php
/**
 * Hourly bucket scaffolding for disk files (new bucket + default keys for legacy JSON).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics.php';

class MetricsHourBucketStructureTest extends TestCase
{
    public function testNewEmptyHourBucket_HasExpectedKeysAndBounds(): void
    {
        $hourId = '2026-09-20-08';
        $h = metrics_new_empty_hour_bucket($hourId);
        $this->assertSame('hourly', $h['bucket_type']);
        $this->assertSame($hourId, $h['bucket_id']);
        $this->assertArrayHasKey('airports', $h);
        $this->assertArrayHasKey('global', $h);
        [$start, $end] = metrics_hour_bucket_bounds_from_hour_id($hourId);
        $this->assertSame($start, $h['bucket_start']);
        $this->assertSame($end, $h['bucket_end']);
    }

    public function testNormalizeHourBucketForMerge_FillsNestedGlobalsFromPartialDiskJson(): void
    {
        $hourId = '2026-09-20-08';
        $partial = [
            'bucket_type' => 'hourly',
            'bucket_id' => $hourId,
            'global' => [
                'page_views' => 1,
            ],
        ];
        metrics_normalize_hour_bucket_for_merge($partial, $hourId);
        $this->assertSame([ 'jpg' => 0, 'webp' => 0 ], $partial['global']['format_served']);
        $this->assertSame([ 'webp' => 0, 'jpg_only' => 0 ], $partial['global']['browser_support']);
        $this->assertSame([ 'hits' => 0, 'misses' => 0 ], $partial['global']['cache']);
    }

    public function testFillDefaults_AddsMissingNestedKeysOnPartialData(): void
    {
        $partial = [
            'bucket_type' => 'hourly',
            'bucket_id' => '2026-09-20-08',
            'airports' => [],
            'webcams' => [],
            'global' => [
                'page_views' => 1,
            ],
        ];
        metrics_fill_hour_data_defaults($partial);
        $this->assertArrayHasKey('webcam_images', $partial);
        $this->assertArrayHasKey('webcam_requests', $partial['global']);
        $this->assertArrayHasKey('tiles_by_source', $partial['global']);
    }
}
