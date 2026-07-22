<?php
/**
 * NASR FRQ Data Fetcher
 *
 * Downloads FAA 28-day NASR FRQ.csv, parses pilot-facing airport frequencies,
 * and writes cache/nasr/nasr_frq.json (plus configured slice).
 *
 * Usage: php scripts/fetch-nasr-frq.php [--force]
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/worker-timeout.php';
require_once __DIR__ . '/../lib/file-locks.php';
require_once __DIR__ . '/../lib/nasr/frequencies-parse.php';
require_once __DIR__ . '/../lib/nasr/frequencies-cache.php';
require_once __DIR__ . '/../lib/nasr/discovery.php';
require_once __DIR__ . '/../lib/nasr/util.php';
require_once __DIR__ . '/../lib/nasr/csv-validation.php';

/**
 * Download and extract FRQ.csv to a temp directory.
 *
 * @return array{
 *   dir: string,
 *   source_url: string,
 *   effective_date: ?string,
 *   discovery_source: string,
 *   tmp_root: string
 * }|null
 */
function downloadNasrFrqCsvDirectory(): ?array
{
    $cachedMeta = loadNasrAptMeta();
    $plans = buildNasrAptDownloadPlans(null, $cachedMeta);
    if ($plans === []) {
        return null;
    }

    $plan = $plans[0];
    $effectiveDate = $plan['effective_date'] ?? null;
    if ($effectiveDate === null) {
        return null;
    }

    $url = buildNasrFrqZipUrl($effectiveDate);
    if ($url === '') {
        return null;
    }

    $tmpRoot = sys_get_temp_dir() . '/aviationwx-nasr-frq-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $extractDir = $tmpRoot . '/csv';
    if (!@mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
        return null;
    }

    $zipPath = $tmpRoot . '/frq.zip';
    if (!nasrHttpDownloadToFile($url, $zipPath)) {
        nasrCleanupDirectory($tmpRoot);
        return null;
    }

    if (!nasrDownloadedZipFileIsValid($zipPath)) {
        aviationwx_log('warning', 'nasr_frq: rejected invalid zip download', [
            'source_url' => $url,
            'zip_bytes' => @filesize($zipPath),
        ], 'app');
        nasrCleanupDirectory($tmpRoot);
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        nasrCleanupDirectory($tmpRoot);
        return null;
    }

    $extracted = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!is_string($name) || basename($name) !== 'FRQ.csv') {
            continue;
        }
        $contents = $zip->getFromIndex($i);
        if ($contents === false) {
            break;
        }
        if (file_put_contents($extractDir . '/FRQ.csv', $contents, LOCK_EX) === false) {
            break;
        }
        $extracted = true;
        break;
    }
    $zip->close();

    if (!$extracted || !is_readable($extractDir . '/FRQ.csv')) {
        nasrCleanupDirectory($tmpRoot);
        return null;
    }

    if (!nasrFrqCsvFileIsValid($extractDir . '/FRQ.csv')) {
        aviationwx_log('warning', 'nasr_frq: rejected invalid CSV extract', [
            'source_url' => $url,
        ], 'app');
        nasrCleanupDirectory($tmpRoot);
        return null;
    }

    return [
        'dir' => $extractDir,
        'source_url' => $url,
        'effective_date' => $effectiveDate,
        'discovery_source' => $plan['discovery_source'] ?? 'apt_plan',
        'tmp_root' => $tmpRoot,
    ];
}

/**
 * Fetch NASR FRQ when cache is missing or stale.
 */
function fetchNasrFrqIfNeeded(bool $force = false): bool
{
    if (!$force && is_readable(CACHE_NASR_FRQ_DATA_FILE) && !nasrFrqCacheNeedsRefresh()) {
        return true;
    }

    $lockPath = getNasrFrqFetchLockPath();
    $lock = acquireExclusiveFileLock($lockPath);
    if ($lock === false) {
        aviationwx_log('info', 'nasr_frq: fetch skipped, lock held', [], 'app');
        return is_readable(CACHE_NASR_FRQ_DATA_FILE);
    }

    try {
        if (!$force && is_readable(CACHE_NASR_FRQ_DATA_FILE) && !nasrFrqCacheNeedsRefresh()) {
            return true;
        }

        $download = downloadNasrFrqCsvDirectory();
        if ($download === null) {
            aviationwx_log('error', 'nasr_frq: download failed, retaining previous cache', [], 'app');
            updateNasrAptMetaFields([
                'frq_last_fetch_error' => 'download_failed',
                'frq_last_fetch_error_at' => gmdate('c'),
            ]);
            return is_readable(CACHE_NASR_FRQ_DATA_FILE);
        }

        $tmpRoot = $download['tmp_root'];
        unset($download['tmp_root']);

        try {
            $parsed = nasrParseFrqCsvFile($download['dir'] . '/FRQ.csv');
        } catch (Throwable $e) {
            aviationwx_log('error', 'nasr_frq: parse failed, retaining previous cache', [
                'error' => $e->getMessage(),
            ], 'app');
            updateNasrAptMetaFields([
                'frq_last_fetch_error' => 'parse_failed',
                'frq_last_fetch_error_at' => gmdate('c'),
            ]);
            nasrCleanupDirectory($tmpRoot);
            return is_readable(CACHE_NASR_FRQ_DATA_FILE);
        }

        $airportCount = count($parsed['airports']);
        if ($airportCount < NASR_FRQ_MIN_AIRPORT_COUNT) {
            aviationwx_log('error', 'nasr_frq: airport count below minimum, retaining previous cache', [
                'airport_count' => $airportCount,
                'minimum' => NASR_FRQ_MIN_AIRPORT_COUNT,
                'source_url' => $download['source_url'],
            ], 'app');
            updateNasrAptMetaFields([
                'frq_last_fetch_error' => 'airport_count_below_minimum',
                'frq_last_fetch_error_at' => gmdate('c'),
            ]);
            nasrCleanupDirectory($tmpRoot);
            return is_readable(CACHE_NASR_FRQ_DATA_FILE);
        }

        $meta = [
            'frq_effective_date' => $parsed['effective_date'] ?? $download['effective_date'],
            'frq_source_urls' => [$download['source_url']],
            'frq_discovery_source' => $download['discovery_source'],
            'frq_last_discovery_at' => gmdate('c'),
        ];

        $saved = saveNasrFrqCache($parsed['airports'], $meta, $parsed['pairing'] ?? []);
        if (!$saved) {
            aviationwx_log('error', 'nasr_frq: failed to write cache', [], 'app');
            nasrCleanupDirectory($tmpRoot);
            return is_readable(CACHE_NASR_FRQ_DATA_FILE);
        }

        aviationwx_log('info', 'nasr_frq: cache updated', [
            'airport_count' => $airportCount,
            'effective_date' => $meta['frq_effective_date'],
            'source_url' => $download['source_url'],
        ], 'app');

        nasrCleanupDirectory($tmpRoot);
        return true;
    } finally {
        releaseExclusiveFileLock($lock, $lockPath);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    initWorkerTimeout(NASR_FRQ_WORKER_TIMEOUT, 'nasr_frq');
    $force = in_array('--force', $argv ?? [], true);
    $ok = fetchNasrFrqIfNeeded($force);
    exit($ok ? 0 : 1);
}
