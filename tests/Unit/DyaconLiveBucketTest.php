<?php
/**
 * DyaconLive 10-minute bucket schedule tests (safety-critical staleness path).
 *
 * KAOC probing confirmed clock-aligned :00/:10/... reports in station timezone.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/dyaconlive-bucket.php';

class DyaconLiveBucketTest extends TestCase
{
    private const TZ = 'America/Boise';

    /**
     * @param string $localDateTime Y-m-d H:i:s in America/Boise
     */
    private function localUnix(string $localDateTime): int
    {
        $dt = new DateTimeImmutable($localDateTime, new DateTimeZone(self::TZ));
        return $dt->getTimestamp();
    }

    public function testFloorToBucketUnix_AlignsToTenMinuteBoundary(): void
    {
        $now = $this->localUnix('2026-07-07 09:47:15');
        $bucket = dyaconliveFloorToBucketUnix($now, self::TZ);
        $dt = (new DateTimeImmutable('@' . $bucket))->setTimezone(new DateTimeZone(self::TZ));
        $this->assertSame('2026-07-07 09:40:00', $dt->format('Y-m-d H:i:s'));
    }

    public function testExpectedLatestBucket_At0947_Returns0940(): void
    {
        $now = $this->localUnix('2026-07-07 09:47:00');
        $expected = dyaconliveExpectedLatestBucketUnix($now, self::TZ);
        $dt = (new DateTimeImmutable('@' . $expected))->setTimezone(new DateTimeZone(self::TZ));
        $this->assertSame('2026-07-07 09:40:00', $dt->format('Y-m-d H:i:s'));
    }

    public function testExpectedLatestBucket_At0941BeforeGrace_Returns0930(): void
    {
        $now = $this->localUnix('2026-07-07 09:41:00');
        $expected = dyaconliveExpectedLatestBucketUnix($now, self::TZ, 10, 90);
        $dt = (new DateTimeImmutable('@' . $expected))->setTimezone(new DateTimeZone(self::TZ));
        $this->assertSame('2026-07-07 09:30:00', $dt->format('Y-m-d H:i:s'));
    }

    public function testExpectedLatestBucket_At094135AfterGrace_Returns0940(): void
    {
        $now = $this->localUnix('2026-07-07 09:41:35');
        $expected = dyaconliveExpectedLatestBucketUnix($now, self::TZ, 10, 90);
        $dt = (new DateTimeImmutable('@' . $expected))->setTimezone(new DateTimeZone(self::TZ));
        $this->assertSame('2026-07-07 09:40:00', $dt->format('Y-m-d H:i:s'));
    }

    public function testShouldSkipUpstream_NoState_NeverSkips(): void
    {
        $now = $this->localUnix('2026-07-07 09:47:00');
        $this->assertFalse(dyaconliveShouldSkipUpstreamFetch(null, $now, self::TZ));
    }

    public function testShouldSkipUpstream_HasCurrentBucket_Skips(): void
    {
        $now = $this->localUnix('2026-07-07 09:47:00');
        $last = $this->localUnix('2026-07-07 09:40:00');
        $this->assertTrue(dyaconliveShouldSkipUpstreamFetch($last, $now, self::TZ));
    }

    public function testShouldSkipUpstream_BehindExpected_DoesNotSkip(): void
    {
        $now = $this->localUnix('2026-07-07 09:47:00');
        $last = $this->localUnix('2026-07-07 09:30:00');
        $this->assertFalse(dyaconliveShouldSkipUpstreamFetch($last, $now, self::TZ));
    }

    public function testParseBucketIsoToUnix_Invalid_ReturnsNull(): void
    {
        $this->assertNull(dyaconliveParseBucketIsoToUnix('not-a-date', self::TZ));
    }

    public function testParseBucketIsoToUnix_Valid_ReturnsUnix(): void
    {
        $unix = dyaconliveParseBucketIsoToUnix('2026-07-07T09:40:00', self::TZ);
        $this->assertIsInt($unix);
        $dt = (new DateTimeImmutable('@' . $unix))->setTimezone(new DateTimeZone(self::TZ));
        $this->assertSame('2026-07-07 09:40:00', $dt->format('Y-m-d H:i:s'));
    }
}
