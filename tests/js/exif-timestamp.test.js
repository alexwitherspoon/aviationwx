/**
 * Unit Tests for EXIF Timestamp Extractor
 * 
 * Run with: node tests/js/exif-timestamp.test.js
 * Or in browser console after loading exif-timestamp.js
 */

// Mock window for Node.js environment
if (typeof window === 'undefined') {
    global.window = global;
}

// Load the module (in Node.js, you'd need to load it differently)
// For browser testing, ensure exif-timestamp.js is loaded first

/**
 * Create a minimal valid JPEG with EXIF DateTimeOriginal
 * 
 * This builds a valid JPEG structure with:
 * - SOI marker
 * - APP1 marker with EXIF data
 * - Minimal TIFF structure
 * - IFD0 with ExifIFD pointer
 * - ExifIFD with DateTimeOriginal
 * - EOI marker
 * 
 * @param {string} dateTimeStr - DateTime in "YYYY:MM:DD HH:MM:SS" format
 * @param {boolean} littleEndian - Use little-endian byte order
 * @returns {ArrayBuffer} Valid JPEG with EXIF
 */
function createTestJpegWithExif(dateTimeStr, littleEndian = true) {
    // DateTime string with null terminator
    const dateBytes = new TextEncoder().encode(dateTimeStr + '\0');
    
    // Calculate offsets
    // TIFF header starts at offset 0 within EXIF data
    // IFD0 starts at offset 8 (after TIFF header)
    // IFD0 has 1 entry (ExifIFD pointer) = 2 + 12 = 14 bytes, plus 4 bytes for next IFD pointer
    // ExifIFD starts at offset 8 + 14 + 4 = 26
    // ExifIFD has 1 entry (DateTimeOriginal) = 2 + 12 = 14 bytes, plus 4 bytes for next IFD
    // DateTimeOriginal string starts at offset 26 + 14 + 4 = 44
    
    const tiffHeaderSize = 8;
    const ifd0Start = 8;
    const ifd0Size = 2 + 12 + 4; // numEntries(2) + 1 entry(12) + nextIFD(4)
    const exifIfdStart = ifd0Start + ifd0Size; // 26
    const exifIfdSize = 2 + 12 + 4; // numEntries(2) + 1 entry(12) + nextIFD(4)
    const dateTimeStart = exifIfdStart + exifIfdSize; // 44
    
    const tiffDataSize = dateTimeStart + dateBytes.length;
    
    // APP1 segment: "Exif\0\0" (6) + TIFF data
    const app1DataSize = 6 + tiffDataSize;
    
    // Full JPEG: SOI(2) + APP1 marker(2) + length(2) + APP1 data + EOI(2)
    const jpegSize = 2 + 2 + 2 + app1DataSize + 2;
    
    const buffer = new ArrayBuffer(jpegSize);
    const view = new DataView(buffer);
    const bytes = new Uint8Array(buffer);
    
    let offset = 0;
    
    // SOI marker (FFD8)
    view.setUint16(offset, 0xFFD8);
    offset += 2;
    
    // APP1 marker (FFE1)
    view.setUint16(offset, 0xFFE1);
    offset += 2;
    
    // APP1 length (includes length field itself)
    view.setUint16(offset, app1DataSize + 2);
    offset += 2;
    
    // "Exif\0\0" signature
    bytes.set([0x45, 0x78, 0x69, 0x66, 0x00, 0x00], offset);
    offset += 6;
    
    const tiffStart = offset;
    
    // TIFF header
    // Byte order marker
    if (littleEndian) {
        view.setUint16(offset, 0x4949); // "II"
    } else {
        view.setUint16(offset, 0x4D4D); // "MM"
    }
    offset += 2;
    
    // TIFF magic (42)
    view.setUint16(offset, 42, littleEndian);
    offset += 2;
    
    // Offset to IFD0 (relative to TIFF start)
    view.setUint32(offset, ifd0Start, littleEndian);
    offset += 4;
    
    // IFD0
    // Number of entries
    view.setUint16(offset, 1, littleEndian);
    offset += 2;
    
    // Entry: ExifIFD pointer (tag 0x8769)
    view.setUint16(offset, 0x8769, littleEndian); // Tag
    offset += 2;
    view.setUint16(offset, 4, littleEndian); // Type: LONG
    offset += 2;
    view.setUint32(offset, 1, littleEndian); // Count
    offset += 4;
    view.setUint32(offset, exifIfdStart, littleEndian); // Value: offset to ExifIFD
    offset += 4;
    
    // Next IFD offset (0 = no more IFDs)
    view.setUint32(offset, 0, littleEndian);
    offset += 4;
    
    // ExifIFD
    // Number of entries
    view.setUint16(offset, 1, littleEndian);
    offset += 2;
    
    // Entry: DateTimeOriginal (tag 0x9003)
    view.setUint16(offset, 0x9003, littleEndian); // Tag
    offset += 2;
    view.setUint16(offset, 2, littleEndian); // Type: ASCII
    offset += 2;
    view.setUint32(offset, dateBytes.length, littleEndian); // Count (includes null)
    offset += 4;
    view.setUint32(offset, dateTimeStart, littleEndian); // Value: offset to string
    offset += 4;
    
    // Next IFD offset (0 = no more IFDs)
    view.setUint32(offset, 0, littleEndian);
    offset += 4;
    
    // DateTimeOriginal string
    bytes.set(dateBytes, offset);
    offset += dateBytes.length;
    
    // EOI marker (FFD9)
    view.setUint16(offset, 0xFFD9);
    
    return buffer;
}

