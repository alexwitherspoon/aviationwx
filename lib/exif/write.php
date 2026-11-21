<?php

/**
 * Self-contained EXIF stamp writer.
 *
 * Builds a little-endian EXIF block with the nine tags the pipeline writes,
 * splices it into JPEG, PNG, or WebP, writes atomically, restores mtime, and
 * verifies by reading back. XMP is deliberately not written (EXIF-only policy).
 */

require_once __DIR__ . '/jpeg.php';
require_once __DIR__ . '/png.php';
require_once __DIR__ . '/webp.php';

/**
 * Read Make, Model, and Orientation from an existing image's EXIF, if present.
 *
 * Used on stamp so these display-relevant and provenance tags survive a block
 * replacement, matching exiftool's preserve-on-overwrite behavior.
 *
 * @param string $path Image path
 * @return array{make: ?string, model: ?string, orientation: ?int}
 */
function exifNativeReadIfd0Carry(string $path): array
{
    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        return ['make' => null, 'model' => null, 'orientation' => null];
    }

    $tiff = null;
    if (substr($bytes, 0, 2) === "\xFF\xD8") {
        $tiff = exifNativeJpegExtractTiff($bytes);
    } elseif (substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
        $tiff = exifNativePngExtractTiff($bytes);
    } elseif (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
        $tiff = exifNativeWebpExtractTiff($bytes);
    }
    if ($tiff === null) {
        return ['make' => null, 'model' => null, 'orientation' => null];
    }

    $ifd0 = (exifNativeParseTiffTags($tiff))['ifd0'] ?? [];
    $make = isset($ifd0[0x010F]) && is_string($ifd0[0x010F]) ? $ifd0[0x010F] : null;
    $model = isset($ifd0[0x0110]) && is_string($ifd0[0x0110]) ? $ifd0[0x0110] : null;
    $orientation = $ifd0[0x0112] ?? null;
    if (is_array($orientation) && isset($orientation[0]) && is_int($orientation[0])) {
        $orientation = $orientation[0];
    }
    $orientation = is_int($orientation) ? $orientation : null;

    return ['make' => $make, 'model' => $model, 'orientation' => $orientation];
}

/**
 * Read pixel dimensions from image container bytes.
 *
 * Parses the raw header of each supported container: JPEG scans SOF markers,
 * PNG reads the IHDR, WebP reads the VP8X, VP8, or VP8L frame tag. Used to
 * write ExifImageWidth and ExifImageHeight on a stamp.
 *
 * @param string $bytes Image bytes
 * @return array{width: int, height: int}|null
 */
