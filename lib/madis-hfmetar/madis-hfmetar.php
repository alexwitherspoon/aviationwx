<?php
/**
 * MADIS HFMETAR decoder
 *
 * Turns a classic netCDF HFMETAR file into per-station observations in
 * AviationWX standard units. HFMETAR carries Federal ASOS observations at
 * 5-minute marks (12 per hour per station).
 *
 * Wind direction is true north (degrees), matching MADIS convention and the
 * project's internal standard. Units converted here:
 * - Temperature/dewpoint: kelvin -> celsius
 * - Wind speed/gust: m/s -> knots (kts = m/s x 1.943844)
 * - Altimeter: pascal -> inHg
 * - Visibility: meters -> statute miles
 *
 * @package AviationWX\Madis
 */

require_once __DIR__ . '/netcdf-classic.php';
require_once __DIR__ . '/../units.php';

use AviationWX\Madis\NetcdfClassic;
use AviationWX\Madis\NetcdfFormatException;

/** Federal ASOS metarType value in HFMETAR files. */
const MADIS_HFMETAR_TYPE_FEDERAL_ASOS = 1;

/** Base URL for the MADIS HFMETAR realtime netCDF files. */
const MADIS_HFMETAR_CACHE_GZ_URL_PREFIX = 'https://madis-data.ncep.noaa.gov/madisPublic1/data/LDAD/hfmetar/netCDF/';

/**
 * URL of the netCDF file for the given UTC hour.
 *
 * @param int|null $now Reference time for tests
 * @return string
 */
function madisHfmetarCurrentHourFileUrl(?int $now = null): string
{
    $now = $now ?? time();

    return MADIS_HFMETAR_CACHE_GZ_URL_PREFIX . gmdate('Ymd_H', $now) . '00.gz';
}

/**
 * Decode HFMETAR file contents into observations for one station.
 *
 * @param string $bytes Raw netCDF bytes, gzipped or not
 * @param string $stationId Station ICAO (e.g. 'KSPB')
 * @return array<int, array<string, mixed>>|null Observations sorted by time, or null when the
 *   station is absent or the file cannot be parsed
 */
function madisHfmetarDecodeBytes(string $bytes, string $stationId): ?array
{
    $stationId = strtoupper(trim($stationId));
    if ($stationId === '') {
        return null;
    }
    if (substr($bytes, 0, 2) === "\x1f\x8b") {
        $bytes = gzdecode($bytes);
        if ($bytes === false) {
            return null;
        }
    }

    try {
        $netcdf = new NetcdfClassic($bytes);
    } catch (NetcdfFormatException $e) {
        return null;
    }

    return madisHfmetarObservationsFromNetcdf($netcdf, $stationId);
}

/**
 * Decode an HFMETAR file on disk into observations for one station.
 *
 * @param string $path Path to the .gz or .nc file
 * @param string $stationId Station ICAO (e.g. 'KSPB')
 * @return array<int, array<string, mixed>>|null
 */
function madisHfmetarDecodeFile(string $path, string $stationId): ?array
{
    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        return null;
    }

    return madisHfmetarDecodeBytes($bytes, $stationId);
}

/**
 * Read a required per-record column, or null when the file lacks it.
 *
 * @param NetcdfClassic $netcdf Parsed file
 * @param string $name Variable name
 * @return array<int, mixed>|null
 */
function madisHfmetarColumn(NetcdfClassic $netcdf, string $name): ?array
{
    if (!$netcdf->hasVariable($name)) {
        return null;
    }

    return $netcdf->readColumn($name);
}

/**
 * Build observation arrays for one station from parsed netCDF.
 *
 * Station match uses the stationId column. Rows whose observation time or
 * wind fields are missing sentinels are dropped; a row with no usable wind
 * still counts as an observation for the daily tracking ring.
 *
 * @param NetcdfClassic $netcdf Parsed file
 * @param string $stationId Station ICAO (uppercase)
 * @return array<int, array<string, mixed>>|null
 */
