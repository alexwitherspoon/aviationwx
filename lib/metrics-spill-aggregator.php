<?php
/**
 * Merge per-worker spill journals (JSONL) and legacy JSON shards into hourly metrics files.
 */

declare(strict_types=1);

require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/metrics-spill-journal.php';
require_once __DIR__ . '/metrics-spill-payload.php';
require_once __DIR__ . '/metrics.php';

/**
 * Run one spill merge pass under a non-blocking singleton lock.
 *
 * Respects METRICS_SPILL_MERGE_MAX_FILES_PER_RUN and METRICS_SPILL_MERGE_MAX_RUNTIME_MS.
 * When the budget is hit mid-hour, merged counters are written to the hourly file and consumed
 * spill shards are deleted so the next pass can continue the same UTC bucket (avoids stuck backlogs).
 *
 * @return array<string, mixed> Telemetry keys: lock_contended (bool), hours_touched (int),
 *         hourly_writes (int), spills_merged (int), spills_deleted (int), orphans_pruned (int),
 *         errors (list of string codes)
 */
function metrics_run_spill_aggregator_once(): array
{
    $stats = [
        'lock_contended' => false,
        'hours_touched' => 0,
        'hourly_writes' => 0,
        'spills_merged' => 0,
        'spills_deleted' => 0,
        'orphans_pruned' => 0,
        'errors' => [],
    ];

    foreach ([CACHE_METRICS_DIR, CACHE_METRICS_HOURLY_DIR, CACHE_METRICS_SPILL_DIR] as $dir) {
        if (!ensureCacheDir($dir)) {
            $stats['errors'][] = 'metrics_cache_dir_unavailable';
            aviationwx_log('error', 'metrics spill aggregator: required cache directory not available', [
                'dir' => $dir,
            ], 'app');

            return $stats;
        }
    }

    $lockPath = getMetricsAggregatorLockPath();
    $lockFp = @fopen($lockPath, 'c+');
    if ($lockFp === false) {
        $stats['errors'][] = 'aggregator_lock_open_failed';

        return $stats;
    }

    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        fclose($lockFp);
        $stats['lock_contended'] = true;

        return $stats;
    }

    $t0Ns = hrtime(true);
    $filesMergedThisRun = 0;

    try {
        $spillRoot = getMetricsSpillRootDir();
        $hourDirs = is_dir($spillRoot) ? (glob($spillRoot . '/*', GLOB_ONLYDIR) ?: []) : [];
        sort($hourDirs);

        foreach ($hourDirs as $hourDir) {
            if ($filesMergedThisRun >= METRICS_SPILL_MERGE_MAX_FILES_PER_RUN) {
                break;
            }
            if (metrics_spill_aggregator_runtime_ms($t0Ns) >= METRICS_SPILL_MERGE_MAX_RUNTIME_MS) {
                break;
            }

            $hourId = basename($hourDir);
            if (!metrics_hour_id_is_valid($hourId)) {
                continue;
            }

            $spillFiles = metrics_spill_aggregator_list_spill_paths_for_hour($hourDir);
            if ($spillFiles === []) {
                continue;
            }

            $hourFile = getMetricsHourlyPath($hourId);
            $hourData = [];
            if (file_exists($hourFile)) {
                $content = @file_get_contents($hourFile);
                if ($content !== false && $content !== '') {
                    $decoded = @json_decode($content, true);
                    $hourData = is_array($decoded) ? $decoded : [];
                }
            }

            if (!isset($hourData['bucket_type'])) {
                $hourData = metrics_new_empty_hour_bucket($hourId);
            }

            metrics_normalize_hour_bucket_for_merge($hourData, $hourId);

            $pendingDeletes = [];
            $hourHadMerges = false;
            $hourBudgetExhausted = false;

            foreach ($spillFiles as $spillPath) {
                if ($filesMergedThisRun >= METRICS_SPILL_MERGE_MAX_FILES_PER_RUN) {
                    $hourBudgetExhausted = true;
                    break;
                }
                if (metrics_spill_aggregator_runtime_ms($t0Ns) >= METRICS_SPILL_MERGE_MAX_RUNTIME_MS) {
                    $hourBudgetExhausted = true;
                    break;
                }

                $consumedPath = null;
                $journalFullyConsumed = true;
                $mergedUnits = metrics_spill_aggregator_merge_spill_path(
                    $spillPath,
                    $hourId,
                    $hourData,
                    $consumedPath,
                    $t0Ns,
                    $journalFullyConsumed
                );
                if ($mergedUnits === null || $mergedUnits < 1) {
                    continue;
                }

                if ($consumedPath !== null && $journalFullyConsumed) {
                    $pendingDeletes[] = $consumedPath;
                }
                $hourHadMerges = true;
                $filesMergedThisRun++;
                $stats['spills_merged'] += $mergedUnits;
            }

            if (!$hourHadMerges) {
                continue;
            }

            if (!metrics_spill_aggregator_write_hour_bucket($hourData, $hourFile, $hourId, $stats)) {
                continue;
            }

            $stats['hours_touched']++;
            $stats['hourly_writes']++;

            foreach ($pendingDeletes as $del) {
                if (@unlink($del)) {
                    $stats['spills_deleted']++;
                }
            }

            // rmdir succeeds only when the hour dir is empty; no glob scan (large backlogs are costly).
            @rmdir($hourDir);

            // Persist partial hour progress before stopping; next pass continues the same bucket.
            if ($hourBudgetExhausted) {
                break;
            }
        }

        metrics_spill_aggregator_prune_orphan_spills($stats, $t0Ns);
        metrics_spill_aggregator_write_last_run($stats);
    } finally {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }

    return $stats;
}

