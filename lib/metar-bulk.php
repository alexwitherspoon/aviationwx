<?php
/**
 * METAR bulk ingest and per-station JSON slices for `parseMETARResponse()`. Entry: `metarBulkRefreshRun()`.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/metar-bulk-csv-schema.php';

// AWC national METAR CSV gzip; prefer scheduler-driven refresh over ad-hoc pulls.
const METAR_BULK_CACHE_GZ_URL = 'https://aviationweather.gov/data/cache/metars.cache.csv.gz';

/**
 * Uppercase ICAO safe for a filename segment, or null if invalid (path traversal guard).
 */
function metarBulkSanitizeIcaoForFilename(string $icao): ?string {
    $u = strtoupper(trim($icao));
    if ($u === '' || !preg_match('/^[A-Z0-9]{3,5}$/', $u)) {
        return null;
    }

    return $u;
}

/**
 * ICAOs to retain from bulk CSV: each metar `station_id` plus `nearby_stations`, from config.
 *
 * @param array<string, mixed>|null $config
 * @return array<string, true>
 */
function metarBulkCollectConfiguredStationIds(?array $config = null): array {
    require_once __DIR__ . '/config.php';
    if ($config === null) {
        $config = loadConfig(false);
    }
    $out = [];
    if (!is_array($config) || !isset($config['airports']) || !is_array($config['airports'])) {
        return $out;
    }
    foreach ($config['airports'] as $airport) {
        if (!is_array($airport) || !isAirportEnabled($airport) || empty($airport['weather_sources']) || !is_array($airport['weather_sources'])) {
            continue;
        }
        foreach ($airport['weather_sources'] as $source) {
            if (!is_array($source) || ($source['type'] ?? '') !== 'metar') {
                continue;
            }
            $sid = $source['station_id'] ?? '';
            if (is_string($sid) && $sid !== '') {
                $k = metarBulkSanitizeIcaoForFilename($sid);
                if ($k !== null) {
                    $out[$k] = true;
                }
            }
            $near = $source['nearby_stations'] ?? [];
            if (is_array($near)) {
                foreach ($near as $n) {
                    if (!is_string($n) || $n === '') {
                        continue;
                    }
                    $nk = metarBulkSanitizeIcaoForFilename($n);
                    if ($nk !== null) {
                        $out[$nk] = true;
                    }
                }
            }
        }
    }

    return $out;
}

/**
 * Enabled airports in config (same filter as scheduler weather jobs).
 *
 * @param array<string, mixed>|null $config
 */
function metarBulkCountEnabledAirports(?array $config = null): int {
    require_once __DIR__ . '/config.php';
    if ($config === null) {
        $config = loadConfig(false);
    }
    if (!is_array($config) || !isset($config['airports']) || !is_array($config['airports'])) {
        return 0;
    }
    $n = 0;
    foreach ($config['airports'] as $airport) {
        if (is_array($airport) && isAirportEnabled($airport)) {
            $n++;
        }
    }

    return $n;
}

/**
 * National gzip ingest helps multi-airport installs; a single enabled airport uses per-station HTTP only.
 *
 * @param array<string, mixed>|null $config
 */
function metarBulkShouldUseNationalBulk(?array $config = null): bool {
    return metarBulkCountEnabledAirports($config) > 1;
}

/**
 * CSV `observation_time` to Unix UTC; unknown parses sort before any real observation.
 */
function metarBulkParseObservationTime(string $observationTimeCell): int {
    $t = trim($observationTimeCell);
    if ($t === '') {
        return PHP_INT_MIN;
    }
    $ts = strtotime($t);
    if ($ts === false) {
        return PHP_INT_MIN;
    }

    return (int) $ts;
}

/**
 * One CSV cell by logical column name (supports duplicate header names via occurrence index).
 *
 * @param array<int, string|null> $fields
 * @param array<string, list<int>> $lists
 */
function metarBulkCsvRowCell(array $fields, array $lists, string $column, int $occurrence = 0): mixed
{
    if (!isset($lists[$column][$occurrence])) {
        return null;
    }
    $i = $lists[$column][$occurrence];

    return $fields[$i] ?? null;
}

/**
 * Cached index lists for the canonical header (unit tests and callers that omit `$lists`).
 *
 * @return array<string, list<int>>
 */
