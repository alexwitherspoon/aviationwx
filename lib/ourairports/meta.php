<?php

/**
 * OurAirports bulk CSV metadata (ETag, probe/fetch timestamps).
 *
 * `etag` is the tag for bytes on disk after the last successful fetch only.
 * Probe stores upstream HEAD values separately in `upstream_etag`.
 */

require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/locks.php';
require_once __DIR__ . '/urls.php';

/**
 * Default per-file meta record.
 *
 * @return array<string, mixed>
 */
function ourAirportsDefaultFileMeta(): array
{
    return [
        'etag' => null,
        'upstream_etag' => null,
        'last_modified' => null,
        'last_probe_at' => null,
        'last_probe_result' => null,
        'last_fetch_at' => null,
    ];
}

/**
 * Load OurAirports meta from disk.
 *
 * @return array<string, mixed>
 */
function ourAirportsLoadMeta(): array
{
    if (!is_readable(CACHE_OURAIRPORTS_META_FILE)) {
        return ['files' => []];
    }

    $raw = @file_get_contents(CACHE_OURAIRPORTS_META_FILE);
    if ($raw === false || $raw === '') {
        return ['files' => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['files' => []];
    }

    if (!isset($decoded['files']) || !is_array($decoded['files'])) {
        $decoded['files'] = [];
    }

    return $decoded;
}

/**
 * Persist OurAirports meta atomically.
 *
 * @param array<string, mixed> $meta
 */
function ourAirportsSaveMeta(array $meta): bool
{
    ensureCacheDir(CACHE_OURAIRPORTS_DIR);

    if (!isset($meta['files']) || !is_array($meta['files'])) {
        $meta['files'] = [];
    }

    $meta['updated_at'] = time();

    $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $tmp = CACHE_OURAIRPORTS_META_FILE . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, CACHE_OURAIRPORTS_META_FILE)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/**
 * Get per-file meta, merged with defaults.
 *
 * @return array<string, mixed>
 */
function ourAirportsGetFileMeta(string $fileKey): array
{
    $meta = ourAirportsLoadMeta();
    $files = $meta['files'];
    $fileMeta = isset($files[$fileKey]) && is_array($files[$fileKey]) ? $files[$fileKey] : [];

    return array_merge(ourAirportsDefaultFileMeta(), $fileMeta);
}

/**
 * Merge updates into one file's meta and save under the meta lock.
 *
 * @param array<string, mixed> $updates
 */
function ourAirportsUpdateFileMeta(string $fileKey, array $updates): bool
{
    if (!ourAirportsIsValidFileKey($fileKey)) {
        return false;
    }

    $saved = ourAirportsWithMetaLock(static function () use ($fileKey, $updates): bool {
        $meta = ourAirportsLoadMeta();
        $existing = isset($meta['files'][$fileKey]) && is_array($meta['files'][$fileKey])
            ? $meta['files'][$fileKey]
            : [];
        $current = array_merge(ourAirportsDefaultFileMeta(), $existing);
        $meta['files'][$fileKey] = array_merge($current, $updates);

        return ourAirportsSaveMeta($meta);
    });

    return $saved === true;
}

/**
 * Normalize ETag values for comparison.
 */
function ourAirportsNormalizeEtag(?string $etag): ?string
{
    if ($etag === null) {
        return null;
    }

    $etag = trim($etag);
    if ($etag === '') {
        return null;
    }

    return $etag;
}

/**
 * Normalize HTTP Last-Modified values for comparison.
 */
function ourAirportsNormalizeLastModified(?string $lastModified): ?string
{
    if ($lastModified === null) {
        return null;
    }

    $lastModified = trim($lastModified);
    if ($lastModified === '') {
        return null;
    }

    $timestamp = strtotime($lastModified);
    if ($timestamp === false) {
        return $lastModified;
    }

    return gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
}
