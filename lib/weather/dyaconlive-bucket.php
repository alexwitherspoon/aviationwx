<?php
/**
 * DyaconLive 10-minute bucket schedule helpers.
 *
 * KAOC probing confirmed reports align to wall-clock :00/:10/... boundaries in station timezone.
 */

require_once __DIR__ . '/../constants.php';

/**
 * Floor a Unix timestamp to the start of its 10-minute bucket in the given timezone.
 *
 * @param int $nowUnix Current time (UTC unix)
 * @param string $timezone IANA timezone (e.g. America/Boise)
 * @param int $intervalMinutes Report interval (default 10)
 * @return int Bucket start as Unix timestamp (UTC)
 */
function dyaconliveFloorToBucketUnix(
    int $nowUnix,
    string $timezone,
    int $intervalMinutes = DYACONLIVE_REPORT_INTERVAL_MINUTES
): int {
    $intervalMinutes = max(1, $intervalMinutes);
    try {
        $dt = (new DateTimeImmutable('@' . $nowUnix))->setTimezone(new DateTimeZone($timezone));
    } catch (Exception $e) {
        $dt = new DateTimeImmutable('@' . $nowUnix);
    }

    $minute = (int) $dt->format('i');
    $flooredMinute = (int) (floor($minute / $intervalMinutes) * $intervalMinutes);
    $bucketLocal = $dt->setTime((int) $dt->format('H'), $flooredMinute, 0);

    return $bucketLocal->getTimestamp();
}

/**
 * Latest bucket we expect DyaconLive to have published by now (grace after boundary).
 *
 * @param int $nowUnix Current time (UTC unix)
 * @param string $timezone IANA timezone
 * @param int $intervalMinutes Report interval minutes
 * @param int $graceSeconds Wait after bucket boundary before expecting that bucket
 * @return int Expected latest complete bucket Unix timestamp
 */
function dyaconliveExpectedLatestBucketUnix(
    int $nowUnix,
    string $timezone,
    int $intervalMinutes = DYACONLIVE_REPORT_INTERVAL_MINUTES,
    int $graceSeconds = DYACONLIVE_BUCKET_GRACE_SECONDS
): int {
    $intervalSeconds = max(60, $intervalMinutes * 60);
    $currentBoundary = dyaconliveFloorToBucketUnix($nowUnix, $timezone, $intervalMinutes);
    if ($nowUnix < $currentBoundary + $graceSeconds) {
        return $currentBoundary - $intervalSeconds;
    }

    return $currentBoundary;
}

/**
 * Whether upstream HTTP can be skipped (local state already has expected bucket).
 *
 * @param int|null $lastBucketUnix Last ingested bucket from state file
 * @param int $nowUnix Current time
 * @param string $timezone Station timezone
 * @return bool True when cached state is current enough
 */
function dyaconliveShouldSkipUpstreamFetch(?int $lastBucketUnix, int $nowUnix, string $timezone): bool
{
    if ($lastBucketUnix === null || $lastBucketUnix <= 0) {
        return false;
    }

    $expected = dyaconliveExpectedLatestBucketUnix($nowUnix, $timezone);

    return $lastBucketUnix >= $expected;
}

/**
 * Parse Dyacon API datetime string (no offset) in station timezone to Unix UTC.
 *
 * @param string $iso Local datetime from API (e.g. 2026-07-07T09:40:00)
 * @param string $timezone IANA timezone
 * @return int|null Unix timestamp or null on parse failure
 */
function dyaconliveParseBucketIsoToUnix(string $iso, string $timezone): ?int
{
    $iso = trim($iso);
    if ($iso === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($iso, new DateTimeZone($timezone));
    } catch (Exception $e) {
        return null;
    }

    return $dt->getTimestamp();
}