function metarBulkGetDefaultCsvColumnLists(): array
{
    static $cached = null;
    if ($cached === null) {
        $cached = metarBulkCsvBuildColumnIndexLists(metarBulkCsvExpectedHeaderColumns());
    }

    return $cached;
}

/**
 * Map one AWC CSV row to a single METAR API object. `$lists` must match the validated file header.
 *
 * @param array<int, string|null> $fields
 * @param array<string, list<int>> $lists
 * @return array<string, mixed>|null
 */
function metarBulkCsvRowToApiRecord(array $fields, array $lists): ?array
{
    $need = metarBulkCsvExpectedColumnCount();
    if (count($fields) < $need) {
        return null;
    }
    $station = metarBulkSanitizeIcaoForFilename((string) (metarBulkCsvRowCell($fields, $lists, 'station_id', 0) ?? ''));
    if ($station === null) {
        return null;
    }
    $rawOb = trim((string) (metarBulkCsvRowCell($fields, $lists, 'raw_text', 0) ?? ''));
    if ($rawOb === '') {
        return null;
    }

    $temp = metarBulkParseOptionalFloat(metarBulkCsvRowCell($fields, $lists, 'temp_c', 0));
    $dew = metarBulkParseOptionalFloat(metarBulkCsvRowCell($fields, $lists, 'dewpoint_c', 0));

    $wdir = null;
    $wdirCell = metarBulkCsvRowCell($fields, $lists, 'wind_dir_degrees', 0);
    if ($wdirCell !== null && $wdirCell !== '' && is_numeric($wdirCell)) {
        $wdir = (int) round((float) $wdirCell);
    }
    $wspd = null;
    $wspdCell = metarBulkCsvRowCell($fields, $lists, 'wind_speed_kt', 0);
    if ($wspdCell !== null && $wspdCell !== '' && is_numeric($wspdCell)) {
        $wspd = (int) round((float) $wspdCell);
    }
    $wgst = null;
    $gustCell = metarBulkCsvRowCell($fields, $lists, 'wind_gust_kt', 0);
    if ($gustCell !== null && $gustCell !== '' && is_numeric($gustCell)) {
        $gustKts = (int) round((float) $gustCell);
        if ($gustKts >= 0 && $gustKts <= 200) {
            $wgst = $gustKts;
        }
    }

    $visCell = trim((string) (metarBulkCsvRowCell($fields, $lists, 'visibility_statute_mi', 0) ?? ''));
    $altimInHg = metarBulkParseOptionalFloat(metarBulkCsvRowCell($fields, $lists, 'altim_in_hg', 0));
    $altimHpa = null;
    if ($altimInHg !== null) {
        require_once __DIR__ . '/units.php';
        $altimHpa = inhgToHpa($altimInHg);
    }

    $clouds = [];
    $covers = $lists['sky_cover'] ?? [];
    $bases = $lists['cloud_base_ft_agl'] ?? [];
    $pairCount = min(count($covers), count($bases));
    for ($p = 0; $p < $pairCount; $p++) {
        $ci = $covers[$p];
        $bi = $bases[$p];
        $coverRaw = $fields[$ci] ?? null;
        $cover = strtoupper(trim((string) $coverRaw));
        if ($cover === '' || $cover === 'NCD' || $cover === 'NSC') {
            continue;
        }
        $base = null;
        $baseRaw = $fields[$bi] ?? null;
        if ($baseRaw !== null && $baseRaw !== '' && is_numeric($baseRaw)) {
            $base = (int) round((float) $baseRaw);
        }
        $clouds[] = ['cover' => $cover, 'base' => $base];
    }

    $vertVis = null;
    $vvCell = metarBulkCsvRowCell($fields, $lists, 'vert_vis_ft', 0);
    if ($vvCell !== null && $vvCell !== '' && is_numeric($vvCell)) {
        $ft = (float) $vvCell;
        if ($ft > 0) {
            // `parseMETARResponse()` expects vertVis in hundreds of feet; CSV gives feet AGL.
            $vertVis = $ft / 100.0;
        }
    }

    $pcp24hr = metarBulkParseOptionalFloat(metarBulkCsvRowCell($fields, $lists, 'pcp24hr_in', 0));
    $precip = metarBulkParseOptionalFloat(metarBulkCsvRowCell($fields, $lists, 'precip_in', 0));

    $obsTime = null;
    $obsStr = trim((string) (metarBulkCsvRowCell($fields, $lists, 'observation_time', 0) ?? ''));
    if ($obsStr !== '') {
        $ts = strtotime($obsStr);
        if ($ts !== false) {
            $obsTime = (int) $ts;
        }
    }

    $out = [
        'rawOb' => $rawOb,
        'temp' => $temp,
        'dewp' => $dew,
        'wdir' => $wdir,
        'wspd' => $wspd,
        'altim' => $altimHpa,
        'clouds' => $clouds,
    ];
    if ($visCell !== '') {
        if (is_numeric($visCell)) {
            $out['visib'] = (float) $visCell;
        } else {
            $out['visib'] = $visCell;
        }
    }
    if ($vertVis !== null) {
        $out['vertVis'] = $vertVis;
    }
    if ($obsTime !== null) {
        $out['obsTime'] = $obsTime;
    }
    if ($pcp24hr !== null) {
        $out['pcp24hr'] = $pcp24hr;
    }
    if ($precip !== null) {
        $out['precip'] = $precip;
    }
    if ($wgst !== null) {
        // Bulk slices only: AWC CSV `wind_gust_kt` when `rawOb` has no G-group (HTTP JSON has no gust field).
        $out['wgst'] = $wgst;
    }

    return $out;
}

