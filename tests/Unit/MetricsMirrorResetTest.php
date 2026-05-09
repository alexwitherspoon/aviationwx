<?php
/**
 * Status bundle APCu mirror must survive metrics_reset_all() (dashboard_metrics_* prefix, not metrics_*).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics.php';

class MetricsMirrorResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
    }

    /**
     * dashboard_metrics_* mirror is not cleared when metrics counters are reset
     */
    public function testStatusBundleMirrorKey_SurvivesMetricsResetAll(): void
    {
        if (!function_exists('apcu_store') || !function_exists('apcu_fetch') || !function_exists('apcu_enabled')
            || !@apcu_enabled()) {
            $this->markTestSkipped('APCu not available or disabled');
        }

        $payload = [
            'generated_at' => time(),
            'today_bucket_id' => gmdate('Y-m-d'),
            'bundle' => ['rolling7' => ['airports' => []]],
        ];
        $this->assertTrue(
            apcu_store(METRICS_STATUS_BUNDLE_MIRROR_APCU_KEY, $payload, 120)
        );

        metrics_increment('global_page_views');
        $this->assertGreaterThan(0, metrics_get('global_page_views'));

        metrics_reset_all();

        $this->assertSame(0, metrics_get('global_page_views'));

        $mirror = @apcu_fetch(METRICS_STATUS_BUNDLE_MIRROR_APCU_KEY);
        $this->assertIsArray($mirror);
        $this->assertArrayHasKey('bundle', $mirror);
        $this->assertSame('dashboard_metrics_status_bundle_mirror_v1', METRICS_STATUS_BUNDLE_MIRROR_APCU_KEY);
    }
}
