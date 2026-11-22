<?php
/**
 * MADIS HFMETAR bulk refresh
 *
 * Downloads the current UTC hour's HFMETAR netCDF file once and slices it
 * into per-station JSON files under cache/madis-hfmetar/stations/. The
 * unified fetcher reads those slices (via madisHfmetarTryReadStationSlice)
 * instead of hitting NOAA per airport per cycle, mirroring the METAR bulk
 * pattern.
 *
 * The hourly file is rewritten by MADIS roughly every 5-10 minutes as the
 * 5-minute ASOS observations arrive, so the scheduler reruns this worker on
 * MADIS_HFMETAR_REFRESH_INTERVAL_SECONDS (5 minutes) to keep slices fresh.
 *
 * @package AviationWX\Madis
 */

require_once __DIR__ . '/netcdf-classic.php';
require_once __DIR__ . '/madis-hfmetar.php';
require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../weather-health.php';
require_once __DIR__ . '/../circuit-breaker.php';

use AviationWX\Madis\NetcdfClassic;
use AviationWX\Madis\NetcdfFormatException;

/** Bulk download status: a changed file (200) was downloaded. */
const MADIS_HFMETAR_DL_CHANGED = 'changed';
/** Bulk download status: the file is unchanged (304 Not Modified). */
const MADIS_HFMETAR_DL_NOT_MODIFIED = 'not_modified';
/** Bulk download status: the request failed. */
const MADIS_HFMETAR_DL_FAILED = 'failed';

/**
 * Configured madis_hfmetar station ids from enabled airports.
 *
 * @param array<string, mixed>|null $config
 * @return array<string, true>
 */
function madisHfmetarBulkCollectConfiguredStationIds(?array $config = null): array
{
    if ($config === null) {
        $config = loadConfig(false);
    }
    $out = [];
    if (!is_array($config) || !isset($config['airports']) || !is_array($config['airports'])) {
        return $out;
    }
    foreach ($config['airports'] as $airport) {
        if (!is_array($airport) || !isAirportEnabled($airport) || empty($airport['weather_sources'])
            || !is_array($airport['weather_sources'])) {
            continue;
        }
        foreach ($airport['weather_sources'] as $source) {
            if (!is_array($source) || ($source['type'] ?? '') !== 'madis_hfmetar') {
                continue;
            }
            $sid = $source['station_id'] ?? '';
            if (is_string($sid) && $sid !== '') {
                $out[strtoupper(trim($sid))] = true;
            }
        }
    }

    return $out;
}

/**
 * Create the cache directories if missing.
 */
function madisHfmetarBulkEnsureDirectories(): void
{
    foreach ([getMadisHfmetarCacheDir(), getMadisHfmetarStationsDir(), getMadisHfmetarTempDir()] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            aviationwx_log('warning', 'madis_hfmetar_bulk: cache dir create failed', ['dir' => $dir], 'app');
        }
    }
}

/**
 * Download the current UTC hour's netCDF file to a destination path.
 *
 * Sends a conditional request (If-Modified-Since) when a prior Last-Modified
 * is known. A 304 Not Modified means the file is unchanged, so the caller
 * keeps the existing slices instead of re-downloading a file it already has.
 *
 * @param string $destAbsolute Destination file path
 * @param string|null $ifModifiedSince Prior Last-Modified header value, or null for an unconditional fetch
 * @param int|null $httpCode HTTP status from the response, when available
 * @param string|null $lastModified Last-Modified header from a 200 response, when available
 * @return string One of MADIS_HFMETAR_DL_*
 */
