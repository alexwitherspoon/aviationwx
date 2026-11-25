<?php
/**
 * Shared station clock toolkit for weather adapters.
 *
 * Any adapter with a station clock may use this; adapters whose protocol
 * defines the time source deterministically (a unix epoch per spec) prefer
 * the deterministic stationClockPlausibleUtc() recency guard over framing,
 * because the spec already fixes the frame.
 *
 * The three clock shapes:
 *
 * - naive wall clock (a console clock with no timezone; e.g. the Vantage
 *   GETTIME response), which may be UTC or the site's local time; frame it
 *   with stationClockFrameNaive();
 * - unix epoch (UTC by spec; e.g. WeatherFlow device epochs, WeatherLink
 *   Live raw.ts); confirm recency with stationClockPlausibleUtc();
 * - bridge arrival time (the MQTT push exception), which is bridge UTC and
 *   needs no clock check at all.
 *
 * For naive clocks we identify the framing by alignment: if the naive value
 * sits within tolerance of now-in-UTC it is UTC; if it sits within tolerance
 * of now-in-the-site-timezone it is local. Identified local times are
 * normalized to UTC by trying each offset the zone has used in the year and
 * keeping the candidate that is plausible against the processing instant
 * (DST-aware, transition days and repeated fall-back hours included). Times
 * that match no frame are unidentifiable and are rejected rather than
 * guessed at. Epochs get a recency guard: a device clock older than the
 * tolerance is stale and rejected.
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
        // The naive value is a wall clock; the offset in effect at that wall
        // time is standard or daylight and only the tz database knows which.
        // Looking the offset up at the naive value read as UTC picks the wrong
        // side of a transition: on a spring-forward morning the console runs
        // the new offset while the naive value still sits at the old one. So
        // sample the zone's distinct offsets across the year, derive one
        // candidate instant per offset (utc = wall - offset), and accept the
        // candidate that is plausible against the processing instant. On a
        // fall-back day the wall time occurs twice; the plausible candidate
        // is the one within tolerance, which selects the right occurrence.
        $distinctOffsets = [];
        foreach ([0, 30, 91, 182, 273, 364] as $daysAhead) {
            $distinctOffsets[$tz->getOffset(new DateTime('@' . ($naive + $daysAhead * 86400)))] = true;
        }
        $accepted = null;
        foreach (array_keys($distinctOffsets) as $offsetSec) {
            $epochUtc = $naive - $offsetSec;
            if ($epochUtc > $futureLimit) {
                continue;
            }
            $ageLocal = $now - $epochUtc;
            if ($ageLocal >= -$tolerance && $ageLocal <= $tolerance) {
                if ($accepted === null || $ageLocal < $now - $accepted) {
                    $accepted = $epochUtc;
                }
            }
        }
        if ($accepted !== null) {
            return ['ok' => true, 'epoch_utc' => $accepted, 'framing' => 'local', 'reason' => null];
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