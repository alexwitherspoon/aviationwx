<?php
/**
 * Unit tests for station clock framing and UTC normalization.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/station-clock.php';
require_once __DIR__ . '/../../lib/bridge/weather-store.php';

class StationClockTest extends TestCase
{
    public function testFrameNaive_UtcAligned(): void
    {
        $now = (new DateTime('2025-01-15 18:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $r = stationClockFrameNaive($now - 10, $now, ['site_timezone' => 'America/Chicago']);
        $this->assertTrue($r['ok']);
        $this->assertSame('utc', $r['framing']);
        $this->assertSame($now - 10, $r['epoch_utc']);
    }

    public function testFrameNaive_LocalWinterChicagoNormalizesToUtc(): void
    {
        // Chicago winter is UTC-6. A local console displays 12:00 when the
        // true instant is 18:00 UTC; the naive value is 6h behind.
        $now = (new DateTime('2025-01-15 18:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $naive = $now - 6 * 3600;
        $r = stationClockFrameNaive($naive, $now, ['site_timezone' => 'America/Chicago']);
        $this->assertTrue($r['ok']);
        $this->assertSame('local', $r['framing']);
        $this->assertSame($now, $r['epoch_utc']);
    }

    public function testFrameNaive_LocalSummerChicagoUsesDstOffset(): void
    {
        // Chicago summer is UTC-5 (DST). Same test in July.
        $now = (new DateTime('2025-07-15 18:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $naive = $now - 5 * 3600;
        $r = stationClockFrameNaive($naive, $now, ['site_timezone' => 'America/Chicago']);
        $this->assertTrue($r['ok']);
        $this->assertSame('local', $r['framing']);
        $this->assertSame($now, $r['epoch_utc']);
    }

    public function testFrameNaive_HalfHourZoneNormalizes(): void
    {
        // Asia/Kolkata is UTC+5:30. A local console displays 23:30 when the
        // true instant is 18:00 UTC (naive value 5h30m ahead).
        $now = (new DateTime('2025-01-15 18:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $naive = $now + 5 * 3600 + 30 * 60;
        $r = stationClockFrameNaive($naive, $now, ['site_timezone' => 'Asia/Kolkata']);
        $this->assertTrue($r['ok']);
        $this->assertSame('local', $r['framing']);
        $this->assertSame($now, $r['epoch_utc']);
    }

    public function testFrameNaive_UnidentifiableRejected(): void
    {
        $now = (new DateTime('2025-01-15 18:00:00', new DateTimeZone('UTC')))->getTimestamp();
        // 47 minutes off matches neither UTC nor the site frame.
        $naive = $now - 47 * 60;
        $r = stationClockFrameNaive($naive, $now, ['site_timezone' => 'America/Chicago']);
        $this->assertFalse($r['ok']);
        $this->assertNull($r['framing']);
        $this->assertNotNull($r['reason']);
    }

    public function testFrameNaive_HourOffReportsOffsetForOperator(): void
    {
        $now = (new DateTime('2025-01-15 18:00:00', new DateTimeZone('UTC')))->getTimestamp();
        // Seven hours ahead of UTC on a Chicago site: no frame fits.
        $naive = $now - 7 * 3600;
        $r = stationClockFrameNaive($naive, $now, ['site_timezone' => 'America/Chicago']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('7 h off UTC', $r['reason']);
    }

    public function testFrameNaive_NoSiteTimezoneOnlyUtcFrame(): void
    {
        $now = (new DateTime('2025-01-15 18:00:00', new DateTimeZone('UTC')))->getTimestamp();
        // Local-encoded time with no site timezone cannot be framed.
        $naive = $now - 6 * 3600;
        $r = stationClockFrameNaive($naive, $now, []);
        $this->assertFalse($r['ok']);
    }

    public function testPlausibleUtc_RecentAccepted(): void
    {
        $now = time();
        $this->assertSame(true, stationClockPlausibleUtc($now - 30, $now)['ok']);
    }

    public function testPlausibleUtc_StaleRejected(): void
    {
        $now = time();
        $r = stationClockPlausibleUtc($now - 30 * 60, $now);
        $this->assertFalse($r['ok']);
        $this->assertSame('station_clock_stale', $r['reason']);
    }

    public function testPlausibleUtc_FutureRejected(): void
    {
        $now = time();
        $r = stationClockPlausibleUtc($now + BRIDGE_WEATHER_FUTURE_SKEW_SECONDS + 5, $now);
        $this->assertFalse($r['ok']);
        $this->assertSame('station_clock_future', $r['reason']);
    }

    public function testPlausibleUtc_InvalidEpochRejected(): void
    {
        $r = stationClockPlausibleUtc(0, time());
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_epoch', $r['reason']);
    }
}