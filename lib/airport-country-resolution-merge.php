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
 * Whether the aggregate file is missing or does not match the current airports.json SHA.
 *
 * @param string $configFilePath Resolved airports.json path
 * @param string $configSha256 SHA-256 hex of current config file contents
 * @return bool True when the aggregate is missing, unreadable, invalid, version-mismatched, or SHA-mismatched
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
    $GLOBALS['__aviationwx_country_merge_finger'] = $finger;

    if (!is_readable($path)) {
        return;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        aviationwx_log('warning', 'country resolution aggregate invalid json', ['path' => $path], 'app');
        return;
    }
    if (($data['version'] ?? null) !== COUNTRY_RESOLUTION_AGGREGATE_SCHEMA_VERSION) {
        return;
    }
    if (!isset($data['config_sha256']) || !is_string($data['config_sha256']) || $data['config_sha256'] !== $configSha256) {
        return;
    }
    if (!isset($data['airports']) || !is_array($data['airports'])) {
        return;
    }
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
            $config['airports'][$aidLower]['_country_resolution_geo_iso'] = ($u === '') ? null : $u;
        }
    }
}
