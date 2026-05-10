<?php
/**
 * Shape tests for status metrics bundle JSON (no HTTP server).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/metrics.php';

class StatusMetricsBundleShapeTest extends TestCase
{
    public function testEmbeddedProfileMatchesPageJsonEncodeFlags(): void
    {
        $profile = [
            'generated_at' => 1700000000,
            'current_hour_id' => '2025-06-15-14',
            'window_completed_hours' => METRICS_STATUS_HOURLY_PROFILE_COMPLETED_HOURS,
            'schema_version' => METRICS_STATUS_HOURLY_PROFILE_SCHEMA_VERSION,
            'hours' => [
                ['hour_id' => '2025-06-15-13', 'complete' => true, 'views' => ['kspb' => 1]],
            ],
        ];
        $json = json_encode(
            $profile,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
        $this->assertIsString($json);
        $back = json_decode($json, true);
        $this->assertIsArray($back);
        $this->assertSame(METRICS_STATUS_HOURLY_PROFILE_SCHEMA_VERSION, $back['schema_version']);
    }

    public function testMetricsGetStatusBundleIncludesMultiPeriodAndSchema(): void
    {
        @mkdir(CACHE_METRICS_HOURLY_DIR, 0755, true);
        @mkdir(CACHE_METRICS_DAILY_DIR, 0755, true);
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
        metrics_invalidate_status_bundle_mirror();

        $bundle = metrics_get_status_bundle();
        $this->assertArrayHasKey('multiPeriod', $bundle);
        $this->assertArrayHasKey('hourly_profile', $bundle);
        $this->assertSame(METRICS_STATUS_HOURLY_PROFILE_SCHEMA_VERSION, $bundle['hourly_profile']['schema_version']);
    }
}
