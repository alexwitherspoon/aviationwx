<?php

/**
 * Self-contained PNG EXIF reader.
 *
 * Reads the PNG eXIf chunk directly from bytes, with no external tool and no
 * PHP exif extension. The TIFF parsing is shared with jpeg.php.
 */

require_once __DIR__ . '/jpeg.php';

/**
 * Read EXIF tags from a PNG path.
 *
 * @param string $path PNG file path
 * @return array{status: string, DateTimeOriginal?: string, OffsetTimeOriginal?: string, GpsDateStamp?: string, GpsTimeComponents?: array{0: int, 1: int, 2: int}}
 */
function exifNativePngRead(string $path): array
{
    if (!is_readable($path)) {
        return ['status' => 'error'];
    }

    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        return ['status' => 'error'];
    }

    $tiff = exifNativePngExtractTiff($bytes);
    if ($tiff === null) {
        return ['status' => 'absent'];
    }

    return exifNativeTagsToResult(exifNativeParseTiffTags($tiff));
}

/**
 * Extract the Exif TIFF blob from a PNG eXIf chunk, or null when absent.
 *
 * The eXIf payload is the same EXIF block stored in a JPEG APP1, so it may or
 * may not carry the leading "Exif\0\0" signature before the TIFF header.
 *
 * @param string $bytes Full PNG bytes
 * @return string|null TIFF bytes, or null when no eXIf chunk exists
 */
function exifNativePngExtractTiff(string $bytes): ?string
{
    $signature = "\x89PNG\r\n\x1a\n";
    if (strlen($bytes) < 8 || substr($bytes, 0, 8) !== $signature) {
        return null;
    }

    $pos = 8;
    $length = strlen($bytes);
    while ($pos + 8 <= $length) {
        $chunkLen = exifNativeBeUint32($bytes, $pos);
        $type = substr($bytes, $pos + 4, 4);
        $dataStart = $pos + 8;
        $dataEnd = $dataStart + $chunkLen;
        if ($dataEnd + 4 > $length) {
            return null;
        }

        if ($type === 'eXIf') {
            $payload = substr($bytes, $dataStart, $chunkLen);
            // Reject a tampered eXIf chunk: PNG chunks are CRC-protected, and a
            // frame whose metadata fails the check must not be trusted.
            $expected = pack('N', crc32('eXIf' . $payload));
            if (substr($bytes, $dataEnd, 4) !== $expected) {
                return null;
            }
            return exifNativeStripExifSignature($payload);
        }

        // Next chunk is 4-byte length + 4-byte type + data + 4-byte CRC.
        $pos = $dataEnd + 4;
    }

    return null;
}

/**
 * Strip a leading "Exif\0\0" signature if present, leaving raw TIFF.
 *
 * @param string $payload A container's EXIF payload
 * @return string|null TIFF bytes, or null when it holds no TIFF header
 */
function exifNativeStripExifSignature(string $payload): ?string
{
    $tiff = $payload;
    if (substr($payload, 0, 6) === "Exif\x00\x00") {
        $tiff = substr($payload, 6);
    }
    if (strlen($tiff) >= 8 && (substr($tiff, 0, 2) === 'II' || substr($tiff, 0, 2) === 'MM')) {
        return $tiff;
    }
    return null;
}

/**
 * Big-endian unsigned 32-bit read.
 */
function exifNativeBeUint32(string $s, int $offset): int
{
    return (ord($s[$offset]) << 24)
        | (ord($s[$offset + 1]) << 16)
        | (ord($s[$offset + 2]) << 8)
        | ord($s[$offset + 3]);
}
