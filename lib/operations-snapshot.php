<?php
/**
 * Operations snapshot for Public API GET /v1/operations
 *
 * A scheduler-built JSON envelope (see scripts/build-operations-snapshot.php) aggregates
 * pre-warmed status caches plus scrubbed log fingerprints. The API reads the envelope and
 * applies freshness and optional detail gating.
 */

require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';

/** JSON schema version embedded in snapshot payloads. */
if (!defined('OPERATIONS_SNAPSHOT_SCHEMA_VERSION')) {
    define('OPERATIONS_SNAPSHOT_SCHEMA_VERSION', 1);
}

/**
 * Redact sensitive substrings from a scalar string (URLs with query, obvious tokens).
 *
 * @param string $value Raw string
 * @return string Redacted string
 */
function operations_snapshot_redact_scalar_string(string $value): string {
    $out = preg_replace('#(https?://[^\s]+)\?[^\s]+#', '$1?[redacted]', $value) ?? $value;
    $out = preg_replace('#(?i)(api[_-]?key|token|secret|password|bearer)\s*[:=]\s*\S+#', '$1=[redacted]', $out) ?? $out;
    return $out;
}

/**
 * Return true if a context key should have its value fully redacted.
 *
 * @param string $key Context array key
 * @return bool True when value must be redacted
 */
function operations_snapshot_is_sensitive_context_key(string $key): bool {
    $k = strtolower($key);
    if (str_contains($k, 'password')) {
        return true;
    }
    if (str_contains($k, 'token') || str_contains($k, 'secret')) {
        return true;
    }
    if ($k === 'authorization' || str_contains($k, 'api_key') || str_contains($k, 'apikey')) {
        return true;
    }
    if (str_contains($k, 'cookie') || str_contains($k, 'bearer')) {
        return true;
    }
    return false;
}

/**
 * Recursively scrub context arrays for log fingerprint samples.
 *
 * @param mixed $ctx Context value
 * @param int $depth Current recursion depth
 * @return mixed Scrubbed structure
 */
function operations_snapshot_scrub_context(mixed $ctx, int $depth = 0): mixed {
    if ($depth > 6) {
        return '[truncated]';
    }
    if (is_string($ctx)) {
        return operations_snapshot_redact_scalar_string($ctx);
    }
    if (is_int($ctx) || is_float($ctx) || is_bool($ctx) || $ctx === null) {
        return $ctx;
    }
    if (!is_array($ctx)) {
        return null;
    }
    $allow = [
        'source' => true,
        'log_type' => true,
        'level' => true,
        'component' => true,
        'provider' => true,
        'airport_id' => true,
        'endpoint' => true,
        'path' => true,
        'exit_code' => true,
        'http_code' => true,
    ];
    $out = [];
    foreach ($ctx as $key => $val) {
        if (!is_string($key)) {
            continue;
        }
        if (operations_snapshot_is_sensitive_context_key($key)) {
            $out[$key] = '[redacted]';
            continue;
        }
        $lk = strtolower($key);
        if (!isset($allow[$lk]) && !preg_match('/^(error|message|reason|stage|operation)$/i', $key)) {
            continue;
        }
        $out[$key] = operations_snapshot_scrub_context($val, $depth + 1);
    }
    return $out;
}

/**
 * Read the last $maxBytes of a file as a string (best-effort).
 *
 * @param string $path Absolute path
 * @param int $maxBytes Maximum tail size
 * @return string File tail or empty string
 */
function operations_snapshot_read_log_tail(string $path, int $maxBytes = 2097152): string {
    if (!is_readable($path)) {
        return '';
    }
    $size = @filesize($path);
    if ($size === false || $size <= 0) {
        return '';
    }
    $start = 0;
    if ($size > $maxBytes) {
        $start = $size - $maxBytes;
    }
    $fp = @fopen($path, 'rb');
    if ($fp === false) {
        return '';
    }
    if ($start > 0 && !@fseek($fp, $start)) {
        fclose($fp);
        return '';
    }
    $data = @stream_get_contents($fp);
    fclose($fp);
    if ($data === false) {
        return '';
    }
    if ($start > 0) {
        $nl = strpos($data, "\n");
        if ($nl !== false) {
            $data = substr($data, $nl + 1);
        }
    }
    return $data;
}

/**
 * Parse JSONL log tail and aggregate warning+ entries from the last hour.
 *
 * @param string $tailContent Tail content (UTF-8)
 * @param int $sinceUnix Inclusive lower bound for entry timestamp
 * @param int $maxFingerprints Cap on distinct fingerprints returned
 * @return array<int, array{fingerprint:string,level:string,message:string,count:int,last_ts:string,sample_context:array}>
 */
