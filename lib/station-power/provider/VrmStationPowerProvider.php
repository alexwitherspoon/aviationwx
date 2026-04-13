<?php
/**
 * VRM API provider: fetches stats and BatterySummary, returns canonical station-power field arrays.
 *
 * Airport page copy lives outside this module. Hour-based time-to-go strings parse to a whole-hour integer
 * string when the text includes an hours unit.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../../logger.php';

/**
 * VRM REST API (v2) integration for station power metrics.
 */
final class VrmStationPowerProvider
{
    /** @see https://vrmapi.victronenergy.com/v2 */
    private const API_BASE = 'https://vrmapi.victronenergy.com/v2';

    /** Instantaneous / recent averages (same wire format as Node-RED flows). */
    private const STATS_INTERVAL_15MIN = '15mins';

    /**
     * Hourly buckets for local-calendar-day energy integration (avg power x 1 h).
     * VRM accepts the same `interval` query style as 15-minute stats.
     */
    private const STATS_INTERVAL_HOURLY = 'hours';

    /** Minimum gap between stats GET and BatterySummary GET (Node-RED convention). */
    private const MIN_REQUEST_SPACING_SECONDS = 5;

    /** Stats query window must stay within API max for 15mins interval (31 days). */
    private const STATS_WINDOW_SECONDS = 172800; // 48 hours; enough for latest bucket and local day

    /**
     * Pc and Pb are in kW. consumption and solar_yield are in watts (VRM custom stats wire format).
     * Canonical cache always stores watts for all power fields.
     */
    private const KW_TO_W = 1000.0;

    /** Default BatterySummary meta keys for voltage and TTG in widget `records.data`. */
    private const META_VOLTAGE = 47;

    private const META_TTG = 52;

    /**
     * Volts strictly below this value satisfy the suspect-bank-voltage branch that requests /diagnostics.
     */
    private const BATTERY_BANK_VOLTS_SUSPECT_MAX = 10.0;

    /**
     * Normalize decoded stats JSON (+ optional widget JSON) into canonical station-power fields.
     *
     * @param array<string,mixed>|null $statsJson Decoded /stats response
     * @param array<string,mixed>|null $widgetJson Decoded BatterySummary widget response
     * @param int $fetchedAt Unix time when HTTP completed successfully
     * @return array<string,mixed> Canonical cache row (includes solar_total_watts aggregate and per-path Pc/Pb)
     */
    public static function normalizeFromStatsResponse(?array $statsJson, ?array $widgetJson, int $fetchedAt): array
    {
        $records = is_array($statsJson) && isset($statsJson['records']) && is_array($statsJson['records'])
            ? $statsJson['records']
            : [];

        $soc = self::lastRowPrimaryValue($records['bs'] ?? null);
        $load = self::lastRowPrimaryValue($records['consumption'] ?? null);
        $solarYieldW = self::lastRowPrimaryValue($records['solar_yield'] ?? null);
        $pcKw = self::lastRowPrimaryValue($records['Pc'] ?? null);
        $pbKw = self::lastRowPrimaryValue($records['Pb'] ?? null);

        $pc = self::solarKwToWatts($pcKw);
        $pb = self::solarKwToWatts($pbKw);
        // solar_yield is watts (same as consumption), not kW like Pc/Pb.
        $solarFromYield = $solarYieldW;
        // Portal "Solar yield" uses attribute solar_yield when present; else Pc+Pb (kW converted above).
        $solarTotal = $solarFromYield !== null
            ? $solarFromYield
            : self::sumOptionalWatts($pc, $pb);

        $tsMs = self::maxSampleTimeMs($records);

        $volts = null;
        $ttg = null;
        if (is_array($widgetJson)) {
            [$volts, $ttg] = self::extractBatterySummaryVoltageAndTtg($widgetJson);
        }

        return [
            'provider' => 'vrm',
            'fetched_at' => $fetchedAt,
            'sample_time_ms' => $tsMs,
            'battery_soc_percent' => $soc,
            'load_watts' => $load,
            'solar_total_watts' => $solarTotal,
            'solar_pc_watts' => $pc,
            'solar_pb_watts' => $pb,
            'battery_volts' => $volts,
            'time_to_go_display' => $ttg,
            'stats_interval' => self::STATS_INTERVAL_15MIN,
            'solar_daily_kwh_utc' => null,
            'load_daily_kwh_utc' => null,
            'solar_daily_wh_local' => null,
            'load_daily_wh_local' => null,
        ];
    }