function exifNativeContainerDimensions(string $bytes): ?array
{
    $length = strlen($bytes);
    if ($length < 8) {
        return null;
    }

    // JPEG: walk the segment table looking for an SOF marker.
    if (substr($bytes, 0, 2) === "\xFF\xD8") {
        $pos = 2;
        while ($pos + 9 <= $length) {
            if ($bytes[$pos] !== "\xFF") {
                return null;
            }
            $code = ord($bytes[$pos + 1]);
            if ($code === 0xD8 || $code === 0x01 || ($code >= 0xD0 && $code <= 0xD7)) {
                $pos += 2;
                continue;
            }
            if ($code === 0xD9) {
                return null; // EOI before any SOF
            }
            $segLen = (ord($bytes[$pos + 2]) << 8) | ord($bytes[$pos + 3]);
            if ($segLen < 2 || $pos + 2 + $segLen > $length) {
                return null;
            }
            $isSof = $code >= 0xC0 && $code <= 0xCF && !in_array($code, [0xC4, 0xC8, 0xCC], true);
            if ($isSof && $segLen >= 7) {
                $height = (ord($bytes[$pos + 5]) << 8) | ord($bytes[$pos + 6]);
                $width = (ord($bytes[$pos + 7]) << 8) | ord($bytes[$pos + 8]);
                return ($width > 0 && $height > 0) ? ['width' => $width, 'height' => $height] : null;
            }
            $pos += 2 + $segLen;
        }
        return null;
    }

    // PNG: width and height in the IHDR payload.
    if (substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n" && $length >= 24) {
        $width = (ord($bytes[16]) << 24) | (ord($bytes[17]) << 16) | (ord($bytes[18]) << 8) | ord($bytes[19]);
        $height = (ord($bytes[20]) << 24) | (ord($bytes[21]) << 16) | (ord($bytes[22]) << 8) | ord($bytes[23]);
        return ($width > 0 && $height > 0) ? ['width' => $width, 'height' => $height] : null;
    }

    // WebP: read the canvas from the first image chunk.
    if (substr($bytes, 0, 4) === 'RIFF' && $length >= 12 && substr($bytes, 8, 4) === 'WEBP') {
        $pos = 12;
        while ($pos + 8 <= $length) {
            $fourcc = substr($bytes, $pos, 4);
            $chunkLen = (ord($bytes[$pos + 4]) | (ord($bytes[$pos + 5]) << 8)
                | (ord($bytes[$pos + 6]) << 16) | (ord($bytes[$pos + 7]) << 24));
            if ($chunkLen > $length - $pos - 8) {
                return null;
            }
            $d = $pos + 8;
            if ($fourcc === 'VP8X' && $chunkLen >= 10) {
                $width = 1 + (ord($bytes[$d + 4]) | (ord($bytes[$d + 5]) << 8) | (ord($bytes[$d + 6]) << 16));
                $height = 1 + (ord($bytes[$d + 7]) | (ord($bytes[$d + 8]) << 8) | (ord($bytes[$d + 9]) << 16));
                return ['width' => $width, 'height' => $height];
            }
            if ($fourcc === 'VP8 ' && $chunkLen >= 11) {
                // 3-byte frame tag, then the key-frame start code, then
                // 14-bit width and height stored little-endian.
                if (ord($bytes[$d + 3]) !== 0x9D || ord($bytes[$d + 4]) !== 0x01 || ord($bytes[$d + 5]) !== 0x2A) {
                    return null; // not a key frame; cannot trust the canvas
                }
                $width = (ord($bytes[$d + 6]) | (ord($bytes[$d + 7]) << 8)) & 0x3FFF;
                $height = (ord($bytes[$d + 8]) | (ord($bytes[$d + 9]) << 8)) & 0x3FFF;
                return ($width > 0 && $height > 0) ? ['width' => $width, 'height' => $height] : null;
            }
            if ($fourcc === 'VP8L' && $chunkLen >= 5 && ord($bytes[$d]) === 0x2F) {
                $bits = ord($bytes[$d + 1]) | (ord($bytes[$d + 2]) << 8)
                    | (ord($bytes[$d + 3]) << 16) | (ord($bytes[$d + 4]) << 24);
                return ['width' => 1 + ($bits & 0x3FFF), 'height' => 1 + (($bits >> 14) & 0x3FFF)];
            }
            $pos = $d + $chunkLen + ($chunkLen % 2);
        }
        return null;
    }

    return null;
}

/**
 * Stamp EXIF onto an image.
 *
 * Writes DateTimeOriginal (local), OffsetTimeOriginal, GPS DateStamp and
 * TimeStamp (UTC), plus the four EXIF attribution tags. XMP is not written.
 * Make, Model, and Orientation from an existing EXIF are preserved so a block
 * replacement does not change display or provenance behavior.
 *
 * @param string $path Path to the image to stamp
 * @param int|null $timestamp Canonical capture time; null uses file mtime
 * @param string $timezone IANA timezone for the local DateTimeOriginal
 * @return bool True when the stamp was written and verified
 */