function operations_snapshot_aggregate_log_fingerprints(
    string $tailContent,
    int $sinceUnix,
    int $maxFingerprints = 30
): array {
    $levels = ['warning' => true, 'error' => true, 'critical' => true, 'alert' => true, 'emergency' => true];
    $agg = [];

    $lines = preg_split("/\r\n|\n|\r/", $tailContent) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $row = json_decode($line, true);
        if (!is_array($row)) {
            continue;
        }
        $level = isset($row['level']) ? strtolower((string) $row['level']) : '';
        if (!isset($levels[$level])) {
            continue;
        }
        $tsRaw = $row['ts'] ?? '';
        $tsUnix = is_string($tsRaw) ? strtotime($tsRaw) : false;
        if ($tsUnix === false || $tsUnix < $sinceUnix) {
            continue;
        }
        $message = isset($row['message']) ? operations_snapshot_redact_scalar_string((string) $row['message']) : '';
        $fingerprint = $level . '|' . $message;
        if (!isset($agg[$fingerprint])) {
            $ctx = isset($row['context']) && is_array($row['context'])
                ? operations_snapshot_scrub_context($row['context'])
                : [];
            $agg[$fingerprint] = [
                'fingerprint' => $fingerprint,
                'level' => $level,
                'message' => $message,
                'count' => 0,
                'last_ts' => gmdate('c', $tsUnix),
                'sample_context' => is_array($ctx) ? $ctx : [],
            ];
        }
        $agg[$fingerprint]['count']++;
        $prevUnix = strtotime($agg[$fingerprint]['last_ts']);
        if ($prevUnix === false || $tsUnix > $prevUnix) {
            $agg[$fingerprint]['last_ts'] = gmdate('c', $tsUnix);
        }
    }

    usort($agg, static function (array $a, array $b): int {
        return ($b['count'] <=> $a['count']) ?: strcmp($a['fingerprint'], $b['fingerprint']);
    });

    return array_slice(array_values($agg), 0, $maxFingerprints);
}

/**
 * Load JSON envelope written by status pre-warm workers (cached_at, ttl, key, data).
 *
 * @param string $path Absolute path
 * @return mixed|null Decoded data payload or null
 */
function operations_snapshot_load_envelope_data(string $path): mixed {
    if (!is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !array_key_exists('data', $decoded)) {
        return null;
    }
    return $decoded['data'];
}

/**
 * Summarize airport health list into counts and worst rows.
 *
 * @param array<int, array<string, mixed>> $airports Airport health rows
 * @param int $worstLimit Max worst airports to include in details
 * @return array{counts: array<string,int>, worst: array<int, array<string, mixed>>}
 */
function operations_snapshot_summarize_airport_health(array $airports, int $worstLimit = 15): array {
    $counts = ['operational' => 0, 'degraded' => 0, 'down' => 0, 'maintenance' => 0, 'other' => 0];
    $worst = [];
    foreach ($airports as $row) {
        if (!is_array($row)) {
            continue;
        }
        $st = isset($row['status']) ? (string) $row['status'] : 'other';
        if (isset($counts[$st])) {
            $counts[$st]++;
        } else {
            $counts['other']++;
        }
        if ($st === 'degraded' || $st === 'down' || $st === 'maintenance') {
            $msg = '';
            if (isset($row['components']) && is_array($row['components'])) {
                foreach ($row['components'] as $comp) {
                    if (!is_array($comp)) {
                        continue;
                    }
                    $cst = $comp['status'] ?? '';
                    if ($cst === 'degraded' || $cst === 'down' || $cst === 'failed') {
                        $msg = isset($comp['message']) ? (string) $comp['message'] : '';
                        break;
                    }
                }
            }
            $worst[] = [
                'id' => $row['id'] ?? '',
                'status' => $st,
                'message' => operations_snapshot_redact_scalar_string($msg),
            ];
        }
    }
    usort($worst, static function (array $a, array $b): int {
        $rank = ['down' => 0, 'maintenance' => 1, 'degraded' => 2];
        $ra = $rank[$a['status']] ?? 9;
        $rb = $rank[$b['status']] ?? 9;
        return $ra <=> $rb;
    });
    $worst = array_slice($worst, 0, $worstLimit);

    return ['counts' => $counts, 'worst' => $worst];
}