function madisHfmetarObservationsFromNetcdf(NetcdfClassic $netcdf, string $stationId): ?array
{
    $stationIds = madisHfmetarColumn($netcdf, 'stationId');
    if ($stationIds === null) {
        return null;
    }

    $rows = [];
    foreach ($stationIds as $record => $id) {
        if (strtoupper(trim((string)$id)) !== $stationId) {
            continue;
        }
        $rows[] = $record;
    }
    if ($rows === []) {
        return null;
    }

    $obsTime = madisHfmetarColumn($netcdf, 'observationTime');
    $temperature = madisHfmetarColumn($netcdf, 'temperature');
    $dewpoint = madisHfmetarColumn($netcdf, 'dewpoint');
    $altimeter = madisHfmetarColumn($netcdf, 'altimeter');
    $windDir = madisHfmetarColumn($netcdf, 'windDir');
    $windSpeed = madisHfmetarColumn($netcdf, 'windSpeed');
    $windGust = madisHfmetarColumn($netcdf, 'windGust');
    $visibility = madisHfmetarColumn($netcdf, 'visibility');
    $metarType = madisHfmetarColumn($netcdf, 'metarType');
    $raw = madisHfmetarColumn($netcdf, 'rawMessage');
    $latitude = madisHfmetarColumn($netcdf, 'latitude');
    $longitude = madisHfmetarColumn($netcdf, 'longitude');
    $skyCvr = madisHfmetarColumn($netcdf, 'skyCvr');
    $skyBase = madisHfmetarColumn($netcdf, 'skyCovLayerBase');

    $observations = [];
    foreach ($rows as $record) {
        if ($obsTime === null || $netcdf->isMissing('observationTime', $obsTime[$record])) {
            continue;
        }
        $time = (int)$obsTime[$record];

        $observation = [
            'obs_time' => $time,
            'temperature_c' => madisHfmetarKelvinToCelsius($temperature, $netcdf, 'temperature', $record),
            'dewpoint_c' => madisHfmetarKelvinToCelsius($dewpoint, $netcdf, 'dewpoint', $record),
            'altimeter_inhg' => madisHfmetarPascalToInhg($altimeter, $netcdf, 'altimeter', $record),
            'wind_direction_deg' => madisHfmetarValueOrNull($windDir, $netcdf, 'windDir', $record),
            'wind_speed_kts' => madisHfmetarMpsToKnots($windSpeed, $netcdf, 'windSpeed', $record),
            'gust_speed_kts' => madisHfmetarMpsToKnots($windGust, $netcdf, 'windGust', $record),
            'visibility_sm' => madisHfmetarMetersToSm($visibility, $netcdf, 'visibility', $record),
            'metar_type' => madisHfmetarValueOrNull($metarType, $netcdf, 'metarType', $record),
            'latitude' => madisHfmetarValueOrNull($latitude, $netcdf, 'latitude', $record),
            'longitude' => madisHfmetarValueOrNull($longitude, $netcdf, 'longitude', $record),
            'ceiling_ft' => null,
            'cloud_cover' => null,
            'raw' => is_array($raw) ? (string)($raw[$record] ?? '') : '',
        ];
        $cloud = madisHfmetarCloudLayer($skyCvr, $skyBase, $netcdf, $record);
        if ($cloud !== null) {
            $observation['ceiling_ft'] = $cloud['ceiling_ft'];
            $observation['cloud_cover'] = $cloud['cloud_cover'];
        }
        $observations[] = $observation;
    }

    if ($observations === []) {
        return null;
    }
    usort($observations, static fn ($a, $b) => $a['obs_time'] <=> $b['obs_time']);

    return $observations;
}

/**
 * Convert a kelvin column value to celsius, null when missing.
 *
 * @param array<int, mixed>|null $column
 */