function exifNativeStamp(string $path, ?int $timestamp = null, string $timezone = 'UTC'): bool
{
    if (!is_readable($path)) {
        return false;
    }
    if ($timestamp === null) {
        $timestamp = @filemtime($path);
    }
    if ($timestamp === false || $timestamp <= 0) {
        $timestamp = time();
    }

    $local = exifNativeLocalDateTimeParts($timestamp, $timezone);
    if ($local === null) {
        return false;
    }

    $carry = exifNativeReadIfd0Carry($path);

    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        return false;
    }

    $values = [
        'dt' => $local['date_time'],
        'offset' => $local['offset'],
        'gpsDate' => gmdate('Y:m:d', $timestamp),
        'gpsTime' => [
            (int) gmdate('H', $timestamp),
            (int) gmdate('i', $timestamp),
            (int) gmdate('s', $timestamp),
        ],
        'imageDescription' => 'Aviation weather webcam image processed by AviationWX.org',
        'artist' => 'AviationWX.org',
        'copyright' => "© AviationWX.org and image contributors",
        'userComment' => 'Processed by AviationWX.org',
        'make' => $carry['make'],
        'model' => $carry['model'],
        'orientation' => $carry['orientation'],
    ];
    $dims = exifNativeContainerDimensions($bytes);
    if ($dims !== null) {
        $values['width'] = $dims['width'];
        $values['height'] = $dims['height'];
    }
    $tiff = exifNativeBuildTiff($values);

    $exifPayload = "Exif\x00\x00" . $tiff;
    $next = null;
    if (substr($bytes, 0, 2) === "\xFF\xD8") {
        $next = exifNativeSpliceJpeg($bytes, $exifPayload);
    } elseif (substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
        $next = exifNativeSplicePng($bytes, $exifPayload);
    } elseif (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
        $next = exifNativeSpliceWebp($bytes, $exifPayload);
    } else {
        return false; // Unsupported format; do not touch the file.
    }
    if ($next === null) {
        return false;
    }

    return exifNativeWriteAtomically($path, $next) && exifNativeVerifyStamp($path, $local['date_time'], $timestamp);
}

/**
 * Compute local DateTimeOriginal and UTC offset for a timestamp.
 *
 * @param int $timestamp Unix timestamp
 * @param string $timezone IANA timezone
 * @return array{date_time: string, offset: string}|null
 */
function exifNativeLocalDateTimeParts(int $timestamp, string $timezone): ?array
{
    try {
        $dt = new DateTime('@' . $timestamp);
        $dt->setTimezone(new DateTimeZone($timezone));
    } catch (Exception $e) {
        return null;
    }

    return [
        'date_time' => $dt->format('Y:m:d H:i:s'),
        'offset' => $dt->format('P'),
    ];
}

/**
 * Assemble a little-endian TIFF block from IFD specs and out-of-line blobs.
 *
 * Shared by both TIFF builders so offset math and data layout live in one
 * place. Values longer than 4 bytes are placed out-of-line on even offsets;
 * shorter values are inline. An entry points at a blob with 'key', carries
 * inline data through 'int' (numeric) or 'inline' (bytes), or targets another
 * IFD with 'next' (its index, resolved to a byte offset during layout).
 *
 * @param list<array{entries: list<array{tag: int, type: int, count: int, key?: string, int?: int|string, inline?: string, next?: int}>}> $ifds
 * @param array<string, string> $blobs
 * @return string TIFF bytes
 */
function exifNativeAssembleTiff(array $ifds, array $blobs): string
{
    $unit = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 7 => 1, 9 => 4, 10 => 8];

    // TIFF requires entries within an IFD to be ascending by tag.
    foreach ($ifds as &$ifd) {
        usort($ifd['entries'], static fn (array $a, array $b): int => $a['tag'] <=> $b['tag']);
    }
    unset($ifd);

    $offsets = [];
    $offset = 8;
    foreach ($ifds as $index => $ifd) {
        $offsets[$index] = $offset;
        $offset += 2 + count($ifd['entries']) * 12 + 4;
    }
    $dataStart = $offset;
    if ($dataStart % 2 !== 0) {
        $dataStart++;
    }

    $addr = [];
    $next = $dataStart;
    foreach ($ifds as &$ifd) {
        foreach ($ifd['entries'] as &$entry) {
            if (isset($entry['next'])) {
                $entry['int'] = $offsets[$entry['next']];
                unset($entry['next']);
            } elseif (($unit[$entry['type']] ?? 1) * $entry['count'] <= 4) {
                // Inline value: numeric or short blob bytes.
                if (isset($entry['key'])) {
                    $entry['inline'] = $blobs[$entry['key']];
                }
            } else {
                if ($next % 2 !== 0) {
                    $next++;
                }
                $addr[$entry['key']] = $next;
                $next += strlen($blobs[$entry['key']]);
                $entry['int'] = $addr[$entry['key']];
            }
            unset($entry['key']);
        }
    }
    unset($ifd, $entry);

    $out = 'II' . exifNativeLe16(0x2A) . exifNativeLe32(8);
    foreach ($ifds as $ifd) {
        $out .= exifNativeLe16(count($ifd['entries']));
        foreach ($ifd['entries'] as $entry) {
            $value = $entry['inline'] ?? $entry['int'] ?? '';
            $out .= exifNativeEntry($entry['tag'], $entry['type'], $entry['count'], $value);
        }
        $out .= exifNativeLe32(0);
    }

    // Append out-of-line blobs at their allocated offsets, in offset order.
    asort($addr);
    foreach ($addr as $key => $target) {
        while (strlen($out) < $target) {
            $out .= "\x00";
        }
        $out .= $blobs[$key];
    }

    return $out;
}