/**
 * Merge circuit-open counters by source from recent weather health hourly buckets.
 *
 * @param array<string, mixed> $weatherFileDecoded Full weather_health.json decode
 * @return array<string, int>
 */
function operations_snapshot_weather_circuit_by_source(array $weatherFileDecoded): array {
    $oneHourAgo = gmdate('Y-m-d-H', time() - 3600);
    $by = [];
    $buckets = $weatherFileDecoded['hourly_buckets'] ?? [];
    if (!is_array($buckets)) {
        return $by;
    }
    foreach ($buckets as $hourKey => $bucket) {
        if (!is_string($hourKey) || $hourKey < $oneHourAgo) {
            continue;
        }
        if (!is_array($bucket)) {
            continue;
        }
        foreach ($bucket as $k => $v) {
            if (!is_string($k) || !str_starts_with($k, 'circuit_open_') || $k === 'circuit_open_events') {
                continue;
            }
            $src = substr($k, strlen('circuit_open_'));
            $n = is_int($v) ? $v : (int) $v;
            if (!isset($by[$src])) {
                $by[$src] = 0;
            }
            $by[$src] += $n;
        }
    }
    ksort($by);
    return $by;
}

/**
 * Determine whether verbose detail should be exposed to API clients.
 *
 * @param array<string, mixed> $data Inner snapshot data (before freshness meta)
 * @return bool True when details should be included
 */
function operations_snapshot_verbose_detail_warranted(array $data): bool {
    $uptime = isset($data['uptime_layer']) && is_array($data['uptime_layer']) ? $data['uptime_layer'] : [];
    $sys = $uptime['system'] ?? null;
    if (is_array($sys) && (($sys['status'] ?? '') !== 'operational')) {
        return true;
    }
    $api = $uptime['public_api'] ?? null;
    if (is_array($api) && (($api['status'] ?? '') !== 'operational')) {
        return true;
    }
    $dp = isset($data['data_plane']) && is_array($data['data_plane']) ? $data['data_plane'] : [];
    $wx = $dp['weather'] ?? null;
    if (is_array($wx) && (($wx['status'] ?? '') !== 'operational')) {
        return true;
    }
    $var = $dp['variant'] ?? null;
    if (is_array($var) && (($var['status'] ?? '') !== 'operational')) {
        return true;
    }
    $summary = isset($dp['airport_summary']) && is_array($dp['airport_summary']) ? $dp['airport_summary'] : [];
    $counts = isset($summary['counts']) && is_array($summary['counts']) ? $summary['counts'] : [];
    $bad = (int) ($counts['degraded'] ?? 0) + (int) ($counts['down'] ?? 0);
    if ($bad > 0) {
        return true;
    }
    $details = isset($data['details']) && is_array($data['details']) ? $data['details'] : [];
    $fps = $details['log_fingerprints'] ?? [];
    if (is_array($fps) && $fps !== []) {
        return true;
    }
    $m = is_array($wx) && isset($wx['metrics']) && is_array($wx['metrics']) ? $wx['metrics'] : [];
    if ((int) ($m['circuit_open_events_last_hour'] ?? 0) > 0) {
        return true;
    }
    return false;
}

/**
 * Strip verbose keys when not warranted.
 *
 * @param array<string, mixed> $data Inner snapshot
 * @param bool $verbose When false, remove heavy detail blocks
 * @return array<string, mixed> Filtered snapshot
 */
function operations_snapshot_apply_verbose_gate(array $data, bool $verbose): array {
    if ($verbose) {
        return $data;
    }
    if (isset($data['details']) && is_array($data['details'])) {
        unset($data['details']['log_fingerprints'], $data['details']['airport_worst'], $data['details']['weather_circuit_by_source']);
    }
    return $data;
}

/**
 * Build the inner snapshot payload (no envelope wrapper).
 *
 * @param string $cacheBaseDir Cache root (CACHE_BASE_DIR in production)
 * @param array<string, mixed> $options Optional: int `now`, string `log_path`
 * @return array<string, mixed> Snapshot data
 */
