<?php
/**
 * National airspace map aggregate (NMS side-channel).
 *
 * Stores normalized AirspaceRecord rows upserted during per-airport NMS fetch
 * before airport relevance filtering. Map layer projects records to GeoJSON;
 * per-airport banner caches remain separate.
 */

declare(strict_types=1);

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/filter.php';
require_once __DIR__ . '/map-layer.php';
require_once __DIR__ . '/schedule.php';
require_once __DIR__ . '/closure-parse.php';

/** @var int Schema version for map-airspace.json envelope */
const NOTAM_MAP_AIRSPACE_SCHEMA_VERSION = 1;

/** @var string Source type written into field_sources for NMS-ingested rows */
const NOTAM_AIRSPACE_SOURCE_NMS = 'nms';

/**
 * Extract normalized NOTAM number bucket key from a public id.
 *
 * @param string $notamId Public NOTAM id (e.g. A3389/2026, 2698/2026)
 * @return string|null Bucket such as N:3389, or null when not parseable
 */
function notamAirspaceNormNumberFromId(string $notamId): ?string
{
    $notamId = trim($notamId);
    if ($notamId === '') {
        return null;
    }

    if (preg_match('/^([A-Za-z]?\d+)\/(\d{4})$/', $notamId, $matches) === 1) {
        return 'N:' . (int) $matches[1];
    }

    return null;
}

/**
 * Whether a parsed NMS row has enough schedule metadata for banner revalidation.
 *
 * @param array<string, mixed> $notam Parsed NOTAM row
 */
function notamAirspaceRecordBannerCapable(array $notam): bool
{
    if (!isTfr($notam)) {
        return false;
    }

    if (trim((string) ($notam['id'] ?? '')) === '') {
        return false;
    }

    if (trim((string) ($notam['text'] ?? '')) === '') {
        return false;
    }

    $status = trim((string) ($notam['status'] ?? ''));
    if ($status !== '' && $status !== 'unknown') {
        return true;
    }

    $start = trim((string) ($notam['start_time_utc'] ?? ''));
    if ($start !== '') {
        return true;
    }

    notamEnsureEffectiveSegments($notam);
    $segments = $notam['effective_segments'] ?? null;

    return is_array($segments) && $segments !== [];
}

/**
 * Whether a parsed NMS row has closure-parse inputs (data sufficiency only).
 *
 * @param array<string, mixed> $notam Parsed NOTAM row
 */
function notamAirspaceRecordRunwayClosureCapable(array $notam): bool
{
    if (isNotamCancellation($notam)) {
        return false;
    }

    if (!notamRestrictionScopeIsRunwayOrAerodrome($notam)) {
        return false;
    }

    $text = notamNormalizeProse((string) ($notam['text'] ?? ''));

    return notamProseHasClosureKeyword($text);
}

/**
 * Per-field provenance map for a fully NMS-sourced airspace record.
 *
 * @param array<string, mixed> $notam Parsed NOTAM row
 * @return array<string, string>
 */
function notamAirspaceNmsFieldSourcesForNotam(array $notam): array
{
    $sources = [
        'notam_id' => NOTAM_AIRSPACE_SOURCE_NMS,
        'text' => NOTAM_AIRSPACE_SOURCE_NMS,
    ];

    if (notamTfrMapLayerMinimalFeatureForGeometryKey($notam) !== null) {
        $sources['geometry'] = NOTAM_AIRSPACE_SOURCE_NMS;
    }

    notamEnsureEffectiveSegments($notam);
    if (isset($notam['effective_segments']) && is_array($notam['effective_segments']) && $notam['effective_segments'] !== []) {
        $sources['effective_segments'] = NOTAM_AIRSPACE_SOURCE_NMS;
    }

    $start = trim((string) ($notam['start_time_utc'] ?? ''));
    $end = trim((string) ($notam['end_time_utc'] ?? ''));
    if ($start !== '' || $end !== '') {
        $sources['status'] = NOTAM_AIRSPACE_SOURCE_NMS;
    }

    $text = (string) ($notam['text'] ?? '');
    $verticalLimits = parseTfrVerticalLimitsSummary($text);
    if ($verticalLimits !== null && $verticalLimits !== '') {
        $sources['vertical_limits'] = NOTAM_AIRSPACE_SOURCE_NMS;
    }

    if (isTfr($notam)) {
        $sources['restriction_kind'] = NOTAM_AIRSPACE_SOURCE_NMS;
    }

    return $sources;
}