    /**
     * Fetch from VRM and return canonical array, or null on hard failure (caller retains prior cache).
     *
     * @param array<string,mixed> $vrmConfig Keys: installation_id (int), access_token (string)
     * @param string|null $airportTimezone PHP timezone id for local-calendar-day energy (e.g. America/Boise); UTC if null/invalid
     * @return array<string,mixed>|null
     */
    public static function fetchCanonical(array $vrmConfig, ?string $airportTimezone = null): ?array
    {
        $id = isset($vrmConfig['installation_id']) ? (int) $vrmConfig['installation_id'] : 0;
        $token = isset($vrmConfig['access_token']) && is_string($vrmConfig['access_token'])
            ? $vrmConfig['access_token']
            : '';
        if ($id <= 0 || $token === '') {
            aviationwx_log('warning', 'station_power vrm: missing installation_id or access_token', [], 'app');
            return null;
        }

        $end = time();
        $start = $end - self::STATS_WINDOW_SECONDS;
        $statsUrl = self::buildStatsUrl($id, $start, $end, self::STATS_INTERVAL_15MIN);
        [$code, $body] = self::httpGet($statsUrl, $token);
        if ($code !== HTTP_STATUS_OK || $body === null || $body === '') {
            aviationwx_log('warning', 'station_power vrm: stats request failed', [
                'http_code' => $code,
                'installation_id' => $id,
            ], 'app');
            return null;
        }
        $statsJson = json_decode($body, true);
        if (!is_array($statsJson)) {
            aviationwx_log('warning', 'station_power vrm: stats JSON decode failed', ['installation_id' => $id], 'app');
            return null;
        }

        sleep(self::MIN_REQUEST_SPACING_SECONDS);

        $widgetUrl = self::API_BASE . '/installations/' . $id . '/widgets/BatterySummary';
        [$wCode, $wBody] = self::httpGet($widgetUrl, $token);
        $widgetJson = null;
        if ($wCode === HTTP_STATUS_OK && $wBody !== null && $wBody !== '') {
            $decoded = json_decode($wBody, true);
            if (is_array($decoded)) {
                $widgetJson = $decoded;
            } else {
                aviationwx_log('warning', 'station_power vrm: BatterySummary JSON decode failed', [
                    'installation_id' => $id,
                ], 'app');
            }
        } else {
            aviationwx_log('warning', 'station_power vrm: BatterySummary widget request failed', [
                'http_code' => $wCode,
                'installation_id' => $id,
            ], 'app');
        }

        sleep(self::MIN_REQUEST_SPACING_SECONDS);

        $overallUrl = self::buildOverallstatsUrl($id);
        [$oCode, $oBody] = self::httpGet($overallUrl, $token);
        $overallJson = null;
        if ($oCode === HTTP_STATUS_OK && $oBody !== null && $oBody !== '') {
            $decodedOverall = json_decode($oBody, true);
            $overallJson = is_array($decodedOverall) ? $decodedOverall : null;
        }

        $fetchedAt = time();
        $canonical = self::normalizeFromStatsResponse($statsJson, $widgetJson, $fetchedAt);
        $canonical = self::mergeOverallstatsIntoCanonical($canonical, $overallJson);

        $bvCanon = $canonical['battery_volts'] ?? null;
        $suspectBankVolts = is_numeric($bvCanon) && (float) $bvCanon < self::BATTERY_BANK_VOLTS_SUSPECT_MAX;
        $ttgCanon = $canonical['time_to_go_display'] ?? null;
        $ttgMissingOrUseless = $ttgCanon === null || $ttgCanon === ''
            || self::isTtgDisplayUseless($ttgCanon);
        $needsDiagnostics = $ttgMissingOrUseless
            || $bvCanon === null || !is_numeric($bvCanon)
            || $suspectBankVolts;
        if ($needsDiagnostics) {
            sleep(self::MIN_REQUEST_SPACING_SECONDS);
            $diagUrl = self::buildDiagnosticsUrl($id);
            [$dCode, $dBody] = self::httpGet($diagUrl, $token);
            $diagJson = null;
            if ($dCode === HTTP_STATUS_OK && $dBody !== null && $dBody !== '') {
                $decodedDiag = json_decode($dBody, true);
                if (is_array($decodedDiag)) {
                    $diagJson = $decodedDiag;
                } else {
                    aviationwx_log('warning', 'station_power vrm: diagnostics JSON decode failed', [
                        'installation_id' => $id,
                    ], 'app');
                }
            } else {
                aviationwx_log('warning', 'station_power vrm: diagnostics request failed', [
                    'http_code' => $dCode,
                    'installation_id' => $id,
                ], 'app');
            }
            $canonical = self::mergeDiagnosticsBatteryFields($canonical, $diagJson);
        }

        sleep(self::MIN_REQUEST_SPACING_SECONDS);

        $hourlyUrl = self::buildStatsUrl($id, $start, $end, self::STATS_INTERVAL_HOURLY);
        [$hCode, $hBody] = self::httpGet($hourlyUrl, $token);
        $hourlyJson = null;
        if ($hCode === HTTP_STATUS_OK && $hBody !== null && $hBody !== '') {
            $decodedHourly = json_decode($hBody, true);
            if (is_array($decodedHourly)) {
                $hourlyJson = $decodedHourly;
            } else {
                aviationwx_log('warning', 'station_power vrm: hourly stats JSON decode failed', [
                    'installation_id' => $id,
                ], 'app');
            }
        } else {
            aviationwx_log('warning', 'station_power vrm: hourly stats request failed', [
                'http_code' => $hCode,
                'installation_id' => $id,
            ], 'app');
        }

        $merged = self::mergeLocalDailyWhFromStats($canonical, $hourlyJson, $airportTimezone, null);
        if (isset($merged['time_to_go_display'])) {
            $ttgRaw = $merged['time_to_go_display'];
            $merged['time_to_go_display'] = is_string($ttgRaw)
                ? self::normalizeTimeToGoDisplay($ttgRaw)
                : null;
        }

        return $merged;
    }