/**
 * Milliseconds elapsed since the given hrtime anchor.
 *
 * @param int|float $t0Ns Nanoseconds from hrtime(true)
 * @return float Elapsed milliseconds
 */
function metrics_spill_aggregator_runtime_ms($t0Ns): float
{
    return (hrtime(true) - $t0Ns) / 1e6;
}

/**
 * Atomically write merged hour bucket JSON to the canonical hourly metrics path.
 *
 * @param array<string, mixed> $hourData Merged bucket (last_flush set here)
 * @param string               $hourFile Target hourly JSON path
 * @param string               $hourId   UTC hour id for error telemetry
 * @param array<string, mixed> $stats    Stats array; errors[] appended on failure
 * @return bool True when the hourly file was written
 */
function metrics_spill_aggregator_write_hour_bucket(
    array $hourData,
    string $hourFile,
    string $hourId,
    array &$stats
): bool {
    $hourData['last_flush'] = time();

    $jsonPayload = json_encode($hourData, JSON_PRETTY_PRINT);
    if ($jsonPayload === false) {
        $stats['errors'][] = 'json_encode_failed:' . $hourId;

        return false;
    }

    $tmpFile = $hourFile . '.tmp.aggr.' . getmypid();
    $written = @file_put_contents($tmpFile, $jsonPayload, LOCK_EX);
    if ($written === false) {
        @unlink($tmpFile);
        $stats['errors'][] = 'hourly_tmp_write_failed:' . $hourId;

        return false;
    }

    if (!@rename($tmpFile, $hourFile)) {
        @unlink($tmpFile);
        $stats['errors'][] = 'hourly_rename_failed:' . $hourId;

        return false;
    }

    return true;
}

/**
 * List legacy .json shards, live .jsonl journals, and claimed .jsonl.merging.* files for one hour dir.
 *
 * @param string $hourDir Absolute path to spill/{hourId}
 * @return list<string> Sorted spill paths
 */
function metrics_spill_aggregator_list_spill_paths_for_hour(string $hourDir): array
{
    $legacy = glob($hourDir . '/*.json') ?: [];
    $legacy = array_values(array_filter($legacy, static function (string $p): bool {
        $base = basename($p);

        return strpos($base, '.tmp.') === false;
    }));

    $journals = glob($hourDir . '/*.jsonl') ?: [];
    $journals = array_values(array_filter($journals, static function (string $p): bool {
        return metrics_spill_path_is_worker_journal($p);
    }));

    $claimed = glob($hourDir . '/*.jsonl.merging.*') ?: [];
    $claimed = array_values(array_filter($claimed, static function (string $p): bool {
        return strpos(basename($p), '.tmp.') === false;
    }));

    $paths = array_merge($legacy, $journals, $claimed);
    sort($paths);

    return $paths;
}

/**
 * Merge one spill path (legacy JSON shard or worker JSONL journal) into hour bucket data.
 *
 * @param string               $spillPath     Absolute spill file path
 * @param string               $hourId        UTC hour bucket id
 * @param array<string, mixed> $hourData      Hourly bucket (mutated in place)
 * @param string|null          $consumedPath         Set when merge succeeded; delete after hourly write
 * @param int|float|null       $t0Ns                 Aggregator runtime anchor for per-line budget checks
 * @param bool|null            $journalFullyConsumed False when a JSONL claim still has unread lines
 * @return int|null Delta records merged (lines for JSONL, 1 for legacy shard), or null
 */