/**
 * Build one AirspaceRecord from a parsed NMS NOTAM row.
 *
 * @param array<string, mixed> $notam Parsed NOTAM row
 * @param string $sourceAirportId Airport that produced this fetch
 * @param string $timezone IANA timezone for serve-time revalidation
 * @return array<string, mixed>|null Null when not drawable for map side-channel
 */
function notamAirspaceRecordFromNotam(array $notam, string $sourceAirportId, string $timezone): ?array
{
    if (!isTfr($notam)) {
        return null;
    }

    $notamId = trim((string) ($notam['id'] ?? ''));
    if ($notamId === '') {
        return null;
    }

    $minimal = notamTfrMapLayerMinimalFeatureForGeometryKey($notam);
    if ($minimal === null) {
        return null;
    }

    $geometryKind = (string) ($minimal['properties']['geometry_kind'] ?? '');
    $record = [
        'notam_id' => $notamId,
        'norm_number' => notamAirspaceNormNumberFromId($notamId),
        'restriction_kind' => 'tfr',
        'geometry' => $minimal['geometry'],
        'geometry_kind' => $geometryKind,
        'notam' => $notam,
        'timezone' => $timezone,
        'source_airport_id' => strtolower(trim($sourceAirportId)),
        'upserted_at' => time(),
        'capabilities' => [
            'map' => true,
            'banner' => notamAirspaceRecordBannerCapable($notam),
            'runway_closure' => notamAirspaceRecordRunwayClosureCapable($notam),
        ],
        'record_sources' => [NOTAM_AIRSPACE_SOURCE_NMS],
        'field_sources' => notamAirspaceNmsFieldSourcesForNotam($notam),
        'merged_at' => null,
    ];

    if ($geometryKind === 'circle') {
        $record['radius_nm'] = (float) ($minimal['properties']['radius_nm'] ?? 0);
    }

    return $record;
}

/**
 * Whether the decoded map-airspace envelope has the expected shape.
 *
 * @param array<string, mixed>|null $decoded Decoded JSON
 */
function notamMapAirspaceAggregateEnvelopeIsValid(?array $decoded): bool
{
    if (!is_array($decoded)) {
        return false;
    }

    if ((int) ($decoded['schema_version'] ?? 0) !== NOTAM_MAP_AIRSPACE_SCHEMA_VERSION) {
        return false;
    }

    $records = $decoded['records'] ?? null;

    return is_array($records);
}

/**
 * Mtime of map-airspace.json after clearing stat cache.
 */
function notamMapAirspaceAggregateMtime(): int
{
    $path = getNotamMapAirspaceAggregatePath();
    clearstatcache(true, $path);

    return is_file($path) ? (int) @filemtime($path) : 0;
}

/**
 * Read the national airspace aggregate from disk.
 *
 * @return array<string, mixed>|null Envelope with records map, or null when missing/invalid
 */
