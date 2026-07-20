<?php
/**
 * NASR APT Data Fetcher
 *
 * Downloads FAA 28-day NASR APT CSV group, parses runway performance fields,
 * and writes cache/nasr/nasr_apt.json plus nasr_meta.json.
 *
 * Usage: php scripts/fetch-nasr-apt.php [--force]
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/worker-timeout.php';
require_once __DIR__ . '/../lib/file-locks.php';
require_once __DIR__ . '/../lib/nasr/parse.php';
require_once __DIR__ . '/../lib/nasr/cache.php';
require_once __DIR__ . '/../lib/nasr/discovery.php';
require_once __DIR__ . '/../lib/nasr/util.php';

@ini_set('memory_limit', '1024M');

/**
 * Download and extract APT CSV group to a temp directory.
 *
 * @return array{
 *   dir: string,
 *   source_url: string,
 *   effective_date: ?string,
 *   discovery_source: string,
 *   tracked_next_cycle_date: ?string,
 *   known_cycle_dates: list<string>
 * }|null
 */
function downloadNasrAptCsvDirectory(): ?array
{
    $cachedMeta = loadNasrAptMeta();
    $plans = buildNasrAptDownloadPlans(null, $cachedMeta);
    if ($plans === []) {
        return null;
    }

    $tmpRoot = sys_get_temp_dir() . '/aviationwx-nasr-apt-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (!@mkdir($tmpRoot, 0700, true) && !is_dir($tmpRoot)) {
        return null;
    }

    foreach ($plans as $plan) {
        $url = $plan['source_url'];
        $zipPath = $tmpRoot . '/apt.zip';
        if (!nasrHttpDownloadToFile($url, $zipPath)) {
            continue;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            continue;
        }

        $extractDir = $tmpRoot . '/csv';
        @mkdir($extractDir, 0700, true);
        if (!nasrExtractAllowlistedAptCsvFromZip($zip, $extractDir)) {
            $zip->close();
            continue;
        }
        $zip->close();

        if (!is_readable($extractDir . '/APT_BASE.csv')
            || !is_readable($extractDir . '/APT_RWY.csv')
            || !is_readable($extractDir . '/APT_RWY_END.csv')) {
            continue;
        }

        return [
            'dir' => $extractDir,
            'source_url' => $url,
            'effective_date' => $plan['effective_date'],
            'discovery_source' => $plan['discovery_source'] ?? 'unknown',
            'tracked_next_cycle_date' => $plan['tracked_next_cycle_date'] ?? null,
            'known_cycle_dates' => $plan['known_cycle_dates'] ?? [],
        ];
    }

    nasrCleanupDirectory($tmpRoot);
    return null;
}

/**
 * Extract only NASR APT CSV files from a zip, rejecting path traversal entries.
 *
 * @param ZipArchive $zip Open archive
 * @param string $extractDir Destination directory (flat; no subpaths)
 */
function nasrExtractAllowlistedAptCsvFromZip(ZipArchive $zip, string $extractDir): bool
{
    $allowed = ['APT_BASE.csv', 'APT_RWY.csv', 'APT_RWY_END.csv'];
    $written = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if (!is_string($entry) || $entry === '') {
            continue;
        }
        $normalized = str_replace('\\', '/', $entry);
        if (str_starts_with($normalized, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
            continue;
        }
        $base = basename($normalized);
        if (!in_array($base, $allowed, true)) {
            continue;
        }

        $stream = $zip->getStream($entry);
        if ($stream === false) {
            return false;
        }
        $dest = $extractDir . '/' . $base;
        $destHandle = @fopen($dest, 'wb');
        if ($destHandle === false) {
            fclose($stream);
            return false;
        }
        $copied = stream_copy_to_stream($stream, $destHandle);
        fclose($stream);
        fclose($destHandle);
        if ($copied === false) {
            return false;
        }
        $written[$base] = true;
    }

    foreach ($allowed as $name) {
        if (empty($written[$name])) {
            return false;
        }
    }

    return true;
}

/**
 * Run NASR APT fetch when cache is missing or older than NASR_CACHE_MAX_AGE.
 *
 * @return bool True when cache exists after run (including skipped fresh cache)
 */