/**
 * Build a little-endian EXIF TIFF block for the given tag values.
 *
 * @param array{
 *   dt: string, offset: string, gpsDate: string,
 *   gpsTime: array{0: int, 1: int, 2: int},
 *   imageDescription: string, artist: string, copyright: string, userComment: string,
 *   make?: string, model?: string, orientation?: int, width?: int, height?: int
 * } $values
 * @return string TIFF bytes
 */
function exifNativeBuildTiff(array $values): string
{
    $blob = [
        'desc' => $values['imageDescription'] . "\x00",
        'artist' => $values['artist'] . "\x00",
        'copyright' => $values['copyright'] . "\x00",
        'dt' => $values['dt'] . "\x00",
        'offset' => $values['offset'] . "\x00",
        'userComment' => "ASCII\x00\x00\x00" . $values['userComment'],
        'gpsDate' => $values['gpsDate'] . "\x00",
    ];
    if (!empty($values['make'])) {
        $blob['make'] = $values['make'] . "\x00";
    }
    if (!empty($values['model'])) {
        $blob['model'] = $values['model'] . "\x00";
    }
    $blob['gpsTime'] = '';
    foreach ($values['gpsTime'] as $component) {
        $norm = max(0, (int) round($component));
        $blob['gpsTime'] .= exifNativeLe32($norm) . exifNativeLe32(1);
    }

    $ifd0 = [
        ['tag' => 0x010E, 'type' => 2, 'count' => strlen($blob['desc']), 'key' => 'desc'],
        ['tag' => 0x013B, 'type' => 2, 'count' => strlen($blob['artist']), 'key' => 'artist'],
        ['tag' => 0x8298, 'type' => 2, 'count' => strlen($blob['copyright']), 'key' => 'copyright'],
    ];
    if (isset($blob['make'])) {
        $ifd0[] = ['tag' => 0x010F, 'type' => 2, 'count' => strlen($blob['make']), 'key' => 'make'];
    }
    if (isset($blob['model'])) {
        $ifd0[] = ['tag' => 0x0110, 'type' => 2, 'count' => strlen($blob['model']), 'key' => 'model'];
    }
    if (!empty($values['orientation'])) {
        $ifd0[] = ['tag' => 0x0112, 'type' => 3, 'count' => 1, 'int' => (int) $values['orientation']];
    }

    $ifd0[] = ['tag' => 0x0213, 'type' => 3, 'count' => 1, 'int' => 1]; // YCbCrPositioning centered

    $exif = [
        ['tag' => 0x9000, 'type' => 7, 'count' => 4, 'inline' => '0231'],
        ['tag' => 0x9101, 'type' => 7, 'count' => 4, 'inline' => "\x01\x02\x03\x00"], // ComponentsConfiguration YCbCr
        ['tag' => 0x9003, 'type' => 2, 'count' => strlen($blob['dt']), 'key' => 'dt'],
        ['tag' => 0x9011, 'type' => 2, 'count' => strlen($blob['offset']), 'key' => 'offset'],
        ['tag' => 0x9286, 'type' => 7, 'count' => strlen($blob['userComment']), 'key' => 'userComment'],
        ['tag' => 0xA001, 'type' => 3, 'count' => 1, 'int' => 1], // ColorSpace sRGB
    ];
    if (!empty($values['width']) && !empty($values['height'])) {
        $exif[] = ['tag' => 0xA002, 'type' => 4, 'count' => 1, 'int' => (int) $values['width']];
        $exif[] = ['tag' => 0xA003, 'type' => 4, 'count' => 1, 'int' => (int) $values['height']];
    }
    $gps = [
        ['tag' => 0x0000, 'type' => 1, 'count' => 4, 'inline' => "\x02\x03\x00\x00"], // GPSVersionID 2.3.0.0
        ['tag' => 0x0007, 'type' => 5, 'count' => 3, 'key' => 'gpsTime'],
        ['tag' => 0x001D, 'type' => 2, 'count' => strlen($blob['gpsDate']), 'key' => 'gpsDate'],
    ];

    $ifd0[] = ['tag' => 0x8769, 'type' => 4, 'count' => 1, 'next' => 1]; // Exif IFD
    $ifd0[] = ['tag' => 0x8825, 'type' => 4, 'count' => 1, 'next' => 2]; // GPS IFD

    return exifNativeAssembleTiff([
        ['entries' => $ifd0],
        ['entries' => $exif],
        ['entries' => $gps],
    ], $blob);
}

