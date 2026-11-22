<?php

/**
 * Self-contained WebP EXIF reader.
 *
 * Reads the WebP RIFF EXIF chunk directly from bytes, with no external tool and
 * no PHP exif extension. The TIFF parsing is shared with jpeg.php.
 */

require_once __DIR__ . '/jpeg.php';
require_once __DIR__ . '/png.php';

/**
 * Read EXIF tags from a WebP path.
 *
 * @param string $path WebP file path
 * @return array{status: string, DateTimeOriginal?: string, OffsetTimeOriginal?: string, GpsDateStamp?: string, GpsTimeComponents?: array{0: int, 1: int, 2: int}}
 */
function exifNativeWebpRead(string $path): array
{
    if (!is_readable($path)) {
        return ['status' => 'error'];
    }

    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        return ['status' => 'error'];
    }

    $tiff = exifNativeWebpExtractTiff($bytes);
    if ($tiff === null) {
        return ['status' => 'absent'];
    }

    return exifNativeTagsToResult(exifNativeParseTiffTags($tiff));
}

/**
 * Extract the Exif TIFF blob from a WebP RIFF EXIF chunk, or null when absent.
 *
 * The EXIF chunk payload follows the same convention as a JPEG APP1 and may
 * carry the leading "Exif\0\0" signature before the TIFF header.
 *
 * @param string $bytes Full WebP bytes
 * @return string|null TIFF bytes, or null when no EXIF chunk exists
 */
function exifNativeWebpExtractTiff(string $bytes): ?string
{
    if (strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
        return null;
    }

    $pos = 12;
    $length = strlen($bytes);
    while ($pos + 8 <= $length) {
        $fourcc = substr($bytes, $pos, 4);
        $chunkLen = exifNativeLeUint32($bytes, $pos + 4);
        $dataStart = $pos + 8;
        $dataEnd = $dataStart + $chunkLen;
        if ($dataEnd > $length) {
            return null;
        }

        if ($fourcc === 'EXIF') {
            $payload = substr($bytes, $dataStart, $chunkLen);
            return exifNativeStripExifSignature($payload);
        }

        // RIFF chunks are padded to even length.
        $pos = $dataStart + $chunkLen + ($chunkLen % 2);
    }

    return null;
}

/**
 * Little-endian unsigned 32-bit read.
 */
function exifNativeLeUint32(string $s, int $offset): int
{
    return ord($s[$offset])
        | (ord($s[$offset + 1]) << 8)
        | (ord($s[$offset + 2]) << 16)
        | (ord($s[$offset + 3]) << 24);
}
