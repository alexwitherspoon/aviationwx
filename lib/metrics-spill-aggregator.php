<?php
/**
 * Merge per-worker spill JSON snapshots into canonical hourly metrics files.
 */

declare(strict_types=1);

require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/metrics-spill-payload.php';
require_once __DIR__ . '/metrics.php';

/**
 * Run one spill merge pass under a non-blocking singleton lock.
 *
 * Respects METRICS_SPILL_MERGE_MAX_FILES_PER_RUN and METRICS_SPILL_MERGE_MAX_RUNTIME_MS.
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

    ensureCacheDir(CACHE_METRICS_DIR);
    ensureCacheDir(CACHE_METRICS_HOURLY_DIR);
    ensureCacheDir(CACHE_METRICS_SPILL_DIR);

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
            if (!preg_match('/^\d{4}-\d{2}-\d{2}-\d{2}$/', $hourId)) {
                continue;
            }

            $spillFiles = glob($hourDir . '/*.json') ?: [];
            $spillFiles = array_values(array_filter($spillFiles, static function (string $p): bool {
                $base = basename($p);

                return strpos($base, '.tmp.') === false;
            }));

            if ($spillFiles === []) {
                continue;
            }

            sort($spillFiles);

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

            foreach ($spillFiles as $spillPath) {
                if ($filesMergedThisRun >= METRICS_SPILL_MERGE_MAX_FILES_PER_RUN) {
                    break 2;
                }
                if (metrics_spill_aggregator_runtime_ms($t0Ns) >= METRICS_SPILL_MERGE_MAX_RUNTIME_MS) {
                    break 2;
                }

                $parsed = metrics_spill_aggregator_parse_spill_file($spillPath, $hourId);
                if ($parsed === null) {
                    continue;
                }

                metrics_apply_flat_counters_to_hour_data($hourData, $parsed['counters']);
                $pendingDeletes[] = $spillPath;
                $filesMergedThisRun++;
                $stats['spills_merged']++;
            }

            if ($pendingDeletes === []) {
                continue;
            }

            $hourData['last_flush'] = time();

            $jsonPayload = json_encode($hourData, JSON_PRETTY_PRINT);
            if ($jsonPayload === false) {
                $stats['errors'][] = 'json_encode_failed:' . $hourId;

                continue;
            }

            $tmpFile = $hourFile . '.tmp.aggr.' . getmypid();
            $written = @file_put_contents($tmpFile, $jsonPayload, LOCK_EX);
            if ($written === false) {
                @unlink($tmpFile);
                $stats['errors'][] = 'hourly_tmp_write_failed:' . $hourId;

                continue;
            }

            if (!@rename($tmpFile, $hourFile)) {
                @unlink($tmpFile);
                $stats['errors'][] = 'hourly_rename_failed:' . $hourId;

                continue;
            }

            $stats['hours_touched']++;
            $stats['hourly_writes']++;

            foreach ($pendingDeletes as $del) {
                if (@unlink($del)) {
                    $stats['spills_deleted']++;
                }
            }

            @rmdir($hourDir);
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
 * Remove stale spill shards that were never merged (crash / stuck worker).
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
        if (!str_ends_with($name, '.json')) {
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