/**
 * Build a credit-only TIFF block.
 *
 * A rendered card has no capture time, so unlike exifNativeBuildTiff this
 * omits DateTimeOriginal and GPS. Writes ImageDescription, Artist, and
 * Copyright in IFD0 and UserComment in the Exif IFD, where exiftool placed
 * them. Empty credit fields are skipped.
 *
 * @param array{imageDescription?: string, artist?: string, copyright?: string, userComment?: string} $credit
 * @return string TIFF bytes
 */
function exifNativeBuildCreditTiff(array $credit): string
{
    $desc = (string) ($credit['imageDescription'] ?? '');
    $artist = (string) ($credit['artist'] ?? '');
    $copyright = (string) ($credit['copyright'] ?? '');
    $userComment = (string) ($credit['userComment'] ?? '');

    $blob = [];
    $ifd0 = [];
    foreach ([
        ['tag' => 0x010E, 'value' => $desc, 'key' => 'desc'],
        ['tag' => 0x013B, 'value' => $artist, 'key' => 'artist'],
        ['tag' => 0x8298, 'value' => $copyright, 'key' => 'copyright'],
    ] as $tag) {
        if ($tag['value'] !== '') {
            $blob[$tag['key']] = $tag['value'] . "\x00";
            $ifd0[] = ['tag' => $tag['tag'], 'type' => 2, 'count' => strlen($blob[$tag['key']]), 'key' => $tag['key']];
        }
    }

    $exif = [['tag' => 0x9000, 'type' => 7, 'count' => 4, 'inline' => '0231']];
    if ($userComment !== '') {
        // Exif requires a charset header on UserComment; exiftool writes the
        // ASCII header followed by the bytes. The reader strips it on read.
        $blob['userComment'] = "ASCII\x00\x00\x00" . $userComment;
        $exif[] = ['tag' => 0x9286, 'type' => 7, 'count' => strlen($blob['userComment']), 'key' => 'userComment'];
    }

    $ifd0[] = ['tag' => 0x8769, 'type' => 4, 'count' => 1, 'next' => 1]; // Exif IFD

    return exifNativeAssembleTiff([
        ['entries' => $ifd0],
        ['entries' => $exif],
    ], $blob);
}

/**
 * Build one 12-byte IFD entry.
 *
 * Out-of-line when the value exceeds 4 bytes (value is then the data offset);
 * inline otherwise. Undefined/BYTE/ASCII inline values carry raw bytes; SHORT
 * and LONG inline values are numbers.
 *
 * @param int $tag Tag id
 * @param int $type TIFF type id
 * @param int $count Component count
 * @param int|string $value Offset when out-of-line, or inline value
 * @return string 12-byte entry
 */
function exifNativeEntry(int $tag, int $type, int $count, int|string $value): string
{
    $unit = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 7 => 1, 9 => 4, 10 => 8][$type] ?? 1;
    $bytesNeeded = $unit * $count;

    if ($bytesNeeded <= 4) {
        if (is_int($value)) {
            // SHORT and LONG values are stored as numbers in the field.
            $raw = ($type === 3) ? exifNativeLe16($value) : exifNativeLe32($value);
        } else {
            $raw = (string) $value;
        }
        $raw = substr(str_pad($raw, 4, "\x00"), 0, 4);
    } else {
        $raw = exifNativeLe32((int) $value);
    }

    return exifNativeLe16($tag) . exifNativeLe16($type) . exifNativeLe32($count) . $raw;
}

/**
 * Replace or insert an Exif APP1 segment in a JPEG.
 *
 * @param string $bytes JPEG bytes
 * @param string $exifPayload 'Exif\0\0' + TIFF
 * @return string|null New JPEG bytes, or null on structural error
 */
