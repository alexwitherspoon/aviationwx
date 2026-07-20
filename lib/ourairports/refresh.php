<?php

/**
 * OurAirports and runway merge refresh policy helpers.
 */

require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/locks.php';
require_once __DIR__ . '/meta.php';
require_once __DIR__ . '/urls.php';

/** File keys that feed scripts/fetch-runways.php merge inputs. */
const OURAIRPORTS_RUNWAY_MERGE_FILE_KEYS = ['airports', 'runways'];

/**
 * Probe results that require a bulk fetch retry until upstream recovers.
 *
 * @return list<string>
 */
function ourAirportsProbeResultsRequiringFetch(): array
{
    return ['changed', 'error'];
}

/**
 * Return last_probe_result for a file key, if set.
 */
function ourAirportsFileProbeResult(string $fileKey): ?string
{
    $meta = ourAirportsGetFileMeta($fileKey);
    $result = $meta['last_probe_result'] ?? null;

    return is_string($result) && $result !== '' ? $result : null;
}

/**
 * Whether a raw OurAirports CSV should be fetched.
 */
function ourAirportsFileNeedsFetch(string $fileKey): bool
{
    if (!ourAirportsIsValidFileKey($fileKey)) {
        return false;
    }

    $path = ourAirportsCsvPath($fileKey);
    if (!is_readable($path)) {
        return true;
    }

    $age = time() - (int) filemtime($path);
    if ($age >= OURAIRPORTS_BULK_HARD_MAX_AGE) {
        return true;
    }

    $probeResult = ourAirportsFileProbeResult($fileKey);
    if ($probeResult !== null && in_array($probeResult, ourAirportsProbeResultsRequiringFetch(), true)) {
        return true;
    }

    return false;
}

/**
 * Whether merge should wait for bulk fetch on this CSV.
 *
 * Probe errors keep bulk retrying but do not block merge when on-disk bytes are still within policy age.
 */
function ourAirportsFileBlocksRunwayMerge(string $fileKey): bool
{
    if (!ourAirportsIsValidFileKey($fileKey)) {
        return true;
    }

    $path = ourAirportsCsvPath($fileKey);
    if (!is_readable($path)) {
        return true;
    }

    $age = time() - (int) filemtime($path);
    if ($age >= OURAIRPORTS_BULK_HARD_MAX_AGE) {
        return true;
    }

    return ourAirportsFileProbeResult($fileKey) === 'changed';
}

/**
 * True when any OurAirports bulk CSV needs fetch.
 */
function ourAirportsBulkNeedsFetch(): bool
{
    foreach (ourAirportsCsvFileKeys() as $fileKey) {
        if (ourAirportsFileNeedsFetch($fileKey)) {
            return true;
        }
    }

    return false;
}

/**
 * True when the scheduler should spawn the OurAirports probe worker.
 */
function ourAirportsProbeWorkerShouldRun(): bool
{
    return !ourAirportsProbeInProgress() && !ourAirportsBulkFetchInProgress();
}

/**
 * True when the scheduler should spawn the bulk fetch worker.
 */
function ourAirportsBulkWorkerShouldRun(): bool
{
    return ourAirportsBulkNeedsFetch() && !ourAirportsBulkFetchInProgress();
}

/**
 * Whether merge worker inputs (airports + runways CSV) are on disk.
 */
function ourAirportsRunwayMergeInputsReady(): bool
{
    foreach (OURAIRPORTS_RUNWAY_MERGE_FILE_KEYS as $fileKey) {
        if (!is_readable(ourAirportsCsvPath($fileKey))) {
            return false;
        }
    }

    return true;
}

/**
 * True when runway merge CSV inputs are on disk and bulk fetch is not pending for them.
 */
function ourAirportsRunwayMergeInputsCurrent(): bool
{
    if (!ourAirportsRunwayMergeInputsReady()) {
        return false;
    }

    foreach (OURAIRPORTS_RUNWAY_MERGE_FILE_KEYS as $fileKey) {
        if (ourAirportsFileBlocksRunwayMerge($fileKey)) {
            return false;
        }
    }

    return true;
}

/**
 * True when runway merge depends on a completed OurAirports bulk fetch.
 */
function ourAirportsRunwayMergeDependsOnBulkFetch(): bool
{
    if (!is_readable(CACHE_RUNWAYS_DATA_FILE)) {
        return true;
    }

    if (ourAirportsRunwaySourcesProbeChanged()) {
        return true;
    }

    if (ourAirportsRunwaySourcesNewerThanMerge()) {
        return true;
    }

    return false;
}

/**
 * Whether cached FAA NGDA runway CSV should be re-downloaded.
 */
function faaNgdaRunwayCsvNeedsRefresh(): bool
{
    if (!is_readable(CACHE_FAA_NGDA_RUNWAYS_CSV)) {
        return true;
    }

    $age = time() - (int) filemtime(CACHE_FAA_NGDA_RUNWAYS_CSV);

    return $age >= FAA_NGDA_RUNWAY_REFRESH_MAX_AGE;
}

/**
 * Record an FAA NGDA runway CSV download attempt for merge backoff.
 */
function faaNgdaRecordFetchAttempt(bool $succeeded): void
{
    $saved = ourAirportsWithMetaLock(static function () use ($succeeded): bool {
        $meta = ourAirportsLoadMeta();
        $ngda = isset($meta['faa_ngda']) && is_array($meta['faa_ngda']) ? $meta['faa_ngda'] : [];
        $ngda['last_fetch_attempt_at'] = time();
        if ($succeeded) {
            $ngda['last_fetch_succeeded_at'] = time();
            unset($ngda['last_fetch_error']);
        } else {
            $ngda['last_fetch_error'] = 'download_failed';
        }
        $meta['faa_ngda'] = $ngda;

        return ourAirportsSaveMeta($meta);
    });

    if ($saved !== true) {
        aviationwx_log('warning', 'faa_ngda: failed to record fetch attempt in meta', [], 'app');
    }
}

