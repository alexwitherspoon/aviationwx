<?php
/**
 * NASR FRQ cache load/save and airport lookup.
 */

require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/identifiers.php';
require_once __DIR__ . '/frequencies-parse.php';
require_once __DIR__ . '/cache.php';

/**
 * @var array|null In-request memo for configured NASR FRQ slice
 */
$GLOBALS['_nasr_frq_cache_memo'] = null;

/**
 * @var string|null Config SHA tied to in-request FRQ memo
 */
$GLOBALS['_nasr_frq_cache_memo_config_sha'] = null;

/**
 * Whether a decoded NASR FRQ cache payload matches the supported on-disk schema.
 *
 * @param mixed $decoded JSON-decoded cache body
 */
function nasrFrqCachePayloadIsValid($decoded): bool
{
    return is_array($decoded)
        && isset($decoded['schema_version'])
        && (int) $decoded['schema_version'] === NASR_FRQ_SCHEMA_VERSION
        && isset($decoded['airports'])
        && is_array($decoded['airports']);
}

/**
 * Read full NASR FRQ cache from disk.
 *
 * @return array|null Decoded cache payload
 */
function nasrReadFullFrqCacheFromDisk(): ?array
{
    if (!is_readable(CACHE_NASR_FRQ_DATA_FILE)) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents(CACHE_NASR_FRQ_DATA_FILE), true);
    if (!nasrFrqCachePayloadIsValid($decoded)) {
        return null;
    }

    return $decoded;
}

/**
 * Read configured NASR FRQ slice from disk.
 *
 * @return array|null Decoded cache payload
 */
function nasrReadConfiguredFrqCacheFromDisk(): ?array
{
    if (!is_readable(CACHE_NASR_FRQ_CONFIGURED_FILE)) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents(CACHE_NASR_FRQ_CONFIGURED_FILE), true);
    if (!nasrFrqCachePayloadIsValid($decoded)) {
        return null;
    }

    return $decoded;
}

/**
 * Whether the on-disk configured FRQ slice matches current config and full FRQ source.
 *
 * @param array<string, mixed> $meta NASR meta payload
 */
function nasrConfiguredFrqSliceIsCurrent(array $meta, string $configSha): bool
{
    if (($meta['frq_configured_config_sha'] ?? null) !== $configSha) {
        return false;
    }

    if (!is_readable(CACHE_NASR_FRQ_CONFIGURED_FILE)) {
        return false;
    }

    $sourceMtime = @filemtime(CACHE_NASR_FRQ_DATA_FILE);
    if ($sourceMtime === false) {
        return false;
    }

    return (int) ($meta['frq_configured_source_mtime'] ?? 0) === (int) $sourceMtime;
}

/**
 * Persist configured NASR FRQ slice and update meta invalidation fields.
 *
 * @param array<string, mixed> $payload Configured cache body
 */
function nasrWriteConfiguredFrqCache(array $payload, string $configSha): bool
{
    ensureCacheDir(CACHE_NASR_DIR);

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $tmp = CACHE_NASR_FRQ_CONFIGURED_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, CACHE_NASR_FRQ_CONFIGURED_FILE)) {
        @unlink($tmp);
        return false;
    }

    $sourceMtime = @filemtime(CACHE_NASR_FRQ_DATA_FILE);
    if ($sourceMtime === false) {
        return false;
    }

    return updateNasrAptMetaFields([
        'frq_configured_config_sha' => $configSha,
        'frq_configured_source_mtime' => (int) $sourceMtime,
        'frq_configured_arpt_count' => count($payload['airports'] ?? []),
        'frq_configured_built_at' => gmdate('c'),
    ]);
}

/**
 * Rebuild configured NASR FRQ slice from full cache and airports.json.
 *
 * @param array|null $configOverride Optional config for tests
 * @return array|null Configured cache payload on success
 */
function nasrRebuildConfiguredFrqSlice(?array $configOverride = null): ?array
{
    require_once __DIR__ . '/../config.php';

    $configSha = nasrGetConfigShaForSlice();
    if ($configSha === null) {
        return null;
    }

    $full = nasrReadFullFrqCacheFromDisk();
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
        'schema_version' => NASR_FRQ_SCHEMA_VERSION,
        'airports' => $airports,
    ];

    if (!nasrWriteConfiguredFrqCache($payload, $configSha)) {
        return null;
    }

    return $payload;
}

/**
 * Load parsed NASR FRQ cache for platform airports only (memoized per request).
 *
 * @return array|null Cache payload or null when missing/unreadable
 */