function operations_snapshot_build(string $cacheBaseDir, array $options = []): array {
    $now = isset($options['now']) && is_int($options['now']) ? $options['now'] : time();
    $logPath = isset($options['log_path']) && is_string($options['log_path'])
        ? $options['log_path']
        : (defined('AVIATIONWX_LOG_FILE') ? AVIATIONWX_LOG_FILE : '');

    $system = operations_snapshot_load_envelope_data($cacheBaseDir . '/status_system_health.json');
    $public = operations_snapshot_load_envelope_data($cacheBaseDir . '/public_api_health.json');
    $airportsRaw = operations_snapshot_load_envelope_data($cacheBaseDir . '/status_airport_health.json');
    $airports = is_array($airportsRaw) ? $airportsRaw : [];
    $airSummary = operations_snapshot_summarize_airport_health($airports);

    $weatherPath = $cacheBaseDir . '/weather_health.json';
    $weatherHealth = [
        'name' => 'Weather Data Fetching',
        'status' => 'operational',
        'message' => 'No data available',
        'lastChanged' => 0,
        'metrics' => [],
    ];
    $weatherFull = [];
    if (is_readable($weatherPath)) {
        $w = @file_get_contents($weatherPath);
        if ($w !== false) {
            $weatherFull = json_decode($w, true);
            if (is_array($weatherFull) && isset($weatherFull['health']) && is_array($weatherFull['health'])) {
                $weatherHealth = $weatherFull['health'];
            }
        }
    }

    $variantPath = $cacheBaseDir . '/variant_health.json';
    $variantHealth = [
        'name' => 'Webcam Variant Generation',
        'status' => 'operational',
        'message' => 'No data available',
        'lastChanged' => 0,
        'metrics' => [],
    ];
    if (is_readable($variantPath)) {
        $v = @file_get_contents($variantPath);
        if ($v !== false) {
            $vd = json_decode($v, true);
            if (is_array($vd) && isset($vd['health']) && is_array($vd['health'])) {
                $variantHealth = $vd['health'];
            }
        }
    }

    $node = operations_snapshot_load_envelope_data($cacheBaseDir . '/status_node_performance.json');
    $img = operations_snapshot_load_envelope_data($cacheBaseDir . '/status_image_processing.json');
    $page = operations_snapshot_load_envelope_data($cacheBaseDir . '/status_page_render.json');

    $memorySummary = ['snapshots_count' => 0, 'latest_memory_bytes' => null];
    $memPath = $cacheBaseDir . '/memory_history.json';
    if (is_readable($memPath)) {
        $m = @file_get_contents($memPath);
        if ($m !== false) {
            $md = json_decode($m, true);
            if (is_array($md) && isset($md['snapshots']) && is_array($md['snapshots'])) {
                $memorySummary['snapshots_count'] = count($md['snapshots']);
                $last = end($md['snapshots']);
                if (is_array($last) && isset($last['memory'])) {
                    $memorySummary['latest_memory_bytes'] = (int) $last['memory'];
                }
            }
        }
    }

    $cloud = null;
    $cfPath = $cacheBaseDir . '/cloudflare_analytics.json';
    if (is_readable($cfPath)) {
        $c = @file_get_contents($cfPath);
        if ($c !== false) {
            $cd = json_decode($c, true);
            if (is_array($cd)) {
                $cloud = [
                    'unique_visitors_today' => $cd['unique_visitors_today'] ?? null,
                    'requests_today' => $cd['requests_today'] ?? null,
                    'bandwidth_today' => $cd['bandwidth_today'] ?? null,
                    'cached_at' => $cd['cached_at'] ?? null,
                ];
            }
        }
    }

    $aggLast = null;
    if (function_exists('getMetricsAggregatorLastRunPath')) {
        $p = getMetricsAggregatorLastRunPath();
        if (is_readable($p)) {
            $raw = @file_get_contents($p);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $stats = $decoded['stats'] ?? [];
                    $aggLast = [
                        'finished_at' => $decoded['finished_at'] ?? null,
                    ];
                    if (is_array($stats)) {
                        foreach ([
                            'lock_contended',
                            'hours_touched',
                            'hourly_writes',
                            'spills_merged',
                            'spills_deleted',
                            'orphans_pruned',
                        ] as $k) {
                            if (array_key_exists($k, $stats)) {
                                $aggLast[$k] = $stats[$k];
                            }
                        }
                        if (!empty($stats['errors']) && is_array($stats['errors'])) {
                            $aggLast['errors'] = $stats['errors'];
                        }
                    }
                }
            }
        }
    }

    $since = $now - 3600;
    $fingerprints = [];
    if ($logPath !== '' && is_readable($logPath)) {
        $tail = operations_snapshot_read_log_tail($logPath);
        $fingerprints = operations_snapshot_aggregate_log_fingerprints($tail, $since);
    }

    $circuitBySource = is_array($weatherFull) ? operations_snapshot_weather_circuit_by_source($weatherFull) : [];

    return [
        'snapshot_meta' => [
            'schema_version' => OPERATIONS_SNAPSHOT_SCHEMA_VERSION,
            'generated_at' => gmdate('c', $now),
            'generated_at_unix' => $now,
        ],
        'uptime_layer' => [
            'system' => is_array($system) ? $system : null,
            'public_api' => is_array($public) ? $public : null,
        ],
        'data_plane' => [
            'airport_summary' => [
                'total' => count($airports),
                'counts' => $airSummary['counts'],
            ],
            'weather' => $weatherHealth,
            'variant' => $variantHealth,
        ],
        'capacity_layer' => [
            'node_performance' => is_array($node) ? $node : null,
            'image_processing' => is_array($img) ? $img : null,
            'page_render' => is_array($page) ? $page : null,
            'memory_history_summary' => $memorySummary,
        ],
        'edge_layer' => [
            'cloudflare' => $cloud,
        ],
        'pipeline_meta' => [
            'metrics_aggregator_last_run' => $aggLast,
        ],
        'details' => [
            'log_fingerprints' => $fingerprints,
            'airport_worst' => $airSummary['worst'],
            'weather_circuit_by_source' => $circuitBySource,
        ],
    ];
}

