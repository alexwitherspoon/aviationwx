<?php

declare(strict_types=1);

/**
 * Geospatial NOTAM query tuning and cheap XML pre-filters.
 */

require_once __DIR__ . '/../constants.php';

/**
 * NMS query parameters for the geospatial NOTAM fetch (TFR-focused).
 *
 * @return array<string, float|int|string>
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
 * @param array<int, string> $xmlStrings
 *
 * @return array<int, string>
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
