<?php

declare(strict_types=1);

/**
 * NOTAM cache file helpers (atomic writes, path resolution).
 */

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../constants.php';

/**
 * Directory for per-airport NOTAM JSON cache files.
 */
function notamCacheDirectory(): string
{
    if (defined('AVIATIONWX_NOTAM_CACHE_DIR')) {
        return rtrim((string) AVIATIONWX_NOTAM_CACHE_DIR, '/');
    }

    return dirname(__DIR__, 2) . '/cache/notam';
}

/**
 * Resolve cache file path for an airport id.
 */
function notamCacheFilePath(string $airportId): string
{
    return notamCacheDirectory() . '/' . strtolower(trim($airportId)) . '.json';
}

/**
 * Sidecar path recording the last failed NMS fetch attempt (preserved-cache path).
 */
function notamFetchAttemptFilePath(string $airportId): string
{
    return notamCacheDirectory() . '/' . strtolower(trim($airportId)) . '.fetch-attempt';
}

/**
 * Record a failed fetch attempt without mutating the NOTAM cache payload or mtime.
 */
function notamRecordFetchAttempt(string $airportId): void
{
    $path = notamFetchAttemptFilePath($airportId);
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        aviationwx_log('warning', 'notam cache: cannot create directory for fetch attempt', [
            'cache_dir' => $dir,
            'airport' => $airportId,
        ], 'app');

        return;
    }

    if (@file_put_contents($path, (string) time(), LOCK_EX) === false) {
        aviationwx_log('warning', 'notam cache: failed to record fetch attempt', [
            'airport' => $airportId,
            'path' => $path,
        ], 'app');
    }
}

/**
 * Clear fetch-attempt sidecar after a successful refresh.
 */
function notamClearFetchAttempt(string $airportId): void
{
    $path = notamFetchAttemptFilePath($airportId);
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Whether the scheduler should enqueue a NOTAM refresh for this airport.
 */
function notamShouldEnqueueRefresh(string $airportId, int $refreshInterval, ?int $now = null): bool
{
    $now = $now ?? time();
    $cacheFile = notamCacheFilePath($airportId);
    $cacheAge = is_file($cacheFile) ? ($now - (int) filemtime($cacheFile)) : PHP_INT_MAX;
    if ($cacheAge < $refreshInterval) {
        return false;
    }

    $attemptFile = notamFetchAttemptFilePath($airportId);
    if (is_file($attemptFile)) {
        $attemptAge = $now - (int) filemtime($attemptFile);
        if ($attemptAge < NOTAM_FETCH_FAILURE_BACKOFF_SECONDS) {
            return false;
        }
    }

    return true;
}

/**
 * Atomically write NOTAM cache JSON (temp file + rename).
 *
 * @param array<string, mixed> $cacheData
 */
function notamWriteCacheFile(string $cacheFile, array $cacheData): bool
{
    $dir = dirname($cacheFile);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        aviationwx_log('error', 'notam cache: cannot create directory', [
            'cache_dir' => $dir,
        ], 'app');

        return false;
    }

    try {
        $json = json_encode($cacheData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        aviationwx_log('error', 'notam cache: encode failed', [
            'error' => $e->getMessage(),
        ], 'app');

        return false;
    }

    $tmp = $cacheFile . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $written = @file_put_contents($tmp, $json, LOCK_EX);
    if ($written === false || $written !== strlen($json)) {
        aviationwx_log('error', 'notam cache: temp write failed', [
            'cache_file' => $cacheFile,
            'tmp_file' => $tmp,
            'error' => error_get_last()['message'] ?? 'unknown',
        ], 'app');
        @unlink($tmp);

        return false;
    }

    if (!@rename($tmp, $cacheFile)) {
        aviationwx_log('error', 'notam cache: rename failed', [
            'cache_file' => $cacheFile,
            'tmp_file' => $tmp,
            'error' => error_get_last()['message'] ?? 'unknown',
        ], 'app');
        @unlink($tmp);

        return false;
    }

    return true;
}
