<?php
/**
 * Public API - Get Airport Endpoint
 * 
 * GET /v1/airports/{id}
 * 
 * Returns detailed metadata for a single airport including
 * runways, frequencies, services, access info, and external links.
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/weather/utils.php';

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
        ['airport_id' => $airportId]
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
 * @return array Formatted airport details
 */
function formatAirportDetails(string $airportId, array $airport): array
{
    $formatted = [
        'id' => $airportId,
        'name' => $airport['name'] ?? '',
        'icao' => $airport['icao'] ?? null,
        'iata' => $airport['iata'] ?? null,
        'faa' => $airport['faa'] ?? null,
        'lat' => $airport['lat'] ?? null,
        'lon' => $airport['lon'] ?? null,
        'elevation_ft' => $airport['elevation_ft'] ?? null,
        'timezone' => $airport['timezone'] ?? 'UTC',
        'magnetic_declination' => getMagneticDeclination($airport),
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
    
    // Add runways
    if (isset($airport['runways']) && is_array($airport['runways'])) {
        $formatted['runways'] = array_map(function ($runway) {
            return [
                'name' => $runway['name'] ?? '',
                'heading_1' => $runway['heading_1'] ?? null,
                'heading_2' => $runway['heading_2'] ?? null,
            ];
        }, $airport['runways']);
    } else {
        $formatted['runways'] = [];
    }
    
    // Add frequencies
    if (isset($airport['frequencies']) && is_array($airport['frequencies'])) {
        $formatted['frequencies'] = $airport['frequencies'];
    } else {
        $formatted['frequencies'] = [];
    }
    
    // Add services
    if (isset($airport['services']) && is_array($airport['services'])) {
        $formatted['services'] = $airport['services'];
    } else {
        $formatted['services'] = [];
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

    // Custom links from config
    if (isset($airport['links']) && is_array($airport['links'])) {
        $formatted['links'] = array_values(array_filter(array_map(function ($link) {
            if (!is_array($link) || empty($link['label']) || empty($link['url'])) {
                return null;
            }
            return [
                'label' => $link['label'],
                'url' => $link['url'],
            ];
        }, $airport['links'])));
    } else {
        $formatted['links'] = [];
    }

    // Resolved external links (same logic as dashboard - AirNav, SkyVector, AOPA, etc.)
    $formatted['external_links'] = buildResolvedExternalLinks($airport);
    
    // Add availability flags
    $formatted['has_weather'] = hasWeatherSources($airport);
    $formatted['has_webcams'] = isset($airport['webcams']) && is_array($airport['webcams']) && count($airport['webcams']) > 0;
    $formatted['webcam_count'] = $formatted['has_webcams'] ? count($airport['webcams']) : 0;
    
    return $formatted;
}

/**
 * Build resolved external link URLs for dashboard parity
 * 
 * Uses config helpers - no I/O. Returns array of {label, url} for display.
 * 
 * @param array $airport Airport configuration
 * @return array<array{label: string, url: string}>
 */
function buildResolvedExternalLinks(array $airport): array
{
    $links = [];
    $linkIdentifier = getBestIdentifierForLinks($airport);
    $aviationRegion = getAviationRegionFromAirport($airport);

    // AirNav
    $airnavUrl = !empty($airport['airnav_url'])
        ? $airport['airnav_url']
        : ($linkIdentifier ? 'https://www.airnav.com/airport/' . $linkIdentifier : null);
    if ($airnavUrl !== null) {
        $links[] = ['label' => 'AirNav', 'url' => $airnavUrl];
    }

    // SkyVector
    $skyvectorUrl = !empty($airport['skyvector_url'])
        ? $airport['skyvector_url']
        : ($linkIdentifier ? 'https://skyvector.com/airport/' . $linkIdentifier : null);
    if ($skyvectorUrl !== null) {
        $links[] = ['label' => 'SkyVector', 'url' => $skyvectorUrl];
    }

    // AOPA (US or manual override)
    $aopaUrl = !empty($airport['aopa_url'])
        ? $airport['aopa_url']
        : (($aviationRegion === 'US' && $linkIdentifier) ? 'https://www.aopa.org/destinations/airports/' . $linkIdentifier : null);
    if ($aopaUrl !== null) {
        $links[] = ['label' => 'AOPA', 'url' => $aopaUrl];
    }

    // FAA Weather (US or manual override)
    $faaWeatherUrl = null;
    if (!empty($airport['faa_weather_url'])) {
        $faaWeatherUrl = $airport['faa_weather_url'];
    } elseif ($aviationRegion === 'US' && $linkIdentifier !== null && isset($airport['lat']) && isset($airport['lon'])) {
        $buffer = 2.0;
        $minLon = (float)$airport['lon'] - $buffer;
        $minLat = (float)$airport['lat'] - $buffer;
        $maxLon = (float)$airport['lon'] + $buffer;
        $maxLat = (float)$airport['lat'] + $buffer;
        $faaId = preg_replace('/^K/', '', $linkIdentifier);
        $faaWeatherUrl = sprintf(
            'https://weathercams.faa.gov/map/%.5f,%.5f,%.5f,%.5f/airport/%s/',
            $minLon, $minLat, $maxLon, $maxLat,
            $faaId
        );
    }
    if ($faaWeatherUrl !== null) {
        $links[] = ['label' => 'FAA Weather', 'url' => $faaWeatherUrl];
    }

    // Regional weather (CA, AU, or manual override)
    $regionalLink = getRegionalWeatherLinkForAirport($airport);
    if ($regionalLink !== null) {
        $links[] = ['label' => $regionalLink['label'], 'url' => $regionalLink['url']];
    }

    // ForeFlight
    $foreflightUrl = !empty($airport['foreflight_url'])
        ? $airport['foreflight_url']
        : ($linkIdentifier ? 'foreflightmobile://maps/search?q=' . urlencode($linkIdentifier) : null);
    if ($foreflightUrl !== null) {
        $links[] = ['label' => 'ForeFlight', 'url' => $foreflightUrl];
    }

    return $links;
}