function madisHfmetarBulkDownloadCurrentHourGz(
    string $destAbsolute,
    ?string $ifModifiedSince = null,
    ?int &$httpCode = null,
    ?string &$lastModified = null,
    ?array &$responseHeaders = null
): string {
    $httpCode = null;
    $lastModified = null;
    $responseHeaders = [];
    $ch = curl_init();
    if ($ch === false) {
        return MADIS_HFMETAR_DL_FAILED;
    }
    $fp = @fopen($destAbsolute, 'wb');
    if ($fp === false) {
        curl_close($ch);

        return MADIS_HFMETAR_DL_FAILED;
    }
    $requestHeaders = [];
    if (is_string($ifModifiedSince) && $ifModifiedSince !== '') {
        $requestHeaders[] = 'If-Modified-Since: ' . $ifModifiedSince;
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => madisHfmetarCurrentHourFileUrl(),
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => MADIS_HFMETAR_DOWNLOAD_TIMEOUT_SECONDS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'AviationWX MADIS HFMETAR bulk refresh/1.0',
        CURLOPT_HEADERFUNCTION => static function ($curl, $header) use (&$responseHeaders): int {
            $length = strlen($header);
            if (preg_match('/^([^:]+):\s*(.+)\r?$/i', $header, $matches)) {
                $name = strtolower(trim($matches[1]));
                $responseHeaders[$name] = trim($matches[2]);
            }

            return $length;
        },
    ]);
    if ($requestHeaders !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
    }
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $httpCode = $code > 0 ? $code : null;
    curl_close($ch);
    fclose($fp);

    if ($ok === false) {
        @unlink($destAbsolute);

        return MADIS_HFMETAR_DL_FAILED;
    }
    if ($code === 304) {
        @unlink($destAbsolute);

        return MADIS_HFMETAR_DL_NOT_MODIFIED;
    }
    if ($code !== 200) {
        @unlink($destAbsolute);

        return MADIS_HFMETAR_DL_FAILED;
    }
    $lastModified = $responseHeaders['last-modified'] ?? null;

    return MADIS_HFMETAR_DL_CHANGED;
}

/**
 * Read the bulk refresh metadata.
 *
 * @return array<string, mixed>|null
 */
function madisHfmetarBulkReadMeta(): ?array
{
    $path = getMadisHfmetarMetaPath();
    if (!is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $meta = @json_decode($raw, true);

    return is_array($meta) ? $meta : null;
}

/**
 * Refresh the mtime of every station slice so a 304 (unchanged file) keeps the
 * slices serving through the resolver instead of falling back to per-airport
 * HTTP. Content is untouched; only the freshness timestamp advances.
 *
 * @param array<int, string> $icaos Station ids to touch
 * @return int Number of slices touched
 */
function madisHfmetarBulkTouchStationSlices(array $icaos): int
{
    $touched = 0;
    foreach ($icaos as $icao) {
        $path = getMadisHfmetarStationSlicePath($icao);
        if (is_file($path) && @touch($path)) {
            $touched++;
        }
    }

    return $touched;
}

/**
 * Atomically write one station slice file.
 *
 * @param string $icaoUpper Station ICAO
 * @param string $jsonEnvelope JSON body
 * @return bool
 */
function madisHfmetarBulkWriteStationSliceAtomic(string $icaoUpper, string $jsonEnvelope): bool
{
    $path = getMadisHfmetarStationSlicePath($icaoUpper);
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $jsonEnvelope, LOCK_EX) === false) {
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
 * Parse an HFMETAR netCDF file and write one JSON slice per configured station.
 *
 * @param string $gzAbsolute Path to the gzipped netCDF file
 * @param array<string, true> $icaoSet Station ids to retain
 * @param string $fileHour Hour the file covers (e.g. 20261120_23)
 * @return array{written: int, scanned: int}
 */
function madisHfmetarBulkIngestFileToStationSlices(string $gzAbsolute, array $icaoSet, string $fileHour): array
{
    madisHfmetarBulkEnsureDirectories();

    // A valid download that yields no observations is a station not yet
    // reporting, not a failure. Distinguish that from an unparseable file so
    // the refresh run does not report success for a corrupt download.
    $fileBytes = @file_get_contents($gzAbsolute);
    if ($fileBytes === false) {
        return ['error' => 'file read failed'];
    }
    $raw = (substr($fileBytes, 0, 2) === "\x1f\x8b") ? gzdecode($fileBytes) : $fileBytes;
    if ($raw === false) {
        return ['error' => 'file is not a valid gzip stream'];
    }
    try {
        new NetcdfClassic($raw);
    } catch (NetcdfFormatException $e) {
        return ['error' => 'file is not a parseable netCDF'];
    }

    $written = 0;
    $scanned = 0;
    $failed = 0;
    foreach (array_keys($icaoSet) as $icao) {
        $observations = madisHfmetarDecodeFile($gzAbsolute, $icao);
        $scanned++;
        if ($observations === null) {
            continue;
        }
        $envelope = json_encode([
            'station_id' => $icao,
            'file_hour' => $fileHour,
            'updated_at' => time(),
            'observations' => $observations,
        ], JSON_UNESCAPED_SLASHES);
        if ($envelope === false) {
            $failed++;
            continue;
        }
        if (!madisHfmetarBulkWriteStationSliceAtomic($icao, $envelope)) {
            $failed++;
            continue;
        }
        $written++;
    }
    if ($failed > 0) {
        aviationwx_log('warning', 'madis_hfmetar_bulk: slice write failed', ['failed' => $failed], 'app');
    }

    return ['written' => $written, 'scanned' => $scanned];
}

/**
 * Remove slice files for stations no longer configured.
 *
 * @param array<string, true> $icaoSet Stations to retain
 * @return int Number of files removed
 */
function madisHfmetarBulkPruneOrphanStationSlices(array $icaoSet): int
{
    $dir = getMadisHfmetarStationsDir();
    if (!is_dir($dir)) {
        return 0;
    }
    $pruned = 0;
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return 0;
    }
    foreach ($entries as $entry) {
        if (substr($entry, -5) !== '.json') {
            continue;
        }
        $icao = substr($entry, 0, -5);
        if (!isset($icaoSet[$icao])) {
            if (@unlink($dir . '/' . $entry)) {
                $pruned++;
            }
        }
    }

    return $pruned;
}

