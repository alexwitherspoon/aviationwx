<?php

/**
 * OurAirports and runway merge refresh policy helpers.
 */

require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/locks.php';
require_once __DIR__ . '/meta.php';
require_once __DIR__ . '/urls.php';
require_once __DIR__ . '/ingest-airports.php';

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

function ourAirportsFileProbeResult(string $fileKey): ?string
{
    $meta = ourAirportsGetFileMeta($fileKey);
    $result = $meta['last_probe_result'] ?? null;

    return is_string($result) && $result !== '' ? $result : null;
}

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

function ourAirportsBulkNeedsFetch(): bool
{
    foreach (ourAirportsCsvFileKeys() as $fileKey) {
        if (ourAirportsFileNeedsFetch($fileKey)) {
            return true;
        }
    }

    return false;
}

function ourAirportsProbeWorkerShouldRun(): bool
{
    return !ourAirportsProbeInProgress() && !ourAirportsBulkFetchInProgress();
}

function ourAirportsBulkWorkerShouldRun(): bool
{
    return ourAirportsBulkNeedsFetch()
        && !ourAirportsBulkFetchInProgress()
        && !runwaysMergeFetchInProgress();
}

function ourAirportsRunwayMergeInputsReady(): bool
{
    foreach (OURAIRPORTS_RUNWAY_MERGE_FILE_KEYS as $fileKey) {
        if (!is_readable(ourAirportsCsvPath($fileKey))) {
            return false;
        }
    }

    return true;
}

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

function faaNgdaRunwayCsvNeedsRefresh(): bool
{
    if (!is_readable(CACHE_FAA_NGDA_RUNWAYS_CSV)) {
        return true;
    }

    $age = time() - (int) filemtime(CACHE_FAA_NGDA_RUNWAYS_CSV);

    return $age >= FAA_NGDA_RUNWAY_REFRESH_MAX_AGE;
}

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

function faaNgdaRunwayCsvBodyIsValid(string $body): bool
{
    if ($body === '' || strlen($body) < 20) {
        return false;
    }

    $trimmed = ltrim($body);
    if ($trimmed !== '' && $trimmed[0] === '<') {
        return false;
    }

    $firstLine = strtok($body, "\n");

    return is_string($firstLine) && stripos($firstLine, 'ARPT_ID') !== false;
}

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

function ourAirportsRunwaySourcesProbeChanged(): bool
{
    foreach (OURAIRPORTS_RUNWAY_MERGE_FILE_KEYS as $fileKey) {
        if (ourAirportsFileProbeResult($fileKey) === 'changed') {
            return true;
        }
    }

    return false;
}

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
 * Abort runway merge when inputs look partial or the merged cache would shrink materially.
 *
 * @param array<string, mixed> $merged
 * @param array<string, mixed>|null $previousCache
 * @param array<string, array{lat: float, lon: float}> $airportCenters
 */
function runwaysMergeRejectReason(
    array $merged,
    ?array $previousCache,
    array $airportCenters,
    bool $airportsCsvExpected
): ?string {
    if ($airportsCsvExpected && $airportCenters === []) {
        return 'airports.csv present but center mapping is empty';
    }

    $newCount = count($merged);
    if ($newCount === 0) {
        return 'merged airport count is zero';
    }

    if ($previousCache === null) {
        return null;
    }

    $oldAirports = $previousCache['airports'] ?? null;
    if (!is_array($oldAirports) || $oldAirports === []) {
        return null;
    }

    $oldCount = count($oldAirports);
    $minAllowed = (int) floor($oldCount * RUNWAYS_MERGE_MIN_AIRPORT_RETAIN_RATIO);
    if ($newCount < $minAllowed) {
        return 'merged airport count below retention threshold';
    }

    return null;
}

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
 * Whether the runway merge worker should run now.
 *
 * @param bool $holdingExclusiveLock When true, skip the merge-in-progress lock check because
 *                                   the caller already holds the runways fetch lock.
 */
function runwaysMergeWorkerShouldRun(bool $holdingExclusiveLock = false): bool
{
    if (!runwaysCacheNeedsRefresh()) {
        return false;
    }

    if (!$holdingExclusiveLock && runwaysMergeFetchInProgress()) {
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