function exifNativeSpliceJpeg(string $bytes, string $exifPayload): ?string
{
    $length = strlen($bytes);
    if ($length < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
        return null;
    }

    $pos = 2;
    while ($pos + 4 <= $length) {
        $marker = (ord($bytes[$pos]) << 8) | ord($bytes[$pos + 1]);
        if (($marker & 0xFF00) !== 0xFF00) {
            return null;
        }
        if ($marker === 0xFFD8 || ($marker >= 0xFFD0 && $marker <= 0xFFD7) || $marker === 0xFF01) {
            $pos += 2;
            continue;
        }
        if ($marker === 0xFFDA) {
            break; // Start of scan; insert before it.
        }
        $segLen = (ord($bytes[$pos + 2]) << 8) | ord($bytes[$pos + 3]);
        if ($segLen < 2 || $pos + 2 + $segLen > $length) {
            return null;
        }
        $data = substr($bytes, $pos + 4, $segLen - 2);
        if ($marker === 0xFFE1 && substr($data, 0, 6) === "Exif\x00\x00") {
            // Replace in place.
            return substr($bytes, 0, $pos)
                . exifNativeJpegExifSegment($exifPayload)
                . substr($bytes, $pos + 2 + $segLen);
        }
        $pos += 2 + $segLen;
    }

    // No Exif APP1: insert after the first APP0 (JFIF), else right after SOI.
    // An APP0 segment length field sits at bytes[4..5], so a 6-byte minimum
    // guards the read even under a truncated hostile frame.
    $insert = 2;
    if (strlen($bytes) >= 6 && (ord($bytes[2]) << 8 | ord($bytes[3])) === 0xFFE0) {
        $insert = 2 + 2 + (ord($bytes[4]) << 8 | ord($bytes[5]));
        if ($insert > $length) {
            $insert = 2;
        }
    }

    return substr($bytes, 0, $insert)
        . exifNativeJpegExifSegment($exifPayload)
        . substr($bytes, $insert);
}

/**
 * Build a JPEG APP1 segment carrying the EXIF payload.
 */
function exifNativeJpegExifSegment(string $exifPayload): string
{
    return "\xFF\xE1" . exifNativeBe16(strlen($exifPayload) + 2) . $exifPayload;
}

/**
 * Replace or insert an eXIf chunk in a PNG.
 *
 * @param string $bytes PNG bytes
 * @param string $exifPayload 'Exif\0\0' + TIFF
 * @return string|null New PNG bytes, or null on structural error
 */
function exifNativeSplicePng(string $bytes, string $exifPayload): ?string
{
    if (strlen($bytes) < 8 || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        return null;
    }

    // PNG eXIf stores the TIFF directly; the JPEG APP1 "Exif\0\0" wrapper is
    // not part of the chunk. Shed it so conformant readers accept the chunk.
    if (substr($exifPayload, 0, 6) === "Exif\x00\x00") {
        $exifPayload = substr($exifPayload, 6);
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
            return substr($bytes, 0, $pos)
                . exifNativePngExifChunk($exifPayload)
                . substr($bytes, $dataEnd + 4);
        }
        $pos = $dataEnd + 4;
    }

    // No eXIf: insert after IHDR, the first chunk.
    $ihdrLen = exifNativeBeUint32($bytes, 8);
    $insert = 8 + 4 + 4 + $ihdrLen + 4;
    return substr($bytes, 0, $insert)
        . exifNativePngExifChunk($exifPayload)
        . substr($bytes, $insert);
}

/**
 * Build a PNG chunk carrying the EXIF payload (with CRC over type + data).
 */
function exifNativePngExifChunk(string $exifPayload): string
{
    $crc = crc32('eXIf' . $exifPayload);
    return exifNativeBe32(strlen($exifPayload)) . 'eXIf' . $exifPayload . pack('N', $crc);
}

/**
 * Replace or insert an EXIF chunk in a WebP RIFF container.
 *
 * @param string $bytes WebP bytes
 * @param string $exifPayload 'Exif\0\0' + TIFF
 * @return string|null New WebP bytes, or null on structural error
 */
