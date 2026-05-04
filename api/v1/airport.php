<?php
/**
 * Public API - Get Airport Endpoint
 * 
 * GET /v1/airports/{id}
 * 
 * Returns detailed metadata for a single airport including
 * runways, frequencies, services, access info, custom_links, external_links, and related fields.
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/weather/utils.php';
require_once __DIR__ . '/../../lib/runways.php';

/**
 * Handle GET /v1/airports/{id} request
 * 
 * @param array $params Path parameters [0 => airport_id]
 * @param array $context Request context from middleware
 */
function handleGetAirport(array $params, array $context): void
{
    $airportId = validatePublicApiAirportId($params[0] ?? '');
    
    if ($airportId === null) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Invalid airport ID format',
            400
        );
        return;
    }
    
    $airport = getPublicApiAirport($airportId);
    
    if ($airport === null) {
        sendPublicApiError(
            PUBLIC_API_ERROR_AIRPORT_NOT_FOUND,
            'Airport not found: ' . $params[0],
            404
        );
        return;
    }
    
    // Format airport for response
    $formatted = formatAirportDetails($airportId, $airport);
    
    // Send cache headers for metadata
    sendPublicApiCacheHeaders('metadata');
    
    // Send response
    sendPublicApiSuccess(
        ['airport' => $formatted],
        ['airport_id' => $airportId, 'coordinate_system' => 'WGS84']
    );
}

/**
 * Format airport data for detailed response
 * 
 * Mirrors data displayed on the airport dashboard for API parity.
 * Uses existing config helpers (getBestIdentifierForLinks, getAviationRegionFromAirport,
 * getRegionalWeatherLinkForAirport) - no additional I/O, minimal CPU cost.
 * 
 * @param string $airportId Airport ID
 * @param array $airport Airport configuration
 * @return array<string, mixed> Formatted airport detail payload. Operator-defined URLs from
 *         config `links` appear as `custom_links`; built-in resolved URLs as `external_links`.
 */
function formatAirportDetails(string $airportId, array $airport): array
{
    $tzDisplay = getTimezoneDisplayForAirport($airport);
    $baseDomain = getBaseDomain();
    $url = 'https://' . $airportId . '.' . $baseDomain . '/';

    $formatted = [
        'id' => $airportId,
        'name' => $airport['name'] ?? '',
        'icao' => $airport['icao'] ?? null,
        'iata' => $airport['iata'] ?? null,
        'faa' => $airport['faa'] ?? null,
        'iso_country' => getEffectiveIso3166Alpha2ForAirport($airport),
        'lat' => $airport['lat'] ?? null,
        'lon' => $airport['lon'] ?? null,
        'elevation_ft' => $airport['elevation_ft'] ?? null,
        'timezone' => $airport['timezone'] ?? 'UTC',
        'timezone_abbreviation' => $tzDisplay['abbreviation'],
        'timezone_offset_hours' => $tzDisplay['offset_hours'],
        'magnetic_declination' => getMagneticDeclination($airport),
        'url' => $url,
        'address' => $airport['address'] ?? null,
        'maintenance' => isset($airport['maintenance']) && $airport['maintenance'] === true,
        'limited_availability' => isset($airport['limited_availability']) && $airport['limited_availability'] === true,
    ];

    // Access info (matches dashboard General Info block)
    if (isset($airport['access_type']) && in_array($airport['access_type'], ['public', 'private'], true)) {
        $formatted['access_type'] = $airport['access_type'];
        $formatted['permission_required'] = isset($airport['permission_required']) && $airport['permission_required'] === true;
    } else {
        $formatted['access_type'] = null;
        $formatted['permission_required'] = null;
    }

    // Tower status
    if (isset($airport['tower_status']) && in_array($airport['tower_status'], ['towered', 'non_towered'], true)) {
        $formatted['tower_status'] = $airport['tower_status'];
    } else {
        $formatted['tower_status'] = null;
    }
    
    // Add runways with explicit heading reference
    if (isset($airport['runways']) && is_array($airport['runways'])) {
        $formatted['runways'] = array_map(function ($runway) {
            return [
                'name' => $runway['name'] ?? '',
                'heading_1' => $runway['heading_1'] ?? null,
                'heading_2' => $runway['heading_2'] ?? null,
            ];
        }, $airport['runways']);
        $formatted['runway_heading_reference'] = runwaysSegmentsAreInTrueNorth($airport) ? 'true_north' : 'magnetic';
    } else {
        $formatted['runways'] = [];
        $formatted['runway_heading_reference'] = null;
    }
    
    // Frequencies and services: empty returns {} for schema consistency
    if (isset($airport['frequencies']) && is_array($airport['frequencies']) && !empty($airport['frequencies'])) {
        $formatted['frequencies'] = $airport['frequencies'];
    } else {
        $formatted['frequencies'] = (object) [];
    }

    if (isset($airport['services']) && is_array($airport['services']) && !empty($airport['services'])) {
        $formatted['services'] = $airport['services'];
    } else {
        $formatted['services'] = (object) [];
    }

    // Partners (public fields only - no credentials)
    if (isset($airport['partners']) && is_array($airport['partners'])) {
        $formatted['partners'] = array_values(array_filter(array_map(function ($p) {
            if (!is_array($p)) {
                return null;
            }
            $item = [
                'name' => $p['name'] ?? '',
                'url' => $p['url'] ?? '',
            ];
            if (!empty($p['logo'])) {
                $item['logo'] = $p['logo'];
            }
            if (!empty($p['description'])) {
                $item['description'] = $p['description'];
            }
            return $item;
        }, $airport['partners'])));
    } else {
        $formatted['partners'] = [];
    }

    // Config key is links[]; API uses custom_links[] to distinguish from external_links[].
    if (isset($airport['links']) && is_array($airport['links'])) {
        $formatted['custom_links'] = array_values(array_filter(array_map(function ($link) {
            if (!is_array($link) || empty($link['label']) || empty($link['url'])) {
                return null;
            }
            return [
                'label' => $link['label'],
                'url' => $link['url'],
            ];
        }, $airport['links'])));
    } else {
        $formatted['custom_links'] = [];
    }

    // Built-in link targets only (operator links are custom_links above).
    $formatted['external_links'] = buildResolvedExternalLinks($airport);
    
    // Add availability flags
    $formatted['has_weather'] = hasWeatherSources($airport);
    $formatted['has_webcams'] = isset($airport['webcams']) && is_array($airport['webcams']) && count($airport['webcams']) > 0;
    $formatted['webcam_count'] = $formatted['has_webcams'] ? count($airport['webcams']) : 0;
    
    return $formatted;
}

/**
 * Build resolved URLs for standard dashboard resources (not operator custom links).
 *
 * Order matches the built-in link row on the airport dashboard (AirNav, FAA Weather,
 * regional when applicable, ForeFlight). Config `links` are exposed separately as
 * `custom_links` in formatAirportDetails().
 *
 * Uses config helpers only (no network I/O).
 *
 * @param array $airport Airport configuration
 * @return array<array{label: string, url: string}>
 */
function buildResolvedExternalLinks(array $airport): array
{
    return airportExternalLinksBuildResolvedList($airport);
}

