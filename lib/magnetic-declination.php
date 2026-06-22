<?php

/**
 * Magnetic Declination Lookup
 *
 * Safety-critical: Declination aligns runway wind diagram with magnetic north.
 * Incorrect values could mislead pilots interpreting wind vs runway orientation.
 *
 * Production cascade (via getMagneticDeclination in config.php):
 * airport override → global override → offline WMM → 0.
 *
 * Uses bundled NOAA World Magnetic Model coefficients (WMM.COF).
 *
 * @see https://www.ncei.noaa.gov/products/world-magnetic-model/wmm-coefficients
 */

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/wmm/WmmCoefficients.php';
require_once __DIR__ . '/wmm/WmmCalculator.php';

/** Valid declination range (degrees). Values outside indicate bad data. */
const MAGNETIC_DECLINATION_MIN = -180.0;
const MAGNETIC_DECLINATION_MAX = 180.0;

/**
 * Load bundled WMM manifest metadata (cached for the request).
 *
 * @return array<string, mixed>|null Parsed manifest, or null when unreadable or invalid
 */
function getWmmManifest(): ?array
{
    static $manifest = null;
    static $loaded = false;

    if ($loaded) {
        return $manifest;
    }

    $loaded = true;
    $manifestPath = WmmCoefficients::getBundledManifestPath();
    if (!is_readable($manifestPath)) {
        aviationwx_log('error', 'wmm: manifest file is not readable', ['path' => $manifestPath], 'app', true);
        return null;
    }

    $manifestJson = file_get_contents($manifestPath);
    if ($manifestJson === false) {
        aviationwx_log('error', 'wmm: failed to read manifest file', ['path' => $manifestPath], 'app', true);
        return null;
    }

    $parsed = json_decode($manifestJson, true);
    if (!is_array($parsed) || json_last_error() !== JSON_ERROR_NONE) {
        aviationwx_log('error', 'wmm: invalid manifest JSON', [
            'path' => $manifestPath,
            'json_error' => json_last_error_msg(),
        ], 'app', true);
        return null;
    }

    $manifest = $parsed;

    return $manifest;
}

/**
 * Whether bundled WMM coefficients are valid for the given timestamp.
 *
 * Manifest epoch and valid_through_epoch are WMM decimal years (e.g. 2025.0..2030.0), not Unix timestamps.
 *
 * @param int $timestamp Unix timestamp (UTC)
 * @return bool True when the timestamp's decimal year is within the manifest validity window
 */
function isWmmValidForTimestamp(int $timestamp): bool
{
    if ($timestamp < 0) {
        return false;
    }

    $manifest = getWmmManifest();
    if ($manifest === null || !isset($manifest['valid_through_epoch'], $manifest['epoch'])) {
        return false;
    }

    if (!is_numeric($manifest['epoch']) || !is_numeric($manifest['valid_through_epoch'])) {
        return false;
    }

    $epochDecimalYear = (float) $manifest['epoch'];
    $validThroughDecimalYear = (float) $manifest['valid_through_epoch'];
    $decimalYear = WmmCalculator::timestampToDecimalYear($timestamp);

    return $decimalYear >= $epochDecimalYear && $decimalYear <= $validThroughDecimalYear;
}

/**
 * Fetch magnetic declination from bundled WMM coefficients.
 *
 * Returns null when coordinates are invalid, the timestamp's decimal year is outside the
 * manifest validity window (epoch..valid_through_epoch, WMM decimal years), or calculation fails.
 * Caller must fall back to 0.
 *
 * @param float $lat       Latitude (-90 to 90)
 * @param float $lon       Longitude (-180 to 180)
 * @param int|null $timestamp Unix timestamp (UTC); defaults to now
 * @return float|null Declination in degrees, or null on failure
 */
function fetchMagneticDeclinationFromWmm(float $lat, float $lon, ?int $timestamp = null): ?float
{
    $timestamp ??= time();

    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        return null;
    }

    if ($timestamp < 0) {
        return null;
    }

    if (!isWmmValidForTimestamp($timestamp)) {
        static $loggedWmmValidityFailure = false;
        if (!$loggedWmmValidityFailure) {
            $loggedWmmValidityFailure = true;
            $manifest = getWmmManifest();
            if ($manifest === null) {
                // getWmmManifest() already logged unreadable/invalid manifest details once.
            } elseif (
                !isset($manifest['valid_through_epoch'], $manifest['epoch'])
                || !is_numeric($manifest['epoch'])
                || !is_numeric($manifest['valid_through_epoch'])
            ) {
                aviationwx_log('error', 'wmm: manifest missing or non-numeric epoch bounds', [
                    'path' => WmmCoefficients::getBundledManifestPath(),
                ], 'app', true);
            } else {
                aviationwx_log('warning', 'wmm: timestamp outside coefficient validity window', [
                    'timestamp' => $timestamp,
                    'decimal_year' => WmmCalculator::timestampToDecimalYear($timestamp),
                    'epoch' => (float) $manifest['epoch'],
                    'valid_through_epoch' => (float) $manifest['valid_through_epoch'],
                ], 'app', true);
            }
        }
        return null;
    }

    try {
        $declination = WmmCalculator::getDeclination($timestamp, $lat, $lon);
    } catch (\InvalidArgumentException $e) {
        aviationwx_log('warning', 'wmm: declination calculation failed', [
            'lat' => $lat,
            'lon' => $lon,
            'timestamp' => $timestamp,
            'decimal_year' => WmmCalculator::timestampToDecimalYear($timestamp),
            'error' => $e->getMessage(),
        ], 'app', true);
        return null;
    }

    if ($declination < MAGNETIC_DECLINATION_MIN || $declination > MAGNETIC_DECLINATION_MAX) {
        aviationwx_log('warning', 'wmm: declination out of bounds', [
            'lat' => $lat,
            'lon' => $lon,
            'declination' => $declination,
        ], 'app', true);
        return null;
    }

    return $declination;
}