function metrics_spill_aggregator_merge_spill_path(
    string $spillPath,
    string $hourId,
    array &$hourData,
    ?string &$consumedPath = null,
    $t0Ns = null,
    ?bool &$journalFullyConsumed = null
): ?int {
    $consumedPath = null;
    if ($journalFullyConsumed !== null) {
        $journalFullyConsumed = true;
    }

    if (metrics_spill_path_is_worker_journal($spillPath)) {
        $claimed = metrics_spill_journal_claim_for_merge($spillPath);
        if ($claimed === null) {
            return null;
        }

        $consumedPath = $claimed;
        $lines = metrics_spill_journal_merge_claimed_into_hour_data(
            $claimed,
            $hourId,
            $hourData,
            $t0Ns,
            $journalFullyConsumed
        );

        return $lines > 0 ? $lines : null;
    }

    if (metrics_spill_path_is_claimed_journal($spillPath)) {
        $consumedPath = $spillPath;
        $lines = metrics_spill_journal_merge_claimed_into_hour_data(
            $spillPath,
            $hourId,
            $hourData,
            $t0Ns,
            $journalFullyConsumed
        );

        return $lines > 0 ? $lines : null;
    }

    $parsed = metrics_spill_aggregator_parse_spill_file($spillPath, $hourId);
    if ($parsed === null) {
        return null;
    }

    metrics_apply_flat_counters_to_hour_data($hourData, $parsed['counters'], true);
    $consumedPath = $spillPath;

    return 1;
}

/**
 * Read a spill shard file and return normalized counters, or null if the file is unusable.
 *
 * @param string $path Absolute path to spill JSON
 * @param string $expectedHourId Hour directory name (UTC bucket id)
 * @return array{counters: array<string, int>}|null
 */
function metrics_spill_aggregator_parse_spill_file(string $path, string $expectedHourId): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $data = @json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    return metrics_parse_spill_payload_for_merge($data, $expectedHourId);
}

/**
 * Remove stale spill artifacts that were never merged (legacy shards, journals, abandoned claims).
 *
 * @param array<string, mixed> $stats Stats array updated with orphans_pruned count
 * @param int|float $t0Ns Start time from hrtime(true); skips work when runtime budget exceeded
 * @return void
 */
function metrics_spill_aggregator_prune_orphan_spills(array &$stats, $t0Ns): void
{
    if (metrics_spill_aggregator_runtime_ms($t0Ns) >= METRICS_SPILL_MERGE_MAX_RUNTIME_MS) {
        return;
    }

    $spillRoot = getMetricsSpillRootDir();
    if (!is_dir($spillRoot)) {
        return;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($spillRoot, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (metrics_spill_aggregator_runtime_ms($t0Ns) >= METRICS_SPILL_MERGE_MAX_RUNTIME_MS) {
                break;
            }

            if (!$fileInfo->isFile()) {
                continue;
            }

            $name = $fileInfo->getFilename();
            if (strpos($name, '.tmp.') !== false) {
                continue;
            }
            $isSpillArtifact = str_ends_with($name, '.json')
                || str_ends_with($name, '.jsonl')
                || strpos($name, '.merging.') !== false;
            if (!$isSpillArtifact) {
                continue;
            }

            $age = time() - $fileInfo->getMTime();
            if ($age <= METRICS_SPILL_ORPHAN_MAX_AGE_SECONDS) {
                continue;
            }

            if (@unlink($fileInfo->getPathname())) {
                $stats['orphans_pruned']++;
            }
        }
    } catch (Throwable $e) {
        $stats['errors'][] = 'orphan_prune_iterator_failed';
        aviationwx_log('warning', 'metrics spill orphan prune iterator failed', [
            'error' => $e->getMessage(),
        ], 'app');
    }
}

/**
 * Write last-run telemetry JSON for operators (best-effort).
 *
 * @param array<string, mixed> $stats Telemetry from metrics_run_spill_aggregator_once()
 * @return void
 */
function metrics_spill_aggregator_write_last_run(array $stats): void
{
    $payload = [
        'finished_at' => time(),
        'stats' => $stats,
    ];

    $path = getMetricsAggregatorLastRunPath();
    $json = json_encode($payload, JSON_PRETTY_PRINT);
    if ($json === false) {
        return;
    }

    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $path);
    } else {
        @unlink($tmp);
    }
}