    /**
     * Parses hour-based TTG text and returns the nearest whole hour as a decimal string (e.g. "240.00 hrs" -> "240").
     * Empty input, placeholders, and zero-duration displays yield null. Strings without an hours unit are returned unchanged.
     *
     * @param string|null $s VRM or diagnostics TTG field
     * @return string|null Whole-hour string, unchanged non-hour string, or null
     */
    public static function normalizeTimeToGoDisplay(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }
        $t = trim($s);
        if ($t === '') {
            return null;
        }
        if (self::isTtgDisplayUseless($t)) {
            return null;
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:h|hr|hrs|hour|hours)\b/i', $t, $m)) {
            $hours = (int) round((float) $m[1]);
            if ($hours < 0) {
                $hours = 0;
            }

            return (string) $hours;
        }

        return $s;
    }

    /**
     * Merge UTC calendar-day energy totals from /overallstats (stored as kWh, VRM "today" = UTC day).
     *
     * @param array<string,mixed> $canonical Row from normalizeFromStatsResponse
     * @param array<string,mixed>|null $overallJson Decoded overallstats response
     * @return array<string,mixed>
     */
    public static function mergeOverallstatsIntoCanonical(array $canonical, ?array $overallJson): array
    {
        [$solarKwh, $loadKwh] = self::extractTodayKwhFromOverallstats($overallJson);
        $canonical['solar_daily_kwh_utc'] = $solarKwh;
        $canonical['load_daily_kwh_utc'] = $loadKwh;
        return $canonical;
    }

    /**
     * Merges `battery_volts` and `time_to_go_display` from decoded /diagnostics into the canonical row.
     * Voltage is max(widget, diagnostics). TTG from diagnostics replaces null, empty, or placeholder widget values;
     * placeholder TTG clears to null.
     *
     * @param array<string,mixed> $canonical Row after normalize + overallstats
     * @param array<string,mixed>|null $diagJson Decoded diagnostics response
     * @return array<string,mixed>
     */
    public static function mergeDiagnosticsBatteryFields(array $canonical, ?array $diagJson): array
    {
        if ($diagJson === null) {
            return $canonical;
        }
        [$v, $t] = self::extractDiagnosticsVoltageAndTtg($diagJson);
        if ($v !== null) {
            $cur = $canonical['battery_volts'] ?? null;
            if ($cur === null || !is_numeric($cur)) {
                $canonical['battery_volts'] = $v;
            } else {
                $canonical['battery_volts'] = max((float) $cur, $v);
            }
        }
        $existingTtg = $canonical['time_to_go_display'] ?? null;
        if ($t !== null && $t !== '' && !self::isTtgDisplayUseless($t)) {
            if ($existingTtg === null || $existingTtg === '' || self::isTtgDisplayUseless($existingTtg)) {
                $canonical['time_to_go_display'] = $t;
            }
        }
        $finalTtg = $canonical['time_to_go_display'] ?? null;
        if (self::isTtgDisplayUseless($finalTtg)) {
            $canonical['time_to_go_display'] = null;
        }
        return $canonical;
    }

    /**
     * Integrate hourly average power from /stats for local midnight-to-now (airport timezone).
     *
     * Uses one request with `interval=hours` so each bucket is one hour (avg W x 1 h = Wh).
     * Without decoded hourly stats, `solar_daily_wh_local` and `load_daily_wh_local` are not set; UTC kWh fields on the row are unchanged.
     *
     * @param array<string,mixed> $canonical Cache row
     * @param array<string,mixed>|null $hourlyStatsJson Decoded /stats response with hourly interval
     * @param string|null $airportTimezone PHP timezone id
     * @param int|null $clockOverride Unix timestamp for end of window (tests only; null = now)
     * @return array<string,mixed>
     */
    public static function mergeLocalDailyWhFromStats(
        array $canonical,
        ?array $hourlyStatsJson,
        ?string $airportTimezone,
        ?int $clockOverride = null
    ): array {
        if ($hourlyStatsJson === null) {
            return $canonical;
        }

        $tz = self::resolveAirportTimezone($airportTimezone);
        $endTs = $clockOverride ?? time();
        [$startMs, $endMs] = self::localDayWindowMs($tz, $endTs);

        $records = isset($hourlyStatsJson['records']) && is_array($hourlyStatsJson['records'])
            ? $hourlyStatsJson['records']
            : [];
        $bucketH = 1.0;

        $solarFromYield = self::integrateAveragePowerWhInWindow(
            $records['solar_yield'] ?? null,
            $startMs,
            $endMs,
            $bucketH,
            false
        );
        if ($solarFromYield !== null) {
            $canonical['solar_daily_wh_local'] = $solarFromYield;
        } else {
            $pcWh = self::integrateAveragePowerWhInWindow(
                $records['Pc'] ?? null,
                $startMs,
                $endMs,
                $bucketH,
                true
            );
            $pbWh = self::integrateAveragePowerWhInWindow(
                $records['Pb'] ?? null,
                $startMs,
                $endMs,
                $bucketH,
                true
            );
            $canonical['solar_daily_wh_local'] = self::sumOptionalWatts($pcWh, $pbWh);
        }

        $canonical['load_daily_wh_local'] = self::integrateAveragePowerWhInWindow(
            $records['consumption'] ?? null,
            $startMs,
            $endMs,
            $bucketH,
            false
        );

        return $canonical;
    }

    /**
     * @param string|null $airportTimezone PHP timezone identifier
     */
    private static function resolveAirportTimezone(?string $airportTimezone): \DateTimeZone
    {
        if ($airportTimezone !== null && $airportTimezone !== '') {
            try {
                return new \DateTimeZone($airportTimezone);
            } catch (\Exception $e) {
                aviationwx_log('warning', 'station_power vrm: invalid airport timezone, using UTC', [
                    'timezone' => $airportTimezone,
                ], 'app');
            }
        }
        return new \DateTimeZone('UTC');
    }

    /**
     * Local calendar day window [startMs, endMs] for the instant $endTs in $tz.
     *
     * @return array{0: int, 1: int}
     */
    private static function localDayWindowMs(\DateTimeZone $tz, int $endTs): array
    {
        $now = new \DateTime('@' . $endTs);
        $now->setTimezone($tz);
        $start = (clone $now)->setTime(0, 0, 0);
        $startMs = $start->getTimestamp() * 1000;
        $endMs = $endTs * 1000;
        return [$startMs, $endMs];
    }

    /**
     * Sum over in-window buckets: average power x bucket duration (Wh when bucketHours is in hours).
     *
     * @param array<int,mixed>|null $rows VRM stats series rows
     * @param float $bucketHours Duration of one bucket in hours (hourly integration uses 1.0)
     * @param bool $valuesAreKw When true, treat series values as kW (Pc, Pb) before integrating
     */
    private static function integrateAveragePowerWhInWindow(
        $rows,
        int $windowStartMs,
        int $windowEndMs,
        float $bucketHours,
        bool $valuesAreKw = false
    ): ?float {
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        $sum = 0.0;
        $any = false;
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) < 2) {
                continue;
            }
            $ts = isset($row[0]) && is_numeric($row[0]) ? (int) $row[0] : 0;
            if ($ts < $windowStartMs || $ts > $windowEndMs) {
                continue;
            }
            $idx = count($row) >= 4 ? 3 : 1;
            $p = $row[$idx] ?? null;
            if (!is_numeric($p)) {
                continue;
            }
            $pW = (float) $p;
            if ($valuesAreKw) {
                $pW *= self::KW_TO_W;
            }
            $sum += $pW * $bucketHours;
            $any = true;
        }
        return $any ? $sum : null;
    }

    /**
     * Read today.totals solar and consumption cumulative energy (kWh) when present.
     *
     * @param array<string,mixed>|null $overallJson
     * @return array{0: float|null, 1: float|null} solar kWh, load kWh
     */
    private static function extractTodayKwhFromOverallstats(?array $overallJson): array
    {
        if ($overallJson === null) {
            return [null, null];
        }
        $totals = null;
        if (isset($overallJson['today']['totals']) && is_array($overallJson['today']['totals'])) {
            $totals = $overallJson['today']['totals'];
        } elseif (isset($overallJson['records']['today']['totals']) && is_array($overallJson['records']['today']['totals'])) {
            $totals = $overallJson['records']['today']['totals'];
        }
        if ($totals === null) {
            return [null, null];
        }

        $solarKwh = null;
        if (isset($totals['solar_yield']) && is_numeric($totals['solar_yield'])) {
            $solarKwh = (float) $totals['solar_yield'];
        } elseif (
            (isset($totals['Pc']) && is_numeric($totals['Pc']))
            || (isset($totals['Pb']) && is_numeric($totals['Pb']))
        ) {
            $solarKwh = (float) ($totals['Pc'] ?? 0.0) + (float) ($totals['Pb'] ?? 0.0);
        }

        $loadKwh = null;
        if (isset($totals['consumption']) && is_numeric($totals['consumption'])) {
            $loadKwh = (float) $totals['consumption'];
        }

        return [$solarKwh, $loadKwh];
    }

    /**
     * Primary value from the last bucket row. VRM stats rows use `[timestamp_ms, min, max, avg]`; index 3 is
     * the primary value when four or more elements exist, otherwise index 1.
     *
     * @param array<int,mixed>|null $rows Series rows from VRM stats
     */
    private static function lastRowPrimaryValue($rows): ?float
    {
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        $last = $rows[count($rows) - 1];
        if (!is_array($last) || count($last) < 2) {
            return null;
        }
        $idx = count($last) >= 4 ? 3 : 1;
        $v = $last[$idx] ?? null;
        if (!is_numeric($v)) {
            return null;
        }
        return (float) $v;
    }

    /**
     * Convert solar power attributes (Pc, Pb) from kW to watts.
     *
     * @param float|null $kw Value from VRM stats for Pc or Pb (kW)
     * @return float|null Watts, or null when unknown
     */
    private static function solarKwToWatts(?float $kw): ?float
    {
        if ($kw === null) {
            return null;
        }
        return $kw * self::KW_TO_W;
    }

    /**
     * Sum of Pc and Pb in watts (or Wh after integration). Null inputs count as zero; the result is null only when both inputs are null.
     *
     * @param float|null $pcW Solar to loads (W), or integrated Pc contribution (Wh)
     * @param float|null $pbW Solar to battery (W), or integrated Pb contribution (Wh)
     * @return float|null Sum, or null when both inputs are unknown
     */
    private static function sumOptionalWatts(?float $pcW, ?float $pbW): ?float
    {
        if ($pcW === null && $pbW === null) {
            return null;
        }
        return (float) ($pcW ?? 0.0) + (float) ($pbW ?? 0.0);
    }

    /**
     * @param array<string,mixed> $records records.* from stats
     */
    private static function maxSampleTimeMs(array $records): ?int
    {
        $max = null;
        foreach (['bs', 'consumption', 'solar_yield', 'Pc', 'Pb'] as $key) {
            if (!isset($records[$key]) || !is_array($records[$key])) {
                continue;
            }
            $rows = $records[$key];
            if ($rows === []) {
                continue;
            }
            $last = $rows[count($rows) - 1];
            if (!is_array($last) || !isset($last[0]) || !is_numeric($last[0])) {
                continue;
            }
            $t = (int) $last[0];
            if ($max === null || $t > $max) {
                $max = $t;
            }
        }
        return $max;
    }

    /**
     * True for empty strings and zero-duration TTG displays (hours, minutes, or seconds as zero).
     *
     * @param string|null $s Widget or diagnostics TTG text
     */
    private static function isTtgDisplayUseless(?string $s): bool
    {
        if ($s === null) {
            return true;
        }
        $t = trim($s);
        if ($t === '') {
            return true;
        }
        if ($t === '0' || $t === '0.0') {
            return true;
        }
        if (preg_match('/^\s*0(?:\.0+)?\s*(?:h|hr|hrs|hour|hours)\b/i', $t)) {
            return true;
        }
        if (preg_match('/^\s*0(?:\.0+)?\s*(?:min|m|s|sec|secs|second|seconds)\b/i', $t)) {
            return true;
        }

        return false;
    }

    /**
     * First TTG candidate that is not a placeholder, or null if every candidate is a placeholder.
     *
     * @param array<int,string> $candidates
     */
    private static function pickFirstUsefulTtgDisplay(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (!self::isTtgDisplayUseless($c)) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Voltage (V) and TTG string from BatterySummary `records.data` (meta keys 47/52 and TTG-shaped rows).
     *
     * @param array<string,mixed> $widgetJson Decoded widget response
     * @return array{0: float|null, 1: string|null}
     */
    private static function extractBatterySummaryVoltageAndTtg(array $widgetJson): array
    {
        $data = null;
        if (isset($widgetJson['records']) && is_array($widgetJson['records'])) {
            $rec = $widgetJson['records'];
            if (isset($rec['data']) && is_array($rec['data'])) {
                $data = $rec['data'];
            }
        }
        if ($data === null || $data === []) {
            return [null, null];
        }

        $volts = self::maxBankVoltsFromWidgetDataMap($data);
        $ttg = self::widgetFormattedValue($data, (string) self::META_TTG);
        if (self::isTtgDisplayUseless($ttg)) {
            $ttg = null;
        }

        if ($ttg === null) {
            foreach ($data as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $desc = strtolower((string) ($entry['description'] ?? ''));
                $codeName = strtolower((string) ($entry['codeName'] ?? ''));
                if (
                    str_contains($desc, 'ttg')
                    || str_contains($desc, 'time to go')
                    || str_contains($codeName, 'ttg')
                ) {
                    $cand = self::widgetEntryDisplayString($entry);
                    if ($cand !== null && !self::isTtgDisplayUseless($cand)) {
                        $ttg = $cand;
                        break;
                    }
                }
            }
        }

        if (self::isTtgDisplayUseless($ttg)) {
            $ttg = null;
        }

        return [$volts, $ttg];
    }

    /**
     * Maximum voltage among candidate widget rows (multiple voltage metas can appear in one BatterySummary).
     *
     * @param array<string,mixed> $data records.data map
     */
    private static function maxBankVoltsFromWidgetDataMap(array $data): ?float
    {
        $candidates = [];
        $metaV = (string) self::META_VOLTAGE;
        foreach ($data as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $desc = strtolower((string) ($entry['description'] ?? ''));
            $codeName = strtolower((string) ($entry['codeName'] ?? ''));
            if (
                str_contains($desc, 'time to go')
                || str_contains($desc, 'ttg')
                || str_contains($codeName, 'ttg')
            ) {
                continue;
            }
            $unit = strtolower((string) ($entry['unit'] ?? ''));
            $hay = $desc . ' ' . $codeName;
            $fvRaw = $entry['formattedValue'] ?? null;
            $fvStr = is_string($fvRaw) ? $fvRaw : '';
            $looksLikeVolts = $fvStr !== '' && preg_match('/\s*V\b/i', $fvStr);
            $isMetaVoltageKey = (string) $key === $metaV;
            if ($unit !== 'v' && !str_contains($hay, 'voltage') && !$looksLikeVolts && !$isMetaVoltageKey) {
                continue;
            }
            $v = $entry['value'] ?? null;
            if (is_numeric($v)) {
                $candidates[] = (float) $v;
                continue;
            }
            if ($fvStr !== '' && preg_match('/([\d.]+)\s*V\b/i', $fvStr, $m)) {
                $candidates[] = (float) $m[1];
            }
        }
        if ($candidates === []) {
            return null;
        }
        return max($candidates);
    }

    /**
     * Battery voltage and time-to-go from GET /installations/{id}/diagnostics (attribute `code`, e.g. bv).
     *
     * @param array<string,mixed> $diagJson Decoded diagnostics response
     * @return array{0: float|null, 1: string|null}
     */
    private static function extractDiagnosticsVoltageAndTtg(array $diagJson): array
    {
        $rows = self::diagnosticsRowsList($diagJson);
        $vCandidates = [];
        $ttgCandidates = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtolower((string) ($row['code'] ?? ''));
            $name = strtolower((string) ($row['name'] ?? $row['description'] ?? ''));

            if ($code === 'bv' || str_contains($name, 'battery voltage')) {
                $v = self::diagnosticsNumericFromRow($row);
                if ($v !== null) {
                    $vCandidates[] = $v;
                }
            }
            if (
                $code === 'ttg'
                || str_contains($code, 'ttg')
                || str_contains($name, 'time to go')
                || str_contains($name, 'time-to-go')
            ) {
                $parsed = self::diagnosticsDisplayFromRow($row);
                if ($parsed !== null && $parsed !== '') {
                    $ttgCandidates[] = $parsed;
                }
            }
        }
        $volts = $vCandidates === [] ? null : max($vCandidates);
        $ttg = self::pickFirstUsefulTtgDisplay($ttgCandidates);

        return [$volts, $ttg];
    }

    /**
     * Flatten diagnostics `records` into rows (list or `records.data` map/list).
     *
     * @param array<string,mixed> $diagJson
     * @return array<int,array<string,mixed>>
     */
    private static function diagnosticsRowsList(array $diagJson): array
    {
        if (!isset($diagJson['records']) || !is_array($diagJson['records'])) {
            return [];
        }
        $rec = $diagJson['records'];
        $data = isset($rec['data']) && is_array($rec['data']) ? $rec['data'] : $rec;
        $keys = array_keys($data);
        $isList = $keys === range(0, count($data) - 1);
        if ($isList) {
            $out = [];
            foreach ($data as $item) {
                if (is_array($item)) {
                    $out[] = $item;
                }
            }
            return $out;
        }
        $out = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function diagnosticsNumericFromRow(array $row): ?float
    {
        foreach (['rawValue', 'value'] as $k) {
            if (isset($row[$k]) && is_numeric($row[$k])) {
                return (float) $row[$k];
            }
        }
        $fv = $row['formattedValue'] ?? null;
        if (is_string($fv) && $fv !== '' && preg_match('/([\d.]+)/', $fv, $m)) {
            return (float) $m[1];
        }
        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function diagnosticsDisplayFromRow(array $row): ?string
    {
        $fv = $row['formattedValue'] ?? null;
        if (is_string($fv) && $fv !== '') {
            return $fv;
        }
        $n = self::diagnosticsNumericFromRow($row);
        return $n !== null ? (string) $n : null;
    }

    /**
     * @param array<string,mixed> $entry Single widget data row
     */
    private static function widgetEntryDisplayString(array $entry): ?string
    {
        $fv = $entry['formattedValue'] ?? null;
        if (is_string($fv) && $fv !== '') {
            return $fv;
        }
        $v = $entry['value'] ?? null;
        if (is_numeric($v)) {
            return (string) $v;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $data Widget records.data map
     */
    private static function widgetNumericValue(array $data, string $key): ?float
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return null;
        }
        $v = $data[$key]['value'] ?? null;
        if (!is_numeric($v)) {
            return null;
        }
        return (float) $v;
    }

    /**
     * @param array<string,mixed> $data Widget records.data map
     */
    private static function widgetFormattedValue(array $data, string $key): ?string
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return null;
        }
        $fv = $data[$key]['formattedValue'] ?? null;
        if (is_string($fv) && $fv !== '') {
            return $fv;
        }
        $v = $data[$key]['value'] ?? null;
        if (is_numeric($v)) {
            return (string) $v;
        }
        return null;
    }

    private static function buildStatsUrl(int $installationId, int $start, int $end, string $interval): string
    {
        $base = self::API_BASE . '/installations/' . $installationId . '/stats';
        $parts = [
            'type' => 'custom',
            'interval' => $interval,
            'start' => (string) $start,
            'end' => (string) $end,
        ];
        $q = http_build_query($parts, '', '&', PHP_QUERY_RFC3986);
        foreach (['bs', 'consumption', 'solar_yield', 'Pc', 'Pb'] as $code) {
            $q .= '&attributeCodes%5B%5D=' . rawurlencode($code);
        }
        return $base . '?' . $q;
    }

    /**
     * Daily aggregate energy totals (kWh) for the UTC calendar day.
     */
    private static function buildOverallstatsUrl(int $installationId): string
    {
        $base = self::API_BASE . '/installations/' . $installationId . '/overallstats';
        $q = http_build_query(['type' => 'custom'], '', '&', PHP_QUERY_RFC3986);
        foreach (['solar_yield', 'consumption', 'Pc', 'Pb'] as $code) {
            $q .= '&attributeCodes%5B%5D=' . rawurlencode($code);
        }
        return $base . '?' . $q;
    }

    /**
     * Installation diagnostics (attribute rows; battery voltage `bv`, time-to-go `ttg` when present).
     */
    private static function buildDiagnosticsUrl(int $installationId): string
    {
        $base = self::API_BASE . '/installations/' . $installationId . '/diagnostics';
        return $base . '?' . http_build_query(['count' => '500'], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{0:int,1:string|null} HTTP status code and body
     */
    private static function httpGet(string $url, string $token): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => CURL_TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-Authorization: Token ' . $token,
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $body = null;
        }
        curl_close($ch);
        return [$code, $body];
    }
}
