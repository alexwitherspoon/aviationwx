<?php

declare(strict_types=1);

/**
 * NOTAM cache file helpers (atomic writes, path resolution).
 */

require_once __DIR__ . '/../logger.php';

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
    $written = file_put_contents($tmp, $json);
    if ($written === false || $written !== strlen($json)) {
        @unlink($tmp);

        return false;
    }

    if (!rename($tmp, $cacheFile)) {
        @unlink($tmp);

        return false;
    }

    return true;
}