/**
 * @param mixed $cell CSV cell (often string)
 */
function metarBulkParseOptionalFloat(mixed $cell): ?float {
    if ($cell === null) {
        return null;
    }
    $s = trim((string) $cell);
    if ($s === '' || !is_numeric($s)) {
        return null;
    }

    return (float) $s;
}

/**
 * @param array<int, string|null> $fields
 * @param array<string, list<int>>|null $lists From validated header; null uses canonical lists
 */
function metarBulkCsvRowToJsonEnvelope(array $fields, ?array $lists = null): ?string
{
    $lists ??= metarBulkGetDefaultCsvColumnLists();
    $rec = metarBulkCsvRowToApiRecord($fields, $lists);
    if ($rec === null) {
        return null;
    }
    try {
        return json_encode([$rec], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } catch (JsonException) {
        return null;
    }
}

/**
 * Returns on-disk JSON for one ICAO when file mtime is within `METAR_BULK_STATION_FILE_MAX_AGE_SECONDS`.
 */
function metarBulkTryReadJsonResponseForStation(string $stationId): ?string {
    require_once __DIR__ . '/config.php';
    if (!metarBulkShouldUseNationalBulk()) {
        return null;
    }
    $icao = metarBulkSanitizeIcaoForFilename($stationId);
    if ($icao === null) {
        return null;
    }
    $path = getMetarBulkStationJsonPath($icao);
    if (!is_file($path)) {
        return null;
    }
    $age = time() - (int) filemtime($path);
    if ($age > METAR_BULK_STATION_FILE_MAX_AGE_SECONDS) {
        return null;
    }
    $body = @file_get_contents($path);
    if (!is_string($body) || $body === '') {
        return null;
    }

    return $body;
}

/**
 * Stream-download gzip to `destAbsolute`; deletes the file unless HTTP 200.
 */
function metarBulkDownloadMetarCacheGz(string $destAbsolute): bool {
    $ch = curl_init();
    if ($ch === false) {
        return false;
    }
    $fp = @fopen($destAbsolute, 'wb');
    if ($fp === false) {
        curl_close($ch);

        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => METAR_BULK_CACHE_GZ_URL,
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => METAR_BULK_DOWNLOAD_TIMEOUT_SECONDS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'AviationWX METAR bulk refresh/1.0',
    ]);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if ($ok === false || $code !== 200) {
        @unlink($destAbsolute);

        return false;
    }

    return true;
}