function exifNativeSpliceWebp(string $bytes, string $exifPayload): ?string
{
    if (strlen($bytes) < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
        return null;
    }

    // WebP EXIF chunks carry the TIFF directly, not the JPEG APP1 wrapper.
    if (substr($exifPayload, 0, 6) === "Exif\x00\x00") {
        $exifPayload = substr($exifPayload, 6);
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
            $out = substr($bytes, 0, $pos)
                . exifNativeWebpExifChunk($exifPayload)
                . substr($bytes, $dataEnd + ($chunkLen % 2));
            return exifNativeWebpFixRiffSize($out);
        }
        $pos = $dataStart + $chunkLen + ($chunkLen % 2);
    }

    // No EXIF: insert after the RIFF header.
    $out = substr($bytes, 0, 12)
        . exifNativeWebpExifChunk($exifPayload)
        . substr($bytes, 12);
    return exifNativeWebpFixRiffSize($out);
}

/**
 * Build a WebP EXIF chunk ('EXIF', size, data, even padding).
 */
function exifNativeWebpExifChunk(string $exifPayload): string
{
    $suffix = strlen($exifPayload) % 2 === 0 ? '' : "\x00";
    return 'EXIF' . exifNativeLe32(strlen($exifPayload)) . $exifPayload . $suffix;
}

/**
 * Correct the outer RIFF size after a WebP splice.
 */
function exifNativeWebpFixRiffSize(string $bytes): string
{
    $riffSize = strlen($bytes) - 8;
    return substr($bytes, 0, 4) . exifNativeLe32($riffSize) . substr($bytes, 8);
}

/**
 * Extract the TIFF blob from any supported image, or null when absent.
 *
 * @param string $path Image path
 * @return string|null TIFF bytes, or null when the container has no EXIF
 */
function exifNativeExtractTiffBytes(string $path): ?string
{
    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        return null;
    }
    if (substr($bytes, 0, 2) === "\xFF\xD8") {
        return exifNativeJpegExtractTiff($bytes);
    }
    if (substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
        return exifNativePngExtractTiff($bytes);
    }
    if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
        return exifNativeWebpExtractTiff($bytes);
    }
    return null;
}

/**
 * Copy EXIF from one image to another, same or cross format.
 *
 * Replaces or inserts the EXIF block in the destination container. XMP and ICC
 * are deliberately not copied (EXIF-only policy). Fails closed when the source
 * has no EXIF to copy.
 *
 * @param string $source Source image with EXIF
 * @param string $dest Destination image to receive EXIF
 * @return bool True when the copy was written and verified
 */
function exifNativeCopy(string $source, string $dest): bool
{
    if (!is_readable($source) || !is_readable($dest)) {
        return false;
    }

    $tiff = exifNativeExtractTiffBytes($source);
    if ($tiff === null) {
        return false; // Nothing to copy; do not touch the destination.
    }

    return exifNativeCopyTiff($tiff, $dest);
}

/**
 * Copy a pre-extracted TIFF block into a destination image.
 *
 * Same splice and fail-closed verify as exifNativeCopy, minus the source read.
 * Lets a caller extract the source TIFF once and stamp many destinations.
 *
 * @param string $tiff TIFF bytes to embed
 * @param string $dest Destination image to receive EXIF
 * @return bool True when the copy was written and verified
 */
function exifNativeCopyTiff(string $tiff, string $dest): bool
{
    if ($tiff === '' || !is_readable($dest)) {
        return false;
    }

    $bytes = @file_get_contents($dest);
    if ($bytes === false) {
        return false;
    }

    $exifPayload = "Exif\x00\x00" . $tiff;
    $next = null;
    if (substr($bytes, 0, 2) === "\xFF\xD8") {
        $next = exifNativeSpliceJpeg($bytes, $exifPayload);
    } elseif (substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
        $next = exifNativeSplicePng($bytes, $exifPayload);
    } elseif (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
        $next = exifNativeSpliceWebp($bytes, $exifPayload);
    } else {
        return false; // Unsupported destination; do not touch it.
    }
    if ($next === null) {
        return false;
    }

    if (!exifNativeWriteAtomically($dest, $next)) {
        return false;
    }

    // Fail closed: the destination must read back with EXIF.
    return (exifNativeRead($dest)['status'] ?? '') === 'ok';
}

/**
 * Write credit tags onto an image, replacing any existing EXIF block.
 *
 * Static rendered cards have no capture time, so this writes a credit-only
 * block. Writes atomically, restores mtime (exiftool -P equivalence), and
 * verifies the tags read back.
 *
 * @param string $path Image path
 * @param array{imageDescription?: string, artist?: string, copyright?: string, userComment?: string} $credit
 * @return bool True when written and verified
 */