/**
 * Create a JPEG without EXIF data
 */
function createTestJpegWithoutExif() {
    const buffer = new ArrayBuffer(4);
    const view = new DataView(buffer);
    
    view.setUint16(0, 0xFFD8); // SOI
    view.setUint16(2, 0xFFD9); // EOI
    
    return buffer;
}

/**
 * Create a minimal valid WebP with EXIF DateTimeOriginal
 * 
 * WebP structure: RIFF <size> WEBP EXIF <size> <tiff-data>
 * 
 * @param {string} dateTimeStr - DateTime in "YYYY:MM:DD HH:MM:SS" format
 * @param {boolean} littleEndian - Use little-endian byte order for TIFF
 * @returns {ArrayBuffer} Valid WebP with EXIF
 */
function createTestWebPWithExif(dateTimeStr, littleEndian = true) {
    // DateTime string with null terminator
    const dateBytes = new TextEncoder().encode(dateTimeStr + '\0');
    
    // TIFF structure (same as JPEG EXIF but without "Exif\0\0" prefix)
    const tiffHeaderSize = 8;
    const ifd0Start = 8;
    const ifd0Size = 2 + 12 + 4;
    const exifIfdStart = ifd0Start + ifd0Size;
    const exifIfdSize = 2 + 12 + 4;
    const dateTimeStart = exifIfdStart + exifIfdSize;
    const tiffDataSize = dateTimeStart + dateBytes.length;
    
    // Pad EXIF chunk to even size if needed
    const exifChunkSize = tiffDataSize;
    const exifChunkPadding = exifChunkSize % 2;
    
    // WebP: RIFF(4) + size(4) + WEBP(4) + EXIF(4) + size(4) + tiff-data + padding
    const webpSize = 4 + 4 + 4 + exifChunkSize + exifChunkPadding; // Everything after RIFF header
    const totalSize = 8 + webpSize; // RIFF + size + rest
    
    const buffer = new ArrayBuffer(totalSize);
    const view = new DataView(buffer);
    const bytes = new Uint8Array(buffer);
    
    let offset = 0;
    
    // RIFF header
    bytes.set([0x52, 0x49, 0x46, 0x46], offset); // "RIFF"
    offset += 4;
    view.setUint32(offset, webpSize, true); // File size minus 8 (little-endian)
    offset += 4;
    bytes.set([0x57, 0x45, 0x42, 0x50], offset); // "WEBP"
    offset += 4;
    
    // EXIF chunk
    bytes.set([0x45, 0x58, 0x49, 0x46], offset); // "EXIF"
    offset += 4;
    view.setUint32(offset, exifChunkSize, true); // Chunk size (little-endian)
    offset += 4;
    
    const tiffStart = offset;
    
    // TIFF header (same structure as JPEG EXIF)
    if (littleEndian) {
        view.setUint16(offset, 0x4949); // "II"
    } else {
        view.setUint16(offset, 0x4D4D); // "MM"
    }
    offset += 2;
    
    view.setUint16(offset, 42, littleEndian);
    offset += 2;
    
    view.setUint32(offset, ifd0Start, littleEndian);
    offset += 4;
    
    // IFD0
    view.setUint16(offset, 1, littleEndian);
    offset += 2;
    
    view.setUint16(offset, 0x8769, littleEndian); // ExifIFD pointer tag
    offset += 2;
    view.setUint16(offset, 4, littleEndian); // Type: LONG
    offset += 2;
    view.setUint32(offset, 1, littleEndian); // Count
    offset += 4;
    view.setUint32(offset, exifIfdStart, littleEndian);
    offset += 4;
    
    view.setUint32(offset, 0, littleEndian); // Next IFD
    offset += 4;
    
    // ExifIFD
    view.setUint16(offset, 1, littleEndian);
    offset += 2;
    
    view.setUint16(offset, 0x9003, littleEndian); // DateTimeOriginal tag
    offset += 2;
    view.setUint16(offset, 2, littleEndian); // Type: ASCII
    offset += 2;
    view.setUint32(offset, dateBytes.length, littleEndian);
    offset += 4;
    view.setUint32(offset, dateTimeStart, littleEndian);
    offset += 4;
    
    view.setUint32(offset, 0, littleEndian); // Next IFD
    offset += 4;
    
    // DateTimeOriginal string
    bytes.set(dateBytes, offset);
    
    return buffer;
}

