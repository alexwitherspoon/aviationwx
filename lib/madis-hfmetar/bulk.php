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

use AviationWX\Madis\NetcdfClassic;
use AviationWX\Madis\NetcdfFormatException;

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
 * @param string $destAbsolute Destination file path
 * @param int|null $httpCode HTTP status from the response, when available
 * @return bool
 */
function madisHfmetarBulkDownloadCurrentHourGz(string $destAbsolute, ?int &$httpCode = null): bool
{
    $httpCode = null;
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
        CURLOPT_URL => madisHfmetarCurrentHourFileUrl(),
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => MADIS_HFMETAR_DOWNLOAD_TIMEOUT_SECONDS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'AviationWX MADIS HFMETAR bulk refresh/1.0',
    ]);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $httpCode = $code > 0 ? $code : null;
    curl_close($ch);
    fclose($fp);
    if ($ok === false || $code !== 200) {
        @unlink($destAbsolute);

        return false;
    }

    return true;
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
 * @return bool
 */
function madisHfmetarBulkWriteRefreshMeta(int $fetchedAt, string $fileHour, int $written, int $scanned): bool
{
    madisHfmetarBulkEnsureDirectories();
    $path = getMadisHfmetarMetaPath();
    $payload = json_encode([
        'fetched_at' => $fetchedAt,
        'file_hour' => $fileHour,
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
    $httpOk = madisHfmetarBulkDownloadCurrentHourGz($gzTmp, $downloadHttpCode);
    $result['http_ok'] = $httpOk;
    if ($downloadHttpCode !== null) {
        $result['http_code'] = $downloadHttpCode;
    }
    if (!$httpOk) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        @unlink($gzTmp);
        weatherHealthTrackMadisHfmetarBulkDownloadFailure($downloadHttpCode);
        aviationwx_log('warning', 'madis_hfmetar_bulk: download failed', [
            'url' => madisHfmetarCurrentHourFileUrl($now),
            'http_code' => $downloadHttpCode,
        ], 'app');

        return $result;
    }
    weatherHealthTrackMadisHfmetarBulkDownloadSuccess();

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
    $metaWritten = madisHfmetarBulkWriteRefreshMeta($now, $fileHour, $result['written'], $result['scanned']);
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
