<?php

/**
 * Self-contained JPEG EXIF reader.
 *
 * Reads the EXIF APP1 segment of a JPEG directly from bytes, with no external
 * tool and no PHP exif extension. Returns DateTimeOriginal (local) and the GPS
 * UTC pair (GPSDateStamp plus GPSTimeStamp) with an ok/absent/error tri-state
 * so callers can distinguish a missing field from a transient read failure.
 */

// Bound on a single decoded IFD value. Ordinary webcam tags (dates, GPS
// rationals, short strings) sit far below this; it exists so a hostile count
// cannot expand bytes into a huge PHP array during a parse.
if (!defined('EXIF_MAX_VALUE_DECODE_BYTES')) {
    define('EXIF_MAX_VALUE_DECODE_BYTES', 1048576);
}

/**
 * Parse a JPEG path and return the EXIF tags this module understands.
 *
 * Returns a structured array with status and whichever tags were present.
 *
 * status: 'ok' when an EXIF APP1 segment was found and parsed, 'absent'
 * when the file has no EXIF APP1, 'error' when the file is unreadable or
 * the EXIF structure is malformed in a way that prevents a confident read.
 *
 * @param string $path JPEG file path
 * @return array{
 *   status: string,
 *   DateTimeOriginal?: string,
 *   OffsetTimeOriginal?: string,
 *   GpsDateStamp?: string,
 *   GpsTimeComponents?: array{0: int, 1: int, 2: int}
 * }
 */
function exifNativeJpegRead(string $path): array
{
    if (!is_readable($path)) {
        return ['status' => 'error'];
    }

    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        return ['status' => 'error'];
    }

    $tiff = exifNativeJpegExtractTiff($bytes);
    if ($tiff === null) {
        // A file with no Exif APP1 is genuinely absent, not a read error.
        return ['status' => 'absent'];
    }

    return exifNativeTagsToResult(exifNativeParseTiffTags($tiff));
}

/**
 * Normalize a parsed tag group into the module's result shape.
 *
 * Shared by every container reader. Produces 'ok' with whatever tags were
 * found; callers decide absence vs error before reaching here.
 *
 * @param array{exif: array<int, mixed>, gps: array<int, mixed>, ifd0?: array<int, mixed>} $tags
 * @return array{status: string, DateTimeOriginal?: string, OffsetTimeOriginal?: string, GpsDateStamp?: string, GpsTimeComponents?: array{0: int, 1: int, 2: int}, ImageDescription?: string, Artist?: string, Copyright?: string, UserComment?: string, Make?: string, Model?: string, Orientation?: int}
 */
function exifNativeTagsToResult(array $tags): array
{
    $result = ['status' => 'ok'];

    $ifd0 = $tags['ifd0'] ?? [];
    foreach ([0x010e => 'ImageDescription', 0x013b => 'Artist', 0x8298 => 'Copyright', 0x010f => 'Make', 0x0110 => 'Model'] as $tag => $key) {
        $value = $ifd0[$tag] ?? null;
        if (is_string($value) && $value !== '') {
            $result[$key] = $value;
        }
    }
    $orientation = $ifd0[0x0112] ?? null;
    if (is_int($orientation) || (is_array($orientation) && isset($orientation[0]) && is_int($orientation[0]))) {
        $result['Orientation'] = is_array($orientation) ? (int) $orientation[0] : $orientation;
    }

    $tag = $tags['exif'][0x9286] ?? null; // UserComment, UNDEFINED byte array
    $userComment = null;
    if (is_string($tag) && $tag !== '') {
        $userComment = $tag;
    } elseif (is_array($tag)) {
        $bytes = '';
        foreach ($tag as $byte) {
            $bytes .= chr((int) $byte);
        }
        $userComment = $bytes;
    }
    if (is_string($userComment)) {
        // exiftool prefixes a charset for UNDEFINED text; strip it.
        $userComment = preg_replace('/^(ASCII\x00\x00\x00|UNICODE\x00|JIS\x00)/', '', $userComment);
        if ($userComment !== '') {
            $result['UserComment'] = $userComment;
        }
    }

    $tag = $tags['exif'][0x9003] ?? null; // DateTimeOriginal
    if (is_string($tag) && $tag !== '') {
        $result['DateTimeOriginal'] = $tag;
    }
    $tag = $tags['exif'][0x9011] ?? null; // OffsetTimeOriginal
    if (is_string($tag) && $tag !== '') {
        $result['OffsetTimeOriginal'] = $tag;
    }
    $tag = $tags['gps'][0x001d] ?? null;  // GPSDateStamp
    if (is_string($tag) && $tag !== '') {
        $result['GpsDateStamp'] = $tag;
    }
    $gpsTime = $tags['gps'][0x0007] ?? null; // GPSTimeStamp, three rationals
    if (is_array($gpsTime) && count($gpsTime) === 3) {
        $result['GpsTimeComponents'] = [
            (int) $gpsTime[0],
            (int) $gpsTime[1],
            (int) $gpsTime[2],
        ];
    }

    return $result;
}

