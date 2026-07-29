<?php
/**
 * National airspace map aggregate (unified store).
 *
 * NMS side-channel upserts and FAA TFR WFS ingest merge into AirspaceRecord
 * rows keyed by norm_number. Map layer projects records to GeoJSON; per-airport
 * banner caches remain separate until consumer coupling.
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
require_once __DIR__ . '/airspace/identity.php';
require_once __DIR__ . '/airspace/capabilities.php';
require_once __DIR__ . '/airspace/AirspaceAggregator.php';

use AviationWX\Notam\Airspace\AirspaceAggregator;
use AviationWX\Notam\Airspace\Adapter\FaaTfrWfsAdapter;

/** @var int Schema version for map-airspace.json envelope */
const NOTAM_MAP_AIRSPACE_SCHEMA_VERSION = 2;

/**
 * Per-field provenance map for a fully NMS-sourced airspace record.
 *
 * @param array<string, mixed> $notam Parsed NOTAM row
 * @param bool $hasDrawableGeometry When true, geometry was already validated for map draw
 * @return array<string, string>
 */
function notamAirspaceNmsFieldSourcesForNotam(array $notam, bool $hasDrawableGeometry = false): array
{
    $sources = [
        'notam_id' => NOTAM_AIRSPACE_SOURCE_NMS,
        'text' => NOTAM_AIRSPACE_SOURCE_NMS,
    ];

    if ($hasDrawableGeometry) {
        $sources['geometry'] = NOTAM_AIRSPACE_SOURCE_NMS;
    }

    $probe = $notam;
    notamEnsureEffectiveSegments($probe);
    if (isset($probe['effective_segments']) && is_array($probe['effective_segments']) && $probe['effective_segments'] !== []) {
        $sources['effective_segments'] = NOTAM_AIRSPACE_SOURCE_NMS;
    }

    $start = trim((string) ($notam['start_time_utc'] ?? ''));
    $end = trim((string) ($notam['end_time_utc'] ?? ''));
    if ($start !== '') {
        $sources['start_time_utc'] = NOTAM_AIRSPACE_SOURCE_NMS;
    }
    if ($end !== '') {
        $sources['end_time_utc'] = NOTAM_AIRSPACE_SOURCE_NMS;
    }

    $status = trim((string) ($notam['status'] ?? ''));
    if ($status !== '' && $status !== 'unknown') {
        $sources['status'] = NOTAM_AIRSPACE_SOURCE_NMS;
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
        'field_sources' => notamAirspaceNmsFieldSourcesForNotam($notam, true),
        'merged_at' => null,
    ];

    if ($geometryKind === 'circle') {
        $record['radius_nm'] = (float) ($minimal['properties']['radius_nm'] ?? 0);
    }

    return $record;
}

/**
 * Whether the decoded map-airspace envelope has a supported shape.
 *
 * @param array<string, mixed>|null $decoded Decoded JSON
 */
function notamMapAirspaceAggregateEnvelopeIsValid(?array $decoded): bool
{
    if (!is_array($decoded)) {
        return false;
    }

    $schema = (int) ($decoded['schema_version'] ?? 0);
    if ($schema !== 1 && $schema !== NOTAM_MAP_AIRSPACE_SCHEMA_VERSION) {
        return false;
    }

    $records = $decoded['records'] ?? null;

    return is_array($records);
}

/**
 * True when envelope merge_logic_version matches the running aggregator.
 *
 * @param array<string, mixed>|null $envelope Decoded map-airspace.json
 */
function notamMapAirspaceAggregateMergeLogicMatches(?array $envelope): bool
{
    if ($envelope === null) {
        return false;
    }

    $schema = (int) ($envelope['schema_version'] ?? 0);
    if ($schema === 1) {
        // Legacy side-channel stores are readable; migrate on next write.
        return true;
    }

    if ($schema !== NOTAM_MAP_AIRSPACE_SCHEMA_VERSION) {
        return false;
    }

    return (int) ($envelope['merge_logic_version'] ?? -1) === AirspaceAggregator::MERGE_LOGIC_VERSION;
}

