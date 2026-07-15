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
 * @var array|null In-request memo for configured NASR slice
 */
$GLOBALS['_nasr_apt_cache_memo'] = null;

/**
 * @var string|null Config SHA tied to in-request memo (invalidates when airports.json changes)
 */
$GLOBALS['_nasr_apt_cache_memo_config_sha'] = null;

/**
 * Load parsed NASR APT cache for platform airports only (memoized per request).
 *
 * Reads {@see CACHE_NASR_APT_CONFIGURED_FILE}, a slice of the full NASR file limited to
 * identifiers referenced in airports.json. Rebuilds the slice when config or full cache changes.
 *
 * @return array|null Cache payload or null when missing/unreadable
 */
function loadNasrAptCache(): ?array
{
    $configSha = nasrGetConfigShaForSlice();
    if (is_array($GLOBALS['_nasr_apt_cache_memo'])
        && ($GLOBALS['_nasr_apt_cache_memo_config_sha'] ?? null) === $configSha) {
        return $GLOBALS['_nasr_apt_cache_memo'];
    }

    if ($configSha === null) {
        $GLOBALS['_nasr_apt_cache_memo'] = null;
        $GLOBALS['_nasr_apt_cache_memo_config_sha'] = null;
        return null;
    }

    if (!is_readable(CACHE_NASR_APT_DATA_FILE)) {
        $GLOBALS['_nasr_apt_cache_memo'] = null;
        $GLOBALS['_nasr_apt_cache_memo_config_sha'] = null;
        return null;
    }

    $meta = loadNasrAptMeta() ?? [];
    if (!nasrConfiguredSliceIsCurrent($meta, $configSha)) {
        if (nasrRebuildConfiguredAptSlice() === null) {
            $GLOBALS['_nasr_apt_cache_memo'] = null;
            $GLOBALS['_nasr_apt_cache_memo_config_sha'] = null;
            return null;
        }
        $meta = loadNasrAptMeta() ?? [];
    }

    $payload = nasrReadConfiguredAptCacheFromDisk();
    if ($payload === null) {
        $GLOBALS['_nasr_apt_cache_memo'] = null;
        $GLOBALS['_nasr_apt_cache_memo_config_sha'] = null;
        return null;
    }

    $GLOBALS['_nasr_apt_cache_memo'] = $payload;
    $GLOBALS['_nasr_apt_cache_memo_config_sha'] = $configSha;

    return $payload;
}

/**
 * SHA-256 of airports.json for configured-slice invalidation.
 */
function nasrGetConfigShaForSlice(): ?string
{
    require_once __DIR__ . '/../config.php';

    return getConfigFileSha256();
}

/**
 * Collect NASR ARPT_ID values referenced by airports.json rows.
 *
 * @param array|null $config Loaded config from {@see loadConfig()}
 * @return list<string>
 */
function nasrCollectArptIdsForPlatformAirports(?array $config): array
{
    if ($config === null) {
        return [];
    }

    $ids = [];
    foreach ($config['airports'] ?? [] as $airportKey => $airport) {
        if (!is_array($airport)) {
            continue;
        }

        $row = $airport;
        if (empty($row['id']) && is_string($airportKey) && $airportKey !== '') {
            $row['id'] = $airportKey;
        }

        foreach (nasrCandidateArptIds($row) as $candidate) {
            $ids[$candidate] = true;
        }
    }

    return array_keys($ids);
}

/**
 * Build a configured airport map from the full NASR index.
 *
 * @param array<string, array> $fullAirports Full NASR airports keyed by ARPT_ID
 * @param list<string> $arptIds Candidate identifiers from platform config
 * @return array<string, array>
 */
function nasrBuildConfiguredAirportsFromFull(array $fullAirports, array $arptIds): array
{
    $slice = [];
    foreach ($arptIds as $arptId) {
        if (isset($fullAirports[$arptId])) {
            $slice[$arptId] = $fullAirports[$arptId];
        }
    }

    return $slice;
}

/**
 * Whether the on-disk configured slice matches current config and full NASR source.
 *
 * @param array<string, mixed> $meta NASR meta payload
 */
