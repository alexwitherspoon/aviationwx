<?php

/**
 * Per-source reference catalog health leaves.
 */

require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/ourairports/meta.php';
require_once __DIR__ . '/ourairports/refresh.php';
require_once __DIR__ . '/ourairports/urls.php';
require_once __DIR__ . '/ourairports/ingest-airports.php';
require_once __DIR__ . '/nasr/cache.php';
require_once __DIR__ . '/nasr/frequencies-cache.php';

/**
 * Load status-checks lazily to avoid circular require at module load time.
 */
function reference_data_require_status_checks(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    require_once __DIR__ . '/status-checks.php';
    $loaded = true;
}

/**
 * Latest observability timestamp for an OurAirports bulk CSV leaf.
 *
 * @param array<string, mixed> $meta
 */
function reference_data_ourairports_bulk_last_changed(bool $readable, string $path, array $meta): int
{
    $lastChanged = $readable ? (int) filemtime($path) : 0;

    foreach (['last_probe_at', 'last_fetch_at'] as $metaKey) {
        $value = $meta[$metaKey] ?? null;
        if (is_int($value) || (is_string($value) && $value !== '' && ctype_digit($value))) {
            $lastChanged = max($lastChanged, (int) $value);
        }
    }

    return $lastChanged;
}

/**
 * Map a status-checks component row into a reference source leaf.
 *
 * @param array<string, mixed> $health
 * @return array<string, mixed>
 */
function reference_data_source_from_component_health(
    string $slug,
    string $name,
    string $kind,
    array $health,
    ?bool $needsFetch = null
): array {
    $details = is_array($health['details'] ?? null) ? $health['details'] : [];
    if ($needsFetch === null) {
        $needsFetch = ($health['status'] ?? '') !== 'operational';
    }

    return [
        'slug' => $slug,
        'name' => $name,
        'kind' => $kind,
        'status' => $health['status'] ?? 'operational',
        'message' => $health['message'] ?? '',
        'lastChanged' => (int) ($health['lastChanged'] ?? 0),
        'details' => array_merge($details, [
            'local_age_seconds' => $details['local_age_seconds'] ?? null,
            'last_probe_result' => $details['last_probe_result'] ?? null,
            'upstream_last_modified' => $details['upstream_last_modified'] ?? null,
            'needs_fetch' => $needsFetch,
            'last_fetch_error' => $details['last_fetch_error'] ?? null,
            'effective_date' => $details['effective_date'] ?? null,
        ]),
    ];
}

/**
 * @return array<string, mixed>
 */
