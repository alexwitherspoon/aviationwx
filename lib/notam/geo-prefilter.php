<?php

declare(strict_types=1);

/**
 * Geospatial NOTAM query tuning and cheap XML pre-filters.
 */

require_once __DIR__ . '/../constants.php';

/**
 * NMS query parameters for the geospatial NOTAM fetch (TFR-focused).
 *
 * @param float $latitude Airport latitude in decimal degrees
 * @param float $longitude Airport longitude in decimal degrees
 * @param int $radius Search radius in nautical miles
 * @return array<string, float|int|string> Query string parameters for the NMS geo endpoint
 */
function notamBuildGeoQueryParams(float $latitude, float $longitude, int $radius): array
{
    return [
        'latitude' => $latitude,
        'longitude' => $longitude,
        'radius' => $radius,
        'feature' => NOTAM_GEO_QUERY_FEATURE,
    ];
}

/**
 * Cheap scan before XML parse: geospatial results with feature=AIRSPACE should be TFR-like.
 *
 * Location queries still supply aerodrome/runway closures; geo parse is TFR-only.
 *
 * @param string $xml Raw AIXM XML from an NMS geospatial query
 * @return bool True when the payload looks TFR-related
 */
function notamAixmXmlMayBeTfr(string $xml): bool
{
    if (stripos($xml, 'TFR') !== false) {
        return true;
    }
    if (stripos($xml, 'TEMPORARY FLIGHT RESTRICTION') !== false) {
        return true;
    }

    return stripos($xml, 'RESTRICTED') !== false && stripos($xml, 'AIRSPACE') !== false;
}

/**
 * Keep only geo-query XML payloads that may contain TFRs before parsing.
 *
 * @param array<int, string> $xmlStrings Raw AIXM XML strings from a geospatial NMS query
 * @return array<int, string> Subset that passed {@see notamAixmXmlMayBeTfr()}
 */
function notamFilterGeoXmlForTfrParsing(array $xmlStrings): array
{
    $kept = [];
    foreach ($xmlStrings as $xml) {
        if ($xml !== '' && notamAixmXmlMayBeTfr($xml)) {
            $kept[] = $xml;
        }
    }

    return $kept;
}
