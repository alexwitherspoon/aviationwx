<?php
/**
 * HTTP Integrity Headers
 *
 * Helpers for ETag, Content-Digest (RFC 9530), and Content-MD5 (RFC 2616, legacy).
 * Used across image and data endpoints for conditional requests and integrity verification.
 *
 * File digest/MD5 values are cached in APCu (write-once, read-many pattern).
 * Cache key: realpath|mtime - invalidates when file is replaced.
 * TTL: from config (webcam_history_retention_hours, weather_history_retention_hours)
 * or http_integrity_digest_cache_ttl_seconds override.
 */

if (!defined('HTTP_INTEGRITY_DIGEST_PREFIX')) {
    define('HTTP_INTEGRITY_DIGEST_PREFIX', 'aviationwx_digest_');
}

/**
 * Compute weak ETag from file metadata (no file read)
 *
 * @param string $filePath Path to file
 * @param int $mtime File modification time
 * @param int $size File size in bytes
 * @return string Weak ETag (e.g. W/"abc123...")
 */
function computeFileEtag(string $filePath, int $mtime, int $size): string
{
    $hash = sha1($filePath . '|' . $mtime . '|' . $size);
    return 'W/"' . $hash . '"';
}

/**
 * Get cached digest values for a file, or compute and cache
 *
 * @param string $filePath Path to file
 * @param int $mtime File modification time
 * @return array{digest: string|null, md5: string|null}|null Null on failure
 */
function getFileDigestsWithCache(string $filePath, int $mtime): ?array
{
    // @ realpath: symlinks may resolve; fallback to raw path on failure
    $realPath = @realpath($filePath);
    if ($realPath === false) {
        $realPath = $filePath;
    }
    $cacheKey = HTTP_INTEGRITY_DIGEST_PREFIX . md5($realPath . '|' . $mtime);

    if (function_exists('apcu_fetch')) {
        // @ apcu_fetch: APCu may be disabled; we handle false explicitly
        $cached = @apcu_fetch($cacheKey);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
    }

    // @ hash_file: file may be unreadable; we return null on failure
    $shaRaw = @hash_file('sha256', $filePath, true);
    $md5Raw = @hash_file('md5', $filePath, true);
    if ($shaRaw === false || $md5Raw === false) {
        return null;
    }

    $result = [
        'digest' => 'sha-256=:' . base64_encode($shaRaw) . ':',
        'md5' => base64_encode($md5Raw),
    ];

    if (function_exists('apcu_store')) {
        $ttl = function_exists('getHttpIntegrityDigestTtlSeconds')
            ? getHttpIntegrityDigestTtlSeconds()
            : 86400; // 24h fallback when config not loaded
        // @ apcu_store: cache write is best-effort; response still valid without it
        @apcu_store($cacheKey, $result, $ttl);
    }

    return $result;
}

/**
 * Compute Content-Digest (RFC 9530) for file: sha-256=Base64
 * Uses APCu cache when available (write-once, read-many).
 *
 * @param string $filePath Path to file
 * @return string|null Content-Digest value or null on failure
 */
function computeFileContentDigest(string $filePath): ?string
{
    // @ filemtime/hash_file: handle missing/unreadable files; return null on failure
    $mtime = @filemtime($filePath);
    if ($mtime === false) {
        $raw = @hash_file('sha256', $filePath, true);
        return $raw !== false ? 'sha-256=:' . base64_encode($raw) . ':' : null;
    }
    $digests = getFileDigestsWithCache($filePath, $mtime);
    return $digests['digest'] ?? null;
}

/**
 * Compute Content-Digest for string content (RFC 9530)
 *
 * @param string $content Raw body content
 * @return string Content-Digest value
 */
function computeContentDigestFromString(string $content): string
{
    $raw = hash('sha256', $content, true);
    return 'sha-256=:' . base64_encode($raw) . ':';
}

/**
 * Compute Content-MD5 (RFC 2616, legacy) for file
 * Uses APCu cache when available (write-once, read-many).
 *
 * @param string $filePath Path to file
 * @return string|null Content-MD5 value or null on failure
 */
function computeFileContentMd5(string $filePath): ?string
{
    // @ filemtime/hash_file: handle missing/unreadable files; return null on failure
    $mtime = @filemtime($filePath);
    if ($mtime === false) {
        $raw = @hash_file('md5', $filePath, true);
        return $raw !== false ? base64_encode($raw) : null;
    }
    $digests = getFileDigestsWithCache($filePath, $mtime);
    return $digests['md5'] ?? null;
}

/**
 * Compute Content-MD5 for string content
 *
 * @param string $content Raw body content
 * @return string Content-MD5 value
 */
function computeContentMd5FromString(string $content): string
{
    return base64_encode(md5($content, true));
}

/**
 * Check conditional request and send 304 if content unchanged
 *
 * @param string $etag ETag value (weak or strong)
 * @param int $mtime File modification time
 * @return bool True if 304 was sent (caller should not send body), false otherwise
 */
function maybeSend304IfUnchanged(string $etag, int $mtime): bool
{
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    $ifModSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';

    $matchEtag = false;
    if ($ifNoneMatch !== '') {
        if (trim($ifNoneMatch) === '*') {
            $matchEtag = true;
        } else {
            foreach (array_map('trim', explode(',', $ifNoneMatch)) as $candidate) {
                if ($candidate === $etag) {
                    $matchEtag = true;
                    break;
                }
            }
        }
    }
    // @ strtotime: malformed If-Modified-Since; we treat as no match
    $matchMod = ($ifModSince !== '' && @strtotime($ifModSince) >= $mtime);

    if ($matchEtag || $matchMod) {
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        http_response_code(304);
        return true;
    }
    return false;
}

/**
 * Add integrity headers for a file response (ETag, Content-Digest, Content-MD5)
 * Call before sending body. Returns true if 304 was sent.
 *
 * @param string $filePath Path to file
 * @param int|null $mtime Optional mtime (default: filemtime)
 * @return bool True if 304 sent (do not send body), false to continue
 */
function addIntegrityHeadersForFile(string $filePath, ?int $mtime = null): bool
{
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return false;
    }

    $size = filesize($filePath);
    if ($size === false || $size <= 0) {
        return false;
    }

    $mtime = $mtime ?? filemtime($filePath);
    if ($mtime === false) {
        $mtime = time();
    }

    $etag = computeFileEtag($filePath, $mtime, $size);
    if (maybeSend304IfUnchanged($etag, $mtime)) {
        return true;
    }

    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

    $digests = getFileDigestsWithCache($filePath, $mtime);
    if ($digests !== null) {
        if ($digests['digest'] !== null) {
            header('Content-Digest: ' . $digests['digest']);
        }
        if ($digests['md5'] !== null) {
            header('Content-MD5: ' . $digests['md5']);
        }
    }

    return false;
}
