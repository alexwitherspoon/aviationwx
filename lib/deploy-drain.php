<?php
/**
 * Deploy worker drain coordination (CD pause + scheduler ProcessPool drain).
 *
 * Markers live on the shared cache volume so the host can request/wait without
 * depending on PHP inside the image being replaced.
 *
 * Timeline from flag started_at:
 *   - before live MAX: pause new work; mark .done when all pools idle
 *   - at live MAX: SIGTERM remaining live pools (weather/webcam/...); reference may continue
 *   - at reference MAX: SIGTERM remaining reference pools; mark .done
 *   - before forceMAX+ABANDON: stay paused so CD can recreate (Apache still serves)
 *   - at forceMAX+ABANDON: clear markers and resume (abandoned CD must not pause forever)
 *
 * Airport reference pools use DEPLOY_WORKER_DRAIN_REFERENCE_MAX_SECONDS (NASR-scale).
 * Other fire-and-forget CLIs (metrics, airspace) are not waited on.
 */

require_once __DIR__ . '/constants.php';

/**
 * Override cache directory for tests/CLI (null clears the override).
 *
 * @param string|null $dir Absolute cache directory, or null to use CACHE_BASE_DIR
 * @return void
 */
function deploy_drain_set_cache_base(?string $dir): void {
    if ($dir === null || $dir === '') {
        unset($GLOBALS['deployDrainCacheDirectoryOverride']);
        return;
    }
    $GLOBALS['deployDrainCacheDirectoryOverride'] = $dir;
}

/**
 * @return string Absolute cache base directory
 */
function deploy_drain_cache_base(): string {
    if (
        isset($GLOBALS['deployDrainCacheDirectoryOverride'])
        && is_string($GLOBALS['deployDrainCacheDirectoryOverride'])
        && $GLOBALS['deployDrainCacheDirectoryOverride'] !== ''
    ) {
        return $GLOBALS['deployDrainCacheDirectoryOverride'];
    }

    if (defined('CACHE_BASE_DIR')) {
        return (string) CACHE_BASE_DIR;
    }

    return dirname(__DIR__) . '/cache';
}

/**
 * @return string Absolute path to the deploy drain request flag
 */
function deploy_drain_flag_path(): string {
    return deploy_drain_cache_base() . '/' . DEPLOY_DRAIN_FLAG_BASENAME;
}

/**
 * @return string Absolute path to the deploy drain completion marker
 */
function deploy_drain_done_path(): string {
    return deploy_drain_cache_base() . '/' . DEPLOY_DRAIN_DONE_BASENAME;
}

/**
 * File presence is authoritative; corrupt JSON still counts as requested.
 *
 * @return bool
 */
function deploy_drain_is_requested(): bool {
    return is_file(deploy_drain_flag_path());
}

/**
 * @return bool
 */
function deploy_drain_is_complete(): bool {
    return is_file(deploy_drain_done_path());
}

/**
 * @param string $path Absolute JSON path
 * @return array<string, mixed>|null
 */
function deploy_drain_read_json_file(string $path): ?array {
    if (!is_file($path)) {
        return null;
    }

    // @ : marker may vanish between is_file and read during container teardown
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}

/**
 * @return array{started_at?: int}|null Null when missing or corrupt
 */
function deploy_drain_read_flag_payload(): ?array {
    return deploy_drain_read_json_file(deploy_drain_flag_path());
}

/**
 * @return array{reason?: string, completed_at?: int}|null
 */
function deploy_drain_read_done_payload(): ?array {
    return deploy_drain_read_json_file(deploy_drain_done_path());
}

/**
 * Prefers JSON started_at; falls back to flag mtime when JSON is corrupt.
 *
 * @return int|null
 */
function deploy_drain_started_at(): ?int {
    $payload = deploy_drain_read_flag_payload();
    if (is_array($payload) && isset($payload['started_at']) && is_numeric($payload['started_at'])) {
        $started = (int) $payload['started_at'];
        if ($started > 0) {
            return $started;
        }
    }

    $path = deploy_drain_flag_path();
    if (!is_file($path)) {
        return null;
    }

    // @ : mtime can fail on transient unlinks
    $mtime = @filemtime($path);
    if ($mtime === false || $mtime <= 0) {
        return null;
    }

    return (int) $mtime;
}