/**
 * True when an overdue FAA NGDA refresh should spawn the runway merge worker.
 *
 * Failed downloads back off so the scheduler does not respawn merge every minute.
 */
function faaNgdaOverdueRefreshShouldTriggerMerge(): bool
{
    if (!faaNgdaRunwayCsvNeedsRefresh()) {
        return false;
    }

    $meta = ourAirportsLoadMeta();
    $ngda = isset($meta['faa_ngda']) && is_array($meta['faa_ngda']) ? $meta['faa_ngda'] : [];
    $lastAttempt = isset($ngda['last_fetch_attempt_at']) ? (int) $ngda['last_fetch_attempt_at'] : 0;
    if ($lastAttempt <= 0) {
        return true;
    }

    return (time() - $lastAttempt) >= FAA_NGDA_FETCH_RETRY_INTERVAL;
}

/**
 * True when OurAirports probe meta for runway merge inputs requires bulk or merge action.
 */
function ourAirportsRunwaySourcesProbeNeedsAction(): bool
{
    foreach (OURAIRPORTS_RUNWAY_MERGE_FILE_KEYS as $fileKey) {
        $probeResult = ourAirportsFileProbeResult($fileKey);
        if ($probeResult !== null && in_array($probeResult, ourAirportsProbeResultsRequiringFetch(), true)) {
            return true;
        }
    }

    return false;
}

/**
 * True when OurAirports probe meta indicates runway-related upstream content changed.
 */
function ourAirportsRunwaySourcesProbeChanged(): bool
{
    foreach (OURAIRPORTS_RUNWAY_MERGE_FILE_KEYS as $fileKey) {
        if (ourAirportsFileProbeResult($fileKey) === 'changed') {
            return true;
        }
    }

    return false;
}

/**
 * True when a runway merge input CSV is newer than the merged runway cache.
 */
function ourAirportsRunwaySourcesNewerThanMerge(): bool
{
    if (!is_readable(CACHE_RUNWAYS_DATA_FILE)) {
        return false;
    }

    $mergeMtime = (int) filemtime(CACHE_RUNWAYS_DATA_FILE);
    foreach (OURAIRPORTS_RUNWAY_MERGE_FILE_KEYS as $fileKey) {
        $path = ourAirportsCsvPath($fileKey);
        if (is_readable($path) && (int) filemtime($path) > $mergeMtime) {
            return true;
        }
    }

    return false;
}

/**
 * True when the cached FAA NGDA CSV is newer than the merged runway cache.
 */
function faaNgdaRunwayCsvNewerThanMerge(): bool
{
    if (!is_readable(CACHE_RUNWAYS_DATA_FILE) || !is_readable(CACHE_FAA_NGDA_RUNWAYS_CSV)) {
        return false;
    }

    return (int) filemtime(CACHE_FAA_NGDA_RUNWAYS_CSV) > (int) filemtime(CACHE_RUNWAYS_DATA_FILE);
}

/**
 * True when another runway merge worker holds the lock.
 */
function runwaysMergeFetchInProgress(): bool
{
    return ourAirportsLockIsHeld(getRunwaysFetchLockPath());
}

/**
 * True when scripts/fetch-runways.php should rebuild runways_data.json.
 */
function runwaysCacheNeedsRefresh(): bool
{
    if (!is_readable(CACHE_RUNWAYS_DATA_FILE)) {
        return true;
    }

    $age = time() - (int) filemtime(CACHE_RUNWAYS_DATA_FILE);
    if ($age >= RUNWAYS_CACHE_MAX_AGE) {
        return true;
    }

    if (ourAirportsRunwaySourcesProbeNeedsAction()) {
        return true;
    }

    if (ourAirportsRunwaySourcesNewerThanMerge()) {
        return true;
    }

    if (faaNgdaOverdueRefreshShouldTriggerMerge()) {
        return true;
    }

    if (faaNgdaRunwayCsvNewerThanMerge()) {
        return true;
    }

    return false;
}

/**
 * True when the scheduler should spawn the runway merge worker.
 */
function runwaysMergeWorkerShouldRun(): bool
{
    if (!runwaysCacheNeedsRefresh()) {
        return false;
    }

    if (runwaysMergeFetchInProgress()) {
        return false;
    }

    if (ourAirportsBulkFetchInProgress()) {
        return false;
    }

    if (ourAirportsRunwayMergeDependsOnBulkFetch() && !ourAirportsRunwayMergeInputsCurrent()) {
        return false;
    }

    return true;
}

/**
 * Human-readable reason runway merge is waiting, when refresh is due but merge cannot run.
 */
function runwaysMergeWaitingReason(): ?string
{
    if (!runwaysCacheNeedsRefresh()) {
        return null;
    }

    if (runwaysMergeFetchInProgress()) {
        return 'merge worker in progress';
    }

    if (ourAirportsBulkFetchInProgress()) {
        return 'OurAirports bulk fetch in progress';
    }

    if (!ourAirportsRunwayMergeInputsReady()) {
        return 'waiting for OurAirports runway CSV inputs';
    }

    foreach (OURAIRPORTS_RUNWAY_MERGE_FILE_KEYS as $fileKey) {
        if (ourAirportsFileBlocksRunwayMerge($fileKey)) {
            return 'waiting for OurAirports bulk fetch on ' . $fileKey;
        }
    }

    return null;
}