/**
 * Normalize a disk envelope to schema v2 shape (in memory).
 *
 * @param array<string, mixed> $envelope
 * @return array<string, mixed>
 */
function notamMapAirspaceAggregateNormalizeEnvelope(array $envelope): array
{
    $recordsIn = is_array($envelope['records'] ?? null) ? $envelope['records'] : [];
    $candidates = [];
    foreach ($recordsIn as $record) {
        if (is_array($record)) {
            $candidates[] = $record;
        }
    }

    $mergedRecords = AirspaceAggregator::merge($candidates);
    $now = time();
    $dataUpdatedAt = (int) ($envelope['data_updated_at'] ?? $envelope['updated_at'] ?? $now);
    if ($dataUpdatedAt <= 0) {
        $dataUpdatedAt = $now;
    }

    $sourceStatus = is_array($envelope['source_status'] ?? null) ? $envelope['source_status'] : [];
    if ($sourceStatus === []) {
        $sourceStatus = [
            'nms' => ['ok' => true, 'updated_at' => $dataUpdatedAt],
        ];
    }

    return [
        'schema_version' => NOTAM_MAP_AIRSPACE_SCHEMA_VERSION,
        'merge_logic_version' => AirspaceAggregator::MERGE_LOGIC_VERSION,
        'data_updated_at' => $dataUpdatedAt,
        'updated_at' => $dataUpdatedAt,
        'coverage_sources' => notamMapAirspaceAggregateCoverageSourcesFromStatus($sourceStatus),
        'source_status' => $sourceStatus,
        'records' => $mergedRecords,
        // Diagnostics only - never used for serve eligibility.
        'map_layer_build_token' => 'merge-v' . AirspaceAggregator::MERGE_LOGIC_VERSION,
    ];
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
 * Empty aggregate envelope (aggregator-owned metadata).
 *
 * @return array<string, mixed>
 */
function notamMapAirspaceAggregateEmptyEnvelope(): array
{
    $now = time();

    return [
        'schema_version' => NOTAM_MAP_AIRSPACE_SCHEMA_VERSION,
        'merge_logic_version' => AirspaceAggregator::MERGE_LOGIC_VERSION,
        'data_updated_at' => $now,
        'updated_at' => $now,
        'coverage_sources' => ['nms'],
        'source_status' => [
            'nms' => ['ok' => true, 'updated_at' => $now],
        ],
        'records' => [],
        'map_layer_build_token' => 'merge-v' . AirspaceAggregator::MERGE_LOGIC_VERSION,
    ];
}

/**
 * Acquire the national store lock and run a writer callback.
 *
 * @param callable $mutator Receives current envelope; returns envelope to write or null to abort
 */
function notamMapAirspaceAggregateWithLock(callable $mutator): bool
{
    $lockPath = getNotamMapAirspaceUpsertLockPath();
    $lockDir = dirname($lockPath);
    if (!is_dir($lockDir) && !@mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
        aviationwx_log('warning', 'notam map airspace: cannot create upsert lock directory', [
            'path' => $lockDir,
        ], 'app');

        return false;
    }

    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) {
        aviationwx_log('warning', 'notam map airspace: upsert lock open failed', [
            'path' => $lockPath,
        ], 'app');

        return false;
    }

    if (!@flock($fp, LOCK_EX)) {
        fclose($fp);
        aviationwx_log('warning', 'notam map airspace: upsert lock acquire failed', [
            'path' => $lockPath,
        ], 'app');

        return false;
    }

    try {
        $current = notamMapAirspaceAggregateRead() ?? notamMapAirspaceAggregateEmptyEnvelope();
        $next = $mutator($current);
        if (!is_array($next)) {
            return false;
        }

        if (!notamMapAirspaceAggregateWrite($next)) {
            aviationwx_log('warning', 'notam map airspace: write failed under lock', [], 'app');

            return false;
        }

        return true;
    } finally {
        @flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Upsert drawable TFR rows from a per-airport fetch (side-channel).
 *
 * Called after parse + dedup and before relevance filtering. Merges candidates
 * into the national store; envelope metadata is aggregator-owned (no deploy SHA).
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

        $candidates[] = $record;
    }

    if ($candidates === []) {
        return;
    }

    $ok = notamMapAirspaceAggregateWithLock(static function (array $envelope) use ($candidates): array {
        $normalized = notamMapAirspaceAggregateNormalizeEnvelope($envelope);
        $existing = array_values($normalized['records']);
        $merged = AirspaceAggregator::merge(array_merge($existing, $candidates));
        $now = time();

        $sourceStatus = is_array($normalized['source_status'] ?? null) ? $normalized['source_status'] : [];
        $sourceStatus['nms'] = ['ok' => true, 'updated_at' => $now];

        $normalized['records'] = $merged;
        $normalized['data_updated_at'] = $now;
        $normalized['updated_at'] = $now;
        $normalized['source_status'] = $sourceStatus;
        $normalized['coverage_sources'] = notamMapAirspaceAggregateCoverageSourcesFromStatus($sourceStatus);
        $normalized['schema_version'] = NOTAM_MAP_AIRSPACE_SCHEMA_VERSION;
        $normalized['merge_logic_version'] = AirspaceAggregator::MERGE_LOGIC_VERSION;
        $normalized['map_layer_build_token'] = 'merge-v' . AirspaceAggregator::MERGE_LOGIC_VERSION;

        return $normalized;
    });

    if (!$ok) {
        aviationwx_log('warning', 'notam map airspace: upsert merge failed', [
            'airport' => $airportId,
            'candidate_count' => count($candidates),
        ], 'app');
    }
}