/**
 * Create a WebP without EXIF data
 */
function createTestWebPWithoutExif() {
    // Minimal WebP: RIFF + size + WEBP + VP8 chunk (minimal)
    const buffer = new ArrayBuffer(20);
    const view = new DataView(buffer);
    const bytes = new Uint8Array(buffer);
    
    bytes.set([0x52, 0x49, 0x46, 0x46], 0); // "RIFF"
    view.setUint32(4, 12, true); // Size
    bytes.set([0x57, 0x45, 0x42, 0x50], 8); // "WEBP"
    bytes.set([0x56, 0x50, 0x38, 0x20], 12); // "VP8 " (lossy chunk)
    view.setUint32(16, 0, true); // Empty chunk
    
    return buffer;
}

/**
 * Create invalid data (not a JPEG)
 */
function createInvalidData() {
    const buffer = new ArrayBuffer(100);
    const bytes = new Uint8Array(buffer);
    // Fill with random-ish data
    for (let i = 0; i < 100; i++) {
        bytes[i] = i * 7 % 256;
    }
    return buffer;
}

// Test runner
const tests = [];
let passed = 0;
let failed = 0;

function test(name, fn) {
    tests.push({ name, fn });
}

function assertEqual(actual, expected, message) {
    if (actual !== expected) {
        throw new Error(`${message}: expected ${expected}, got ${actual}`);
    }
}

function assertNull(actual, message) {
    if (actual !== null) {
        throw new Error(`${message}: expected null, got ${actual}`);
    }
}

function assertTrue(actual, message) {
    if (actual !== true) {
        throw new Error(`${message}: expected true, got ${actual}`);
    }
}

function assertFalse(actual, message) {
    if (actual !== false) {
        throw new Error(`${message}: expected false, got ${actual}`);
    }
}

// Tests

test('parseDateTimeString: valid datetime', () => {
    const result = ExifTimestamp.parseDateTime('2026:01:06 15:30:45');
    // 2026-01-06 15:30:45 UTC
    const expected = Date.UTC(2026, 0, 6, 15, 30, 45) / 1000;
    assertEqual(result, expected, 'Timestamp mismatch');
});

test('parseDateTimeString: invalid format', () => {
    assertNull(ExifTimestamp.parseDateTime('2026-01-06 15:30:45'), 'Should reject wrong separator');
    assertNull(ExifTimestamp.parseDateTime('2026:01:06T15:30:45'), 'Should reject T separator');
    assertNull(ExifTimestamp.parseDateTime('invalid'), 'Should reject garbage');
    assertNull(ExifTimestamp.parseDateTime(''), 'Should reject empty string');
});

test('parseDateTimeString: invalid values', () => {
    assertNull(ExifTimestamp.parseDateTime('2026:13:06 15:30:45'), 'Should reject month 13');
    assertNull(ExifTimestamp.parseDateTime('2026:00:06 15:30:45'), 'Should reject month 0');
    assertNull(ExifTimestamp.parseDateTime('2026:01:32 15:30:45'), 'Should reject day 32');
    assertNull(ExifTimestamp.parseDateTime('2026:01:06 25:30:45'), 'Should reject hour 25');
    assertNull(ExifTimestamp.parseDateTime('2026:01:06 15:60:45'), 'Should reject minute 60');
    assertNull(ExifTimestamp.parseDateTime('2026:01:06 15:30:60'), 'Should reject second 60');
});

test('extract: valid JPEG with EXIF (little-endian)', () => {
    const dateStr = '2026:01:06 15:30:45';
    const buffer = createTestJpegWithExif(dateStr, true);
    const result = ExifTimestamp.extract(buffer);
    const expected = Date.UTC(2026, 0, 6, 15, 30, 45) / 1000;
    assertEqual(result, expected, 'Timestamp mismatch');
});

test('extract: valid JPEG with EXIF (big-endian)', () => {
    const dateStr = '2026:01:06 15:30:45';
    const buffer = createTestJpegWithExif(dateStr, false);
    const result = ExifTimestamp.extract(buffer);
    const expected = Date.UTC(2026, 0, 6, 15, 30, 45) / 1000;
    assertEqual(result, expected, 'Timestamp mismatch');
});

test('extract: JPEG without EXIF returns null', () => {
    const buffer = createTestJpegWithoutExif();
    const result = ExifTimestamp.extract(buffer);
    assertNull(result, 'Should return null for JPEG without EXIF');
});