function metarBulkEnsureDirectories(): void {
    $base = getMetarBulkCacheDir();
    $stations = getMetarBulkStationsDir();
    $tmp = getMetarBulkTempDir();
    foreach ([$base, $stations, $tmp] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
}

/**
 * Persist refresh metadata after a successful national bulk ingest.
 *
 * @param int $fetchedAt Unix timestamp when ingest completed
 * @param int $written Station JSON files written
 * @param int $scanned CSV rows scanned
 */
function metarBulkWriteRefreshMeta(int $fetchedAt, int $written, int $scanned): bool
{
    metarBulkEnsureDirectories();
    $path = getMetarBulkMetaPath();
    $payload = json_encode([
        'fetched_at' => $fetchedAt,
        'written' => $written,
        'scanned' => $scanned,
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return false;
    }

    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
        @unlink($tmp);

        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }

    return true;
}

/**
 * Seconds since the last successful METAR bulk refresh, or null when unknown.
 *
 * @param int|null $now Injectable reference time for tests
 */
function metarBulkSnapshotAgeSeconds(?int $now = null): ?int
{
    $path = getMetarBulkMetaPath();
    if (!is_readable($path)) {
        return null;
    }

    $meta = json_decode((string) @file_get_contents($path), true);
    if (!is_array($meta)) {
        return null;
    }

    $fetchedAt = (int) ($meta['fetched_at'] ?? 0);
    if ($fetchedAt <= 0) {
        return null;
    }

    $now = $now ?? time();
    if ($fetchedAt > $now) {
        return null;
    }

    return $now - $fetchedAt;
}

/**
 * Write `{ICAO}.json` via hidden temp file then `rename` so readers never see a partial body.
 *
 * @param string $jsonEnvelope JSON string for `file_put_contents`
 */
function metarBulkWriteStationJsonAtomic(string $icaoUpper, string $jsonEnvelope): bool {
    $stationsDir = getMetarBulkStationsDir();
    $safe = metarBulkSanitizeIcaoForFilename($icaoUpper);
    if ($safe === null) {
        return false;
    }
    $final = $stationsDir . '/' . $safe . '.json';
    $tmp = $stationsDir . '/.' . $safe . '.' . uniqid('', true) . '.tmp';
    $written = @file_put_contents($tmp, $jsonEnvelope, LOCK_EX);
    if ($written === false || $written !== strlen($jsonEnvelope)) {
        @unlink($tmp);

        return false;
    }
    if (!@rename($tmp, $final)) {
        @unlink($tmp);

        return false;
    }

    return true;
}

/**
 * Stream gzip CSV; for each configured ICAO keep the row with greatest `observation_time` before writing.
 *
 * @param array<string, true> $icaoSet
 * @return array{written: int, scanned: int, stations_in_csv: int, skipped_short_rows: int, error?: string, header_mismatch?: string}
 */
function metarBulkIngestGzipToStationFiles(string $gzAbsolute, array $icaoSet): array {
    $stats = ['written' => 0, 'scanned' => 0, 'stations_in_csv' => 0, 'skipped_short_rows' => 0];
    if ($icaoSet === []) {
        return $stats;
    }
    $uri = 'compress.zlib://' . $gzAbsolute;
    $h = @fopen($uri, 'rb');
    if ($h === false) {
        $stats['error'] = 'cannot_open_gzip';

        return $stats;
    }
    $header = @fgetcsv($h, 0, ',', '"', '\\');
    if ($header === false || $header === []) {
        fclose($h);
        $stats['error'] = 'bad_csv_header';

        return $stats;
    }
    $header = metarBulkCsvNormalizeHeaderRow($header);
    if (!metarBulkCsvHeaderMatchesExpected($header)) {
        fclose($h);
        $stats['error'] = 'bad_csv_header_schema';
        $stats['header_mismatch'] = metarBulkCsvDescribeHeaderMismatch($header);

        return $stats;
    }
    $lists = metarBulkCsvBuildColumnIndexLists($header);
    $expectedCols = metarBulkCsvExpectedColumnCount();

    /** @var array<string, array{0: int, 1: array<int, string|null>}> $best */
    $best = [];
    while (($row = @fgetcsv($h, 0, ',', '"', '\\')) !== false) {
        $stats['scanned']++;
        if (count($row) < $expectedCols) {
            $stats['skipped_short_rows']++;

            continue;
        }
        $row = array_slice(array_pad($row, $expectedCols, ''), 0, $expectedCols);
        $sidCell = metarBulkCsvRowCell($row, $lists, 'station_id', 0);
        $sid = metarBulkSanitizeIcaoForFilename((string) ($sidCell ?? ''));
        if ($sid === null || !isset($icaoSet[$sid])) {
            continue;
        }
        $obsTs = metarBulkParseObservationTime((string) (metarBulkCsvRowCell($row, $lists, 'observation_time', 0) ?? ''));
        if (!isset($best[$sid]) || $obsTs >= $best[$sid][0]) {
            $best[$sid] = [$obsTs, $row];
        }
    }
    fclose($h);
    $stats['stations_in_csv'] = count($best);

    foreach ($best as $sid => [, $fields]) {
        $json = metarBulkCsvRowToJsonEnvelope($fields, $lists);
        if ($json === null) {
            continue;
        }
        if (metarBulkWriteStationJsonAtomic($sid, $json)) {
            $stats['written']++;
        }
    }

    return $stats;
}

/**
 * @param array<string, true> $icaoSet
 */
function metarBulkPruneOrphanStationFiles(array $icaoSet): int {
    $dir = getMetarBulkStationsDir();
    if (!is_dir($dir)) {
        return 0;
    }
    $removed = 0;
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..' || !str_ends_with($name, '.json')) {
            continue;
        }
        $icao = metarBulkSanitizeIcaoForFilename(substr($name, 0, -5));
        if ($icao === null) {
            continue;
        }
        if (isset($icaoSet[$icao])) {
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_file($path) && @unlink($path)) {
            $removed++;
        }
    }

    return $removed;
}

