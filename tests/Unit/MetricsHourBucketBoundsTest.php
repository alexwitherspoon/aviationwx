<?php
/**
 * Hour bucket bounds derived from metrics hour id strings (UTC).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics.php';

class MetricsHourBucketBoundsTest extends TestCase
{
    public function testHourBucketBounds_FromWellFormedHourId(): void
    {
        [$start, $end] = metrics_hour_bucket_bounds_from_hour_id('2026-03-15-07');
        $this->assertSame(strtotime('2026-03-15 07:00:00 UTC'), $start);
        $this->assertSame($start + 3600, $end);
    }

    public function testHourIdIsValid_RejectsGarbageCalendar(): void
    {
        $this->assertFalse(metrics_hour_id_is_valid('2026-02-30-12'));
        $this->assertFalse(metrics_hour_id_is_valid('2026-99-01-12'));
        $this->assertFalse(metrics_hour_id_is_valid('2026-01-15-24'));
        $this->assertFalse(metrics_hour_id_is_valid('2026-99-99-99'));
    }

    public function testHourIdIsValid_AcceptsValidUtcHour(): void
    {
        $this->assertTrue(metrics_hour_id_is_valid('2026-01-15-00'));
        $this->assertTrue(metrics_hour_id_is_valid('2026-12-31-23'));
    }

    public function testHourBucketBounds_InvalidHourId_Throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        metrics_hour_bucket_bounds_from_hour_id('2026-99-99-12');
    }
}
