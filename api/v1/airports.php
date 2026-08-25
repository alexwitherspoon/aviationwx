<?php
/**
 * Public API - List Airports Endpoint
 * 
 * GET /v1/airports
 * 
 * Returns a list of all listed (publicly discoverable) airports with basic metadata.
 * Unlisted airports (test sites, airports being commissioned) are excluded by default.
 * 
 * Query parameters:
 * - maintenance: Filter by maintenance mode ('true' or 'false')
 * - limited_availability: Filter by limited availability ('true' or 'false')
 * - include_unlisted: Include unlisted airports ('true' or 'false', default: false)
 * - operator: Include airports that have one or more matching weathercams or weather sources
 * - has_webcams: Filter by weathercam presence ('true' or 'false'); not operator-sliced
 * - has_weather: Filter by weather-source presence ('true' or 'false'); not operator-sliced
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/weather/utils.php';

/**
 * Handle GET /v1/airports request
 * 
 * @param array $params Path parameters (empty for this endpoint)
 * @param array $context Request context from middleware
 */
function handleListAirports(array $params, array $context): void
{
    $includeUnlisted = false;
    if (isset($_GET['include_unlisted'])) {
        $unlistedParam = strtolower(trim($_GET['include_unlisted']));
        $includeUnlisted = ($unlistedParam === 'true' || $unlistedParam === '1');
    }

    $airports = getPublicApiAirports(true, $includeUnlisted);

    $maintenanceFilter = null;
    if (isset($_GET['maintenance'])) {
        $maintenanceParam = strtolower(trim($_GET['maintenance']));
        if ($maintenanceParam === 'true' || $maintenanceParam === '1') {
            $maintenanceFilter = true;
        } elseif ($maintenanceParam === 'false' || $maintenanceParam === '0') {
            $maintenanceFilter = false;
        }
    }

    $limitedAvailabilityFilter = null;
    if (isset($_GET['limited_availability'])) {
        $limitedParam = strtolower(trim($_GET['limited_availability']));
        if ($limitedParam === 'true' || $limitedParam === '1') {
            $limitedAvailabilityFilter = true;
        } elseif ($limitedParam === 'false' || $limitedParam === '0') {
            $limitedAvailabilityFilter = false;
        }
    }

    $operatorParse = parseOperatorQueryFromGet();
    if (!$operatorParse['ok']) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            $operatorParse['error'],
            400
        );
        return;
    }
    $operatorFilter = $operatorParse['value'];

    $hasWebcamsFilter = parsePublicApiOptionalBooleanQuery('has_webcams');
    $hasWeatherFilter = parsePublicApiOptionalBooleanQuery('has_weather');

    $formattedAirports = [];
    foreach ($airports as $airportId => $airport) {
        $formatted = formatAirportSummary($airportId, $airport, $operatorFilter);

        if ($maintenanceFilter !== null && $formatted['maintenance'] !== $maintenanceFilter) {
            continue;
        }

        if ($limitedAvailabilityFilter !== null && $formatted['limited_availability'] !== $limitedAvailabilityFilter) {
            continue;
        }

        if ($operatorFilter !== null && !airportMatchesOperator($airport, $operatorFilter)) {
            continue;
        }

        if ($hasWebcamsFilter !== null && $formatted['has_webcams'] !== $hasWebcamsFilter) {
            continue;
        }

        if ($hasWeatherFilter !== null && $formatted['has_weather'] !== $hasWeatherFilter) {
            continue;
        }

        $formattedAirports[] = $formatted;
    }

    usort($formattedAirports, function ($a, $b) {
        return strcmp($a['id'], $b['id']);
    });

    sendPublicApiCacheHeaders('metadata');

    sendPublicApiSuccess(
        ['airports' => $formattedAirports],
        ['total' => count($formattedAirports), 'coordinate_system' => 'WGS84']
    );
}

/**
 * Format airport data for list response
 *
 * @param string $airportId Airport ID
 * @param array $airport Airport configuration
 * @param string|null $operatorFilter When set, webcam_count counts matching weathercams only
 * @return array Formatted airport summary
 */
function formatAirportSummary(string $airportId, array $airport, ?string $operatorFilter = null): array
{
    // has_weather and has_webcams are airport capabilities. webcam_count still
    // counts matching weathercams when operator is set.
    $hasWeather = hasWeatherSources($airport);
    $webcamCount = countWeathercamsForOperator($airport, $operatorFilter);
    $hasWebcams = countWeathercamsForOperator($airport, null) > 0;

    $baseDomain = getBaseDomain();
    $url = 'https://' . $airportId . '.' . $baseDomain . '/';

    return [
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
        'maintenance' => isset($airport['maintenance']) && $airport['maintenance'] === true,
        'limited_availability' => isset($airport['limited_availability']) && $airport['limited_availability'] === true,
        'unlisted' => isset($airport['unlisted']) && $airport['unlisted'] === true,
        'has_weather' => $hasWeather,
        'has_webcams' => $hasWebcams,
        'webcam_count' => $webcamCount,
        'url' => $url,
    ];
}

/**
 * Parse an optional true/false Public API query flag.
 *
 * Recognizes true/false and 1/0. Omitted or unrecognized values mean no filter.
 *
 * @param string $name Query parameter name
 * @return bool|null True/false when recognized, null when omitted or unrecognized
 */
function parsePublicApiOptionalBooleanQuery(string $name): ?bool
{
    if (!array_key_exists($name, $_GET)) {
        return null;
    }

    $raw = $_GET[$name];
    if (is_array($raw)) {
        return null;
    }

    $param = strtolower(trim((string) $raw));
    if ($param === 'true' || $param === '1') {
        return true;
    }
    if ($param === 'false' || $param === '0') {
        return false;
    }

    return null;
}

