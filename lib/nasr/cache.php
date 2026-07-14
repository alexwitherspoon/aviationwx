<?php
/**
 * NASR APT cache load/save and airport lookup.
 */

require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/identifiers.php';
require_once __DIR__ . '/parse.php';
require_once __DIR__ . '/discovery.php';

/**
 * @var array|null In-request memo for parsed NASR cache
 */
$GLOBALS['_nasr_apt_cache_memo'] = null;

/**
 * Load parsed NASR APT cache (memoized per request).
 *
 * @return array|null Cache payload or null when missing/unreadable
 */
function loadNasrAptCache(): ?array
{
    if (is_array($GLOBALS['_nasr_apt_cache_memo'])) {
        return $GLOBALS['_nasr_apt_cache_memo'];
    }

    $path = CACHE_NASR_APT_DATA_FILE;
    if (!is_readable($path)) {
        $GLOBALS['_nasr_apt_cache_memo'] = null;
        return null;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['airports']) || !is_array($decoded['airports'])) {
        $GLOBALS['_nasr_apt_cache_memo'] = null;
        return null;
    }

    $GLOBALS['_nasr_apt_cache_memo'] = $decoded;
    return $decoded;
}

/**
 * Persist NASR APT cache and metadata.
 *
 * @param array $airports Parsed airports keyed by ARPT_ID
 * @param array $meta Metadata (effective_date, source_urls, fetched_at, etc.)
 * @return bool True on success
 */
function saveNasrAptCache(array $airports, array $meta): bool
{
    ensureCacheDir(CACHE_NASR_DIR);

    $payload = [
        'schema_version' => NASR_APT_SCHEMA_VERSION,
        'airports' => $airports,
    ];

    $dataJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($dataJson === false) {
        return false;
    }

    $meta['schema_version'] = NASR_APT_SCHEMA_VERSION;
    $meta['fetched_at'] = $meta['fetched_at'] ?? gmdate('c');
    $meta['airport_count'] = count($airports);
    $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);
    if ($metaJson === false) {
        return false;
    }

    $tmpData = CACHE_NASR_APT_DATA_FILE . '.tmp.' . getmypid();
    $tmpMeta = CACHE_NASR_APT_META_FILE . '.tmp.' . getmypid();

    if (file_put_contents($tmpData, $dataJson, LOCK_EX) === false) {
        @unlink($tmpData);
        return false;
    }
    if (file_put_contents($tmpMeta, $metaJson, LOCK_EX) === false) {
        @unlink($tmpData);
        @unlink($tmpMeta);
        return false;
    }

    if (!@rename($tmpData, CACHE_NASR_APT_DATA_FILE)) {
        @unlink($tmpData);
        @unlink($tmpMeta);
        return false;
    }
    if (!@rename($tmpMeta, CACHE_NASR_APT_META_FILE)) {
        @unlink($tmpMeta);
        if (!updateNasrAptMetaFields($meta)) {
            return false;
        }
    }

    $GLOBALS['_nasr_apt_cache_memo'] = $payload;
    return true;
}

/**
 * Load NASR APT metadata file.
 *
 * @return array<string, mixed>|null
 */
function loadNasrAptMeta(): ?array
{
    $path = CACHE_NASR_APT_META_FILE;
    if (!is_readable($path)) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Merge fields into NASR meta without touching airport data cache.
 *
 * @param array<string, mixed> $fields
 * @return bool True on success
 */
function updateNasrAptMetaFields(array $fields): bool
{
    ensureCacheDir(CACHE_NASR_DIR);

    $meta = loadNasrAptMeta() ?? [];
    $meta = array_merge($meta, $fields);
    $meta['schema_version'] = NASR_APT_SCHEMA_VERSION;

    $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);
    if ($metaJson === false) {
        return false;
    }

    $tmpMeta = CACHE_NASR_APT_META_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmpMeta, $metaJson, LOCK_EX) === false) {
        @unlink($tmpMeta);
        return false;
    }

    if (!@rename($tmpMeta, CACHE_NASR_APT_META_FILE)) {
        @unlink($tmpMeta);
        return false;
    }

    return true;
}

/**
 * Whether NASR APT cache should be refreshed.
 */
function nasrAptCacheNeedsRefresh(): bool
{
    if (!is_readable(CACHE_NASR_APT_DATA_FILE)) {
        return true;
    }

    $age = time() - (int) filemtime(CACHE_NASR_APT_DATA_FILE);
    if ($age > NASR_CACHE_MAX_AGE) {
        return true;
    }

    $meta = loadNasrAptMeta();
    if ($meta !== null && nasrCycleRediscoveryNeeded($meta)) {
        return true;
    }

    return false;
}

/**
 * Resolve NASR airport record for a platform airport config row.
 *
 * @param array $airport Airport configuration
 * @return array|null NASR airport record
 */
function getNasrAirportForConfig(array $airport): ?array
{
    $cache = loadNasrAptCache();
    if ($cache === null) {
        return null;
    }

    $airports = $cache['airports'];
    foreach (nasrCandidateArptIds($airport) as $candidate) {
        if (isset($airports[$candidate])) {
            return $airports[$candidate];
        }
    }

    return null;
}

/**
 * Field elevation for performance attention (config overrides NASR).
 *
 * @param array $airport Airport configuration
 * @param array|null $nasrRecord Matched NASR record
 */
function getEffectiveFieldElevationFt(array $airport, ?array $nasrRecord): ?int
{
    if (isset($airport['elevation_ft']) && is_numeric($airport['elevation_ft'])) {
        return (int) round((float) $airport['elevation_ft']);
    }
    if ($nasrRecord !== null && isset($nasrRecord['elev_ft']) && is_numeric($nasrRecord['elev_ft'])) {
        return (int) $nasrRecord['elev_ft'];
    }
    return null;
}

/**
 * Config override for runway length when explicitly set by operator.
 */
function getConfigRunwayLengthOverrideFt(array $airport): ?int
{
    if (isset($airport['runway_length_ft']) && is_numeric($airport['runway_length_ft'])) {
        $len = (int) round((float) $airport['runway_length_ft']);
        return $len > 0 ? $len : null;
    }
    return null;
}

/**
 * Config override for runway surface code.
 */
function getConfigRunwaySurfaceOverride(array $airport): ?string
{
    if (!empty($airport['runway_surface']) && is_string($airport['runway_surface'])) {
        $surface = strtoupper(trim($airport['runway_surface']));
        return $surface !== '' ? $surface : null;
    }
    return null;
}

/**
 * Clear in-request NASR cache memo (testing).
 */
function resetNasrAptCacheMemo(): void
{
    $GLOBALS['_nasr_apt_cache_memo'] = null;
}

/**
 * Inject NASR cache for unit tests without touching disk.
 *
 * @param array|null $payload Cache payload or null to clear
 */
function setNasrAptCacheForTesting(?array $payload): void
{
    $GLOBALS['_nasr_apt_cache_memo'] = $payload;
}

/**
 * Load NASR cache from a CSV directory (testing and bootstrap).
 *
 * @return array Parsed cache payload
 */
function nasrBuildCacheFromCsvDirectory(string $csvDir): array
{
    $parsed = nasrParseAptCsvDirectory($csvDir);
    return [
        'schema_version' => NASR_APT_SCHEMA_VERSION,
        'airports' => $parsed['airports'],
        'effective_date' => $parsed['effective_date'],
    ];
}
