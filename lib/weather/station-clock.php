<?php
/**
 * Station clock framing and UTC normalization.
 *
 * Weather adapters receive observed_at from station clocks a few ways:
 *
 * - naive wall clock (a console clock with no timezone; e.g. the Vantage
 *   GETTIME response), which may be UTC or the site's local time;
 * - unix epoch (UTC by convention; e.g. WeatherFlow device epochs, WeatherLink
 *   Live raw.ts), which only needs a recency sanity check;
 * - bridge arrival time (the MQTT push exception), which is bridge UTC and
 *   needs no clock check at all.
 *
 * For naive clocks we identify the framing by alignment: if the naive value
 * sits within tolerance of now-in-UTC it is UTC; if it sits within tolerance
 * of now-in-the-site-timezone it is local. Identified local times are
 * normalized to UTC via the timezone at that instant (DST-aware, so the
 * offset is not a fixed integer). Times that match no frame are unidentifiable
 * and are rejected rather than guessed at. Epochs get a recency guard: a
 * device clock older than the tolerance is stale and rejected.
 *
 * The bridge replays failed POSTs for up to ten minutes, so the recency
 * tolerance must exceed the replay window; it also absorbs poll cadence.
 */

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../bridge/weather-store.php';

/** Default recency tolerance: replay window (10 min) plus poll cadence slack. */
const STATION_CLOCK_RECENCY_SLACK_SEC = 15 * 60;

/**
 * Frame a naive wall-clock value as UTC or site-local, normalizing to UTC.
 *
 * @param int $naive Naive wall-clock value, read as if UTC
 * @param int $now Processing time (unix)
 * @param array $opts tolerance_sec (default STATION_CLOCK_RECENCY_SLACK_SEC),
 *        site_timezone (string|null; null disables the local frame)
 * @return array{ok: bool, epoch_utc: int, framing: string|null, reason: string|null}
 */
function stationClockFrameNaive(int $naive, int $now, array $opts = []): array
{
    $tolerance = (int) ($opts['tolerance_sec'] ?? STATION_CLOCK_RECENCY_SLACK_SEC);
    $futureLimit = $now + BRIDGE_WEATHER_FUTURE_SKEW_SECONDS;
    $siteTz = isset($opts['site_timezone']) && is_string($opts['site_timezone']) && $opts['site_timezone'] !== ''
        ? $opts['site_timezone']
        : null;

    $ageUtc = $now - $naive;
    if ($ageUtc >= -$tolerance && $ageUtc <= $tolerance) {
        if ($naive > $futureLimit) {
            return ['ok' => false, 'epoch_utc' => 0, 'framing' => null, 'reason' => 'station_clock_future'];
        }
        return ['ok' => true, 'epoch_utc' => $naive, 'framing' => 'utc', 'reason' => null];
    }

    if ($siteTz !== null) {
        try {
            $tz = new DateTimeZone($siteTz);
        } catch (\Exception $e) {
            aviationwx_log('warning', 'station clock', ['reason' => 'invalid_site_timezone', 'tz' => $siteTz], 'app');
            return __stationClockRejectNaive($naive, $now, $ageUtc);
        }
        // Offset at the naive value (the tz database folds the DST hour).
        $offsetSec = $tz->getOffset(new DateTime('@' . $naive));
        $epochUtc = $naive - $offsetSec;
        if ($epochUtc <= $futureLimit) {
            $ageLocal = $now - $epochUtc;
            if ($ageLocal >= -$tolerance && $ageLocal <= $tolerance) {
                return ['ok' => true, 'epoch_utc' => $epochUtc, 'framing' => 'local', 'reason' => null];
            }
        }
    }

    if ($ageUtc < -BRIDGE_WEATHER_FUTURE_SKEW_SECONDS) {
        return ['ok' => false, 'epoch_utc' => 0, 'framing' => null, 'reason' => 'station_clock_future'];
    }
    return __stationClockRejectNaive($naive, $now, $ageUtc);
}

/**
 * Recency guard for unix-UTC epochs (device clocks).
 *
 * @param int $epoch Device epoch (UTC)
 * @param int $now Processing time (unix)
 * @param int $toleranceSec Defaults to STATION_CLOCK_RECENCY_SLACK_SEC
 * @return array{ok: bool, reason: string|null}
 */
function stationClockPlausibleUtc(int $epoch, int $now, int $toleranceSec = STATION_CLOCK_RECENCY_SLACK_SEC): array
{
    if ($epoch <= 0) {
        return ['ok' => false, 'reason' => 'invalid_epoch'];
    }
    $age = $now - $epoch;
    if ($age > $toleranceSec) {
        return ['ok' => false, 'reason' => 'station_clock_stale'];
    }
    if ($age < -BRIDGE_WEATHER_FUTURE_SKEW_SECONDS) {
        return ['ok' => false, 'reason' => 'station_clock_future'];
    }
    return ['ok' => true, 'reason' => null];
}

/**
 * Shared fail-closed result for an unidentifiable naive clock.
 *
 * @param int $naive Naive value
 * @param int $now Processing time
 * @param int $ageUtc Apparent age when read as UTC
 * @return array{ok: false, epoch_utc: int, framing: null, reason: string}
 */
function __stationClockRejectNaive(int $naive, int $now, int $ageUtc): array
{
    $hoursOff = $ageUtc / 3600.0;
    $rounded = (int) round($hoursOff);
    if (abs($hoursOff - $rounded) < 0.25) {
        $reason = "appears $rounded h off UTC, matches no site frame";
    } else {
        $reason = 'matches neither UTC nor the site timezone';
    }
    return ['ok' => false, 'epoch_utc' => 0, 'framing' => null, 'reason' => $reason];
}