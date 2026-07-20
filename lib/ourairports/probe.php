<?php

/**
 * OurAirports upstream HEAD probes (ETag change detection).
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/locks.php';
require_once __DIR__ . '/meta.php';
require_once __DIR__ . '/urls.php';

/**
 * Resolve probe outcome from HEAD response and local cache state.
 *
 * `etag` in meta always reflects the last successful on-disk fetch; probe never overwrites it.
 * When upstream omits ETag, Last-Modified is compared before marking changed.
 *
 * @return 'changed'|'unchanged'|'error'
 */
function ourAirportsResolveProbeResult(
    bool $headOk,
    ?string $storedFetchEtag,
    ?string $remoteEtag,
    bool $fileMissing,
    ?string $storedLastModified = null,
    ?string $remoteLastModified = null
): string {
    if (!$headOk) {
        return 'error';
    }

    if ($fileMissing) {
        return 'changed';
    }

    if ($remoteEtag !== null) {
        if ($storedFetchEtag !== null && $remoteEtag !== $storedFetchEtag) {
            return 'changed';
        }

        if ($storedFetchEtag === null) {
            return 'changed';
        }

        return 'unchanged';
    }

    $storedLm = ourAirportsNormalizeLastModified($storedLastModified);
    $remoteLm = ourAirportsNormalizeLastModified($remoteLastModified);
    if ($storedLm !== null && $remoteLm !== null) {
        return $storedLm === $remoteLm ? 'unchanged' : 'changed';
    }

    return 'changed';
}

/**
 * Probe one OurAirports CSV and update meta.
 *
 * @return array{file_key: string, result: string, http_code: ?int, skipped: bool}
 */
function ourAirportsProbeFile(string $fileKey): array
{
    if (!ourAirportsIsValidFileKey($fileKey)) {
        return ['file_key' => $fileKey, 'result' => 'error', 'http_code' => null, 'skipped' => false];
    }

    if (ourAirportsBulkFetchInProgress()) {
        return ['file_key' => $fileKey, 'result' => 'unchanged', 'http_code' => null, 'skipped' => true];
    }

    $current = ourAirportsGetFileMeta($fileKey);
    $response = ourAirportsHttpHead(ourAirportsCsvUrl($fileKey));
    $now = time();
    $path = ourAirportsCsvPath($fileKey);
    $fileMissing = !is_readable($path);
    $storedFetchEtag = ourAirportsNormalizeEtag(is_string($current['etag'] ?? null) ? $current['etag'] : null);
    $remoteEtag = $response['etag'];
    $storedLastModified = is_string($current['last_modified'] ?? null) ? $current['last_modified'] : null;

    $result = ourAirportsResolveProbeResult(
        $response['ok'],
        $storedFetchEtag,
        $remoteEtag,
        $fileMissing,
        $storedLastModified,
        $response['last_modified']
    );

    $updates = [
        'last_probe_at' => $now,
        'last_probe_result' => $result,
        'upstream_etag' => $remoteEtag,
    ];
    if ($response['last_modified'] !== null) {
        $updates['last_modified'] = ourAirportsNormalizeLastModified($response['last_modified']);
    }

    ourAirportsUpdateFileMeta($fileKey, $updates);

    return [
        'file_key' => $fileKey,
        'result' => $result,
        'http_code' => $response['http_code'],
        'skipped' => false,
    ];
}

/**
 * Probe all configured OurAirports CSV files.
 *
 * @return list<array{file_key: string, result: string, http_code: ?int, skipped: bool}>
 */
function ourAirportsProbeAll(): array
{
    $fp = ourAirportsAcquireExclusiveLock(CACHE_OURAIRPORTS_PROBE_LOCK);
    if ($fp === false) {
        return [];
    }

    try {
        if (ourAirportsBulkFetchInProgress()) {
            return [];
        }

        $results = [];
        foreach (ourAirportsCsvFileKeys() as $fileKey) {
            $results[] = ourAirportsProbeFile($fileKey);
        }

        return $results;
    } finally {
        ourAirportsReleaseExclusiveLock($fp, CACHE_OURAIRPORTS_PROBE_LOCK);
    }
}