function madisHfmetarKelvinToCelsius(?array $column, NetcdfClassic $netcdf, string $name, int $record): ?float
{
    $value = madisHfmetarValueOrNull($column, $netcdf, $name, $record);
    if ($value === null) {
        return null;
    }

    return kelvinToCelsius((float) $value);
}

/**
 * Convert an altimeter column value from pascal to inHg.
 *
 * @param array<int, mixed>|null $column
 */
function madisHfmetarPascalToInhg(?array $column, NetcdfClassic $netcdf, string $name, int $record): ?float
{
    $value = madisHfmetarValueOrNull($column, $netcdf, $name, $record);
    if ($value === null) {
        return null;
    }

    return pascalToInhg((float) $value);
}

/**
 * Convert a wind column value from m/s to knots.
 *
 * @param array<int, mixed>|null $column
 */
function madisHfmetarMpsToKnots(?array $column, NetcdfClassic $netcdf, string $name, int $record): ?float
{
    $value = madisHfmetarValueOrNull($column, $netcdf, $name, $record);
    if ($value === null) {
        return null;
    }

    return mpsToKnots((float) $value);
}

/**
 * Convert a visibility column value from meters to statute miles.
 *
 * @param array<int, mixed>|null $column
 */
function madisHfmetarMetersToSm(?array $column, NetcdfClassic $netcdf, string $name, int $record): ?float
{
    $value = madisHfmetarValueOrNull($column, $netcdf, $name, $record);
    if ($value === null) {
        return null;
    }

    return metersToStatuteMiles((float) $value);
}

/**
 * Return a column value for a record, or null when the column or value is missing.
 *
 * @param array<int, mixed>|null $column
 */
function madisHfmetarValueOrNull(?array $column, NetcdfClassic $netcdf, string $name, int $record): mixed
{
    if ($column === null || !array_key_exists($record, $column)) {
        return null;
    }
    $value = $column[$record];
    if ($netcdf->isMissing($name, $value)) {
        return null;
    }

    return $value;
}

/**
 * Ceiling and cloud cover from the sky layer columns.
 *
 * Ceiling is the lowest BKN/OVC/OVX layer base (feet AGL), matching the
 * METAR convention; cloud_cover is that layer's code, or the first reported
 * layer when no ceiling layer exists. Layer bases are meters in the file.
 *
 * @param array<int, mixed>|null $skyCvr skyCvr column (per record: array of layer codes)
 * @param array<int, mixed>|null $skyBase skyCovLayerBase column (per record: array of floats)
 * @return array{ceiling_ft: int|null, cloud_cover: string|null}|null
 */
function madisHfmetarCloudLayer(?array $skyCvr, ?array $skyBase, NetcdfClassic $netcdf, int $record): ?array
{
    if ($skyCvr === null || $skyBase === null || !array_key_exists($record, $skyCvr)) {
        return null;
    }
    $layers = $skyCvr[$record];
    $bases = $skyBase[$record];
    if (!is_array($layers) || !is_array($bases)) {
        return null;
    }

    $ceilingFt = null;
    $ceilingCover = null;
    $firstCover = null;
    foreach ($layers as $index => $cover) {
        $cover = strtoupper(trim((string)$cover));
        if ($cover === '') {
            continue;
        }
        if ($firstCover === null) {
            $firstCover = $cover;
        }
        $baseFt = null;
        $base = $bases[$index] ?? null;
        if ($base !== null && !$netcdf->isMissing('skyCovLayerBase', $base)) {
            $baseFt = (int) round(metersToFeet((float) $base));
        }
        if (in_array($cover, ['BKN', 'OVC', 'OVX'], true) && $baseFt !== null
            && ($ceilingFt === null || $baseFt < $ceilingFt)) {
            $ceilingFt = $baseFt;
            $ceilingCover = $cover;
        }
    }

    if ($ceilingFt === null && $firstCover === null) {
        return null;
    }

    return [
        'ceiling_ft' => $ceilingFt,
        'cloud_cover' => $ceilingFt !== null ? $ceilingCover : $firstCover,
    ];
}