function fetchNasrAptIfNeeded(bool $force = false): bool
{
    if (!$force && is_readable(CACHE_NASR_APT_DATA_FILE) && !nasrAptCacheNeedsRefresh()) {
        return true;
    }

    $lockPath = getNasrAptFetchLockPath();
    $lock = acquireExclusiveFileLock($lockPath);
    if ($lock === false) {
        aviationwx_log('info', 'nasr_apt: fetch skipped, lock held', [], 'app');
        return is_readable(CACHE_NASR_APT_DATA_FILE);
    }

    try {
        if (!$force && is_readable(CACHE_NASR_APT_DATA_FILE) && !nasrAptCacheNeedsRefresh()) {
            return true;
        }

        updateNasrAptMetaFields([
            'last_fetch_attempt_at' => gmdate('c'),
        ]);

        $download = downloadNasrAptCsvDirectory();
        if ($download === null) {
            $errorMessage = 'NASR APT download failed, retaining previous cache';
            updateNasrAptMetaFields([
                'last_fetch_error' => $errorMessage,
                'last_fetch_error_at' => gmdate('c'),
            ]);
            aviationwx_log('error', 'nasr_apt: download failed, retaining previous cache', [], 'app');
            return is_readable(CACHE_NASR_APT_DATA_FILE);
        }

        $extractRoot = dirname($download['dir']);
        try {
            $parsed = nasrParseAptCsvDirectory($download['dir']);
        } catch (Throwable $e) {
            $errorMessage = 'NASR APT parse failed, retaining previous cache';
            updateNasrAptMetaFields([
                'last_fetch_error' => $errorMessage,
                'last_fetch_error_at' => gmdate('c'),
            ]);
            aviationwx_log('error', 'nasr_apt: parse failed, retaining previous cache', [
                'error' => $e->getMessage(),
                'source_url' => $download['source_url'],
            ], 'app');
            nasrCleanupDirectory($extractRoot);
            return is_readable(CACHE_NASR_APT_DATA_FILE);
        }

        $airportCount = count($parsed['airports']);
        if ($airportCount < NASR_APT_MIN_AIRPORT_COUNT) {
            $errorMessage = 'NASR APT parse produced too few airports, retaining previous cache';
            updateNasrAptMetaFields([
                'last_fetch_error' => $errorMessage,
                'last_fetch_error_at' => gmdate('c'),
            ]);
            aviationwx_log('error', 'nasr_apt: airport count below minimum, retaining previous cache', [
                'airport_count' => $airportCount,
                'minimum' => NASR_APT_MIN_AIRPORT_COUNT,
                'source_url' => $download['source_url'],
            ], 'app');
            nasrCleanupDirectory($extractRoot);
            return is_readable(CACHE_NASR_APT_DATA_FILE);
        }

        $effectiveDate = $parsed['effective_date'] ?? $download['effective_date'];
        $trackedNext = $download['tracked_next_cycle_date'] ?? nasrEstimateNextCycleDate($effectiveDate);

        $meta = [
            'effective_date' => $effectiveDate,
            'tracked_current_cycle_date' => $effectiveDate,
            'tracked_next_cycle_date' => $trackedNext,
            'known_cycle_dates' => $download['known_cycle_dates'],
            'discovery_source' => $download['discovery_source'],
            'last_discovery_at' => gmdate('c'),
            'source_urls' => [$download['source_url']],
            'fetched_at' => gmdate('c'),
            'last_fetch_error' => null,
            'last_fetch_error_at' => null,
            'row_counts' => [
                'airports' => $airportCount,
            ],
        ];

        $saved = saveNasrAptCache($parsed['airports'], $meta);
        if (!$saved) {
            aviationwx_log('error', 'nasr_apt: failed to write cache', [], 'app');
            nasrCleanupDirectory($extractRoot);
            return is_readable(CACHE_NASR_APT_DATA_FILE);
        }

        aviationwx_log('info', 'nasr_apt: cache updated', [
            'airport_count' => $airportCount,
            'effective_date' => $effectiveDate,
            'source_url' => $download['source_url'],
            'discovery_source' => $download['discovery_source'],
            'tracked_next_cycle_date' => $trackedNext,
        ], 'app');

        nasrCleanupDirectory($extractRoot);
        return true;
    } finally {
        releaseExclusiveFileLock($lock, $lockPath);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    initWorkerTimeout(NASR_APT_WORKER_TIMEOUT, 'nasr_apt');
    $force = in_array('--force', $argv ?? [], true);
    $ok = fetchNasrAptIfNeeded($force);
    exit($ok ? 0 : 1);
}