function reference_data_ourairports_bulk_source_health(string $fileKey, string $slug, string $name): array
{
    $path = ourAirportsCsvPath($fileKey);
    $meta = ourAirportsGetFileMeta($fileKey);
    $readable = is_readable($path);
    $localAge = $readable ? time() - (int) filemtime($path) : null;
    $needsFetch = ourAirportsFileNeedsFetch($fileKey);

    $status = 'operational';
    $messages = [];
    if (!$readable) {
        $status = 'down';
        $messages[] = 'CSV missing';
    } elseif ($needsFetch) {
        $status = 'degraded';
        $probeResult = $meta['last_probe_result'] ?? null;
        if ($probeResult === 'error') {
            $messages[] = 'Upstream probe failed';
        } elseif ($probeResult === 'changed') {
            $messages[] = 'Upstream changed';
        } elseif ($localAge !== null && $localAge >= OURAIRPORTS_BULK_HARD_MAX_AGE) {
            $messages[] = 'Hard max age exceeded';
        } else {
            $messages[] = 'Refresh recommended';
        }
    }

    return [
        'slug' => $slug,
        'name' => $name,
        'kind' => 'bulk',
        'status' => $status,
        'message' => $messages !== [] ? implode(' • ', $messages) : 'Up to date',
        'lastChanged' => reference_data_ourairports_bulk_last_changed($readable, $path, $meta),
        'details' => [
            'local_age_seconds' => $localAge,
            'last_probe_result' => is_string($meta['last_probe_result'] ?? null) ? $meta['last_probe_result'] : null,
            'upstream_last_modified' => is_string($meta['last_modified'] ?? null) ? $meta['last_modified'] : null,
            'needs_fetch' => $needsFetch,
            'last_fetch_error' => null,
            'effective_date' => null,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function reference_data_config_source_health(?array $config, ?string $configSha256 = null): array
{
    $airports = is_array($config) ? ($config['airports'] ?? []) : [];
    if (!is_array($airports) || $airports === []) {
        return [
            'slug' => 'airports_config',
            'name' => 'airports.json',
            'kind' => 'config',
            'status' => 'down',
            'message' => 'Configuration missing or empty',
            'lastChanged' => 0,
            'details' => [
                'local_age_seconds' => null,
                'last_probe_result' => null,
                'upstream_last_modified' => null,
                'needs_fetch' => false,
                'last_fetch_error' => null,
                'effective_date' => null,
                'airport_count' => 0,
                'runway_override_count' => 0,
                'frequencies_override_count' => 0,
                'config_sha256' => is_string($configSha256) && $configSha256 !== '' ? $configSha256 : null,
            ],
        ];
    }

    $runwayOverrideCount = 0;
    $frequenciesOverrideCount = 0;
    foreach ($airports as $airport) {
        if (!is_array($airport)) {
            continue;
        }
        if (isset($airport['runway_length_ft']) && is_numeric($airport['runway_length_ft'])) {
            $runwayOverrideCount++;
        }
        if (isset($airport['frequencies']) && is_array($airport['frequencies']) && $airport['frequencies'] !== []) {
            $frequenciesOverrideCount++;
        }
    }

    $airportCount = count($airports);
    $message = $airportCount === 1
        ? '1 airport configured'
        : "{$airportCount} airports configured";
    if ($runwayOverrideCount > 0) {
        $message .= $runwayOverrideCount === 1
            ? ' • 1 runway override'
            : " • {$runwayOverrideCount} runway overrides";
    }
    if ($frequenciesOverrideCount > 0) {
        $message .= $frequenciesOverrideCount === 1
            ? ' • 1 frequency override'
            : " • {$frequenciesOverrideCount} frequency overrides";
    }

    $configPath = function_exists('getConfigFilePath') ? getConfigFilePath() : null;
    $lastChanged = 0;
    if (is_string($configPath) && $configPath !== '' && is_readable($configPath)) {
        $lastChanged = (int) filemtime($configPath);
    }

    return [
        'slug' => 'airports_config',
        'name' => 'airports.json',
        'kind' => 'config',
        'status' => 'operational',
        'message' => $message,
        'lastChanged' => $lastChanged,
        'details' => [
            'local_age_seconds' => $lastChanged > 0 ? time() - $lastChanged : null,
            'last_probe_result' => null,
            'upstream_last_modified' => null,
            'needs_fetch' => false,
            'last_fetch_error' => null,
            'effective_date' => null,
            'airport_count' => $airportCount,
            'runway_override_count' => $runwayOverrideCount,
            'frequencies_override_count' => $frequenciesOverrideCount,
            'config_sha256' => is_string($configSha256) && $configSha256 !== '' ? $configSha256 : null,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function reference_data_faa_ngda_runways_source_health(): array
{
    $path = CACHE_FAA_NGDA_RUNWAYS_CSV;
    $readable = is_readable($path);
    $localAge = $readable ? time() - (int) filemtime($path) : null;
    $needsRefresh = faaNgdaRunwayCsvNeedsRefresh();

    $status = 'operational';
    $message = 'Up to date';
    if (!$readable) {
        $status = 'down';
        $message = 'FAA NGDA CSV missing';
    } elseif ($needsRefresh) {
        $status = 'degraded';
        $message = 'Refresh recommended';
    }

    return [
        'slug' => 'faa_ngda_runways',
        'name' => 'FAA NGDA runways',
        'kind' => 'bulk',
        'status' => $status,
        'message' => $message,
        'lastChanged' => $readable ? (int) filemtime($path) : 0,
        'details' => [
            'local_age_seconds' => $localAge,
            'last_probe_result' => null,
            'upstream_last_modified' => null,
            'needs_fetch' => $needsRefresh,
            'last_fetch_error' => null,
            'effective_date' => null,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function reference_data_runways_merged_source_health(?array $config): array
{
    reference_data_require_status_checks();

    return reference_data_source_from_component_health(
        'runways_merged',
        'Merged runway cache',
        'computed',
        checkRunwayCacheHealth($config)
    );
}

/**
 * @return array<string, mixed>
 */
function reference_data_nasr_apt_source_health(): array
{
    reference_data_require_status_checks();
    $health = checkNasrAptCacheHealth();
    $details = is_array($health['details'] ?? null) ? $health['details'] : [];

    return reference_data_source_from_component_health(
        'nasr_apt',
        'NASR APT',
        'bulk',
        $health,
        (bool) ($details['needs_refresh'] ?? false)
    );
}

/**
 * @return array<string, mixed>
 */
function reference_data_nasr_frq_source_health(): array
{
    $path = CACHE_NASR_FRQ_DATA_FILE;
    $readable = is_readable($path);
    $localAge = $readable ? time() - (int) filemtime($path) : null;
    $needsRefresh = nasrFrqCacheNeedsRefresh();
    $meta = loadNasrAptMeta();
    $lastError = is_array($meta) ? ($meta['frq_last_fetch_error'] ?? null) : null;

    $status = 'operational';
    $messages = [];
    if (!$readable) {
        $status = 'down';
        $messages[] = 'NASR FRQ cache missing';
    } else {
        if ($needsRefresh) {
            $status = 'degraded';
            $messages[] = 'Refresh recommended';
        }
        if (is_string($lastError) && $lastError !== '') {
            $status = 'degraded';
            $messages[] = 'Last fetch failed';
        }
    }

    $airportCount = 0;
    if (is_array($meta) && isset($meta['frq_airport_count'])) {
        $airportCount = (int) $meta['frq_airport_count'];
    } elseif ($readable) {
        // @ suppresses json_decode warnings for malformed cache; we handle null below
        $decoded = @json_decode((string) @file_get_contents($path), true);
        if (is_array($decoded) && isset($decoded['airports']) && is_array($decoded['airports'])) {
            $airportCount = count($decoded['airports']);
        }
    }

    $effectiveDate = is_array($meta) ? ($meta['frq_effective_date'] ?? null) : null;

    $message = $airportCount > 0
        ? "{$airportCount} airports in FRQ cache"
        : 'NASR FRQ cache present';
    if ($messages !== []) {
        $message .= ' • ' . implode(' • ', $messages);
    } elseif (!$needsRefresh) {
        $message .= ' • Up to date';
    }

    return [
        'slug' => 'nasr_frq',
        'name' => 'NASR FRQ',
        'kind' => 'bulk',
        'status' => $status,
        'message' => $message,
        'lastChanged' => $readable ? (int) filemtime($path) : 0,
        'details' => [
            'local_age_seconds' => $localAge,
            'last_probe_result' => null,
            'upstream_last_modified' => null,
            'needs_fetch' => $needsRefresh,
            'last_fetch_error' => is_string($lastError) ? $lastError : null,
            'effective_date' => is_string($effectiveDate) ? $effectiveDate : null,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function reference_data_ourairports_identity_source_health(): array
{
    $csv = reference_data_ourairports_bulk_source_health('airports', 'ourairports_airports', 'OurAirports airports');
    $jsonStale = ourAirportsIdentityCacheIsStale();
    if ($jsonStale && ($csv['status'] ?? '') === 'operational') {
        $csv['status'] = 'degraded';
        $csv['message'] .= ' • Identity JSON older than CSV';
        $csv['details']['needs_fetch'] = true;
    }

    return $csv;
}

/**
 * @return array<string, mixed>
 */
function reference_data_ourairports_frequencies_source_health(): array
{
    $csv = reference_data_ourairports_bulk_source_health(
        'airport_frequencies',
        'ourairports_frequencies',
        'OurAirports frequencies'
    );
    $jsonStale = ourAirportsFrequenciesCacheIsStale();
    if ($jsonStale && ($csv['status'] ?? '') === 'operational') {
        $csv['status'] = 'degraded';
        $csv['message'] .= ' • Frequencies JSON older than CSV';
        $csv['details']['needs_fetch'] = true;
    }

    return $csv;
}

/**
 * @return array<string, mixed>
 */
function reference_data_country_resolution_source_health(?array $config, ?string $configSha256): array
{
    reference_data_require_status_checks();

    return reference_data_source_from_component_health(
        'country_resolution',
        'Airport country resolution',
        'computed',
        checkAirportCountryResolutionHealth($config, $configSha256)
    );
}

/**
 * @return array<string, mixed>
 */
function reference_data_wmm_source_health(): array
{
    reference_data_require_status_checks();

    return reference_data_source_from_component_health(
        'wmm',
        'World Magnetic Model',
        'bundled',
        checkMagneticDeclinationHealth(),
        false
    );
}

/**
 * Human-readable diagnostics for a source leaf details block (status page).
 *
 * @param array<string, mixed> $sourceRow
 */
function reference_data_source_diagnostics_text(array $sourceRow): string
{
    $details = is_array($sourceRow['details'] ?? null) ? $sourceRow['details'] : [];
    $parts = [];

    if (!empty($details['needs_fetch'])) {
        $parts[] = 'needs fetch';
    }
    if (is_string($details['last_probe_result'] ?? null) && $details['last_probe_result'] !== '') {
        $parts[] = 'probe: ' . $details['last_probe_result'];
    }
    if (is_string($details['last_fetch_error'] ?? null) && $details['last_fetch_error'] !== '') {
        $parts[] = 'fetch error: ' . $details['last_fetch_error'];
    }
    if (is_string($details['effective_date'] ?? null) && $details['effective_date'] !== '') {
        $parts[] = 'cycle: ' . $details['effective_date'];
    }
    if (isset($details['runway_override_count']) && (int) $details['runway_override_count'] > 0) {
        $parts[] = 'runway overrides: ' . (int) $details['runway_override_count'];
    }
    if (isset($details['frequencies_override_count']) && (int) $details['frequencies_override_count'] > 0) {
        $parts[] = 'frequency overrides: ' . (int) $details['frequencies_override_count'];
    }
    if (is_string($details['config_sha256'] ?? null) && $details['config_sha256'] !== '') {
        $parts[] = 'config sha: ' . substr($details['config_sha256'], 0, 12);
    }
    if (isset($details['local_age_seconds']) && $details['local_age_seconds'] !== null) {
        $parts[] = 'age: ' . (int) $details['local_age_seconds'] . 's';
    }

    return implode(' · ', $parts);
}
