<?php
/**
 * NASR APT Data Fetcher
 *
 * Downloads FAA 28-day NASR APT CSV group, parses runway performance fields,
 * and writes cache/nasr/nasr_apt.json plus nasr_meta.json.
 *
 * Usage: php scripts/fetch-nasr-apt.php
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/nasr/parse.php';
require_once __DIR__ . '/../lib/nasr/cache.php';

@ini_set('memory_limit', '512M');

/**
 * Acquire fetch lock; return handle or false.
 *
 * @return resource|false
 */
function acquireNasrAptFetchLock()
{
    $lockPath = getNasrAptFetchLockPath();
    $dir = dirname($lockPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    if (file_exists($lockPath)) {
        $age = time() - filemtime($lockPath);
        if ($age > FILE_LOCK_STALE_SECONDS) {
            @unlink($lockPath);
        }
    }

    $fp = @fopen($lockPath, 'c+');
    if (!$fp) {
        return false;
    }
    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    return $fp;
}

/**
 * Download URL to string.
 */
function nasrDownloadUrl(string $url): ?string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'AviationWX NASR Fetcher/1.0',
    ]);
    $content = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($content === false || $httpCode !== 200) {
        return null;
    }
    return $content;
}

/**
 * Build NASR APT zip URL for an effective date (YYYY-MM-DD).
 */
function buildNasrAptZipUrl(string $dateYmd): string
{
    return 'https://nfdc.faa.gov/webContent/28DaySub/extra/' . $dateYmd . '_APT_CSV.zip';
}

/**
 * Candidate effective dates to try (newest first).
 *
 * @return list<string>
 */
function nasrAptZipCandidateDates(): array
{
    $dates = [];
    $meta = null;
    if (is_readable(CACHE_NASR_APT_META_FILE)) {
        $decoded = json_decode((string) file_get_contents(CACHE_NASR_APT_META_FILE), true);
        if (is_array($decoded) && !empty($decoded['effective_date'])) {
            $meta = (string) $decoded['effective_date'];
        }
    }

    if ($meta !== null) {
        $dates[] = $meta;
    }
    $dates[] = NASR_APT_ZIP_FALLBACK_DATE;

    $now = time();
    for ($offsetDays = 0; $offsetDays <= 84; $offsetDays += 28) {
        $dates[] = gmdate('Y-m-d', $now - ($offsetDays * 86400));
    }

    $unique = [];
    foreach ($dates as $date) {
        if (!in_array($date, $unique, true)) {
            $unique[] = $date;
        }
    }
    return $unique;
}

/**
 * Download and extract APT CSV group to a temp directory.
 *
 * @return array{dir: string, source_url: string, effective_date: ?string}|null
 */
function downloadNasrAptCsvDirectory(): ?array
{
    $tmpRoot = sys_get_temp_dir() . '/aviationwx-nasr-apt-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (!@mkdir($tmpRoot, 0700, true) && !is_dir($tmpRoot)) {
        return null;
    }

    foreach (nasrAptZipCandidateDates() as $dateYmd) {
        $url = buildNasrAptZipUrl($dateYmd);
        $zipBytes = nasrDownloadUrl($url);
        if ($zipBytes === null) {
            continue;
        }

        $zipPath = $tmpRoot . '/apt.zip';
        if (file_put_contents($zipPath, $zipBytes) === false) {
            continue;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            continue;
        }

        $extractDir = $tmpRoot . '/csv';
        @mkdir($extractDir, 0700, true);
        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            continue;
        }
        $zip->close();

        if (!is_readable($extractDir . '/APT_RWY.csv') || !is_readable($extractDir . '/APT_RWY_END.csv')) {
            continue;
        }

        return [
            'dir' => $extractDir,
            'source_url' => $url,
            'effective_date' => $dateYmd,
        ];
    }

    nasrCleanupDirectory($tmpRoot);
    return null;
}

/**
 * Recursively remove a directory.
 */
function nasrCleanupDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            nasrCleanupDirectory($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
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

    $lock = acquireNasrAptFetchLock();
    if ($lock === false) {
        aviationwx_log('info', 'nasr_apt: fetch skipped, lock held', [], 'app');
        return is_readable(CACHE_NASR_APT_DATA_FILE);
    }

    try {
        if (!$force && is_readable(CACHE_NASR_APT_DATA_FILE) && !nasrAptCacheNeedsRefresh()) {
            return true;
        }

        $download = downloadNasrAptCsvDirectory();
        if ($download === null) {
            aviationwx_log('error', 'nasr_apt: download failed, retaining previous cache', [], 'app');
            return is_readable(CACHE_NASR_APT_DATA_FILE);
        }

        $parsed = nasrParseAptCsvDirectory($download['dir']);
        $effectiveDate = $parsed['effective_date'] ?? $download['effective_date'];

        $meta = [
            'effective_date' => $effectiveDate,
            'source_urls' => [$download['source_url']],
            'fetched_at' => gmdate('c'),
            'row_counts' => [
                'airports' => count($parsed['airports']),
            ],
        ];

        $saved = saveNasrAptCache($parsed['airports'], $meta);
        if (!$saved) {
            aviationwx_log('error', 'nasr_apt: failed to write cache', [], 'app');
            return is_readable(CACHE_NASR_APT_DATA_FILE);
        }

        aviationwx_log('info', 'nasr_apt: cache updated', [
            'airport_count' => count($parsed['airports']),
            'effective_date' => $effectiveDate,
        ], 'app');

        nasrCleanupDirectory(dirname($download['dir']));
        return true;
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $force = in_array('--force', $argv ?? [], true);
    $ok = fetchNasrAptIfNeeded($force);
    exit($ok ? 0 : 1);
}