/**
 * Extract the Exif TIFF blob from a JPEG APP1 segment, or null when absent.
 *
 * @param string $bytes Full JPEG bytes
 * @return string|null TIFF bytes (from 'II'/'MM' header onward), or null
 */
function exifNativeJpegExtractTiff(string $bytes): ?string
{
    $length = strlen($bytes);
    if ($length < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
        return null;
    }

    $pos = 2;
    while ($pos + 4 <= $length) {
        $marker = ord($bytes[$pos]) << 8 | ord($bytes[$pos + 1]);
        if (($marker & 0xFF00) !== 0xFF00) {
            return null;
        }
        if ($marker === 0xFFD8 || ($marker >= 0xFFD0 && $marker <= 0xFFD7) || $marker === 0xFF01) {
            // Standalone markers with no length field.
            $pos += 2;
            continue;
        }
        if ($marker === 0xFFDA) {
            // Start of scan; no EXIF appears after here.
            return null;
        }
        if ($pos + 4 > $length) {
            return null;
        }
        $segLen = ord($bytes[$pos + 2]) << 8 | ord($bytes[$pos + 3]);
        if ($segLen < 2 || $pos + 2 + $segLen > $length) {
            return null;
        }
        // The length field counts its own 2 bytes plus the data, so this
        // segment ends 2 + segLen bytes after the marker.
        $dataLen = $segLen - 2;
        if ($marker === 0xFFE1) { // APP1
            $payload = substr($bytes, $pos + 4, $dataLen);
            if (substr($payload, 0, 6) === "Exif\x00\x00") {
                $tiff = substr($payload, 6);
                if (strlen($tiff) >= 8 && (substr($tiff, 0, 2) === 'II' || substr($tiff, 0, 2) === 'MM')) {
                    return $tiff;
                }
            }
        }
        $pos += 2 + $segLen;
    }

    return null;
}

/**
 * Parse an Exif TIFF blob into the IFD tag groups this module needs.
 *
 * Walks IFD0, then the ExifSubIFD (tag 0x8769) and the GPS IFD (tag 0x8825),
 * returning a map of tagId to decoded value for each group.
 *
 * @param string $tiff TIFF bytes beginning with the byte-order marker
 * @return array{ifd0: array<int, mixed>, exif: array<int, mixed>, gps: array<int, mixed>}
 */
