<?php
/**
 * Wind Direction Normalization - True North
 *
 * All internal wind direction values are normalized to TRUE NORTH (degrees 0-360).
 * Display layers convert to magnetic north when needed (e.g. runway wind diagram).
 *
 * Source conventions (researched 2025):
 * - METAR, NWS, AWOSnet, SWOB (Nav Canada): true north
 * - Tempest, Ambient, WeatherLink, Synoptic: true north (calibration to true north)
 * - PWSWeather: uncertain (PWS network; consumer stations may use magnetic)
 *
 * Sources that report magnetic are converted to true at ingest using airport declination.
 *
 * @package AviationWX\Weather
 */

require_once __DIR__ . '/data/WeatherSnapshot.php';
require_once __DIR__ . '/data/WindGroup.php';
require_once __DIR__ . '/data/WeatherReading.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../heading-conversion.php';

use AviationWX\Weather\Data\WeatherSnapshot;
use AviationWX\Weather\Data\WindGroup;
use AviationWX\Weather\Data\WeatherReading;

/** Source types that report wind direction in magnetic north (convert to true at ingest) */
const WIND_SOURCES_REPORTING_MAGNETIC = ['pwsweather'];

/**
 * Normalize wind direction to true north for display/storage
 *
 * Sources that report magnetic north are converted: True = Magnetic + Declination.
 * Other sources pass through unchanged (already true north).
 *
 * @param WeatherSnapshot|null $snapshot Snapshot from adapter
 * @param array $airport Airport config (lat, lon for declination)
 * @param string $sourceType Source type (e.g. 'metar', 'pwsweather')
 * @return WeatherSnapshot|null Snapshot with normalized wind, or null if input null
 */
function normalizeWindToTrueNorth(?WeatherSnapshot $snapshot, array $airport, string $sourceType): ?WeatherSnapshot {
    if ($snapshot === null || !$snapshot->isValid) {
        return $snapshot;
    }

    if (!in_array($sourceType, WIND_SOURCES_REPORTING_MAGNETIC, true)) {
        return $snapshot;
    }

    $wind = $snapshot->wind;
    if (!$wind->direction->hasValue() || !is_numeric($wind->direction->value)) {
        return $snapshot;
    }

    $declination = getMagneticDeclination($airport);
    $magneticDir = (float) $wind->direction->value;
    $trueDir = convertMagneticToTrue($magneticDir, $declination);

    $obsTime = $wind->direction->observationTime;
    $source = $wind->direction->source ?? $snapshot->source;
    $newDirection = WeatherReading::degrees(round($trueDir), $source, $obsTime);
    $newWind = new WindGroup(
        $wind->speed,
        $newDirection,
        $wind->gust,
        $wind->source
    );

    return new WeatherSnapshot(
        source: $snapshot->source,
        fetchTime: $snapshot->fetchTime,
        temperature: $snapshot->temperature,
        dewpoint: $snapshot->dewpoint,
        humidity: $snapshot->humidity,
        pressure: $snapshot->pressure,
        precipAccum: $snapshot->precipAccum,
        wind: $newWind,
        visibility: $snapshot->visibility,
        ceiling: $snapshot->ceiling,
        cloudCover: $snapshot->cloudCover,
        rawMetar: $snapshot->rawMetar,
        isValid: $snapshot->isValid,
        metarStationId: $snapshot->metarStationId,
        stationId: $snapshot->stationId
    );
}