/**
 * Merge WFS records into the national store (national worker).
 *
 * @param list<array<string, mixed>> $wfsRecords
 */
function notamMapAirspaceAggregateMergeWfsRecords(array $wfsRecords): bool
{
    return notamMapAirspaceAggregateWithLock(static function (array $envelope) use ($wfsRecords): array {
        $normalized = notamMapAirspaceAggregateNormalizeEnvelope($envelope);
        $existing = array_values($normalized['records']);
        $merged = AirspaceAggregator::merge(array_merge($existing, $wfsRecords));
        $now = time();

        $sourceStatus = is_array($normalized['source_status'] ?? null) ? $normalized['source_status'] : [];
        $sourceStatus[FaaTfrWfsAdapter::SOURCE_TYPE] = ['ok' => true, 'updated_at' => $now];

        $normalized['records'] = $merged;
        $normalized['data_updated_at'] = $now;
        $normalized['updated_at'] = $now;
        $normalized['source_status'] = $sourceStatus;
        $normalized['coverage_sources'] = notamMapAirspaceAggregateCoverageSourcesFromStatus($sourceStatus);
        $normalized['schema_version'] = NOTAM_MAP_AIRSPACE_SCHEMA_VERSION;
        $normalized['merge_logic_version'] = AirspaceAggregator::MERGE_LOGIC_VERSION;
        $normalized['map_layer_build_token'] = 'merge-v' . AirspaceAggregator::MERGE_LOGIC_VERSION;

        return $normalized;
    });
}

/**
 * Mark a source unhealthy without clearing existing records (fail-soft degrade).
 */
function notamMapAirspaceAggregateMarkSourceStatus(string $source, bool $ok, string $error = ''): bool
{
    $source = trim($source);
    if ($source === '') {
        return false;
    }

    return notamMapAirspaceAggregateWithLock(static function (array $envelope) use ($source, $ok, $error): array {
        $normalized = notamMapAirspaceAggregateNormalizeEnvelope($envelope);
        $sourceStatus = is_array($normalized['source_status'] ?? null) ? $normalized['source_status'] : [];
        $entry = [
            'ok' => $ok,
            'updated_at' => time(),
        ];
        if (!$ok && $error !== '') {
            $entry['error'] = $error;
        }
        $sourceStatus[$source] = $entry;
        $normalized['source_status'] = $sourceStatus;
        $normalized['coverage_sources'] = notamMapAirspaceAggregateCoverageSourcesFromStatus($sourceStatus);

        return $normalized;
    });
}