function exifNativeParseTiffTags(string $tiff): array
{
    // The IFD0 offset is read from the TIFF header, never assumed. JPEG APP1
    // uses 8; exiftool's WebP EXIF chunk embeds an "Exif" marker and uses 16.
    $isLittle = substr($tiff, 0, 2) === 'II';
    $groups = ['exif' => [], 'gps' => []];

    $ifd0Offset = exifNativeUint32($tiff, 4, $isLittle);
    $ifd0 = exifNativeReadIfdEntries($tiff, $ifd0Offset, $isLittle);
    if ($ifd0 === null) {
        return $groups;
    }
    $ifd0Tags = [];
    foreach ($ifd0['entries'] as $entry) {
        $ifd0Tags[$entry['tag']] = $entry;
    }
    // IFD0's own tags (ImageDescription, Artist, Copyright) are surfaced so the
    // write path can verify what it wrote.
    $ifd0Own = [];
    foreach ($ifd0['entries'] as $entry) {
        $ifd0Own[$entry['tag']] = $entry['value'];
    }
    $groups['ifd0'] = $ifd0Own;
    if (isset($ifd0Tags[0x8769]) && is_numeric($ifd0Tags[0x8769]['value'])) { // Exif IFD
        $sub = exifNativeReadIfdEntries($tiff, (int) $ifd0Tags[0x8769]['value'], $isLittle);
        if ($sub !== null) {
            foreach ($sub['entries'] as $entry) {
                $groups['exif'][$entry['tag']] = $entry['value'];
            }
        }
    }
    if (isset($ifd0Tags[0x8825]) && is_numeric($ifd0Tags[0x8825]['value'])) { // GPS IFD
        $sub = exifNativeReadIfdEntries($tiff, (int) $ifd0Tags[0x8825]['value'], $isLittle);
        if ($sub !== null) {
            foreach ($sub['entries'] as $entry) {
                $groups['gps'][$entry['tag']] = $entry['value'];
            }
        }
    }

    return $groups;
}

/**
 * Decode one IFD table at an offset.
 *
 * @return array{entries: list<array{tag: int, value: mixed}>}|null
 */
function exifNativeReadIfdEntries(string $tiff, int $offset, bool $isLittle): ?array
{
    $length = strlen($tiff);
    if ($offset + 2 > $length) {
        return null;
    }
    $count = exifNativeUint16($tiff, $offset, $isLittle);
    // A real EXIF IFD is small. Bound the count so a hostile file cannot force
    // an unbounded entry walk.
    if ($count > 512) {
        return null;
    }
    $pos = $offset + 2;
    $entries = [];
    for ($i = 0; $i < $count; $i++) {
        if ($pos + 12 > $length) {
            return null;
        }
        $tag = exifNativeUint16($tiff, $pos, $isLittle);
        $type = exifNativeUint16($tiff, $pos + 2, $isLittle);
        $valueCount = exifNativeUint32($tiff, $pos + 4, $isLittle);

        $valueFields = [
            1 => 1,  // BYTE
            2 => 1,  // ASCII
            3 => 2,  // SHORT
            4 => 4,  // LONG
            5 => 8,  // RATIONAL
            6 => 1,  // SBYTE
            7 => 1,  // UNDEFINED
            9 => 4,  // SLONG
            10 => 8, // SRATIONAL
        ];
        $unit = $valueFields[$type] ?? null;
        if ($unit === null) {
            $value = null;
        } else {
            $bytesNeeded = $unit * $valueCount;
            // Bound how much a single value may expand. Decoding expands bytes
            // to PHP array elements, so an oversized claim on a hostile file
            // would balloon memory even though it is inside the file bounds.
            if ($bytesNeeded > EXIF_MAX_VALUE_DECODE_BYTES) {
                $value = null;
            } elseif ($bytesNeeded <= 4) {
                // Inline value in the 4-byte field.
                $value = exifNativeDecodeValue(
                    $tiff,
                    $pos + 8,
                    min(4, $bytesNeeded),
                    $type,
                    $valueCount,
                    $isLittle
                );
            } elseif ($bytesNeeded <= (int) $length - (int) exifNativeUint32($tiff, $pos + 8, $isLittle)) {
                $dataOffset = exifNativeUint32($tiff, $pos + 8, $isLittle);
                if ($dataOffset + $bytesNeeded <= $length) {
                    $value = exifNativeDecodeValue(
                        $tiff,
                        $dataOffset,
                        $bytesNeeded,
                        $type,
                        $valueCount,
                        $isLittle
                    );
                } else {
                    $value = null;
                }
            } else {
                $value = null;
            }
        }
        $entries[] = ['tag' => $tag, 'value' => $value];
        $pos += 12;
    }

    return ['entries' => $entries];
}

/**
 * Decode an IFD entry value of a known type.
 *
 * @param string $tiff TIFF bytes
 * @param int $offset Offset of the value bytes
 * @param int $bytelength Length of the value bytes
 * @param int $type TIFF type id
 * @param int $count Number of components
 * @param bool $isLittle Endianness
 * @return int|string|array<int, int>|null Decoded value
 */