/**
 * Write snapshot envelope to disk (atomic rename).
 *
 * @param string $path Target path (CACHE_OPERATIONS_SNAPSHOT_FILE)
 * @param array<string, mixed> $data Inner snapshot payload
 * @param int $ttlSeconds TTL hint stored in envelope (max staleness policy for readers)
 * @return bool True on success
 */
function operations_snapshot_write_envelope(string $path, array $data, int $ttlSeconds): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $fileData = [
        'cached_at' => time(),
        'ttl' => $ttlSeconds,
        'key' => 'operations_snapshot',
        'data' => $data,
    ];
    $json = json_encode($fileData, JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Read operations snapshot envelope from disk.
 *
 * @param string|null $path Absolute path; default CACHE_OPERATIONS_SNAPSHOT_FILE
 * @return array<string, mixed>|null Full envelope or null
 */
function operations_snapshot_read_envelope(?string $path = null): ?array
{
    $path = $path ?? CACHE_OPERATIONS_SNAPSHOT_FILE;
    if (!is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Prepare the operations object for GET /v1/operations (freshness + verbose gate).
 *
 * @param string|null $snapshotPath Optional envelope path (for tests)
 * @return array<string, mixed> Payload keyed for sendPublicApiSuccess
 */
function operations_snapshot_get_api_payload(?string $snapshotPath = null): array
{
    $maxAge = defined('OPERATIONS_SNAPSHOT_MAX_AGE_SECONDS') ? OPERATIONS_SNAPSHOT_MAX_AGE_SECONDS : 1800;
    $envelope = operations_snapshot_read_envelope($snapshotPath);
    $now = time();

    if ($envelope === null || !isset($envelope['data']) || !is_array($envelope['data'])) {
        return [
            'operations' => [
                'snapshot_meta' => [
                    'schema_version' => OPERATIONS_SNAPSHOT_SCHEMA_VERSION,
                    'freshness' => 'missing',
                    'generated_at' => null,
                    'generated_at_unix' => null,
                    'age_seconds' => null,
                    'max_age_seconds' => $maxAge,
                ],
                'uptime_layer' => null,
                'data_plane' => null,
                'capacity_layer' => null,
                'edge_layer' => null,
                'pipeline_meta' => null,
                'details' => null,
            ],
        ];
    }

    $cachedAt = isset($envelope['cached_at']) ? (int) $envelope['cached_at'] : 0;
    $age = max(0, $now - $cachedAt);
    $freshness = $age > $maxAge ? 'stale' : 'ok';

    $inner = $envelope['data'];
    if (!is_array($inner)) {
        $inner = [];
    }

    $verbose = operations_snapshot_verbose_detail_warranted($inner);
    $inner = operations_snapshot_apply_verbose_gate($inner, $verbose);

    if (!isset($inner['snapshot_meta']) || !is_array($inner['snapshot_meta'])) {
        $inner['snapshot_meta'] = [];
    }
    $inner['snapshot_meta']['freshness'] = $freshness;
    $inner['snapshot_meta']['age_seconds'] = $age;
    $inner['snapshot_meta']['max_age_seconds'] = $maxAge;
    $inner['snapshot_meta']['verbose_detail'] = $verbose;
    if (!array_key_exists('schema_version', $inner['snapshot_meta'])) {
        $inner['snapshot_meta']['schema_version'] = OPERATIONS_SNAPSHOT_SCHEMA_VERSION;
    }

    return ['operations' => $inner];
}