/**
 * @param int|null $now Current unix time (injectable for tests)
 * @return int|null Elapsed seconds, or null when start time is unknown
 */
function deploy_drain_elapsed_seconds(?int $now = null): ?int {
    $started = deploy_drain_started_at();
    if ($started === null) {
        return null;
    }

    $now = $now ?? time();
    return max(0, $now - $started);
}

/**
 * Total drain TTL (force window + abandon window) from started_at.
 *
 * @param int $maxSeconds
 * @param int $abandonSeconds
 * @return int
 */
function deploy_drain_ttl_seconds(
    int $maxSeconds = DEPLOY_WORKER_DRAIN_MAX_SECONDS,
    int $abandonSeconds = DEPLOY_WORKER_DRAIN_ABANDON_SECONDS
): int {
    return max(1, $maxSeconds) + max(0, $abandonSeconds);
}

/**
 * Wall clock used for abandon and CD wait when reference pools may still be draining.
 *
 * @return int
 */
function deploy_drain_reference_aware_max_seconds(): int
{
    return max(
        (int) DEPLOY_WORKER_DRAIN_MAX_SECONDS,
        (int) DEPLOY_WORKER_DRAIN_REFERENCE_MAX_SECONDS
    );
}

/**
 * Default CD wait for .done (reference window + grace).
 *
 * @return int
 */
function deploy_drain_cd_wait_seconds(): int
{
    return deploy_drain_reference_aware_max_seconds() + (int) DEPLOY_WORKER_DRAIN_WAIT_GRACE_SECONDS;
}

/**
 * Temp file + rename so readers never see a partial JSON marker.
 *
 * @param string $path Destination path
 * @param array<string, mixed> $payload Data to encode
 * @return bool True on success
 */
function deploy_drain_write_json_atomic(string $path, array $payload): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        return false;
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    // @ : non-critical write; caller treats false as failure
    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
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
 * Idempotent request. Clears a stale done marker so CD wait cannot short-circuit.
 *
 * @param int|null $now Current unix time (injectable for tests)
 * @return bool True when flag exists after the call
 */
function deploy_drain_request(?int $now = null): bool {
    $now = $now ?? time();
    $flagPath = deploy_drain_flag_path();
    $donePath = deploy_drain_done_path();

    if (is_file($donePath)) {
        // @ : best-effort clear of stale done before a new drain window
        @unlink($donePath);
    }

    if (deploy_drain_is_requested()) {
        // Keep original started_at so max/abandon are measured from the first request.
        return true;
    }

    return deploy_drain_write_json_atomic($flagPath, [
        'started_at' => $now,
    ]);
}

/**
 * First completion reason wins (idempotent).
 *
 * @param string $reason Completion reason (idle|forced_timeout|no_scheduler|...)
 * @param int|null $now Current unix time (injectable for tests)
 * @return bool True when done marker exists after the call
 */
function deploy_drain_mark_complete(string $reason, ?int $now = null): bool {
    $now = $now ?? time();
    if (deploy_drain_is_complete()) {
        return true;
    }

    $reason = trim($reason);
    if ($reason === '') {
        $reason = 'unspecified';
    }

    return deploy_drain_write_json_atomic(deploy_drain_done_path(), [
        'reason' => $reason,
        'completed_at' => $now,
    ]);
}

/**
 * @return bool True when neither marker remains
 */
function deploy_drain_clear_markers(): bool {
    $flag = deploy_drain_flag_path();
    $done = deploy_drain_done_path();

    if (is_file($flag)) {
        @unlink($flag);
    }
    if (is_file($done)) {
        @unlink($done);
    }

    return !is_file($flag) && !is_file($done);
}

