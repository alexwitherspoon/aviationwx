<?php
/**
 * Unit tests for METAR completeness aggregation helpers.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/metar-completeness-aggregate.php';

class MetarCompletenessAggregateTest extends TestCase
{
    public function testNormalizeAggregateFieldObsTime_integer(): void
    {
        $this->assertSame(1700000000, normalizeAggregateFieldObsTime(1700000000));
    }

    public function testNormalizeAggregateFieldObsTime_floatWhole(): void
    {
        $this->assertSame(1700000000, normalizeAggregateFieldObsTime(1700000000.0));
    }

    public function testNormalizeAggregateFieldObsTime_numericString(): void
    {
        $this->assertSame(1700000000, normalizeAggregateFieldObsTime('1700000000'));
    }

    public function testNormalizeAggregateFieldObsTime_invalidReturnsNull(): void
    {
        $this->assertNull(normalizeAggregateFieldObsTime('abc'));
        $this->assertNull(normalizeAggregateFieldObsTime([]));
        $this->assertNull(normalizeAggregateFieldObsTime(1.5));
    }

    public function testPickMetarSnapshotForFieldCompleteness_invalidFieldThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        pickMetarSnapshotForFieldCompleteness([], 'wind_speed', null);
    }
}
