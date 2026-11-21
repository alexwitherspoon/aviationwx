<?php

/**
 * Format dispatcher for the self-contained EXIF readers.
 *
 * Sniffs the container from bytes and routes to the right reader, covering the
 * full advertised input surface: jpg, png, webp.
 */

require_once __DIR__ . '/jpeg.php';
require_once __DIR__ . '/png.php';
require_once __DIR__ . '/webp.php';

/**
 * Read EXIF tags from any supported image path.
 *
 * Detects jpg, png, and webp by magic bytes and returns the same structured
 * result shape as the container readers.
 *
 * @param string $path Image file path
 * @return array{status: string, DateTimeOriginal?: string, OffsetTimeOriginal?: string, GpsDateStamp?: string, GpsTimeComponents?: array{0: int, 1: int, 2: int}}
 */
function exifNativeRead(string $path): array
{
    if (!is_readable($path)) {
        return ['status' => 'error'];
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return ['status' => 'error'];
    }
    $head = fread($handle, 12);
    fclose($handle);

    if ($head !== false && strlen($head) >= 2 && substr($head, 0, 2) === "\xFF\xD8") {
        return exifNativeJpegRead($path);
    }
    if ($head !== false && strlen($head) >= 8 && substr($head, 0, 8) === "\x89PNG\r\n\x1a\n") {
        return exifNativePngRead($path);
    }
    if ($head !== false && strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
        return exifNativeWebpRead($path);
    }

    return ['status' => 'error'];
}