/**
 * Write the refresh metadata file.
 *
 * @param string|null $lastModified Last-Modified header from the most recent
 *   200 response, used as the conditional If-Modified-Since on the next fetch.
 * @return bool
 */
function madisHfmetarBulkWriteRefreshMeta(
    int $fetchedAt,
    string $fileHour,
    int $written,
    int $scanned,
    ?string $lastModified = null
): bool {
    madisHfmetarBulkEnsureDirectories();
    $path = getMadisHfmetarMetaPath();
    $payload = json_encode([
        'fetched_at' => $fetchedAt,
        'file_hour' => $fileHour,
        'written' => $written,
        'scanned' => $scanned,
        'last_modified' => $lastModified,
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
 * Seconds since the last successful bulk refresh, or null when unknown.
 *
 * @param int|null $now Injectable reference time for tests
 */
function madisHfmetarBulkSnapshotAgeSeconds(?int $now = null): ?int
{
    $now = $now ?? time();
    $path = getMadisHfmetarMetaPath();
    if (!is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $meta = @json_decode($raw, true);
    if (!is_array($meta) || !isset($meta['fetched_at']) || !is_numeric($meta['fetched_at'])) {
        return null;
    }

    return $now - (int) $meta['fetched_at'];
}

/**
 * Read a station slice when it is fresh enough to use.
 *
 * @param string $stationId Station ICAO
 * @return string|null JSON envelope body, or null when stale or missing
 */
function madisHfmetarTryReadStationSlice(string $stationId): ?string
{
    $icao = strtoupper(trim($stationId));
    if ($icao === '') {
        return null;
    }
    $path = getMadisHfmetarStationSlicePath($icao);
    if (!is_readable($path)) {
        return null;
    }
    $mtime = @filemtime($path);
    if ($mtime === false || (time() - $mtime) > MADIS_HFMETAR_STATION_FILE_MAX_AGE_SECONDS) {
        return null;
    }
    $content = @file_get_contents($path);
    if ($content === false || $content === '') {
        return null;
    }

    return $content;
}

/**
 * Run one MADIS HFMETAR bulk refresh cycle.
 *
 * @param int|null $now Injectable reference time for tests
 * @return array<string, mixed>
 */
function madisHfmetarBulkRefreshRun(?int $now = null): array
{
    $now = $now ?? time();
    $result = ['ok' => false, 'written' => 0, 'scanned' => 0, 'pruned' => 0];
    if (shouldMockExternalServices() || isTestMode()) {
        $result['ok'] = true;
        $result['note'] = 'skipped_mock_or_test_mode';

        return $result;
    }

    $icaoSet = madisHfmetarBulkCollectConfiguredStationIds();
    if ($icaoSet === []) {
        $result['ok'] = true;
        $result['note'] = 'no_madis_stations_configured';

        return $result;
    }

    madisHfmetarBulkEnsureDirectories();
    $lockPath = getMadisHfmetarRefreshLockPath();
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

    $tmpDir = getMadisHfmetarTempDir();
    $gzTmp = $tmpDir . '/hfmetar.' . uniqid('', true) . '.gz';
    $downloadHttpCode = null;
    $lastModified = null;
    $responseHeaders = null;
    $meta = madisHfmetarBulkReadMeta();
    $ifModifiedSince = is_array($meta) ? ($meta['last_modified'] ?? null) : null;

    // Honor an open shared breaker: a recent 429/503 has already backed us
    // off, so skip the download instead of compounding the request rate.
    $madisSourceConfig = ['type' => 'madis_hfmetar'];
    $breaker = checkWeatherCircuitBreaker('madis_bulk', 'madis_hfmetar', $madisSourceConfig);
    if (is_array($breaker) && ($breaker['skip'] ?? false)) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        weatherHealthTrackCircuitOpen('madis_bulk', 'madis_hfmetar');
        $result['note'] = 'circuit_open';
        aviationwx_log('info', 'madis_hfmetar_bulk: skipped, shared circuit open', [
            'backoff_remaining' => $breaker['backoff_remaining'] ?? null,
        ], 'app');

        return $result;
    }

    $downloadStatus = madisHfmetarBulkDownloadCurrentHourGz(
        $gzTmp,
        is_string($ifModifiedSince) ? $ifModifiedSince : null,
        $downloadHttpCode,
        $lastModified,
        $responseHeaders
    );
    $result['http_ok'] = $downloadStatus !== MADIS_HFMETAR_DL_FAILED;
    if ($downloadHttpCode !== null) {
        $result['http_code'] = $downloadHttpCode;
    }

    if ($downloadStatus === MADIS_HFMETAR_DL_NOT_MODIFIED) {
        // The file is unchanged since our last fetch. Touch the slices so the
        // resolver keeps serving them instead of falling back to per-airport
        // HTTP, and record the check as current without a redundant rewrite.
        @unlink($gzTmp);
        $touched = madisHfmetarBulkTouchStationSlices(array_keys($icaoSet));
        $priorWritten = is_array($meta) ? (int) ($meta['written'] ?? 0) : 0;
        $priorScanned = is_array($meta) ? (int) ($meta['scanned'] ?? 0) : 0;
        $metaWritten = madisHfmetarBulkWriteRefreshMeta(
            $now,
            gmdate('Ymd_H', $now),
            $priorWritten,
            $priorScanned,
            is_array($meta) ? ($meta['last_modified'] ?? null) : null
        );
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        $result['touched'] = $touched;
        $result['meta_written'] = $metaWritten;
        $result['ok'] = true;
        $result['note'] = 'not_modified';
        recordWeatherSuccess('madis_bulk', 'madis_hfmetar', $madisSourceConfig);
        weatherHealthTrackMadisHfmetarBulkDownloadSuccess();
        aviationwx_log('info', 'madis_hfmetar_bulk: file unchanged (304)', [
            'http_code' => $downloadHttpCode,
            'touched' => $touched,
        ], 'app');

        return $result;
    }

    if ($downloadStatus === MADIS_HFMETAR_DL_FAILED) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        @unlink($gzTmp);
        // A 429/503 is a backpressure signal: open the shared breaker so every
        // MADIS airport and the worker itself back off, honoring Retry-After.
        if ($downloadHttpCode === 429 || $downloadHttpCode === HTTP_STATUS_SERVICE_UNAVAILABLE) {
            recordWeatherFailure(
                'madis_bulk',
                'madis_hfmetar',
                'transient',
                $downloadHttpCode,
                null,
                $madisSourceConfig,
                $responseHeaders
            );
        }
        weatherHealthTrackMadisHfmetarBulkDownloadFailure($downloadHttpCode);
        aviationwx_log('warning', 'madis_hfmetar_bulk: download failed', [
            'url' => madisHfmetarCurrentHourFileUrl($now),
            'http_code' => $downloadHttpCode,
        ], 'app');

        return $result;
    }
    weatherHealthTrackMadisHfmetarBulkDownloadSuccess();
    recordWeatherSuccess('madis_bulk', 'madis_hfmetar', $madisSourceConfig);

    $fileHour = gmdate('Ymd_H', $now);
    $ingest = madisHfmetarBulkIngestFileToStationSlices($gzTmp, $icaoSet, $fileHour);
    @unlink($gzTmp);

    if (isset($ingest['error'])) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        $result['note'] = $ingest['error'];
        aviationwx_log('warning', 'madis_hfmetar_bulk: ingest failed', ['error' => $ingest['error']], 'app');

        return $result;
    }

    $result['written'] = (int) $ingest['written'];
    $result['scanned'] = (int) $ingest['scanned'];
    $result['pruned'] = madisHfmetarBulkPruneOrphanStationSlices($icaoSet);
    $metaWritten = madisHfmetarBulkWriteRefreshMeta(
        $now,
        $fileHour,
        $result['written'],
        $result['scanned'],
        $lastModified
    );
    $result['meta_written'] = $metaWritten;

    flock($lockFp, LOCK_UN);
    fclose($lockFp);

    if (!$metaWritten) {
        aviationwx_log('warning', 'madis_hfmetar_bulk: meta write failed after ingest', [
            'written' => $result['written'],
        ], 'app');

        return $result;
    }

    $result['ok'] = true;

    return $result;
}
