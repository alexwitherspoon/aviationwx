<?php
/**
 * Per-worker JSONL spill journal: append deltas on request shutdown, claim for aggregator merge.
 */

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/metrics-apply-counters.php';
require_once __DIR__ . '/metrics-spill-payload.php';

/**
 * Build a spill payload object for one JSONL journal line.
 *
 * @param string               $hourId   UTC metrics hour id
 * @param int                  $pid      PHP-FPM worker PID
 * @param array<string, int>   $counters Flat APCu counter map
 * @return array<string, mixed>
 */
function metrics_spill_build_payload(string $hourId, int $pid, array $counters): array
{
    return [
        'schema_version' => METRICS_SPILL_FILE_SCHEMA_VERSION,
        'generated_at' => time(),
        'hour_id' => $hourId,
        'pid' => $pid,
        'counters' => $counters,
    ];
}

/**
 * Append one JSON line to a worker journal under an exclusive flock.
 *
 * @param string               $journalPath Absolute path to {pid}.jsonl
 * @param array<string, mixed> $payload     Output of metrics_spill_build_payload()
 * @return bool True when the line was written
 */
function metrics_spill_journal_append_locked(string $journalPath, array $payload): bool
{
    $line = json_encode($payload);
    if ($line === false) {
        aviationwx_log('warning', 'metrics spill: journal line json_encode failed', [
            'path' => $journalPath,
            'error' => json_last_error_msg(),
        ], 'app');

        return false;
    }
    $line .= "\n";

    $fp = @fopen($journalPath, 'ab');
    if ($fp === false) {
        return false;
    }

    $locked = false;

    try {
        if (!flock($fp, LOCK_EX)) {
            return false;
        }

        $locked = true;

        $written = fwrite($fp, $line);
        if ($written !== strlen($line)) {
            return false;
        }

        fflush($fp);

        return true;
    } finally {
        if ($locked) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}

/**
 * Atomically claim a worker journal for merge (rename away from the live append path).
 *
 * Workers that spill after claim create a fresh {pid}.jsonl. Returns null when missing or busy.
 *
 * @param string $journalPath Live journal path ({pid}.jsonl)
 * @return string|null Absolute path to the claimed .merging copy, or null
 */
function metrics_spill_journal_claim_for_merge(string $journalPath): ?string
{
    if (!is_file($journalPath)) {
        return null;
    }

    $claimPath = $journalPath . '.merging.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@rename($journalPath, $claimPath)) {
        return $claimPath;
    }

    return null;
}

/**
 * Merge all valid lines from a claimed worker journal into hour bucket data.
 *
 * @param string               $claimedPath Renamed journal from metrics_spill_journal_claim_for_merge()
 * @param string               $hourId      UTC hour bucket id
 * @param array<string, mixed> $hourData    Hourly bucket (mutated in place)
 * @param int|float|null       $t0Ns                 Optional hrtime(true) anchor for runtime budget checks
 * @param bool|null            $journalFullyConsumed Set true when every line in the claim was processed
 * @return int|null Lines merged (0 when read succeeded but none applied), or null on open/lock failure
 */
function metrics_spill_journal_merge_claimed_into_hour_data(
    string $claimedPath,
    string $hourId,
    array &$hourData,
    $t0Ns = null,
    ?bool &$journalFullyConsumed = null
): ?int {
    if ($journalFullyConsumed !== null) {
        $journalFullyConsumed = false;
    }

    $fp = @fopen($claimedPath, 'rb');
    if ($fp === false) {
        return null;
    }

    $merged = 0;
    $locked = false;
    $budgetExhausted = false;

    try {
        // Wait for any in-flight append on the renamed inode (worker may still hold LOCK_EX).
        if (!flock($fp, LOCK_SH)) {
            return null;
        }

        $locked = true;

        while (($rawLine = fgets($fp)) !== false) {
            if ($t0Ns !== null
                && (hrtime(true) - $t0Ns) / 1e6 >= METRICS_SPILL_MERGE_MAX_RUNTIME_MS
            ) {
                $budgetExhausted = true;
                if ($merged > 0) {
                    metrics_spill_journal_rewrite_claimed_tail($fp, $claimedPath, $rawLine);
                }
                break;
            }

            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            $data = @json_decode($line, true);
            if (!is_array($data)) {
                continue;
            }

            $parsed = metrics_parse_spill_payload_for_merge($data, $hourId);
            if ($parsed === null) {
                continue;
            }

            metrics_apply_flat_counters_to_hour_data($hourData, $parsed['counters'], true);
            $merged++;
        }

        if (!$budgetExhausted && $journalFullyConsumed !== null) {
            $journalFullyConsumed = true;
        }
    } finally {
        if ($locked) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    return $merged;
}

/**
 * Persist unread journal lines after a partial merge under the runtime budget.
 *
 * @param resource $fp               Open read handle positioned after $unmergedLine
 * @param string   $claimedPath      Claimed journal path to rewrite or delete when empty
 * @param string   $unmergedLine     Line read when the runtime budget was exhausted (not yet merged)
 * @return void
 */
function metrics_spill_journal_rewrite_claimed_tail($fp, string $claimedPath, string $unmergedLine = ''): void
{
    $tail = stream_get_contents($fp);
    if ($tail === false) {
        $tail = '';
    }
    $remainder = $unmergedLine . $tail;
    if ($remainder === '') {
        @unlink($claimedPath);

        return;
    }

    $tmp = $claimedPath . '.tmp.tail.' . getmypid();
    if (@file_put_contents($tmp, $remainder, LOCK_EX) === false) {
        @unlink($tmp);

        return;
    }

    @rename($tmp, $claimedPath);
}

/**
 * Whether a spill path is a live per-worker JSONL journal (not a claim or temp file).
 *
 * @param string $path Absolute spill file path
 * @return bool
 */
function metrics_spill_path_is_worker_journal(string $path): bool
{
    $base = basename($path);

    return str_ends_with($base, '.jsonl')
        && strpos($base, '.merging.') === false
        && strpos($base, '.tmp.') === false;
}

/**
 * Whether a spill path is a claimed journal awaiting merge (rename from live {pid}.jsonl).
 *
 * @param string $path Absolute spill file path
 * @return bool
 */
function metrics_spill_path_is_claimed_journal(string $path): bool
{
    return strpos(basename($path), '.jsonl.merging.') !== false;
}
