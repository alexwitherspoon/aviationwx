<?php
/**
 * Tests APCu status bundle mirror rejects pre-schema bundles (forces rebuild).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics.php';

class MetricsStatusBundleMirrorLegacyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
        metrics_invalidate_status_bundle_mirror();
    }

    public function testMirrorWithoutSchemaVersionIsRejected(): void
    {
        if (!function_exists('apcu_store') || !function_exists('apcu_fetch')) {
            $this->markTestSkipped('APCu not available');
        }

        $legacyBundle = [
            'rolling7' => ['period_days' => 7, 'airports' => [], 'webcams' => [], 'global' => metrics_get_empty_global(), 'generated_at' => time()],
            'rolling1' => ['period_days' => 1, 'airports' => [], 'webcams' => [], 'global' => metrics_get_empty_global(), 'generated_at' => time()],
            'today' => ['bucket_type' => 'today', 'bucket_id' => gmdate('Y-m-d'), 'airports' => [], 'webcams' => [], 'global' => metrics_get_empty_global(), 'generated_at' => time()],
            'hourly_profile' => [
                'generated_at' => time(),
                'hours' => [['hour_id' => '2000-01-01-00', 'complete' => true, 'views' => []]],
                // schema_version intentionally omitted (legacy)
            ],
            'multiPeriod' => [],
        ];

        $payload = [
            'generated_at' => time(),
            'today_bucket_id' => gmdate('Y-m-d'),
            'bundle' => $legacyBundle,
        ];
        @apcu_store(METRICS_STATUS_BUNDLE_MIRROR_APCU_KEY, $payload, METRICS_STATUS_BUNDLE_MIRROR_TTL_SECONDS);

        $hit = metrics_try_get_status_bundle_mirror();
        $this->assertNull($hit, 'Legacy mirror without hourly_profile.schema_version must miss');
    }
}
