<?php
/**
 * WiFiLogger weather ingest storage: publish latest + observation ring + registration.
 *
 * Keyed by airport + wid. latest.json and samples.jsonl hold rtd observations with
 * provider_meta.raw preserved verbatim. registration.json holds the station's first-contact
 * metadata (mac/model/firmware/coordinates/units). The wifilogger adapter parses cache;
 * this store does not publish.
 */

require_once __DIR__ . '/../bridge/store.php';
require_once __DIR__ . '/../cache-paths.php';

if (!defined('UPLOAD_WEATHER_SAMPLES_MAX_LINES')) {
    define('UPLOAD_WEATHER_SAMPLES_MAX_LINES', 7200);
}

/**
 * Persist one accepted rtd observation (latest + observation ring).
 *
 * First-wins at equal observed_unix so same-second garbage cannot replace a good sample.
 *
 * @param string $airportId Airport id
 * @param string $sourceId WiFiLogger wid (station id)
 * @param array $record Normalized record (observed_at, observed_unix, source_id, provider, provider_meta.raw)
 * @param int|null $receivedAt Receive time
 * @return bool
 */
function uploadStoreWeatherObservation(
    string $airportId,
    string $sourceId,
    array $record,
    ?int $receivedAt = null
): bool {
    $receivedAt = $receivedAt ?? time();
    $latestPath = getUploadWeatherLatestCachePath($airportId, $sourceId);
    $samplesPath = getUploadWeatherSamplesCachePath($airportId, $sourceId);
    if ($latestPath === '' || $samplesPath === '') {
        return false;
    }

    $stored = $record;
    $stored['received_at'] = gmdate('c', $receivedAt);
    $stored['airport_id'] = $airportId;

    $newObs = isset($stored['observed_unix']) && is_numeric($stored['observed_unix'])
        ? (int) $stored['observed_unix']
        : 0;
    if (!bridgeUpdateJsonFileLocked($latestPath, static function (?array $existing) use ($stored, $newObs): ?array {
        $existingObs = is_array($existing) && isset($existing['observed_unix']) && is_numeric($existing['observed_unix'])
            ? (int) $existing['observed_unix']
            : 0;
        if ($existing !== null && $newObs <= $existingObs) {
            return null;
        }
        return $stored;
    })) {
        return false;
    }

    return bridgeAppendJsonlRing($samplesPath, $stored, UPLOAD_WEATHER_SAMPLES_MAX_LINES);
}

/**
 * Persist a registration message (first-contact station metadata).
 *
 * @param string $airportId Airport id
 * @param string $sourceId WiFiLogger wid
 * @param array $registration Registration record
 * @return bool
 */
function uploadStoreRegistration(string $airportId, string $sourceId, array $registration): bool
{
    $path = getUploadRegistrationCachePath($airportId, $sourceId);
    return $path !== '' && bridgeWriteJsonFile($path, $registration);
}

/**
 * Load the most recent registration message for a WiFiLogger station.
 *
 * @param string $airportId Airport id
 * @param string $sourceId WiFiLogger wid
 * @return array|null
 */
function uploadLoadRegistration(string $airportId, string $sourceId): ?array
{
    $path = getUploadRegistrationCachePath($airportId, $sourceId);
    return $path !== '' ? bridgeReadJsonFile($path) : null;
}

/**
 * Read a JSON cache file under a shared lock.
 *
 * latest.json is rewritten in place (truncate + write) under an exclusive lock,
 * so an unlocked read can observe empty or partial JSON mid-write and briefly
 * omit a healthy source. A shared lock waits out the writer.
 *
 * @param string $path Absolute file path
 * @return array|null Decoded array or null
 */
function uploadReadJsonFileShared(string $path): ?array
{
    if ($path === '' || !is_readable($path)) {
        return null;
    }
    $fp = @fopen($path, 'r');
    if ($fp === false) {
        return null;
    }
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return null;
    }
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Load latest weather observation for a WiFiLogger station.
 *
 * @param string $airportId Airport id
 * @param string $sourceId WiFiLogger wid
 * @return array|null
 */
function uploadLoadWeatherLatest(string $airportId, string $sourceId): ?array
{
    $path = getUploadWeatherLatestCachePath($airportId, $sourceId);
    return $path !== '' ? uploadReadJsonFileShared($path) : null;
}