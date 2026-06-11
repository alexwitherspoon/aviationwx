<?php
/**
 * Per-credential upstream rate limiting (file-backed token bucket).
 *
 * Coordinates outbound weather API calls across worker processes using flock.
 * Fingerprint credentials with SHA-256; never log raw keys.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/cache-paths.php';

/**
 * Secret/config keys used to fingerprint a shared credential (sorted when hashing).
 *
 * @param string $provider Weather source type (e.g. tempest, pwsweather)
 * @return list<string>
 */
function upstreamRateLimitCredentialFieldNames(string $provider): array
{
    return match ($provider) {
        'tempest' => ['api_key'],
        'ambient' => ['api_key', 'application_key'],
        'pwsweather' => ['client_id', 'client_secret'],
        'weatherlink_v2' => ['api_key', 'api_secret'],
        'weatherlink_v1' => ['api_token'],
        'synopticdata' => ['api_token'],
        'aviationwx_api' => ['api_key'],
        default => [],
    };
}

/**
 * Non-secret identity fields for per-host buckets on keyless or multi-tenant sources.
 *
 * @param string $provider Weather source type
 * @return list<string>
 */
function upstreamRateLimitIdentityFieldNames(string $provider): array
{
    return match ($provider) {
        'metar', 'awosnet', 'swob_auto', 'swob_man', 'nws' => ['station_id'],
        'aviationwx_api' => ['base_url', 'airport_id'],
        default => [],
    };
}

/**
 * All config keys included in the fingerprint (credentials plus identity).
 *
 * @return list<string>
 */
function upstreamRateLimitFingerprintFieldNames(string $provider): array
{
    return array_values(array_unique(array_merge(
        upstreamRateLimitCredentialFieldNames($provider),
        upstreamRateLimitIdentityFieldNames($provider)
    )));
}

/**
 * Stable SHA-256 fingerprint for provider + credential material (no raw secrets in logs).
 *
 * @param string $provider Weather source type
 * @param array<string, mixed> $sourceConfig Source block from weather_sources
 * @return string 64-char hex digest
 */
function upstreamRateFingerprint(string $provider, array $sourceConfig): string
{
    $material = [];
    foreach (upstreamRateLimitFingerprintFieldNames($provider) as $field) {
        if (!isset($sourceConfig[$field]) || !is_string($sourceConfig[$field])) {
            continue;
        }
        $value = trim($sourceConfig[$field]);
        if ($value === '') {
            continue;
        }
        $material[$field] = $value;
    }
    ksort($material);

    $canonical = $provider . "\n" . json_encode($material, JSON_UNESCAPED_UNICODE);

    return hash('sha256', $canonical);
}

/**
 * Stable SHA-256 fingerprint for one rate-limit scope (subset of credential fields).
 *
 * Scoped fingerprints use a distinct canonical prefix so they do not collide with
 * upstreamRateFingerprint() bucket paths for the same provider.
 *
 * @param string $provider Weather source type
 * @param string $scope Scope label (e.g. api_key, application_key)
 * @param array<string, mixed> $sourceConfig Source block from weather_sources
 * @param list<string> $fieldNames Fields to include in this scope
 * @return string 64-char hex digest
 */
function upstreamRateFingerprintForScope(
    string $provider,
    string $scope,
    array $sourceConfig,
    array $fieldNames
): string {
    $material = [];
    foreach ($fieldNames as $field) {
        if (!isset($sourceConfig[$field]) || !is_string($sourceConfig[$field])) {
            continue;
        }
        $value = trim($sourceConfig[$field]);
        if ($value === '') {
            continue;
        }
        $material[$field] = $value;
    }
    ksort($material);

    $canonical = $provider . '/' . $scope . "\n" . json_encode($material, JSON_UNESCAPED_UNICODE);

    return hash('sha256', $canonical);
}

/**
 * Fingerprint for global 429/503 circuit-breaker coordination across airports.
 *
 * Ambient coordinates on developer application_key because that is the shared upstream cap.
 *
 * @param string $provider Weather source type
 * @param array<string, mixed> $sourceConfig Source block from weather_sources
 * @return string 64-char hex digest
 */
