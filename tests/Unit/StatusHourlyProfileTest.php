<?php
/**
 * Unit tests for UTC hourly profile used by the status page local-calendar views.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/metrics.php';

class StatusHourlyProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @mkdir(CACHE_METRICS_HOURLY_DIR, 0755, true);
        $this->cleanMetricsDir(CACHE_METRICS_HOURLY_DIR);
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
    }

    protected function tearDown(): void
    {
        $this->cleanMetricsDir(CACHE_METRICS_HOURLY_DIR);
        parent::tearDown();
    }

    private function cleanMetricsDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function createHourlyFile(string $hourId, string $airportId, int $pageViews): void
    {
        $file = CACHE_METRICS_HOURLY_DIR . '/' . $hourId . '.json';
        file_put_contents($file, json_encode([
            'bucket_type' => 'hourly',
            'bucket_id' => $hourId,
            'airports' => [
                $airportId => ['page_views' => $pageViews, 'weather_requests' => 0, 'webcam_requests' => 0],
            ],
            'webcams' => [],
            'global' => metrics_get_empty_global(),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Profile shape: 26 completed hours + 1 partial; partial matches metrics_get_current_hour sparse extract.
     */
    public function testMetricsGetStatusHourlyProfile_CompletedFromDiskPartialFromLive(): void
    {
        $snapshot = strtotime('2025-06-15 14:22:00 UTC');
        $prevHourId = '2025-06-15-13';
        $currentHourId = '2025-06-15-14';
        $this->createHourlyFile($prevHourId, 'kspb', 100);
        // Current hour bucket on disk (scheduler-flushed); APCu optional for this assertion.
        $this->createHourlyFile($currentHourId, 'kspb', 42);

        $live = metrics_get_current_hour($snapshot);
        $liveSparse = metrics_sparse_page_views_from_hour_aggregate($live);

        $profile = metrics_get_status_hourly_profile($snapshot);

        $this->assertSame(METRICS_STATUS_HOURLY_PROFILE_SCHEMA_VERSION, $profile['schema_version']);
        $this->assertSame($snapshot, $profile['generated_at']);
        $this->assertSame((int) METRICS_STATUS_HOURLY_PROFILE_COMPLETED_HOURS, $profile['window_completed_hours']);
        $this->assertSame('2025-06-15-14', $profile['current_hour_id']);
        $this->assertCount(
            METRICS_STATUS_HOURLY_PROFILE_COMPLETED_HOURS + 1,
            $profile['hours'],
            '26 completed UTC hours plus one partial hour'
        );

        $prevRow = null;
        foreach ($profile['hours'] as $row) {
            if ($row['hour_id'] === $prevHourId) {
                $prevRow = $row;
                break;
            }
        }
        $this->assertNotNull($prevRow);
        $this->assertTrue($prevRow['complete']);
        $this->assertSame(['kspb' => 100], $prevRow['views']);

        $last = $profile['hours'][count($profile['hours']) - 1];
        $this->assertSame('2025-06-15-14', $last['hour_id']);
        $this->assertFalse($last['complete']);
        $this->assertSame($liveSparse, $last['views']);
        $this->assertSame(42, $last['views']['kspb'] ?? 0);
    }

    /**
     * Zero views are omitted from sparse maps.
     */
    public function testMetricsSparsePageViewsFromHourAggregate_OmitsZero(): void
    {
        $agg = [
            'airports' => [
                'kfoo' => ['page_views' => 0, 'weather_requests' => 1, 'webcam_requests' => 0],
                'kbar' => ['page_views' => 3, 'weather_requests' => 0, 'webcam_requests' => 0],
            ],
        ];
        $sparse = metrics_sparse_page_views_from_hour_aggregate($agg);
        $this->assertSame(['kbar' => 3], $sparse);
    }

    /**
     * Disk sparse map skips re-reading hourly JSON when bundle already decoded the hour.
     */
    public function testMetricsGetStatusHourlyProfile_ReusesDiskSparseMap(): void
    {
        $snapshot = strtotime('2025-06-15 14:22:00 UTC');
        $hourId = '2025-06-15-13';
        $this->createHourlyFile($hourId, 'kspb', 77);
        $diskSparse = [
            $hourId => ['kspb' => 77],
        ];

        $profile = metrics_get_status_hourly_profile($snapshot, null, $diskSparse);

        foreach ($profile['hours'] as $row) {
            if ($row['hour_id'] === $hourId) {
                $this->assertSame(['kspb' => 77], $row['views']);

                return;
            }
        }
        $this->fail('Expected hour row not found');
    }
}