/**
 * Pure decision for one scheduler drain evaluation.
 *
 * Live pools force at $liveMaxSeconds; reference pools force at $referenceMaxSeconds.
 * Abandon TTL uses the larger of the two force windows.
 *
 * @param bool $requested Drain flag present
 * @param bool $alreadyComplete Done marker present
 * @param int|null $startedAt Drain start unix time (null if unknown)
 * @param int $liveActive Live ProcessPool active count
 * @param int $referenceActive Reference ProcessPool active count
 * @param int $now Current unix time
 * @param int $liveMaxSeconds Live force-terminate ceiling
 * @param int $referenceMaxSeconds Reference force-terminate ceiling
 * @param int $abandonSeconds Extra seconds after max force before auto-resume
 * @return array{
 *   allow_new_work: bool,
 *   suppress_scheduler_restart: bool,
 *   action: 'none'|'wait'|'mark_complete_idle'|'force_terminate_live'|'force_terminate_reference'|'force_terminate'|'already_complete'|'abandon_clear'
 * }
 */
function deploy_drain_evaluate_state(
    bool $requested,
    bool $alreadyComplete,
    ?int $startedAt,
    int $liveActive,
    int $referenceActive = 0,
    int $now = 0,
    int $liveMaxSeconds = DEPLOY_WORKER_DRAIN_MAX_SECONDS,
    int $referenceMaxSeconds = DEPLOY_WORKER_DRAIN_REFERENCE_MAX_SECONDS,
    int $abandonSeconds = DEPLOY_WORKER_DRAIN_ABANDON_SECONDS
): array {
    $liveActive = max(0, $liveActive);
    $referenceActive = max(0, $referenceActive);
    $activeWorkers = $liveActive + $referenceActive;
    $liveMaxSeconds = max(1, $liveMaxSeconds);
    $referenceMaxSeconds = max($liveMaxSeconds, $referenceMaxSeconds);
    // Abandon TTL follows the force window still in play (reference when any reference workers remain).
    $forceMax = $referenceActive > 0 ? $referenceMaxSeconds : $liveMaxSeconds;
    $ttl = deploy_drain_ttl_seconds($forceMax, $abandonSeconds);

    if (!$requested && !$alreadyComplete) {
        return [
            'allow_new_work' => true,
            'suppress_scheduler_restart' => false,
            'action' => 'none',
        ];
    }

    if (!$requested && $alreadyComplete) {
        return [
            'allow_new_work' => true,
            'suppress_scheduler_restart' => false,
            'action' => 'abandon_clear',
        ];
    }

    if ($startedAt === null || $startedAt <= 0) {
        if ($activeWorkers > 0) {
            return [
                'allow_new_work' => false,
                'suppress_scheduler_restart' => true,
                'action' => 'force_terminate',
            ];
        }
        if ($alreadyComplete) {
            return [
                'allow_new_work' => true,
                'suppress_scheduler_restart' => false,
                'action' => 'abandon_clear',
            ];
        }
        return [
            'allow_new_work' => false,
            'suppress_scheduler_restart' => true,
            'action' => 'mark_complete_idle',
        ];
    }

    $elapsed = max(0, $now - $startedAt);

    if ($elapsed >= $ttl) {
        return [
            'allow_new_work' => true,
            'suppress_scheduler_restart' => false,
            'action' => 'abandon_clear',
        ];
    }

    if ($alreadyComplete) {
        return [
            'allow_new_work' => false,
            'suppress_scheduler_restart' => true,
            'action' => 'already_complete',
        ];
    }

    if ($activeWorkers === 0) {
        return [
            'allow_new_work' => false,
            'suppress_scheduler_restart' => true,
            'action' => 'mark_complete_idle',
        ];
    }

    if ($elapsed >= $referenceMaxSeconds) {
        return [
            'allow_new_work' => false,
            'suppress_scheduler_restart' => true,
            'action' => 'force_terminate',
        ];
    }

    if ($elapsed >= $liveMaxSeconds && $liveActive > 0) {
        return [
            'allow_new_work' => false,
            'suppress_scheduler_restart' => true,
            'action' => $referenceActive > 0 ? 'force_terminate_live' : 'force_terminate',
        ];
    }

    if ($elapsed >= $liveMaxSeconds && $liveActive === 0 && $referenceActive > 0) {
        return [
            'allow_new_work' => false,
            'suppress_scheduler_restart' => true,
            'action' => 'wait',
        ];
    }

    return [
        'allow_new_work' => false,
        'suppress_scheduler_restart' => true,
        'action' => 'wait',
    ];
}