function upstreamRateGlobalCredentialFingerprint(string $provider, array $sourceConfig): string
{
    return match ($provider) {
        'ambient' => upstreamRateFingerprintForScope(
            'ambient',
            'application_key',
            $sourceConfig,
            ['application_key']
        ),
        default => upstreamRateFingerprint($provider, $sourceConfig),
    };
}

/**
 * Token buckets that must all allow a request for this source (AND across scopes).
 *
 * @param array<string, mixed> $source weather_sources entry (must include type)
 * @return list<array{scope: string, fingerprint: string, rpm: int, burst: int}>
 */
function upstreamRateLimitScopesForSource(array $source): array
{
    $provider = $source['type'] ?? '';
    if (!is_string($provider) || $provider === '') {
        return [];
    }

    return match ($provider) {
        'ambient' => [
            [
                'scope' => 'api_key',
                'fingerprint' => upstreamRateFingerprintForScope('ambient', 'api_key', $source, ['api_key']),
                'rpm' => UPSTREAM_RATE_LIMIT_AMBIENT_API_KEY_RPM,
                'burst' => UPSTREAM_RATE_LIMIT_AMBIENT_API_KEY_BURST,
            ],
            [
                'scope' => 'application_key',
                'fingerprint' => upstreamRateFingerprintForScope('ambient', 'application_key', $source, ['application_key']),
                'rpm' => UPSTREAM_RATE_LIMIT_AMBIENT_APPLICATION_KEY_RPM,
                'burst' => UPSTREAM_RATE_LIMIT_AMBIENT_APPLICATION_KEY_BURST,
            ],
        ],
        default => (static function () use ($provider, $source): array {
            $policy = upstreamRateLimitPolicyForProvider($provider);
            if ($policy['rpm'] <= 0 || $policy['burst'] <= 0) {
                return [];
            }

            return [[
                'scope' => 'credential',
                'fingerprint' => upstreamRateFingerprint($provider, $source),
                'rpm' => $policy['rpm'],
                'burst' => $policy['burst'],
            ]];
        })(),
    };
}

/**
 * RPM and burst limits for a provider (self-throttle before upstream 429s).
 *
 * @param string $provider Weather source type
 * @return array{rpm: int, burst: int}
 */
function upstreamRateLimitPolicyForProvider(string $provider): array
{
    return match ($provider) {
        'tempest' => [
            'rpm' => UPSTREAM_RATE_LIMIT_TEMPEST_RPM,
            'burst' => UPSTREAM_RATE_LIMIT_TEMPEST_BURST,
        ],
        'ambient' => [
            'rpm' => UPSTREAM_RATE_LIMIT_AMBIENT_API_KEY_RPM,
            'burst' => UPSTREAM_RATE_LIMIT_AMBIENT_API_KEY_BURST,
        ],
        'pwsweather' => [
            'rpm' => UPSTREAM_RATE_LIMIT_PWSWEATHER_RPM,
            'burst' => UPSTREAM_RATE_LIMIT_PWSWEATHER_BURST,
        ],
        'weatherlink_v2', 'weatherlink_v1' => [
            'rpm' => UPSTREAM_RATE_LIMIT_WEATHERLINK_RPM,
            'burst' => UPSTREAM_RATE_LIMIT_WEATHERLINK_BURST,
        ],
        'synopticdata' => [
            'rpm' => UPSTREAM_RATE_LIMIT_SYNOPTIC_RPM,
            'burst' => UPSTREAM_RATE_LIMIT_SYNOPTIC_BURST,
        ],
        'metar' => [
            'rpm' => UPSTREAM_RATE_LIMIT_METAR_HTTP_RPM,
            'burst' => UPSTREAM_RATE_LIMIT_METAR_HTTP_BURST,
        ],
        'nws' => [
            'rpm' => UPSTREAM_RATE_LIMIT_NWS_RPM,
            'burst' => UPSTREAM_RATE_LIMIT_NWS_BURST,
        ],
        default => [
            'rpm' => UPSTREAM_RATE_LIMIT_DEFAULT_RPM,
            'burst' => UPSTREAM_RATE_LIMIT_DEFAULT_BURST,
        ],
    };
}