function exifNativeDecodeValue(string $tiff, int $offset, int $bytelength, int $type, int $count, bool $isLittle): int|string|array|null
{
    if ($bytelength <= 0 || $offset + $bytelength > strlen($tiff)) {
        return null;
    }

    switch ($type) {
        case 2: // ASCII
            $raw = substr($tiff, $offset, $bytelength);
            $null = strpos($raw, "\x00");
            if ($null !== false) {
                $raw = substr($raw, 0, $null);
            }
            return $raw;

        case 3: // SHORT
            $out = [];
            for ($i = 0; $i < $count; $i++) {
                $out[] = exifNativeUint16($tiff, $offset + ($i * 2), $isLittle);
            }
            return $count === 1 ? $out[0] : $out;

        case 4: // LONG
            $out = [];
            for ($i = 0; $i < $count; $i++) {
                $out[] = exifNativeUint32($tiff, $offset + ($i * 4), $isLittle);
            }
            return $count === 1 ? $out[0] : $out;

        case 5: // RATIONAL
            $out = [];
            for ($i = 0; $i < $count; $i++) {
                $num = exifNativeUint32($tiff, $offset + ($i * 8), $isLittle);
                $den = exifNativeUint32($tiff, $offset + ($i * 8) + 4, $isLittle);
                $out[] = $den !== 0 ? $num / $den : 0.0;
            }
            return $count === 1 ? $out[0] : $out;

        case 1: // BYTE
        case 7: // UNDEFINED
            $raw = substr($tiff, $offset, $bytelength);
            $out = [];
            for ($i = 0; $i < strlen($raw); $i++) {
                $out[] = ord($raw[$i]);
            }
            return $count === 1 ? $out[0] : $out;

        default:
            return null;
    }
}

/**
 * Big-endian or little-endian unsigned 16-bit read.
 */
function exifNativeUint16(string $s, int $offset, bool $isLittle): int
{
    if ($isLittle) {
        return ord($s[$offset]) | (ord($s[$offset + 1]) << 8);
    }
    return (ord($s[$offset]) << 8) | ord($s[$offset + 1]);
}

/**
 * Big-endian or little-endian unsigned 32-bit read.
 */
function exifNativeUint32(string $s, int $offset, bool $isLittle): int
{
    if ($isLittle) {
        return ord($s[$offset])
            | (ord($s[$offset + 1]) << 8)
            | (ord($s[$offset + 2]) << 16)
            | (ord($s[$offset + 3]) << 24);
    }
    return (ord($s[$offset]) << 24)
        | (ord($s[$offset + 1]) << 16)
        | (ord($s[$offset + 2]) << 8)
        | ord($s[$offset + 3]);
}

/**
 * Build a UTC timestamp from GPSDateStamp and GPSTimeStamp, mirroring the
 * existing parser in exif-utils.php.
 *
 * @param string $dateStamp 'YYYY:MM:DD'
 * @param array{0: int, 1: int, 2: int} $timeComponents hour, minute, second
 * @return int Unix timestamp, or 0 when unparseable
 */
function exifNativeGpsUtcTimestamp(string $dateStamp, array $timeComponents): int
{
    $date = str_replace(':', '-', $dateStamp);
    $time = sprintf('%02d:%02d:%02d', $timeComponents[0], $timeComponents[1], $timeComponents[2]);
    $ts = @strtotime($date . ' ' . $time . ' UTC');
    return ($ts !== false && $ts > 0) ? (int) $ts : 0;
}

/**
 * Build the local-time timestamp from DateTimeOriginal, mirroring the existing
 * getDateTimeOriginalTimestamp() interpretation (local value, parsed as UTC).
 *
 * @param string $dateTimeOriginal 'Y:m:d H:i:s'
 * @return int Unix timestamp, or 0 when unparseable
 */
function exifNativeDateTimeOriginalTimestamp(string $dateTimeOriginal): int
{
    $formatted = str_replace(':', '-', substr($dateTimeOriginal, 0, 10))
        . substr($dateTimeOriginal, 10);
    $ts = @strtotime($formatted . ' UTC');
    return ($ts !== false && $ts > 0) ? (int) $ts : 0;
}
