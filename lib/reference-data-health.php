<?php

/**
 * Reference catalog health (consumer features and source leaves).
 *
 * @see docs/ARCHITECTURE.md#data-classification-and-observability
 */

require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/ourairports/meta.php';
require_once __DIR__ . '/ourairports/refresh.php';
require_once __DIR__ . '/ourairports/urls.php';
require_once __DIR__ . '/ourairports/ingest-airports.php';

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
 * Build one OurAirports bulk CSV source leaf.
 *
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
        'lastChanged' => $readable ? (int) filemtime($path) : 0,
        'details' => [
            'local_age_seconds' => $localAge,
            'last_probe_result' => is_string($meta['last_probe_result'] ?? null) ? $meta['last_probe_result'] : null,
            'upstream_last_modified' => is_string($meta['last_modified'] ?? null) ? $meta['last_modified'] : null,
            'needs_fetch' => $needsFetch,
        ],
    ];
}

/**
 * Build FAA NGDA runway bulk source leaf.
 *
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
        ],
    ];
}

/**
 * Build merged runway cache source leaf.
 *
 * @return array<string, mixed>
 */
function reference_data_runways_merged_source_health(?array $config): array
{
    require_once __DIR__ . '/status-checks.php';
    $health = checkRunwayCacheHealth($config);

    return [
        'slug' => 'runways_merged',
        'name' => 'Merged runway cache',
        'kind' => 'computed',
        'status' => $health['status'],
        'message' => $health['message'],
        'lastChanged' => (int) ($health['lastChanged'] ?? 0),
        'details' => array_merge(
            is_array($health['details'] ?? null) ? $health['details'] : [],
            [
                'local_age_seconds' => null,
                'last_probe_result' => null,
                'upstream_last_modified' => null,
                'needs_fetch' => ($health['status'] ?? '') !== 'operational',
            ]
        ),
    ];
}

/**
 * Build NASR APT bulk source leaf.
 *
 * @return array<string, mixed>
 */
function reference_data_nasr_apt_source_health(): array
{
    require_once __DIR__ . '/status-checks.php';
    $health = checkNasrAptCacheHealth();

    return [
        'slug' => 'nasr_apt',
        'name' => 'NASR APT',
        'kind' => 'bulk',
        'status' => $health['status'],
        'message' => $health['message'],
        'lastChanged' => (int) ($health['lastChanged'] ?? 0),
        'details' => array_merge(
            is_array($health['details'] ?? null) ? $health['details'] : [],
            [
                'local_age_seconds' => null,
                'last_probe_result' => null,
                'upstream_last_modified' => null,
                'needs_fetch' => (bool) ($health['details']['needs_refresh'] ?? false),
            ]
        ),
    ];
}

/**
 * Build NASR FRQ bulk source leaf.
 *
 * @return array<string, mixed>
 */
function reference_data_nasr_frq_source_health(): array
{
    require_once __DIR__ . '/nasr/cache.php';
    require_once __DIR__ . '/nasr/frequencies-cache.php';

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
    if ($readable) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded) && isset($decoded['airports']) && is_array($decoded['airports'])) {
            $airportCount = count($decoded['airports']);
        }
    }

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
            'last_fetch_error' => $lastError,
        ],
    ];
}

/**
 * Build OurAirports airport identity source leaf (CSV + derived JSON).
 *
 * @return array<string, mixed>
 */
function reference_data_ourairports_identity_source_health(): array
{
    $csv = reference_data_ourairports_bulk_source_health('airports', 'ourairports_airports', 'OurAirports airports');
    $jsonStale = ourAirportsIdentityCacheIsStale();
    if ($jsonStale && ($csv['status'] ?? '') === 'operational') {
        $csv['status'] = 'degraded';
        $csv['message'] .= ' • Identity JSON older than CSV';
    }

    return $csv;
}

/**
 * Build OurAirports frequencies source leaf (CSV + derived JSON).
 *
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
    }

    return $csv;
}

/**
 * Build airport country resolution source leaf.
 *
 * @return array<string, mixed>
 */
function reference_data_country_resolution_source_health(?array $config, ?string $configSha256): array
{
    require_once __DIR__ . '/status-checks.php';
    $health = computeAirportCountryResolutionHealth($config, $configSha256);

    return [
        'slug' => 'country_resolution',
        'name' => 'Airport country resolution',
        'kind' => 'computed',
        'status' => $health['status'],
        'message' => $health['message'],
        'lastChanged' => (int) ($health['lastChanged'] ?? 0),
        'details' => array_merge(
            is_array($health['details'] ?? null) ? $health['details'] : [],
            [
                'local_age_seconds' => null,
                'last_probe_result' => null,
                'upstream_last_modified' => null,
                'needs_fetch' => ($health['status'] ?? '') !== 'operational',
            ]
        ),
    ];
}

/**
 * Build bundled WMM source leaf.
 *
 * @return array<string, mixed>
 */
function reference_data_wmm_source_health(): array
{
    require_once __DIR__ . '/status-checks.php';
    $health = checkMagneticDeclinationHealth();

    return [
        'slug' => 'wmm',
        'name' => 'World Magnetic Model',
        'kind' => 'bundled',
        'status' => $health['status'],
        'message' => $health['message'],
        'lastChanged' => (int) ($health['lastChanged'] ?? 0),
        'details' => array_merge(
            is_array($health['details'] ?? null) ? $health['details'] : [],
            [
                'local_age_seconds' => null,
                'last_probe_result' => null,
                'upstream_last_modified' => null,
                'needs_fetch' => false,
            ]
        ),
    ];
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
                'local_age_seconds' => isset($details['local_age_seconds']) ? (int) $details['local_age_seconds'] : null,
                'last_probe_result' => $details['last_probe_result'] ?? null,
                'upstream_last_modified' => $details['upstream_last_modified'] ?? null,
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