function loadNasrFrqCache(): ?array
{
    $configSha = nasrGetConfigShaForSlice();
    if (is_array($GLOBALS['_nasr_frq_cache_memo'])
        && ($GLOBALS['_nasr_frq_cache_memo_config_sha'] ?? null) === $configSha) {
        return $GLOBALS['_nasr_frq_cache_memo'];
    }

    if ($configSha === null) {
        $GLOBALS['_nasr_frq_cache_memo'] = null;
        $GLOBALS['_nasr_frq_cache_memo_config_sha'] = null;
        return null;
    }

    if (!is_readable(CACHE_NASR_FRQ_DATA_FILE)) {
        $meta = loadNasrAptMeta() ?? [];
        if (($meta['frq_configured_config_sha'] ?? null) === $configSha) {
            $payload = nasrReadConfiguredFrqCacheFromDisk();
            if ($payload !== null) {
                $GLOBALS['_nasr_frq_cache_memo'] = $payload;
                $GLOBALS['_nasr_frq_cache_memo_config_sha'] = $configSha;

                return $payload;
            }
        }

        $GLOBALS['_nasr_frq_cache_memo'] = null;
        $GLOBALS['_nasr_frq_cache_memo_config_sha'] = null;
        return null;
    }

    $meta = loadNasrAptMeta() ?? [];
    if (!nasrConfiguredFrqSliceIsCurrent($meta, $configSha)) {
        if (nasrRebuildConfiguredFrqSlice() === null) {
            $GLOBALS['_nasr_frq_cache_memo'] = null;
            $GLOBALS['_nasr_frq_cache_memo_config_sha'] = null;
            return null;
        }
    }

    $payload = nasrReadConfiguredFrqCacheFromDisk();
    if ($payload === null) {
        $GLOBALS['_nasr_frq_cache_memo'] = null;
        $GLOBALS['_nasr_frq_cache_memo_config_sha'] = null;
        return null;
    }

    $GLOBALS['_nasr_frq_cache_memo'] = $payload;
    $GLOBALS['_nasr_frq_cache_memo_config_sha'] = $configSha;

    return $payload;
}

/**
 * Persist NASR FRQ cache and metadata fields.
 *
 * @param array<string, array<string, string>> $airports Parsed frequencies keyed by ARPT_ID
 * @param array<string, mixed> $meta Metadata fields to merge into nasr_meta.json
 */
function saveNasrFrqCache(array $airports, array $meta): bool
{
    ensureCacheDir(CACHE_NASR_DIR);

    $payload = [
        'schema_version' => NASR_FRQ_SCHEMA_VERSION,
        'airports' => $airports,
    ];

    $dataJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($dataJson === false) {
        return false;
    }

    $tmpData = CACHE_NASR_FRQ_DATA_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmpData, $dataJson, LOCK_EX) === false) {
        @unlink($tmpData);
        return false;
    }

    if (!@rename($tmpData, CACHE_NASR_FRQ_DATA_FILE)) {
        @unlink($tmpData);
        return false;
    }

    $meta['frq_schema_version'] = NASR_FRQ_SCHEMA_VERSION;
    $meta['frq_fetched_at'] = $meta['frq_fetched_at'] ?? gmdate('c');
    $meta['frq_airport_count'] = count($airports);
    $meta['frq_last_fetch_error'] = null;
    $meta['frq_last_fetch_error_at'] = null;

    if (!updateNasrAptMetaFields($meta)) {
        return false;
    }

    resetNasrFrqCacheMemo();
    nasrRebuildConfiguredFrqSlice();

    return true;
}

/**
 * Whether NASR FRQ cache should be refreshed.
 */
function nasrFrqCacheNeedsRefresh(): bool
{
    if (!is_readable(CACHE_NASR_FRQ_DATA_FILE)) {
        return true;
    }

    $meta = loadNasrAptMeta();
    if ($meta === null) {
        return true;
    }

    $fetchedAt = $meta['frq_fetched_at'] ?? null;
    if (!is_string($fetchedAt) || $fetchedAt === '') {
        return true;
    }

    $fetchedTs = strtotime($fetchedAt);
    if ($fetchedTs === false) {
        return true;
    }

    return (time() - $fetchedTs) > NASR_CACHE_MAX_AGE;
}

/**
 * Resolve NASR frequency map for a platform airport config row.
 *
 * @param array $airport Airport configuration
 * @return array<string, string> Role => MHz string
 */
function getNasrFrequenciesForConfig(array $airport): array
{
    $cache = loadNasrFrqCache();
    if ($cache === null) {
        return [];
    }

    $airports = $cache['airports'];
    foreach (nasrCandidateArptIds($airport) as $candidate) {
        if (isset($airports[$candidate]) && is_array($airports[$candidate])) {
            return $airports[$candidate];
        }
    }

    return [];
}

/**
 * Clear in-request NASR FRQ cache memo (testing).
 */
function resetNasrFrqCacheMemo(): void
{
    $GLOBALS['_nasr_frq_cache_memo'] = null;
    $GLOBALS['_nasr_frq_cache_memo_config_sha'] = null;
}

/**
 * Inject NASR FRQ cache for unit tests without touching disk.
 *
 * @param array|null $payload Cache payload or null to clear
 */
function setNasrFrqCacheForTesting(?array $payload): void
{
    $GLOBALS['_nasr_frq_cache_memo'] = $payload;
    $GLOBALS['_nasr_frq_cache_memo_config_sha'] = nasrGetConfigShaForSlice();
}

/**
 * Build NASR FRQ cache payload from a CSV directory (tests).
 *
 * @param string $csvDir Directory containing FRQ.csv
 * @return array{airports: array<string, array<string, string>>, effective_date: ?string}
 */
function nasrBuildFrqCacheFromCsvDirectory(string $csvDir): array
{
    return nasrParseFrqCsvFile(rtrim($csvDir, '/') . '/FRQ.csv');
}
