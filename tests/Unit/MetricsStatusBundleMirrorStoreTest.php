<?php
/**
 * Status bundle APCu mirror is populated after a cold metrics_get_status_bundle() build.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics.php';

class MetricsStatusBundleMirrorStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
    }

    /**
     * Cold path must store APCu so subsequent reads can use metrics_try_get_status_bundle_mirror().
     */
    public function testColdPathStoresMirror(): void
    {
        if (!function_exists('apcu_fetch') || !function_exists('apcu_enabled') || !@apcu_enabled()) {
            $this->markTestSkipped('APCu not available or disabled');
        }

        metrics_invalidate_status_bundle_mirror();
        $bundle = metrics_get_status_bundle();
        $this->assertArrayHasKey('rolling7', $bundle);

        $raw = @apcu_fetch(METRICS_STATUS_BUNDLE_MIRROR_APCU_KEY);
        $this->assertIsArray($raw);
        $this->assertArrayHasKey('bundle', $raw);
        $this->assertSame(gmdate('Y-m-d'), $raw['today_bucket_id'] ?? null);
        $b = $raw['bundle'];
        $this->assertArrayHasKey('hourly_profile', $b);
        $this->assertSame(METRICS_STATUS_HOURLY_PROFILE_SCHEMA_VERSION, $b['hourly_profile']['schema_version'] ?? null);
        $this->assertArrayHasKey('multiPeriod', $b);

        $again = metrics_get_status_bundle();
        $this->assertSame($bundle['today']['bucket_id'] ?? null, $again['today']['bucket_id'] ?? null);
    }
}
