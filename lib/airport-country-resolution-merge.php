<?php
/**
 * Merge scheduler-written airport country resolution aggregate into loaded config.
 *
 * The aggregate is keyed by airport id with geometry-derived ISO 3166-1 alpha-2 only.
 * Merged into each airport as `_country_resolution_geo_iso` (internal; never from operator JSON).
 *
 * @package AviationWX
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/country-resolution.php';

/**
 * Clear per-process merge fingerprint so the next loadConfig() re-reads the aggregate file.
 *
 * @return void
 */
function countryResolutionResetMergeFingerprint(): void
{
    $GLOBALS['__aviationwx_country_merge_finger'] = null;
}

/**
 * Remove merged geometry ISO from all airports (fail closed when aggregate cannot be applied).
 *
 * @param array<string, mixed> $config Root config (modified in place)
 * @return void
 */
function countryResolutionClearMergedGeometryIso(array &$config): void
{
    if (!isset($config['airports']) || !is_array($config['airports'])) {
        return;
    }
    foreach ($config['airports'] as &$ap) {
        if (is_array($ap)) {
            unset($ap['_country_resolution_geo_iso']);
        }
    }
    unset($ap);
}

/**
 * Whether the aggregate should be rebuilt: missing, invalid, config SHA mismatch, schema mismatch,
 * or on-disk age exceeds COUNTRY_RESOLUTION_AGGREGATE_MAX_AGE_SECONDS (policy: geometry-derived country
 * is refreshed periodically and always when airports.json changes).
 *
 * Uses file mtime before full JSON read when possible to keep scheduler checks cheap.
 *
 * @param string $configFilePath Resolved airports.json path
 * @param string $configSha256 SHA-256 hex of current config file contents
 * @return bool True when the worker should run
 */
function countryResolutionAggregateShouldRefresh(string $configFilePath, string $configSha256): bool
{
    if (!is_readable($configFilePath)) {
        return false;
    }
    $agg = CACHE_AIRPORT_COUNTRY_RESOLUTION_FILE;
    if (!is_readable($agg)) {
        return true;
    }
    $mtime = @filemtime($agg);
    if ($mtime !== false && (time() - $mtime) >= COUNTRY_RESOLUTION_AGGREGATE_MAX_AGE_SECONDS) {
        return true;
    }
    $raw = @file_get_contents($agg);
    if ($raw === false) {
        return true;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return true;
    }
    if (($data['version'] ?? null) !== COUNTRY_RESOLUTION_AGGREGATE_SCHEMA_VERSION) {
        return true;
    }
    if (!isset($data['config_sha256']) || !is_string($data['config_sha256']) || $data['config_sha256'] !== $configSha256) {
        return true;
    }
    return false;
}

/**
 * Merge aggregate JSON into $config['airports'][*] when file exists and matches config SHA.
 *
 * Skipped when AVIATIONWX_SKIP_COUNTRY_RESOLUTION_MERGE is true (country refresh worker).
 *
 * @param array<string, mixed> $config Root config (modified in place)
 * @param string $configSha256 SHA-256 hex of airports.json content
 * @return void
 */
function countryResolutionMergeAggregateFileIntoConfig(array &$config, string $configSha256): void
{
    if (defined('AVIATIONWX_SKIP_COUNTRY_RESOLUTION_MERGE') && AVIATIONWX_SKIP_COUNTRY_RESOLUTION_MERGE === true) {
        return;
    }
    if (!isset($config['airports']) || !is_array($config['airports'])) {
        return;
    }
    $path = CACHE_AIRPORT_COUNTRY_RESOLUTION_FILE;
    $mtime = @filemtime($path);
    if ($mtime === false) {
        $mtime = 0;
    }
    $finger = $configSha256 . '|' . (string) $mtime;
    if (($GLOBALS['__aviationwx_country_merge_finger'] ?? null) === $finger) {
        return;
    }

    if (!is_readable($path)) {
        countryResolutionClearMergedGeometryIso($config);
        return;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        countryResolutionClearMergedGeometryIso($config);
        return;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        aviationwx_log('warning', 'country resolution aggregate invalid json', ['path' => $path], 'app');
        countryResolutionClearMergedGeometryIso($config);
        return;
    }
    if (($data['version'] ?? null) !== COUNTRY_RESOLUTION_AGGREGATE_SCHEMA_VERSION) {
        countryResolutionClearMergedGeometryIso($config);
        return;
    }
    if (!isset($data['config_sha256']) || !is_string($data['config_sha256']) || $data['config_sha256'] !== $configSha256) {
        countryResolutionClearMergedGeometryIso($config);
        return;
    }
    if (!isset($data['airports']) || !is_array($data['airports'])) {
        countryResolutionClearMergedGeometryIso($config);
        return;
    }

    $GLOBALS['__aviationwx_country_merge_finger'] = $finger;

    foreach ($data['airports'] as $aid => $row) {
        if (!is_string($aid) || $aid === '') {
            continue;
        }
        $aidLower = strtolower($aid);
        if (!isset($config['airports'][$aidLower]) || !is_array($config['airports'][$aidLower])) {
            continue;
        }
        if (!is_array($row)) {
            continue;
        }
        $iso = $row['iso_country'] ?? null;
        if ($iso === null) {
            $config['airports'][$aidLower]['_country_resolution_geo_iso'] = null;
        } elseif (is_string($iso)) {
            $u = strtoupper(trim($iso));
            if ($u === '' || !countryResolutionIsValidIso3166Alpha2($u)) {
                $config['airports'][$aidLower]['_country_resolution_geo_iso'] = null;
            } else {
                $config['airports'][$aidLower]['_country_resolution_geo_iso'] = $u;
            }
        }
    }
}
