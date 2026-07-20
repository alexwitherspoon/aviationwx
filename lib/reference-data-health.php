<?php

/**
 * Reference catalog health (consumer rollup and public operations schema).
 *
 * Per-source leaves live in reference-data-sources.php.
 *
 * @see docs/ARCHITECTURE.md#data-classification-and-observability
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cached-data-loader.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/reference-data-sources.php';

/** @var array<string, int> Rollup precedence (higher = worse). */
const REFERENCE_DATA_STATUS_RANK = [
    'operational' => 0,
    'degraded' => 1,
    'down' => 2,
];

/**
 * Return the worse of two reference-data status strings.
 */
function reference_data_status_worst(string $a, string $b): string
{
    $ra = REFERENCE_DATA_STATUS_RANK[$a] ?? 1;
    $rb = REFERENCE_DATA_STATUS_RANK[$b] ?? 1;

    return $ra >= $rb ? $a : $b;
}

/**
 * Roll up status from child rows that each expose a status field.
 *
 * @param list<array<string, mixed>> $children
 */
function reference_data_rollup_status(array $children): string
{
    $status = 'operational';
    foreach ($children as $child) {
        if (!is_array($child)) {
            continue;
        }
        $childStatus = $child['status'] ?? 'operational';
        if (!is_string($childStatus)) {
            continue;
        }
        $status = reference_data_status_worst($status, $childStatus);
    }

    return $status;
}

/**
 * Roll up lastChanged from child rows.
 *
 * @param list<array<string, mixed>> $children
 */
function reference_data_rollup_last_changed(array $children): int
{
    $last = 0;
    foreach ($children as $child) {
        if (!is_array($child)) {
            continue;
        }
        $last = max($last, (int) ($child['lastChanged'] ?? 0));
    }

    return $last;
}

/**
 * Cache invalidation basis for reference catalog health (file mtimes).
 *
 * @return list<string>
 */
function reference_data_health_cache_paths(): array
{
    return [
        CACHE_OURAIRPORTS_META_FILE,
        CACHE_OURAIRPORTS_AIRPORTS_CSV,
        CACHE_OURAIRPORTS_RUNWAYS_CSV,
        CACHE_OURAIRPORTS_FREQUENCIES_CSV,
        CACHE_RUNWAYS_DATA_FILE,
        CACHE_NASR_APT_META_FILE,
        CACHE_NASR_APT_DATA_FILE,
        CACHE_NASR_FRQ_DATA_FILE,
        CACHE_FAA_NGDA_RUNWAYS_CSV,
        CACHE_AIRPORT_COUNTRY_RESOLUTION_FILE,
        CACHE_OURAIRPORTS_FILE,
        CACHE_OURAIRPORTS_FREQUENCIES_FILE,
    ];
}

/**
 * Build APCu cache key material from on-disk reference catalog inputs.
 */
function reference_data_health_cache_basis(): string
{
    $basis = '';
    foreach (reference_data_health_cache_paths() as $path) {
        $mtime = (file_exists($path) && is_file($path)) ? (int) @filemtime($path) : 0;
        $basis .= $path . ':' . $mtime . ';';
    }

    $cfgPath = getConfigFilePath();
    if (is_string($cfgPath) && $cfgPath !== '' && file_exists($cfgPath) && is_file($cfgPath)) {
        $mt = @filemtime($cfgPath);
        $basis .= 'cfg:' . (($mt !== false) ? (int) $mt : 0);
    }

    return $basis;
}

/**
 * Build reference catalog health with APCu caching in non-test environments.
 *
 * @param array<string, mixed>|null $config Loaded config
 * @param string|null $configSha256 SHA-256 of raw airports.json
 * @return array<string, mixed>
 */
function checkReferenceDataHealth(?array $config, ?string $configSha256 = null): array
{
    if (isTestMode()) {
        return reference_data_health_build($config, $configSha256);
    }

    $cacheKey = 'status_ref_data_health_' . substr(hash('sha256', reference_data_health_cache_basis()), 0, 16);

    return getCachedData(
        static function () use ($config, $configSha256): array {
            return reference_data_health_build($config, $configSha256);
        },
        $cacheKey,
        null,
        STATUS_HEALTH_CACHE_TTL
    );
}

/**
 * Build one consumer feature row with rolled-up status.
 *
 * @param list<array<string, mixed>> $sources
 * @return array<string, mixed>
 */
function reference_data_build_consumer(
    string $slug,
    string $name,
    array $sources,
    ?string $dependencies = null
): array {
    $status = reference_data_rollup_status($sources);
    $messages = [];
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        if (($source['status'] ?? 'operational') !== 'operational' && is_string($source['message'] ?? null)) {
            $messages[] = ($source['name'] ?? $source['slug'] ?? 'source') . ': ' . $source['message'];
        }
    }

    $consumer = [
        'slug' => $slug,
        'name' => $name,
        'status' => $status,
        'message' => $messages !== [] ? implode(' • ', $messages) : 'Up to date',
        'lastChanged' => reference_data_rollup_last_changed($sources),
        'sources' => $sources,
    ];

    if ($dependencies !== null && $dependencies !== '') {
        $consumer['dependencies'] = $dependencies;
    }

    return $consumer;
}