function exifNativeWriteCredit(string $path, array $credit): bool
{
    if (!is_readable($path)) {
        return false;
    }

    $tiff = exifNativeBuildCreditTiff($credit);
    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        return false;
    }

    $exifPayload = "Exif\x00\x00" . $tiff;
    $next = null;
    if (substr($bytes, 0, 2) === "\xFF\xD8") {
        $next = exifNativeSpliceJpeg($bytes, $exifPayload);
    } elseif (substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
        $next = exifNativeSplicePng($bytes, $exifPayload);
    } elseif (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
        $next = exifNativeSpliceWebp($bytes, $exifPayload);
    } else {
        return false; // Unsupported container; do not touch the file.
    }
    if ($next === null) {
        return false;
    }

    if (!exifNativeWriteAtomically($path, $next)) {
        return false;
    }

    return exifNativeVerifyCredit($path, $credit);
}

/**
 * Confirm the credit tags read back exactly as written.
 *
 * @param string $path Image path
 * @param array{imageDescription?: string, artist?: string, copyright?: string, userComment?: string} $credit
 * @return bool True when every non-empty field matches
 */
function exifNativeVerifyCredit(string $path, array $credit): bool
{
    $result = exifNativeRead($path);
    if (($result['status'] ?? '') !== 'ok') {
        return false;
    }

    foreach ([
        'imageDescription' => 'ImageDescription',
        'artist' => 'Artist',
        'copyright' => 'Copyright',
        'userComment' => 'UserComment',
    ] as $key => $tag) {
        $expected = (string) ($credit[$key] ?? '');
        if ($expected !== '' && ($result[$tag] ?? '') !== $expected) {
            return false;
        }
    }

    return true;
}

/**
 * Write atomically to a temp file in the same directory, then rename, and
 * restore the original mtime so cache freshness and cleanup are unchanged.
 */
function exifNativeWriteAtomically(string $path, string $content): bool
{
    // Write through symlinks so a link survives and its target is updated,
    // matching how the cache points current/original links at timestamped files.
    $target = realpath($path);
    if ($target === false || $target === '') {
        $target = $path;
    }

    $mtime = @filemtime($target);
    $atime = @fileatime($target);
    $mode = @fileperms($target);

    $dir = dirname($target);
    $tmp = $dir . '/.exifnative-' . bin2hex(random_bytes(6)) . '.tmp';
    $written = @file_put_contents($tmp, $content, LOCK_EX);
    if ($written !== strlen($content)) {
        @unlink($tmp);
        return false;
    }
    if ($mode !== false) {
        // Preserve the original mode so cache group/permission semantics hold,
        // not the temp file's default umask bits.
        @chmod($tmp, $mode & 0777);
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        return false;
    }
    if ($mtime !== false) {
        @touch($target, $mtime, ($atime !== false) ? $atime : $mtime);
    }
    return true;
}

/**
 * Read back a stamped file and confirm the capture time and GPS wrote.
 */
function exifNativeVerifyStamp(string $path, string $expectedLocale, int $expectedUtc): bool
{
    $result = exifNativeRead($path);
    if (($result['status'] ?? '') !== 'ok') {
        return false;
    }
    if (($result['DateTimeOriginal'] ?? null) !== $expectedLocale) {
        return false;
    }
    if (!isset($result['GpsDateStamp'], $result['GpsTimeComponents'])) {
        return false;
    }
    return exifNativeGpsUtcTimestamp($result['GpsDateStamp'], $result['GpsTimeComponents']) === $expectedUtc;
}

/**
 * Big-endian unsigned 16-bit write-ready helper.
 */
function exifNativeBe16(int $value): string
{
    return pack('n', $value & 0xFFFF);
}

/**
 * Big-endian unsigned 32-bit write-ready helper.
 */
function exifNativeBe32(int $value): string
{
    return pack('N', $value & 0xFFFFFFFF);
}

/**
 * Little-endian unsigned 16-bit write-ready helper.
 */
function exifNativeLe16(int $value): string
{
    return pack('v', $value & 0xFFFF);
}

/**
 * Little-endian unsigned 32-bit write-ready helper.
 */
function exifNativeLe32(int $value): string
{
    return pack('V', $value & 0xFFFFFFFF);
}
