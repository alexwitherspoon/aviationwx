<?php
/**
 * Public API - Weathercam Catalog
 *
 * GET /v1/weathercam/bulk
 *
 * One-query catalog: listed airports that have matching weathercams, each
 * nested with the same webcam objects as GET /v1/airports/{id}/webcams.
 * Image and history URLs are absolute (canonical Public API v1 base).
 *
 * Query parameters:
 * - operator: Weathercam operator only (not the GET /v1/airports union with weather sources)
 */

require_once __DIR__ . '/airports.php';
require_once __DIR__ . '/webcams.php';

/**
 * Handle GET /v1/weathercam/bulk request
 *
 * @param array $params Path parameters (empty for this endpoint)
 * @param array $context Request context from middleware
 */
function handleGetWeathercamBulk(array $params, array $context): void
{
    $operatorParse = parseOperatorQueryFromGet();
    if (!$operatorParse['ok']) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            $operatorParse['error'],
            400
        );
        return;
    }

    $catalog = getWeathercamBulkAirports($operatorParse['value']);
    $webcamCount = 0;
    foreach ($catalog as $airport) {
        $webcamCount += count($airport['webcams'] ?? []);
    }

    sendPublicApiCacheHeaders('metadata');
    sendPublicApiSuccess(
        ['airports' => $catalog],
        [
            'total' => count($catalog),
            'webcam_count' => $webcamCount,
            'coordinate_system' => 'WGS84',
        ]
    );
}

/**
 * Cached catalog, then optional weathercam-operator slice.
 *
 * @param string|null $operatorFilter Lowercase slug, or null for every weathercam
 * @return list<array<string, mixed>>
 */
function getWeathercamBulkAirports(?string $operatorFilter): array
{
    $full = recallWeathercamBulkCatalog();
    if ($full === null) {
        $full = buildWeathercamBulkAirports(getPublicApiAirports(true, false), null);
        rememberWeathercamBulkCatalog($full);
    }

    return filterWeathercamBulkAirportsByOperator($full, $operatorFilter);
}

/**
 * Compose airport summaries with nested matching weathercams.
 *
 * Airports with no matching weathercam are omitted. Unlike GET /v1/airports,
 * weather-source operator does not keep an airport in this catalog.
 *
 * @param array<string, mixed> $airports Airport ID to configuration
 * @param string|null $operatorFilter Lowercase slug, or null for every weathercam
 * @return list<array<string, mixed>>
 */
function buildWeathercamBulkAirports(array $airports, ?string $operatorFilter): array
{
    $formattedAirports = [];
    foreach ($airports as $airportId => $airport) {
        if (!is_string($airportId) || $airportId === '' || !is_array($airport)) {
            continue;
        }
        $webcams = listFormattedWebcamsForAirport($airportId, $airport, $operatorFilter, true);
        if ($webcams === []) {
            continue;
        }
        $summary = formatAirportSummary($airportId, $airport, $operatorFilter);
        $summary['webcams'] = $webcams;
        $formattedAirports[] = $summary;
    }

    usort($formattedAirports, function ($a, $b) {
        return strcmp((string) $a['id'], (string) $b['id']);
    });

    return $formattedAirports;
}

/**
 * Slice a full catalog by resolved weathercam operator without mutating cache rows.
 *
 * @param list<array<string, mixed>> $airports
 * @param string|null $operatorFilter Lowercase slug, or null for no slice
 * @return list<array<string, mixed>>
 */
function filterWeathercamBulkAirportsByOperator(array $airports, ?string $operatorFilter): array
{
    if ($operatorFilter === null) {
        return $airports;
    }

    $filtered = [];
    foreach ($airports as $airport) {
        if (!is_array($airport)) {
            continue;
        }
        $webcams = [];
        foreach ($airport['webcams'] ?? [] as $webcam) {
            if (is_array($webcam) && ($webcam['operator'] ?? '') === $operatorFilter) {
                $webcams[] = $webcam;
            }
        }
        if ($webcams === []) {
            continue;
        }
        $row = $airport;
        $row['webcams'] = $webcams;
        $row['webcam_count'] = count($webcams);
        $filtered[] = $row;
    }

    return $filtered;
}

/**
 * Store the unfiltered catalog for the given airports.json SHA.
 *
 * @param list<array<string, mixed>> $airports
 * @param string|null $sha Config SHA; omitted uses getConfigFileSha256()
 */
function rememberWeathercamBulkCatalog(array $airports, ?string $sha = null): void
{
    $sha = $sha ?? getConfigFileSha256();
    if ($sha === null || $sha === '') {
        return;
    }

    $GLOBALS['_aviationwx_weathercam_bulk_sha'] = $sha;
    $GLOBALS['_aviationwx_weathercam_bulk_airports'] = $airports;

    if (function_exists('apcu_store')) {
        apcu_store(
            'aviationwx_weathercam_bulk',
            ['sha' => $sha, 'airports' => $airports],
            CONFIG_CACHE_TTL
        );
    }
}

/**
 * @return list<array<string, mixed>>|null
 */
function recallWeathercamBulkCatalog(): ?array
{
    $sha = getConfigFileSha256();
    if ($sha === null || $sha === '') {
        return null;
    }

    $localSha = $GLOBALS['_aviationwx_weathercam_bulk_sha'] ?? null;
    $localAirports = $GLOBALS['_aviationwx_weathercam_bulk_airports'] ?? null;
    if ($localSha === $sha && is_array($localAirports)) {
        return $localAirports;
    }

    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch('aviationwx_weathercam_bulk');
        if (
            is_array($cached)
            && ($cached['sha'] ?? '') === $sha
            && isset($cached['airports'])
            && is_array($cached['airports'])
        ) {
            $GLOBALS['_aviationwx_weathercam_bulk_sha'] = $sha;
            $GLOBALS['_aviationwx_weathercam_bulk_airports'] = $cached['airports'];
            return $cached['airports'];
        }
    }

    return null;
}

/**
 * Drop in-request and APCu weathercam catalog cache.
 */
function resetWeathercamBulkCatalogCache(): void
{
    $GLOBALS['_aviationwx_weathercam_bulk_sha'] = null;
    $GLOBALS['_aviationwx_weathercam_bulk_airports'] = null;
    if (function_exists('apcu_delete')) {
        apcu_delete('aviationwx_weathercam_bulk');
    }
}
