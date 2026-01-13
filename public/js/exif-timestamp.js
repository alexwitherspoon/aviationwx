/**
 * Minimal EXIF DateTimeOriginal Extractor
 * 
 * Extracts only the DateTimeOriginal timestamp from JPEG and WebP EXIF data.
 * No external dependencies. Handles both big-endian and little-endian TIFF.
 * 
 * Supported formats:
 * - JPEG: Standard EXIF in APP1 marker
 * - WebP: EXIF chunk in RIFF container
 * 
 * For aviation safety: Used to verify webcam images are actually from
 * the timestamp we expect, preventing display of stale cached images.
 * 
 * @license MIT
 * @see https://www.exif.org/Exif2-2.PDF (EXIF 2.2 Specification)
 * @see https://developers.google.com/speed/webp/docs/riff_container (WebP Container)
 */

(function(global) {
    'use strict';

    // EXIF Tag IDs
    const TAG_EXIF_IFD_POINTER = 0x8769;  // Pointer to EXIF sub-IFD
    const TAG_GPS_IFD_POINTER = 0x8825;   // Pointer to GPS sub-IFD
    const TAG_DATETIME_ORIGINAL = 0x9003; // DateTimeOriginal tag (local time)
    const TAG_GPS_DATE_STAMP = 0x001D;    // GPSDateStamp tag (UTC date)
    const TAG_GPS_TIME_STAMP = 0x0007;    // GPSTimeStamp tag (UTC time)

    // JPEG Markers
    const JPEG_SOI = 0xFFD8;   // Start of Image
    const JPEG_APP1 = 0xFFE1;  // APP1 (EXIF data)
    const JPEG_SOS = 0xFFDA;   // Start of Scan (end of metadata)
    const JPEG_EOI = 0xFFD9;   // End of Image

    // WebP/RIFF signatures
    const RIFF_SIGNATURE = 0x52494646; // "RIFF" in big-endian
    const WEBP_SIGNATURE = 0x57454250; // "WEBP" in big-endian

    // TIFF Constants
    const TIFF_LITTLE_ENDIAN = 0x4949; // "II"
    const TIFF_BIG_ENDIAN = 0x4D4D;    // "MM"
    const TIFF_MAGIC = 42;

    // EXIF Type: ASCII string
    const EXIF_TYPE_ASCII = 2;

    /**
     * Extract DateTimeOriginal from image data (JPEG or WebP)
     * 
     * Automatically detects image format and uses appropriate parser.
     * 
     * @param {ArrayBuffer} buffer - Image file data (first 64KB is sufficient)
     * @returns {number|null} Unix timestamp (seconds) or null if not found/invalid
     */
    function extractDateTimeOriginal(buffer) {
        try {
            if (buffer.byteLength < 12) {
                return null; // Too small to be valid
            }

            const view = new DataView(buffer);

            // Check for JPEG signature (FFD8)
            if (view.getUint16(0) === JPEG_SOI) {
                return extractFromJpeg(buffer, view);
            }

            // Check for WebP signature (RIFF....WEBP)
            if (view.getUint32(0) === RIFF_SIGNATURE && view.getUint32(8) === WEBP_SIGNATURE) {
                return extractFromWebP(buffer, view);
            }

            // Unsupported format
            return null;
        } catch (e) {
            // Any parsing error = invalid EXIF
            console.error('[EXIF] Parse error:', e.message);
            return null;
        }
    }

    /**
     * Extract DateTimeOriginal from JPEG image data
     * 
     * @param {ArrayBuffer} buffer - JPEG file data
     * @param {DataView} view - DataView of buffer
     * @returns {number|null} Unix timestamp (seconds) or null if not found/invalid
     */
    function extractFromJpeg(buffer, view) {
        // Find APP1 marker containing EXIF
        const exifOffset = findExifMarker(view, buffer.byteLength);
        if (exifOffset === null) {
            return null; // No EXIF APP1 marker found
        }

        // Parse EXIF/TIFF structure
        return parseExifTiff(buffer, exifOffset);
    }

    /**
     * Extract DateTimeOriginal from WebP image data
     * 
     * WebP stores EXIF in an "EXIF" chunk within the RIFF container.
     * The chunk contains raw TIFF data (no "Exif\0\0" prefix like JPEG).
     * 
     * @param {ArrayBuffer} buffer - WebP file data
     * @param {DataView} view - DataView of buffer
     * @returns {number|null} Unix timestamp (seconds) or null if not found/invalid
     */
    function extractFromWebP(buffer, view) {
        // WebP structure: RIFF <size> WEBP <chunks...>
        // Each chunk: <4-byte type> <4-byte size> <data>
        // EXIF chunk type: "EXIF"
        
        let offset = 12; // Skip "RIFF" + size + "WEBP"
        const fileSize = view.getUint32(4, true) + 8; // RIFF size is little-endian
        const maxOffset = Math.min(buffer.byteLength, fileSize);

        while (offset + 8 <= maxOffset) {
            // Read chunk type (4 bytes, ASCII)
            const chunkType = String.fromCharCode(
                view.getUint8(offset),
                view.getUint8(offset + 1),
                view.getUint8(offset + 2),
                view.getUint8(offset + 3)
            );
            
            // Chunk size is little-endian
            const chunkSize = view.getUint32(offset + 4, true);
            
            if (chunkType === 'EXIF') {
                // Found EXIF chunk - parse TIFF data directly
                // WebP EXIF chunk contains raw TIFF header (no "Exif\0\0" prefix)
                const tiffStart = offset + 8;
                return parseExifTiff(buffer, tiffStart);
            }
            
            // Move to next chunk (chunks are padded to even byte boundary)
            offset += 8 + chunkSize;
            if (chunkSize % 2 !== 0) {
                offset++; // Padding byte
            }
        }

        return null; // No EXIF chunk found
    }

    /**
     * Find the APP1 marker containing EXIF data
     * 
     * @param {DataView} view - DataView of JPEG buffer
     * @param {number} length - Buffer length
     * @returns {number|null} Offset to TIFF header, or null if not found
     */
    function findExifMarker(view, length) {
        let offset = 2; // Skip SOI marker

        while (offset < length - 12) {
            const marker = view.getUint16(offset);

            // Check for end of metadata markers
            if (marker === JPEG_SOS || marker === JPEG_EOI) {
                return null; // Reached image data without finding EXIF
            }

            // Check for APP1 marker
            if (marker === JPEG_APP1) {
                const segmentLength = view.getUint16(offset + 2);

                // Verify "Exif\0\0" signature (6 bytes)
                // 0x45786966 = "Exif", followed by 0x0000
                if (view.getUint32(offset + 4) === 0x45786966 &&
                    view.getUint16(offset + 8) === 0x0000) {
                    // Return offset to TIFF header (after "Exif\0\0")
                    return offset + 10;
                }

                // Not EXIF APP1, skip this segment
                offset += 2 + segmentLength;
                continue;
            }

            // Other marker with length field
            if ((marker & 0xFF00) === 0xFF00 && marker !== 0xFF00) {
                // Markers FFD0-FFD9 and FF01 have no length
                if ((marker >= 0xFFD0 && marker <= 0xFFD9) || marker === 0xFF01) {
                    offset += 2;
                } else {
                    const segmentLength = view.getUint16(offset + 2);
                    offset += 2 + segmentLength;
                }
            } else {
                // Invalid marker, try next byte
                offset++;
            }
        }

        return null; // EXIF not found
    }

    /**
     * Parse EXIF TIFF structure to find DateTimeOriginal
     * 
     * @param {ArrayBuffer} buffer - Full buffer
     * @param {number} tiffStart - Offset to TIFF header
     * @returns {number|null} Unix timestamp or null
     */
    function parseExifTiff(buffer, tiffStart) {
        const view = new DataView(buffer);

        // Read byte order (II = little-endian, MM = big-endian)
        const byteOrder = view.getUint16(tiffStart);
        let littleEndian;

        if (byteOrder === TIFF_LITTLE_ENDIAN) {
            littleEndian = true;
        } else if (byteOrder === TIFF_BIG_ENDIAN) {
            littleEndian = false;
        } else {
            return null; // Invalid byte order marker
        }

        // Verify TIFF magic number (42)
        if (view.getUint16(tiffStart + 2, littleEndian) !== TIFF_MAGIC) {
            return null; // Invalid TIFF header
        }

        // Get offset to IFD0 (Image File Directory)
        const ifd0Offset = view.getUint32(tiffStart + 4, littleEndian);
        if (ifd0Offset === 0 || tiffStart + ifd0Offset >= buffer.byteLength) {
            return null; // Invalid IFD0 offset
        }

        // Find EXIF IFD pointer in IFD0
        const exifIfdOffset = findTagInIfd(
            view, 
            tiffStart, 
            tiffStart + ifd0Offset, 
            TAG_EXIF_IFD_POINTER, 
            littleEndian
        );

        if (exifIfdOffset === null) {
            return null; // No EXIF IFD pointer found
        }

        // Find DateTimeOriginal in EXIF IFD
        const dateTimeStr = findStringTagInIfd(
            view,
            buffer,
            tiffStart,
            tiffStart + exifIfdOffset,
            TAG_DATETIME_ORIGINAL,
            littleEndian
        );

        if (dateTimeStr === null) {
            return null; // DateTimeOriginal not found
        }

        // Parse date string to Unix timestamp
        return parseDateTimeString(dateTimeStr);
    }

    /**
     * Find a tag's value (as 4-byte integer) in an IFD
     * 
     * @param {DataView} view - DataView of buffer
     * @param {number} tiffStart - Offset to TIFF header
     * @param {number} ifdOffset - Offset to IFD
     * @param {number} targetTag - Tag ID to find
     * @param {boolean} littleEndian - Byte order
     * @returns {number|null} Tag value or null if not found
     */
    function findTagInIfd(view, tiffStart, ifdOffset, targetTag, littleEndian) {
        const numEntries = view.getUint16(ifdOffset, littleEndian);

        for (let i = 0; i < numEntries; i++) {
            const entryOffset = ifdOffset + 2 + (i * 12);
            const tag = view.getUint16(entryOffset, littleEndian);

            if (tag === targetTag) {
                // Return the value/offset field (last 4 bytes of 12-byte entry)
                return view.getUint32(entryOffset + 8, littleEndian);
            }
        }

        return null;
    }

    /**
     * Find a string tag's value in an IFD
     * 
     * @param {DataView} view - DataView of buffer
     * @param {ArrayBuffer} buffer - Full buffer for string extraction
     * @param {number} tiffStart - Offset to TIFF header
     * @param {number} ifdOffset - Offset to IFD
     * @param {number} targetTag - Tag ID to find
     * @param {boolean} littleEndian - Byte order
     * @returns {string|null} Tag string value or null if not found
     */
    function findStringTagInIfd(view, buffer, tiffStart, ifdOffset, targetTag, littleEndian) {
        try {
            if (!(view instanceof DataView)) {
                console.error('[EXIF GPS] findStringTagInIfd: view is not a DataView', typeof view);
                return null;
            }
            
            if (!(buffer instanceof ArrayBuffer)) {
                console.error('[EXIF GPS] findStringTagInIfd: buffer is not ArrayBuffer', typeof buffer);
                return null;
            }
            
            const numEntries = view.getUint16(ifdOffset, littleEndian);

            for (let i = 0; i < numEntries; i++) {
                const entryOffset = ifdOffset + 2 + (i * 12);
                const tag = view.getUint16(entryOffset, littleEndian);

                if (tag === targetTag) {
                    const type = view.getUint16(entryOffset + 2, littleEndian);
                    const count = view.getUint32(entryOffset + 4, littleEndian);

                    // Must be ASCII type
                    if (type !== EXIF_TYPE_ASCII) {
                        return null;
                    }

                    // Determine string location
                    // If count <= 4, string is stored inline in value field
                    // Otherwise, value field contains offset to string
                    let stringOffset;
                    if (count <= 4) {
                        stringOffset = entryOffset + 8;
                    } else {
                        stringOffset = tiffStart + view.getUint32(entryOffset + 8, littleEndian);
                    }

                    // Bounds check
                    if (stringOffset + count > buffer.byteLength) {
                        return null;
                    }

                    // Read string (excluding null terminator)
                    const bytes = new Uint8Array(buffer, stringOffset, count - 1);
                    return String.fromCharCode.apply(null, bytes);
                }
            }

            return null;
        } catch (e) {
            console.error('[EXIF GPS] findStringTagInIfd error:', e.message);
            return null;
        }
    }

    /**
     * Parse EXIF DateTime string to Unix timestamp
     * 
     * EXIF format: "YYYY:MM:DD HH:MM:SS"
     * Our pipeline stores times in UTC (using gmdate in PHP)
     * 
     * @param {string} dateStr - DateTime string from EXIF
     * @returns {number|null} Unix timestamp (seconds) or null if invalid
     */
    function parseDateTimeString(dateStr) {
        // Validate format with regex
        const match = dateStr.match(/^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})$/);
        if (!match) {
            return null;
        }

        const year = parseInt(match[1], 10);
        const month = parseInt(match[2], 10);
        const day = parseInt(match[3], 10);
        const hour = parseInt(match[4], 10);
        const minute = parseInt(match[5], 10);
        const second = parseInt(match[6], 10);

        // Basic validation
        if (month < 1 || month > 12 || day < 1 || day > 31 ||
            hour > 23 || minute > 59 || second > 59) {
            return null;
        }

        // Create UTC date (our pipeline stores EXIF times in UTC)
        const date = new Date(Date.UTC(year, month - 1, day, hour, minute, second));

        // Verify date is valid (catches invalid dates like Feb 30)
        if (isNaN(date.getTime())) {
            return null;
        }

        return Math.floor(date.getTime() / 1000);
    }

    /**
     * Extract GPS timestamp (UTC) from image data
     * 
     * Reads GPS fields per EXIF standard:
     * - GPSDateStamp: YYYY:MM:DD (UTC date)
     * - GPSTimeStamp: HH:MM:SS or HH/1 MM/1 SS/1 (UTC time)
     * 
     * @param {ArrayBuffer} buffer - Image file data
     * @returns {number|null} Unix timestamp (seconds) or null if not found/invalid
     */
    function extractGpsTimestamp(buffer) {
        try {
            if (buffer.byteLength < 12) {
                return null;
            }

            const view = new DataView(buffer);

            // Find EXIF offset based on format
            let exifOffset = null;
            
            // Check for JPEG (FFD8)
            if (view.getUint16(0) === JPEG_SOI) {
                exifOffset = findExifMarker(view, buffer.byteLength);
            }
            // Check for WebP (RIFF....WEBP)
            else if (view.getUint32(0) === RIFF_SIGNATURE && view.getUint32(8) === WEBP_SIGNATURE) {
                // Find EXIF chunk in WebP
                let offset = 12; // After RIFF header
                while (offset < buffer.byteLength - 8) {
                    const chunkView = new DataView(buffer, offset);
                    const chunkType = String.fromCharCode(
                        chunkView.getUint8(0),
                        chunkView.getUint8(1),
                        chunkView.getUint8(2),
                        chunkView.getUint8(3)
                    );
                    const chunkSize = chunkView.getUint32(4, true);
                    
                    if (chunkType === 'EXIF') {
                        // WebP EXIF chunk: skip the 4-byte "Exif\0\0" header if present
                        let tiffOffset = offset + 8;
                        const exifView = new DataView(buffer, tiffOffset);
                        
                        // Check if it starts with "Exif\0\0" (0x45786966 0x0000)
                        if (exifView.getUint32(0) === 0x45786966) {
                            tiffOffset += 4; // Skip "Exif" header
                        }
                        
                        exifOffset = tiffOffset;
                        break;
                    }
                    
                    offset += 8 + chunkSize + (chunkSize % 2);
                }
            }
            
            if (exifOffset === null) {
                return null; // No EXIF found
            }
            
            // Parse TIFF header
            const tiffStart = exifOffset;
            const tiffView = new DataView(buffer, tiffStart);
            const byteOrder = tiffView.getUint16(0);
            const littleEndian = (byteOrder === TIFF_LITTLE_ENDIAN);
            
            // Find GPS IFD pointer in IFD0
            const ifd0Offset = tiffView.getUint32(4, littleEndian);
            const gpsIfdOffset = findTagInIfd(tiffView, 0, ifd0Offset, TAG_GPS_IFD_POINTER, littleEndian);
            
            if (gpsIfdOffset === null) {
                return null; // No GPS IFD
            }
            
            // Read GPS tags (gpsIfdOffset is relative to TIFF start, so add tiffStart for buffer offset)
            const gpsDateStamp = readGpsDateStamp(buffer, tiffStart, tiffStart + gpsIfdOffset, littleEndian);
            const gpsTimeStamp = readGpsTimeStamp(buffer, tiffStart, tiffStart + gpsIfdOffset, littleEndian);
            
            if (!gpsDateStamp || !gpsTimeStamp) {
                return null; // Missing GPS date or time
            }
            
            // Parse GPS date: "YYYY:MM:DD"
            const dateMatch = gpsDateStamp.match(/^(\d{4}):(\d{2}):(\d{2})$/);
            if (!dateMatch) {
                return null;
            }
            
            // Parse GPS time: "HH:MM:SS" or extract from rational
            let hour, minute, second;
            const timeMatch = gpsTimeStamp.match(/^(\d{2}):(\d{2}):(\d{2})$/);
            if (timeMatch) {
                hour = parseInt(timeMatch[1], 10);
                minute = parseInt(timeMatch[2], 10);
                second = parseInt(timeMatch[3], 10);
            } else {
                // Try rational format: [HH/1, MM/1, SS/1]
                if (Array.isArray(gpsTimeStamp) && gpsTimeStamp.length === 3) {
                    hour = Math.floor(gpsTimeStamp[0]);
                    minute = Math.floor(gpsTimeStamp[1]);
                    second = Math.floor(gpsTimeStamp[2]);
                } else {
                    return null;
                }
            }
            
            // Create UTC date from GPS fields
            const year = parseInt(dateMatch[1], 10);
            const month = parseInt(dateMatch[2], 10);
            const day = parseInt(dateMatch[3], 10);
            
            const date = new Date(Date.UTC(year, month - 1, day, hour, minute, second));
            
            if (isNaN(date.getTime())) {
                return null;
            }
            
            return Math.floor(date.getTime() / 1000);
        } catch (e) {
            console.error('[EXIF GPS] Parse error:', e.message);
            return null;
        }
    }

    /**
     * Read GPS DateStamp from GPS IFD
     * 
     * @param {ArrayBuffer} buffer - Image buffer
     * @param {number} tiffStart - Start of TIFF header
     * @param {number} ifdOffset - GPS IFD offset
     * @param {boolean} littleEndian - Byte order
     * @returns {string|null} GPS date string "YYYY:MM:DD" or null
     */
    function readGpsDateStamp(buffer, tiffStart, ifdOffset, littleEndian) {
        try {
            const view = new DataView(buffer);
            const value = findStringTagInIfd(view, buffer, tiffStart, ifdOffset, TAG_GPS_DATE_STAMP, littleEndian);
            return (value && typeof value === 'string') ? value.trim() : null;
        } catch (e) {
            console.error('[EXIF GPS] readGpsDateStamp error:', e.message);
            return null;
        }
    }

    /**
     * Read GPS TimeStamp from GPS IFD
     * 
     * @param {ArrayBuffer} buffer - Image buffer
     * @param {number} tiffStart - Start of TIFF header
     * @param {number} ifdOffset - GPS IFD offset
     * @param {boolean} littleEndian - Byte order
     * @returns {string|Array|null} GPS time "HH:MM:SS" or [HH/1, MM/1, SS/1] or null
     */
    function readGpsTimeStamp(buffer, tiffStart, ifdOffset, littleEndian) {
        try {
            const view = new DataView(buffer);
            
            // Try to read as string first
            const stringValue = findStringTagInIfd(view, buffer, tiffStart, ifdOffset, TAG_GPS_TIME_STAMP, littleEndian);
            if (stringValue) {
                return stringValue.trim();
            }
            
            // GPS TimeStamp is typically stored as 3 rationals [H, M, S]
            // Each rational is numerator/denominator (8 bytes)
            const numEntries = view.getUint16(ifdOffset, littleEndian);
            
            for (let i = 0; i < numEntries; i++) {
                const entryOffset = ifdOffset + 2 + (i * 12);
                const tag = view.getUint16(entryOffset, littleEndian);
                
                if (tag === TAG_GPS_TIME_STAMP) {
                    const type = view.getUint16(entryOffset + 2, littleEndian);
                    const count = view.getUint32(entryOffset + 4, littleEndian);
                    
                    // Type 5 = RATIONAL (2 LONGs: numerator/denominator)
                    if (type === 5 && count === 3) {
                        const dataOffset = tiffStart + view.getUint32(entryOffset + 8, littleEndian);
                        const dataView = new DataView(buffer, dataOffset);
                        
                        // Read 3 rationals
                        const hour = dataView.getUint32(0, littleEndian) / dataView.getUint32(4, littleEndian);
                        const minute = dataView.getUint32(8, littleEndian) / dataView.getUint32(12, littleEndian);
                        const second = dataView.getUint32(16, littleEndian) / dataView.getUint32(20, littleEndian);
                        
                        // Format as HH:MM:SS string
                        const pad = (n) => String(Math.floor(n)).padStart(2, '0');
                        return `${pad(hour)}:${pad(minute)}:${pad(second)}`;
                    }
                }
            }
            
            return null;
        } catch (e) {
            console.error('[EXIF GPS] readGpsTimeStamp error:', e.message);
            return null;
        }
    }

    /**
     * Verify image blob contains expected timestamp
     * 
     * For JPEG: Reads first 64KB (EXIF is in header)
     * For WebP: Reads entire blob (EXIF chunk can be at end of file)
     * 
     * @param {Blob} blob - Image blob to verify
     * @param {number} expectedTimestamp - Expected Unix timestamp
     * @param {number} toleranceSeconds - Allowed difference (default: 5)
     * @returns {Promise<{verified: boolean, reason: string, exifTimestamp: number|null}>}
     */
    async function verifyImageTimestamp(blob, expectedTimestamp, toleranceSeconds) {
        toleranceSeconds = toleranceSeconds || 5;

        try {
            // First, detect format by reading initial bytes
            const headerBlob = blob.slice(0, 12);
            const headerBuffer = await headerBlob.arrayBuffer();
            const headerView = new DataView(headerBuffer);
            
            let buffer;
            
            // Check if JPEG (FFD8 signature)
            if (headerView.getUint16(0) === JPEG_SOI) {
                // JPEG: EXIF is in APP1 marker near start - 64KB is sufficient
                const jpegBlob = blob.slice(0, 65536);
                buffer = await jpegBlob.arrayBuffer();
            } 
            // Check if WebP (RIFF....WEBP signature)
            else if (headerView.getUint32(0) === RIFF_SIGNATURE && 
                     headerView.getUint32(8) === WEBP_SIGNATURE) {
                // WebP: EXIF chunk can be anywhere, often at end
                // Need to read entire file
                buffer = await blob.arrayBuffer();
            }
            else {
                // Unknown format
                return {
                    verified: false,
                    reason: 'unsupported_format',
                    exifTimestamp: null
                };
            }

            // CRITICAL CHANGE: Use GPS timestamp (UTC) instead of DateTimeOriginal (local time)
            // Per EXIF standard:
            // - DateTimeOriginal = local time at camera location
            // - GPS fields = UTC (Zulu time) for aviation use
            // Client must verify against GPS timestamp for accuracy
            const exifTimestamp = extractGpsTimestamp(buffer);

            if (exifTimestamp === null) {
                // Fallback to DateTimeOriginal for backwards compatibility
                // (for images processed before GPS requirement)
                const fallbackTimestamp = extractDateTimeOriginal(buffer);
                
                if (fallbackTimestamp === null) {
                    return {
                        verified: false,
                        reason: 'no_gps_timestamp',
                        exifTimestamp: null
                    };
                }
                
                // Using DateTimeOriginal fallback
                const difference = Math.abs(fallbackTimestamp - expectedTimestamp);
                
                if (difference > toleranceSeconds) {
                    return {
                        verified: false,
                        reason: 'timestamp_mismatch_fallback',
                        exifTimestamp: fallbackTimestamp,
                        difference: difference
                    };
                }
                
                return {
                    verified: true,
                    reason: 'ok_fallback',
                    exifTimestamp: fallbackTimestamp
                };
            }

            const difference = Math.abs(exifTimestamp - expectedTimestamp);

            if (difference > toleranceSeconds) {
                return {
                    verified: false,
                    reason: 'gps_timestamp_mismatch',
                    exifTimestamp: exifTimestamp,
                    difference: difference
                };
            }

            return {
                verified: true,
                reason: 'ok_gps',
                exifTimestamp: exifTimestamp
            };
        } catch (e) {
            return {
                verified: false,
                reason: 'parse_error',
                exifTimestamp: null,
                error: e.message
            };
        }
    }

    // Export functions
    global.ExifTimestamp = {
        extract: extractDateTimeOriginal,
        extractGps: extractGpsTimestamp,
        verify: verifyImageTimestamp,
        parseDateTime: parseDateTimeString
    };

})(typeof window !== 'undefined' ? window : this);

