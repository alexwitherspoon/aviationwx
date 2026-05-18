<?php
/**
 * METAR bulk ingest and per-station JSON slices for `parseMETARResponse()`. Entry: `metarBulkRefreshRun()`.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/cache-paths.php';

// AWC national METAR CSV gzip; prefer scheduler-driven refresh over ad-hoc pulls.
const METAR_BULK_CACHE_GZ_URL = 'https://aviationweather.gov/data/cache/metars.cache.csv.gz';

const METAR_BULK_CSV_MIN_FIELDS = 12;

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
    if ($config === null) {
        require_once __DIR__ . '/config.php';
        $config = loadConfig(false);
    }
    $out = [];
    if (!is_array($config) || !isset($config['airports']) || !is_array($config['airports'])) {
        return $out;
    }
    foreach ($config['airports'] as $airport) {
        if (!is_array($airport) || empty($airport['weather_sources']) || !is_array($airport['weather_sources'])) {
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
 * Map one AWC CSV row (numeric columns; repeated `sky_cover` headers) to a single METAR API object.
 *
 * @param array<int, string|null> $fields
 * @return array<string, mixed>|null
 */
function metarBulkCsvRowToApiRecord(array $fields): ?array {
    if (count($fields) < METAR_BULK_CSV_MIN_FIELDS) {
        return null;
    }
    $station = metarBulkSanitizeIcaoForFilename((string) ($fields[1] ?? ''));
    if ($station === null) {
        return null;
    }
    $rawOb = trim((string) ($fields[0] ?? ''));
    if ($rawOb === '') {
        return null;
    }

    $temp = metarBulkParseOptionalFloat($fields[5] ?? null);
    $dew = metarBulkParseOptionalFloat($fields[6] ?? null);

    $wdir = null;
    if (isset($fields[7]) && $fields[7] !== null && $fields[7] !== '' && is_numeric($fields[7])) {
        $wdir = (int) round((float) $fields[7]);
    }
    $wspd = null;
    if (isset($fields[8]) && $fields[8] !== null && $fields[8] !== '' && is_numeric($fields[8])) {
        $wspd = (int) round((float) $fields[8]);
    }

    $visCell = trim((string) ($fields[10] ?? ''));
    $altimInHg = metarBulkParseOptionalFloat($fields[11] ?? null);
    $altimHpa = $altimInHg !== null ? $altimInHg * 33.8639 : null;

    $clouds = [];
    $pairs = [[22, 23], [24, 25], [26, 27], [28, 29]];
    foreach ($pairs as [$ci, $bi]) {
        if (!isset($fields[$ci])) {
            continue;
        }
        $cover = strtoupper(trim((string) $fields[$ci]));
        if ($cover === '' || $cover === 'NCD' || $cover === 'NSC') {
            continue;
        }
        $base = null;
        if (isset($fields[$bi]) && $fields[$bi] !== null && $fields[$bi] !== '' && is_numeric($fields[$bi])) {
            $base = (int) round((float) $fields[$bi]);
        }
        $clouds[] = ['cover' => $cover, 'base' => $base];
    }

    $vertVis = null;
    if (isset($fields[41]) && $fields[41] !== null && $fields[41] !== '' && is_numeric($fields[41])) {
        $ft = (float) $fields[41];
        if ($ft > 0) {
            // `parseMETARResponse()` expects vertVis in hundreds of feet; CSV gives feet AGL.
            $vertVis = $ft / 100.0;
        }
    }

    $precip = null;
    foreach ([39, 36] as $pi) {
        if (isset($fields[$pi]) && $fields[$pi] !== null && $fields[$pi] !== '' && is_numeric($fields[$pi])) {
            $precip = (float) $fields[$pi];
            break;
        }
    }

    $obsTime = null;
    $obsStr = trim((string) ($fields[2] ?? ''));
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
    if ($precip !== null) {
        $out['pcp24hr'] = $precip;
    }

    return $out;
}

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
 */
function metarBulkCsvRowToJsonEnvelope(array $fields): ?string {
    $rec = metarBulkCsvRowToApiRecord($fields);
    if ($rec === null) {
        return null;
    }
    try {
        return json_encode([$rec], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } catch (JsonException $e) {
        return null;
    }
}

/**
 * Returns on-disk JSON for one ICAO when file mtime is within `METAR_BULK_STATION_FILE_MAX_AGE_SECONDS`.
 */
function metarBulkTryReadJsonResponseForStation(string $stationId): ?string {
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
 * @return array{written: int, scanned: int, stations_in_csv: int, error?: string}
 */
function metarBulkIngestGzipToStationFiles(string $gzAbsolute, array $icaoSet): array {
    $stats = ['written' => 0, 'scanned' => 0, 'stations_in_csv' => 0];
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
    if ($header === false || $header === [] || ($header[0] ?? '') !== 'raw_text') {
        fclose($h);
        $stats['error'] = 'bad_csv_header';

        return $stats;
    }

    /** @var array<string, array{0: int, 1: array<int, string|null>}> $best */
    $best = [];
    while (($row = @fgetcsv($h, 0, ',', '"', '\\')) !== false) {
        $stats['scanned']++;
        if (count($row) < METAR_BULK_CSV_MIN_FIELDS) {
            continue;
        }
        $sid = metarBulkSanitizeIcaoForFilename((string) ($row[1] ?? ''));
        if ($sid === null || !isset($icaoSet[$sid])) {
            continue;
        }
        $obsTs = metarBulkParseObservationTime((string) ($row[2] ?? ''));
        if (!isset($best[$sid]) || $obsTs >= $best[$sid][0]) {
            $best[$sid] = [$obsTs, $row];
        }
    }
    fclose($h);
    $stats['stations_in_csv'] = count($best);

    foreach ($best as $sid => [, $fields]) {
        $json = metarBulkCsvRowToJsonEnvelope($fields);
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

    $icaoSet = metarBulkCollectConfiguredStationIds();
    if ($icaoSet === []) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        $result['ok'] = true;
        $result['note'] = 'no_metar_stations_configured';

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
        aviationwx_log('warning', 'metar_bulk: ingest failed', ['error' => $ingest['error']], 'app');

        return $result;
    }

    $result['written'] = (int) $ingest['written'];
    $result['scanned'] = (int) $ingest['scanned'];
    $result['pruned'] = metarBulkPruneOrphanStationFiles($icaoSet);
    $result['ok'] = true;

    flock($lockFp, LOCK_UN);
    fclose($lockFp);

    aviationwx_log('info', 'metar_bulk: refresh complete', [
        'written' => $result['written'],
        'scanned' => $result['scanned'],
        'stations_in_csv' => (int) ($ingest['stations_in_csv'] ?? 0),
        'pruned' => $result['pruned'],
    ], 'app');

    return $result;
}