test('extract: invalid data returns null', () => {
    const buffer = createInvalidData();
    const result = ExifTimestamp.extract(buffer);
    assertNull(result, 'Should return null for non-JPEG data');
});

test('extract: empty buffer returns null', () => {
    const buffer = new ArrayBuffer(0);
    const result = ExifTimestamp.extract(buffer);
    assertNull(result, 'Should return null for empty buffer');
});

test('extract: truncated JPEG returns null', () => {
    const buffer = new ArrayBuffer(2);
    const view = new DataView(buffer);
    view.setUint16(0, 0xFFD8); // Just SOI, no more data
    const result = ExifTimestamp.extract(buffer);
    assertNull(result, 'Should return null for truncated JPEG');
});

test('extract: valid WebP with EXIF (little-endian)', () => {
    const dateStr = '2026:01:06 15:30:45';
    const buffer = createTestWebPWithExif(dateStr, true);
    const result = ExifTimestamp.extract(buffer);
    const expected = Date.UTC(2026, 0, 6, 15, 30, 45) / 1000;
    assertEqual(result, expected, 'WebP timestamp mismatch');
});

test('extract: valid WebP with EXIF (big-endian)', () => {
    const dateStr = '2026:01:06 15:30:45';
    const buffer = createTestWebPWithExif(dateStr, false);
    const result = ExifTimestamp.extract(buffer);
    const expected = Date.UTC(2026, 0, 6, 15, 30, 45) / 1000;
    assertEqual(result, expected, 'WebP big-endian timestamp mismatch');
});

test('extract: WebP without EXIF returns null', () => {
    const buffer = createTestWebPWithoutExif();
    const result = ExifTimestamp.extract(buffer);
    assertNull(result, 'Should return null for WebP without EXIF');
});

test('verify: matching timestamp', async () => {
    const dateStr = '2026:01:06 15:30:45';
    const buffer = createTestJpegWithExif(dateStr, true);
    const blob = new Blob([buffer], { type: 'image/jpeg' });
    const expected = Date.UTC(2026, 0, 6, 15, 30, 45) / 1000;
    
    const result = await ExifTimestamp.verify(blob, expected, 5);
    assertTrue(result.verified, 'Should verify matching timestamp');
    assertEqual(result.reason, 'ok', 'Reason should be ok');
    assertEqual(result.exifTimestamp, expected, 'EXIF timestamp should match');
});

test('verify: mismatched timestamp', async () => {
    const dateStr = '2026:01:06 15:30:45';
    const buffer = createTestJpegWithExif(dateStr, true);
    const blob = new Blob([buffer], { type: 'image/jpeg' });
    const wrongTimestamp = Date.UTC(2026, 0, 6, 16, 30, 45) / 1000; // 1 hour different
    
    const result = await ExifTimestamp.verify(blob, wrongTimestamp, 5);
    assertFalse(result.verified, 'Should fail for mismatched timestamp');
    assertEqual(result.reason, 'timestamp_mismatch', 'Reason should be timestamp_mismatch');
});

test('verify: within tolerance', async () => {
    const dateStr = '2026:01:06 15:30:45';
    const buffer = createTestJpegWithExif(dateStr, true);
    const blob = new Blob([buffer], { type: 'image/jpeg' });
    const closeTimestamp = Date.UTC(2026, 0, 6, 15, 30, 48) / 1000; // 3 seconds different
    
    const result = await ExifTimestamp.verify(blob, closeTimestamp, 5);
    assertTrue(result.verified, 'Should verify within tolerance');
});

test('verify: no EXIF', async () => {
    const buffer = createTestJpegWithoutExif();
    const blob = new Blob([buffer], { type: 'image/jpeg' });
    
    const result = await ExifTimestamp.verify(blob, Date.now() / 1000, 5);
    assertFalse(result.verified, 'Should fail without EXIF');
    assertEqual(result.reason, 'no_exif', 'Reason should be no_exif');
});

// Run tests
async function runTests() {
    console.log('Running EXIF Timestamp Tests...\n');
    
    for (const t of tests) {
        try {
            await t.fn();
            passed++;
            console.log(`✓ ${t.name}`);
        } catch (e) {
            failed++;
            console.log(`✗ ${t.name}`);
            console.log(`  Error: ${e.message}`);
        }
    }
    
    console.log(`\nResults: ${passed} passed, ${failed} failed`);
    
    if (typeof process !== 'undefined') {
        process.exit(failed > 0 ? 1 : 0);
    }
}

// Auto-run if ExifTimestamp is available
if (typeof ExifTimestamp !== 'undefined') {
    runTests();
} else {
    console.log('ExifTimestamp not loaded. Load exif-timestamp.js first.');
}