/**
 * Pure token-bucket step (testable without filesystem).
 *
 * @param float $tokens Current token balance
 * @param float $lastRefill Unix timestamp of last refill
 * @param int $rpm Sustained requests per minute
 * @param int $burst Maximum bucket size
 * @param float $now Current Unix timestamp
 * @return array{allowed: bool, tokens: float, last_refill: float}
 */
function upstreamRateTokenBucketComputeTake(
    float $tokens,
    float $lastRefill,
    int $rpm,
    int $burst,
    float $now
): array {
    $rpm = max(1, $rpm);
    $burst = max(1, $burst);
    $refillRate = $rpm / 60.0;

    if ($lastRefill <= 0.0) {
        $lastRefill = $now;
        $tokens = (float) $burst;
    } elseif ($now > $lastRefill) {
        $tokens = min((float) $burst, $tokens + ($now - $lastRefill) * $refillRate);
        $lastRefill = $now;
    }

    if ($tokens >= 1.0) {
        return [
            'allowed' => true,
            'tokens' => $tokens - 1.0,
            'last_refill' => $lastRefill,
        ];
    }

    return [
        'allowed' => false,
        'tokens' => $tokens,
        'last_refill' => $lastRefill,
    ];
}

/**
 * Resolve on-disk state path for a fingerprint (test hook via $GLOBALS).
 */
function upstreamRateLimitStateFilePath(string $fingerprint): string
{
    if (isset($GLOBALS['upstreamRateLimitTestRoot'])
        && is_string($GLOBALS['upstreamRateLimitTestRoot'])
        && $GLOBALS['upstreamRateLimitTestRoot'] !== ''
    ) {
        $root = rtrim($GLOBALS['upstreamRateLimitTestRoot'], '/');
        $prefix = strlen($fingerprint) >= 2 ? substr($fingerprint, 0, 2) : $fingerprint;

        return $root . '/' . $prefix . '/' . $fingerprint . '.json';
    }

    return getUpstreamRateLimitStatePath($fingerprint);
}

/**
 * Attempt to consume one outbound request token for this fingerprint.
 *
 * Fails open (allows request) when the state file cannot be read or written.
 *
 * @param string $fingerprint From upstreamRateFingerprint()
 * @param int $rpm Sustained requests per minute
 * @param int $burst Maximum burst size
 * @param float|null $now Injectable Unix timestamp for tests
 * @return bool True when a token was consumed; false when budget exhausted
 */