/**
 * @param array{live: int, reference: int}|int $activeByClass Or legacy total active count
 * @param int|null $now Current unix time (injectable for tests)
 * @return array{
 *   allow_new_work: bool,
 *   suppress_scheduler_restart: bool,
 *   action: 'none'|'wait'|'mark_complete_idle'|'force_terminate_live'|'force_terminate_reference'|'force_terminate'|'already_complete'|'abandon_clear'
 * }
 */
function deploy_drain_evaluate_scheduler_tick($activeByClass, ?int $now = null): array
{
    $now = $now ?? time();
    if (is_int($activeByClass)) {
        $liveActive = max(0, $activeByClass);
        $referenceActive = 0;
    } else {
        $liveActive = max(0, (int) ($activeByClass['live'] ?? 0));
        $referenceActive = max(0, (int) ($activeByClass['reference'] ?? 0));
    }

    return deploy_drain_evaluate_state(
        deploy_drain_is_requested(),
        deploy_drain_is_complete(),
        deploy_drain_started_at(),
        $liveActive,
        $referenceActive,
        $now,
        DEPLOY_WORKER_DRAIN_MAX_SECONDS,
        DEPLOY_WORKER_DRAIN_REFERENCE_MAX_SECONDS,
        DEPLOY_WORKER_DRAIN_ABANDON_SECONDS
    );
}

/**
 * Whether the health watchdog must not spawn a replacement for this tick.
 *
 * @param int|null $now Current unix time (injectable for tests)
 * @return bool
 */
function deploy_drain_should_suppress_scheduler_restart(?int $now = null): bool {
    $tick = deploy_drain_evaluate_scheduler_tick(0, $now);
    return $tick['suppress_scheduler_restart'];
}

/**
 * @param string $action Action from deploy_drain_evaluate_*
 * @param int|null $now Current unix time
 * @param callable():void $forceTerminate Invoked for force/abandon actions (caller scopes by class)
 * @return bool False only when a required marker write/clear fails
 */
function deploy_drain_apply_scheduler_action(string $action, ?int $now, callable $forceTerminate): bool {
    $now = $now ?? time();

    if ($action === 'abandon_clear') {
        $forceTerminate();
        return deploy_drain_clear_markers();
    }

    if ($action === 'force_terminate') {
        $forceTerminate();
        return deploy_drain_mark_complete('forced_timeout', $now);
    }

    // Live force only: reference pools may still be draining; do not write .done yet.
    if ($action === 'force_terminate_live') {
        $forceTerminate();
        return true;
    }

    if ($action === 'mark_complete_idle') {
        return deploy_drain_mark_complete('idle', $now);
    }

    return true;
}

/**
 * @param array<int, object|null> $pools Objects exposing getActiveCount(): int
 * @return int
 */
function deploy_drain_sum_active_workers(array $pools): int {
    $total = 0;
    foreach ($pools as $pool) {
        if ($pool === null || !is_object($pool) || !method_exists($pool, 'getActiveCount')) {
            continue;
        }
        $count = $pool->getActiveCount();
        if (is_int($count) || is_float($count)) {
            $total += max(0, (int) $count);
        }
    }

    return $total;
}

/**
 * @param int $maxWaitSeconds Maximum seconds to wait
 * @param callable():void|null $onPoll Invoked at the start of each poll iteration
 * @param callable():void|null $sleeper Defaults to sleep(1)
 * @return bool True if complete marker observed, false on timeout
 */
function deploy_drain_wait_until_complete(
    int $maxWaitSeconds,
    ?callable $onPoll = null,
    ?callable $sleeper = null
): bool {
    if (deploy_drain_is_complete()) {
        return true;
    }

    $maxWaitSeconds = max(0, $maxWaitSeconds);
    $sleeper = $sleeper ?? static function (): void {
        sleep(1);
    };

    for ($i = 0; $i < $maxWaitSeconds; $i++) {
        if ($onPoll !== null) {
            $onPoll();
        }
        if (deploy_drain_is_complete()) {
            return true;
        }
        $sleeper();
        if (deploy_drain_is_complete()) {
            return true;
        }
    }

    return deploy_drain_is_complete();
}