function notamMapAirspaceAggregateRead(): ?array
{
    $path = getNotamMapAirspaceAggregatePath();
    if (!is_readable($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        aviationwx_log('warning', 'notam map airspace: unreadable aggregate', [
            'path' => $path,
        ], 'app');

        return null;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        aviationwx_log('warning', 'notam map airspace: invalid aggregate JSON', [
            'path' => $path,
            'error' => $e->getMessage(),
        ], 'app');

        return null;
    }

    if (!notamMapAirspaceAggregateEnvelopeIsValid(is_array($decoded) ? $decoded : null)) {
        aviationwx_log('warning', 'notam map airspace: unexpected aggregate shape', [
            'path' => $path,
        ], 'app');

        return null;
    }

    return $decoded;
}

/**
 * Write map-airspace.json atomically.
 *
 * @param array<string, mixed> $envelope Aggregate envelope
 */
function notamMapAirspaceAggregateWrite(array $envelope): bool
{
    $path = getNotamMapAirspaceAggregatePath();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    try {
        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        aviationwx_log('error', 'notam map airspace: encode failed', [
            'error' => $e->getMessage(),
        ], 'app');

        return false;
    }

    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    if (!@rename($tmp, $path)) {
        $ok = @file_put_contents($path, $json, LOCK_EX) !== false;
        @unlink($tmp);

        return $ok;
    }

    return true;
}

/**
 * Empty aggregate envelope with current build token.
 *
 * @return array<string, mixed>
 */
function notamMapAirspaceAggregateEmptyEnvelope(): array
{
    return [
        'schema_version' => NOTAM_MAP_AIRSPACE_SCHEMA_VERSION,
        'records' => [],
        'updated_at' => time(),
        'map_layer_build_token' => notamTfrMapLayerCurrentBuildToken(),
    ];
}

/**
 * Upsert drawable TFR rows from a per-airport fetch (side-channel).
 *
 * Called after parse + dedup and before relevance filtering.
 *
 * @param string $airportId Airport config key
 * @param array<string, mixed> $airport Airport configuration
 * @param array<int, array<string, mixed>> $notams Parsed NOTAM rows (deduplicated)
 */
function notamMapAirspaceAggregateUpsertFromFetch(string $airportId, array $airport, array $notams): void
{
    $timezone = getAirportTimezone($airport);
    $candidates = [];

    foreach ($notams as $notam) {
        if (!is_array($notam)) {
            continue;
        }

        $record = notamAirspaceRecordFromNotam($notam, $airportId, $timezone);
        if ($record === null) {
            continue;
        }

        $notamId = (string) $record['notam_id'];
        $candidates[$notamId] = $record;
    }

    if ($candidates === []) {
        return;
    }

    $lockPath = getNotamMapAirspaceUpsertLockPath();
    $lockDir = dirname($lockPath);
    if (!is_dir($lockDir) && !@mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
        aviationwx_log('warning', 'notam map airspace: cannot create upsert lock directory', [
            'path' => $lockDir,
        ], 'app');

        return;
    }

    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) {
        aviationwx_log('warning', 'notam map airspace: upsert lock open failed', [
            'path' => $lockPath,
        ], 'app');

        return;
    }

    if (!@flock($fp, LOCK_EX)) {
        fclose($fp);
        aviationwx_log('warning', 'notam map airspace: upsert lock acquire failed', [
            'path' => $lockPath,
        ], 'app');

        return;
    }

    try {
        $envelope = notamMapAirspaceAggregateRead() ?? notamMapAirspaceAggregateEmptyEnvelope();
        $records = is_array($envelope['records'] ?? null) ? $envelope['records'] : [];

        foreach ($candidates as $notamId => $record) {
            $records[$notamId] = $record;
        }

        $envelope['records'] = $records;
        $envelope['updated_at'] = time();
        $envelope['map_layer_build_token'] = notamTfrMapLayerCurrentBuildToken();

        if (!notamMapAirspaceAggregateWrite($envelope)) {
            aviationwx_log('warning', 'notam map airspace: upsert write failed', [
                'airport' => $airportId,
                'candidate_count' => count($candidates),
            ], 'app');
        }
    } finally {
        @flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Whether the map-airspace store is older than the NOTAM cache TTL.
 *
 * @param int $ttl Seconds from {@see getNotamCacheTtlSeconds()}
 * @param int|null $nowUnix Optional clock for tests
 */
function notamMapAirspaceAggregateIsStale(int $ttl, ?int $nowUnix = null): bool
{
    $mtime = notamMapAirspaceAggregateMtime();
    if ($mtime <= 0) {
        return true;
    }

    $nowUnix = $nowUnix ?? time();
    $age = $nowUnix - $mtime;

    return $age < 0 || $age >= $ttl;
}

/**
 * True when the airspace store build token matches the current deploy/logic version.
 *
 * @param array<string, mixed>|null $envelope Decoded map-airspace.json
 */
function notamMapAirspaceAggregateBuildTokenMatches(?array $envelope): bool
{
    if ($envelope === null) {
        return false;
    }

    $stored = trim((string) ($envelope['map_layer_build_token'] ?? ''));
    if ($stored === '') {
        return false;
    }

    return $stored === notamTfrMapLayerCurrentBuildToken();
}
