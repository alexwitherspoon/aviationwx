<?php
/**
 * Unit tests for aggregate last_updated normalization (safety-critical display metadata).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/aggregate-timestamps.php';

final class AggregateTimestampsTest extends TestCase
{
    private int $fallback;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fallback = 1_700_000_000;
    }

    public function testNormalizeAggregateLastUpdatedTimes_MultipleCandidates_SetsLastUpdatedToMax(): void
    {
        $data = [
            'last_updated' => 100,
            'last_updated_primary' => 500,
            'last_updated_metar' => 300,
            'obs_time_primary' => 400,
            'obs_time_metar' => 200,
            '_field_obs_time_map' => [
                'temperature' => 450,
                'wind_speed' => 50,
            ],
        ];
        normalizeAggregateLastUpdatedTimes($data, $this->fallback);

        $this->assertSame(500, $data['last_updated']);
        $this->assertSame(date('c', 500), $data['last_updated_iso']);
    }

    public function testNormalizeAggregateLastUpdatedTimes_FieldObsMapOnly_Wins(): void
    {
        $data = [
            'last_updated' => 0,
            'last_updated_primary' => 0,
            '_field_obs_time_map' => [
                'temperature' => 1_800_000_000,
            ],
        ];
        normalizeAggregateLastUpdatedTimes($data, $this->fallback);

        $this->assertSame(1_800_000_000, $data['last_updated']);
    }

    public function testNormalizeAggregateLastUpdatedTimes_AllNonPositive_UsesFallback(): void
    {
        $data = [
            'last_updated' => 0,
            'last_updated_primary' => -1,
            'last_updated_metar' => 0,
            'obs_time_primary' => -100,
            'obs_time_metar' => 0,
            '_field_obs_time_map' => [
                'temperature' => 0,
                'wind_speed' => -5,
            ],
        ];
        normalizeAggregateLastUpdatedTimes($data, $this->fallback);

        $this->assertSame($this->fallback, $data['last_updated']);
        $this->assertSame(date('c', $this->fallback), $data['last_updated_iso']);
    }

    public function testNormalizeAggregateLastUpdatedTimes_EmptyCandidates_UsesFallback(): void
    {
        $data = [
            '_field_obs_time_map' => [],
        ];
        normalizeAggregateLastUpdatedTimes($data, $this->fallback);

        $this->assertSame($this->fallback, $data['last_updated']);
    }

    public function testNormalizeAggregateLastUpdatedTimes_InvalidScalars_UsesFallback(): void
    {
        $data = [
            'last_updated' => [],
            'last_updated_primary' => 'not-a-number',
            'last_updated_metar' => true,
            'obs_time_primary' => '1.5',
            '_field_obs_time_map' => [
                'a' => 'abc',
                'b' => null,
            ],
        ];
        normalizeAggregateLastUpdatedTimes($data, $this->fallback);

        $this->assertSame($this->fallback, $data['last_updated']);
    }

    public function testNormalizeAggregateLastUpdatedTimes_NumericString_ParsesToInt(): void
    {
        $ts = 1_750_000_000;
        $data = [
            'last_updated' => (string) $ts,
            '_field_obs_time_map' => [],
        ];
        normalizeAggregateLastUpdatedTimes($data, $this->fallback);

        $this->assertSame($ts, $data['last_updated']);
    }

    public function testWeatherPositiveAggregateTimestamp_NonPositive_ReturnsNull(): void
    {
        $this->assertNull(weather_positive_aggregate_timestamp(0));
        $this->assertNull(weather_positive_aggregate_timestamp(-10));
        $this->assertNull(weather_positive_aggregate_timestamp(null));
        $this->assertSame(100, weather_positive_aggregate_timestamp(100));
    }

    public function testNormalizeAggregateLastUpdatedTimes_SecondCall_NoChange(): void
    {
        $data = [
            'last_updated' => 400,
            'last_updated_primary' => 300,
            '_field_obs_time_map' => ['temperature' => 350],
        ];
        normalizeAggregateLastUpdatedTimes($data, $this->fallback);
        $first = $data['last_updated'];
        $firstIso = $data['last_updated_iso'];

        normalizeAggregateLastUpdatedTimes($data, $this->fallback);

        $this->assertSame($first, $data['last_updated']);
        $this->assertSame($firstIso, $data['last_updated_iso']);
    }

    public function testNormalizeAggregateLastUpdatedTimes_ZeroLastUpdatedWithValidPrimary_Repairs(): void
    {
        $data = [
            'last_updated' => 0,
            'last_updated_primary' => 1_800_000_100,
            'last_updated_metar' => 1_800_000_050,
            'obs_time_primary' => null,
            '_field_obs_time_map' => [],
        ];
        normalizeAggregateLastUpdatedTimes($data, $this->fallback);

        $this->assertSame(1_800_000_100, $data['last_updated']);
        $this->assertSame(date('c', 1_800_000_100), $data['last_updated_iso']);
    }
}