/**
 * Download, ingest, prune; no network in mock or test mode. Non-blocking lock: exit success if another run holds the lock.
 *
 * @return array{ok: bool, written: int, scanned: int, pruned: int, http_ok?: bool, note?: string}
 */
function metarBulkRefreshRun(): array {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/logger.php';

    $result = ['ok' => false, 'written' => 0, 'scanned' => 0, 'pruned' => 0];
    if (shouldMockExternalServices() || isTestMode()) {
        $result['ok'] = true;
        $result['note'] = 'skipped_mock_or_test_mode';

        return $result;
    }

    if (!metarBulkShouldUseNationalBulk()) {
        $result['ok'] = true;
        $result['note'] = 'skipped_bulk_below_multi_airport_threshold';

        return $result;
    }

    $icaoSet = metarBulkCollectConfiguredStationIds();
    if ($icaoSet === []) {
        $result['ok'] = true;
        $result['note'] = 'no_metar_stations_configured';

        return $result;
    }

    metarBulkEnsureDirectories();
    $lockPath = getMetarBulkRefreshLockPath();
    $lockFp = @fopen($lockPath, 'cb');
    if ($lockFp === false) {
        $result['note'] = 'lock_open_failed';

        return $result;
    }
    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        fclose($lockFp);
        $result['ok'] = true;
        $result['note'] = 'skipped_lock_held';

        return $result;
    }

    $tmpDir = getMetarBulkTempDir();
    $gzTmp = $tmpDir . '/metars.' . uniqid('', true) . '.gz';
    $httpOk = metarBulkDownloadMetarCacheGz($gzTmp);
    $result['http_ok'] = $httpOk;
    if (!$httpOk) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        @unlink($gzTmp);
        aviationwx_log('warning', 'metar_bulk: download failed', ['url' => METAR_BULK_CACHE_GZ_URL], 'app');

        return $result;
    }

    $ingest = metarBulkIngestGzipToStationFiles($gzTmp, $icaoSet);
    @unlink($gzTmp);
    if (isset($ingest['error'])) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        $logCtx = ['error' => $ingest['error']];
        if (isset($ingest['header_mismatch'])) {
            $logCtx['header_mismatch'] = $ingest['header_mismatch'];
        }
        if (($ingest['skipped_short_rows'] ?? 0) > 0) {
            $logCtx['skipped_short_rows'] = (int) $ingest['skipped_short_rows'];
        }
        aviationwx_log('warning', 'metar_bulk: ingest failed', $logCtx, 'app');

        return $result;
    }

    $result['written'] = (int) $ingest['written'];
    $result['scanned'] = (int) $ingest['scanned'];
    $result['pruned'] = metarBulkPruneOrphanStationFiles($icaoSet);
    $result['ok'] = true;
    $fetchedAt = time();
    metarBulkWriteRefreshMeta($fetchedAt, $result['written'], $result['scanned']);

    flock($lockFp, LOCK_UN);
    fclose($lockFp);

    aviationwx_log('info', 'metar_bulk: refresh complete', [
        'metar_bulk_age_seconds' => 0,
        'written' => $result['written'],
        'scanned' => $result['scanned'],
        'stations_in_csv' => (int) ($ingest['stations_in_csv'] ?? 0),
        'skipped_short_rows' => (int) ($ingest['skipped_short_rows'] ?? 0),
        'pruned' => $result['pruned'],
    ], 'app');

    return $result;
}