/**
 * @param array<string, mixed> $sourceStatus
 * @return list<string>
 */
function notamMapAirspaceAggregateCoverageSourcesFromStatus(array $sourceStatus): array
{
    $out = [];
    foreach ($sourceStatus as $name => $status) {
        if (!is_string($name) || $name === '') {
            continue;
        }
        if (is_array($status) && ($status['ok'] ?? false) === true) {
            $out[] = $name;
        }
    }

    return $out !== [] ? $out : ['nms'];
}

/**
 * Whether an already-decoded map-airspace envelope is older than the NOTAM cache TTL.
 *
 * Prefer this when the caller already holds `$envelope` so serve paths do not re-read disk.
 * Falls back to file mtime only when the envelope lacks usable timestamps (legacy files).
 *
 * @param array<string, mixed> $envelope Decoded map-airspace.json
 * @param int $ttl Seconds from {@see getNotamCacheTtlSeconds()}
 * @param int|null $nowUnix Optional clock for tests
 */
function notamMapAirspaceAggregateEnvelopeIsStale(array $envelope, int $ttl, ?int $nowUnix = null): bool
{
    $nowUnix = $nowUnix ?? time();

    $updatedAt = (int) ($envelope['data_updated_at'] ?? $envelope['updated_at'] ?? 0);
    if ($updatedAt <= 0) {
        $updatedAt = notamMapAirspaceAggregateMtime();
    }
    if ($updatedAt <= 0) {
        return true;
    }

    $age = $nowUnix - $updatedAt;

    return $age < 0 || $age >= $ttl;
}

/**
 * Whether the map-airspace store is older than the NOTAM cache TTL.
 *
 * Reads the store when the caller does not already hold an envelope.
 *
 * @param int $ttl Seconds from {@see getNotamCacheTtlSeconds()}
 * @param int|null $nowUnix Optional clock for tests
 */
function notamMapAirspaceAggregateIsStale(int $ttl, ?int $nowUnix = null): bool
{
    $envelope = notamMapAirspaceAggregateRead();
    if ($envelope === null) {
        return true;
    }

    return notamMapAirspaceAggregateEnvelopeIsStale($envelope, $ttl, $nowUnix);
}

/**
 * Legacy name: build-token matching is retired. Delegates to merge_logic_version.
 *
 * @param array<string, mixed>|null $envelope Decoded map-airspace.json
 */
function notamMapAirspaceAggregateBuildTokenMatches(?array $envelope): bool
{
    return notamMapAirspaceAggregateMergeLogicMatches($envelope);
}

/**
 * Migrate legacy envelopes to schema v2. Serve eligibility no longer depends on deploy SHA.
 *
 * @return bool True when map-airspace.json was updated on disk
 */
function notamMapAirspaceAggregateRepairStaleLogicBuildToken(): bool
{
    $envelope = notamMapAirspaceAggregateRead();
    if ($envelope === null) {
        return false;
    }

    $schema = (int) ($envelope['schema_version'] ?? 0);
    if ($schema === NOTAM_MAP_AIRSPACE_SCHEMA_VERSION
        && (int) ($envelope['merge_logic_version'] ?? -1) === AirspaceAggregator::MERGE_LOGIC_VERSION
    ) {
        return false;
    }

    $path = getNotamMapAirspaceAggregatePath();
    $preserveMtime = is_file($path) ? (int) @filemtime($path) : 0;
    $normalized = notamMapAirspaceAggregateNormalizeEnvelope($envelope);

    if (!notamMapAirspaceAggregateWrite($normalized)) {
        return false;
    }

    if ($preserveMtime > 0) {
        @touch($path, $preserveMtime);
        clearstatcache(true, $path);
    }

    return true;
}