/**
 * Build the reference_data system health component.
 *
 * @param array<string, mixed>|null $config Loaded config
 * @param string|null $configSha256 SHA-256 of raw airports.json
 * @return array<string, mixed>
 */
function reference_data_health_build(?array $config, ?string $configSha256 = null): array
{
    $consumers = [
        reference_data_build_consumer('runway_geometry', 'Runway geometry', [
            reference_data_runways_merged_source_health($config),
            reference_data_faa_ngda_runways_source_health(),
            reference_data_ourairports_bulk_source_health('runways', 'ourairports_runways', 'OurAirports runways'),
        ], 'Uses OurAirports airports.csv for centers and FAA to ICAO mapping (see airport_identity).'),
        reference_data_build_consumer('runway_performance', 'Runway performance', [
            reference_data_nasr_apt_source_health(),
        ], 'Active runway closures: see NOTAM under Live observations.'),
        reference_data_build_consumer('airport_identity', 'Airport identity', [
            reference_data_ourairports_identity_source_health(),
        ]),
        reference_data_build_consumer('airport_comms', 'Airport communications', [
            reference_data_nasr_frq_source_health(),
            reference_data_ourairports_frequencies_source_health(),
        ]),
        reference_data_build_consumer('airport_location', 'Airport location', [
            reference_data_country_resolution_source_health($config, $configSha256),
        ]),
        reference_data_build_consumer('geomagnetism', 'Geomagnetism', [
            reference_data_wmm_source_health(),
        ]),
    ];

    $status = reference_data_rollup_status($consumers);
    $messages = [];
    foreach ($consumers as $consumer) {
        if (($consumer['status'] ?? 'operational') !== 'operational') {
            $messages[] = ($consumer['name'] ?? '') . ' ' . ($consumer['status'] ?? 'degraded');
        }
    }

    return [
        'name' => 'Reference data',
        'status' => $status,
        'message' => $messages !== [] ? implode(' • ', $messages) : 'All reference catalogs within policy',
        'lastChanged' => reference_data_rollup_last_changed($consumers),
        'consumers' => $consumers,
    ];
}

/**
 * Convert internal reference_data health to public operations schema (snake_case).
 *
 * @param array<string, mixed> $component Internal checkSystemHealth component row
 * @return array<string, mixed>
 */
function reference_data_health_to_public(array $component): array
{
    $consumers = [];
    foreach ($component['consumers'] ?? [] as $consumer) {
        if (!is_array($consumer)) {
            continue;
        }

        $sources = [];
        foreach ($consumer['sources'] ?? [] as $source) {
            if (!is_array($source)) {
                continue;
            }
            $details = is_array($source['details'] ?? null) ? $source['details'] : [];
            $sources[] = [
                'slug' => (string) ($source['slug'] ?? ''),
                'name' => (string) ($source['name'] ?? ''),
                'kind' => (string) ($source['kind'] ?? 'bulk'),
                'status' => (string) ($source['status'] ?? 'operational'),
                'message' => (string) ($source['message'] ?? ''),
                'local_age_seconds' => array_key_exists('local_age_seconds', $details)
                    ? ($details['local_age_seconds'] === null ? null : (int) $details['local_age_seconds'])
                    : null,
                'last_probe_result' => $details['last_probe_result'] ?? null,
                'upstream_last_modified' => $details['upstream_last_modified'] ?? null,
                'needs_fetch' => (bool) ($details['needs_fetch'] ?? false),
                'last_fetch_error' => $details['last_fetch_error'] ?? null,
                'effective_date' => $details['effective_date'] ?? null,
            ];
        }

        $row = [
            'slug' => (string) ($consumer['slug'] ?? ''),
            'name' => (string) ($consumer['name'] ?? ''),
            'status' => (string) ($consumer['status'] ?? 'operational'),
            'message' => (string) ($consumer['message'] ?? ''),
            'last_changed' => (int) ($consumer['lastChanged'] ?? 0),
            'sources' => $sources,
        ];
        if (isset($consumer['dependencies']) && is_string($consumer['dependencies']) && $consumer['dependencies'] !== '') {
            $row['dependencies'] = $consumer['dependencies'];
        }
        $consumers[] = $row;
    }

    return [
        'status' => (string) ($component['status'] ?? 'operational'),
        'message' => (string) ($component['message'] ?? ''),
        'last_changed' => (int) ($component['lastChanged'] ?? 0),
        'consumers' => $consumers,
    ];
}