function nasrConfiguredSliceIsCurrent(array $meta, string $configSha): bool
{
    if (($meta['configured_config_sha'] ?? null) !== $configSha) {
        return false;
    }

    if (!is_readable(CACHE_NASR_APT_CONFIGURED_FILE)) {
        return false;
    }

    $sourceMtime = @filemtime(CACHE_NASR_APT_DATA_FILE);
    if ($sourceMtime === false) {
        return false;
    }

    return (int) ($meta['configured_source_mtime'] ?? 0) === (int) $sourceMtime;
}

/**
 * Whether a decoded NASR APT cache payload matches the supported on-disk schema.
 *
 * @param mixed $decoded JSON-decoded cache body
 */
function nasrAptCachePayloadIsValid($decoded): bool
{
    return is_array($decoded)
        && isset($decoded['schema_version'])
        && (int) $decoded['schema_version'] === NASR_APT_SCHEMA_VERSION
        && isset($decoded['airports'])
        && is_array($decoded['airports']);
}

/**
 * Read full NASR APT cache from disk (all US airports).
 *
 * @return array|null Decoded cache payload
 */
function nasrReadFullAptCacheFromDisk(): ?array
{
    if (!is_readable(CACHE_NASR_APT_DATA_FILE)) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents(CACHE_NASR_APT_DATA_FILE), true);
    if (!nasrAptCachePayloadIsValid($decoded)) {
        return null;
    }

    return $decoded;
}

/**
 * Read configured NASR slice from disk.
 *
 * @return array|null Decoded cache payload
 */
function nasrReadConfiguredAptCacheFromDisk(): ?array
{
    if (!is_readable(CACHE_NASR_APT_CONFIGURED_FILE)) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents(CACHE_NASR_APT_CONFIGURED_FILE), true);
    if (!nasrAptCachePayloadIsValid($decoded)) {
        return null;
    }

    return $decoded;
}

/**
 * Rebuild configured NASR slice from full cache and airports.json.
 *
 * @param array|null $configOverride Optional config for tests (defaults to {@see loadConfig()})
 * @return array|null Configured cache payload on success
 */
function nasrRebuildConfiguredAptSlice(?array $configOverride = null): ?array
{
    require_once __DIR__ . '/../config.php';

    $configSha = nasrGetConfigShaForSlice();
    if ($configSha === null) {
        return null;
    }

    $full = nasrReadFullAptCacheFromDisk();
    if ($full === null) {
        return null;
    }

    $config = $configOverride ?? loadConfig();
    if ($config === null) {
        return null;
    }

    $arptIds = nasrCollectArptIdsForPlatformAirports($config);
    $airports = nasrBuildConfiguredAirportsFromFull($full['airports'], $arptIds);

    $payload = [
        'schema_version' => NASR_APT_SCHEMA_VERSION,
        'airports' => $airports,
    ];

    if (!nasrWriteConfiguredAptCache($payload, $configSha)) {
        return null;
    }

    return $payload;
}

/**
 * Persist configured NASR slice and update meta invalidation fields.
 *
 * @param array<string, mixed> $payload Configured cache body
 */
function nasrWriteConfiguredAptCache(array $payload, string $configSha): bool
{
    ensureCacheDir(CACHE_NASR_DIR);

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $tmp = CACHE_NASR_APT_CONFIGURED_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, CACHE_NASR_APT_CONFIGURED_FILE)) {
        @unlink($tmp);
        return false;
    }

    $sourceMtime = @filemtime(CACHE_NASR_APT_DATA_FILE);
    if ($sourceMtime === false) {
        return false;
    }

    return updateNasrAptMetaFields([
        'configured_config_sha' => $configSha,
        'configured_source_mtime' => (int) $sourceMtime,
        'configured_arpt_count' => count($payload['airports'] ?? []),
        'configured_built_at' => gmdate('c'),
    ]);
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

    resetNasrAptCacheMemo();
    nasrRebuildConfiguredAptSlice();

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
 * Field elevation for density altitude performance (config overrides NASR).
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
    $GLOBALS['_nasr_apt_cache_memo_config_sha'] = null;
}

/**
 * Inject NASR cache for unit tests without touching disk.
 *
 * @param array|null $payload Cache payload or null to clear
 */
function setNasrAptCacheForTesting(?array $payload): void
{
    $GLOBALS['_nasr_apt_cache_memo'] = $payload;
    $GLOBALS['_nasr_apt_cache_memo_config_sha'] = nasrGetConfigShaForSlice();
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