function upstreamRateTryTake(string $fingerprint, int $rpm, int $burst, ?float $now = null): bool
{
    if ($now === null && isset($GLOBALS['upstreamRateLimitTestNow']) && is_numeric($GLOBALS['upstreamRateLimitTestNow'])) {
        $now = (float) $GLOBALS['upstreamRateLimitTestNow'];
    }
    $now = $now ?? microtime(true);
    $stateFile = upstreamRateLimitStateFilePath($fingerprint);
    $stateDir = dirname($stateFile);
    if (!is_dir($stateDir) && !@mkdir($stateDir, 0755, true) && !is_dir($stateDir)) {
        upstreamRateLimitRecordFailOpen('state_dir_unavailable', $fingerprint, ['dir' => $stateDir]);

        return true;
    }

    $fp = @fopen($stateFile, 'c+');
    if ($fp === false) {
        upstreamRateLimitRecordFailOpen('state_file_open_failed', $fingerprint, ['file' => $stateFile]);

        return true;
    }

    if (!@flock($fp, LOCK_EX)) {
        @fclose($fp);
        upstreamRateLimitRecordFailOpen('flock_failed', $fingerprint, ['file' => $stateFile]);

        return true;
    }

    $tokens = (float) $burst;
    $lastRefill = 0.0;
    $fileSize = @filesize($stateFile);
    if ($fileSize !== false && $fileSize > 0) {
        rewind($fp);
        $content = @stream_get_contents($fp);
        if ($content !== false && $content !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $tokens = (float) ($decoded['tokens'] ?? $burst);
                $lastRefill = (float) ($decoded['last_refill'] ?? 0.0);
            } else {
                // Corrupt state: do not reset to full burst (would bypass limits)
                aviationwx_log('warning', 'upstream rate limit state corrupt, treating bucket as empty', [
                    'file' => $stateFile,
                    'fingerprint_prefix' => substr($fingerprint, 0, 8),
                ], 'app');
                $tokens = 0.0;
                $lastRefill = $now;
            }
        }
    }

    $result = upstreamRateTokenBucketComputeTake($tokens, $lastRefill, $rpm, $burst, $now);

    rewind($fp);
    ftruncate($fp, 0);
    $payload = json_encode([
        'tokens' => $result['tokens'],
        'last_refill' => $result['last_refill'],
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        aviationwx_log('warning', 'upstream rate limit state encode failed, allowing request', [
            'fingerprint_prefix' => substr($fingerprint, 0, 8),
        ], 'app');
        flock($fp, LOCK_UN);
        fclose($fp);

        return $result['allowed'];
    }

    $written = @fwrite($fp, $payload);
    @fflush($fp);
    if ($written === false) {
        aviationwx_log('warning', 'upstream rate limit state write failed, allowing request', [
            'fingerprint_prefix' => substr($fingerprint, 0, 8),
            'file' => $stateFile,
        ], 'app');
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    return $result['allowed'];
}

/**
 * Log fail-open and increment health counters (throttle disabled for that attempt).
 *
 * @param array<string, mixed> $context Additional log context (no secrets)
 */
function upstreamRateLimitRecordFailOpen(string $reason, string $fingerprint, array $context = []): void
{
    aviationwx_log('warning', 'upstream rate limit state unavailable, allowing request', array_merge($context, [
        'reason' => $reason,
        'fingerprint_prefix' => substr($fingerprint, 0, 8),
    ]), 'app');

    if (!function_exists('weatherHealthTrackUpstreamRateLimitFailOpen')) {
        require_once __DIR__ . '/weather-health.php';
    }
    weatherHealthTrackUpstreamRateLimitFailOpen($reason);
}

/**
 * PHPUnit only: enforce buckets while APP_ENV=testing.
 */
function upstreamRateLimitTestForceEnforcement(): void
{
    $GLOBALS['upstreamRateLimitTestForceEnforcement'] = true;
}

/**
 * PHPUnit only: restore default test-mode bypass for upstream throttling.
 */
function upstreamRateLimitTestClearForceEnforcement(): void
{
    unset($GLOBALS['upstreamRateLimitTestForceEnforcement']);
}

/**
 * Consume one upstream token for this source when throttling applies.
 *
 * @param array<string, mixed> $source weather_sources entry (must include type)
 * @return array{allowed: bool, fingerprint_prefix: string|null}
 */
function upstreamRateLimitConsumeForSource(array $source): array
{
    $forceInTests = !empty($GLOBALS['upstreamRateLimitTestForceEnforcement']);
    if (!$forceInTests && (isTestMode() || shouldMockExternalServices())) {
        return ['allowed' => true, 'fingerprint_prefix' => null];
    }

    $provider = $source['type'] ?? '';
    if (!is_string($provider) || $provider === '') {
        return ['allowed' => true, 'fingerprint_prefix' => null];
    }

    $scopes = upstreamRateLimitScopesForSource($source);
    if ($scopes === []) {
        return ['allowed' => true, 'fingerprint_prefix' => null];
    }

    $prefix = null;
    foreach ($scopes as $scope) {
        if (!upstreamRateTryTake($scope['fingerprint'], $scope['rpm'], $scope['burst'])) {
            return [
                'allowed' => false,
                'fingerprint_prefix' => substr($scope['fingerprint'], 0, 8),
            ];
        }
        if ($prefix === null) {
            $prefix = substr($scope['fingerprint'], 0, 8);
        }
    }

    return ['allowed' => true, 'fingerprint_prefix' => $prefix];
}
