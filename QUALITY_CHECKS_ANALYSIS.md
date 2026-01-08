# Quality Check Comparison: Push vs Pull Webcams

## Current State Analysis

### Pull Camera Quality Checks (fetch-webcam.php)
1. ✅ HTTP response validation (200 status)
2. ✅ Minimum size check (>100 bytes, >1KB for JPEG)
3. ✅ Maximum size check (CACHE_FILE_MAX_SIZE)
4. ✅ JPEG structure validation (SOI/EOI markers)
5. ✅ GD image parsing validation (imagecreatefromstring)
6. ✅ Error frame detection (detectErrorFrame)
7. ✅ EXIF timestamp validation (validateExifTimestamp)
8. ✅ EXIF timestamp ensures existence (ensureExifTimestamp)
9. ✅ Variant generation (generateVariantsFromOriginal)

### Push Camera Quality Checks (process-push-webcams.php)
1. ✅ File exists and readable
2. ✅ Minimum size check (>100 bytes)
3. ✅ Maximum size check (per-camera configurable)
4. ✅ File extension validation (if configured)
5. ✅ MIME type validation
6. ✅ Format detection (detectImageFormat)
7. ✅ **NEW** Completeness check (isJpegComplete/isPngComplete/isWebpComplete)
8. ✅ Error frame detection (detectErrorFrame) - JPEG only
9. ✅ EXIF timestamp validation (validateExifTimestamp)
10. ✅ EXIF timestamp ensures existence (ensureExifTimestamp)
11. ✅ **NEW** EXIF timezone normalization (normalizeExifToUtc)
12. ✅ Variant generation (generateVariantsFromOriginal)

## Gaps Identified

### Missing from Push Cameras:
- ❌ GD library validation (imagecreatefromstring test) - Not explicitly tested before processing
- ❌ Error frame detection for PNG/WebP - Only runs on JPEG

### Missing from Pull Cameras:
- ❌ Completeness check (truncated upload detection)
- ❌ EXIF timezone normalization (assumes server generates UTC)

## Recommendations

### 1. Add GD Validation to Push Cameras
Before accepting an image, verify GD can parse it:
```php
$testImg = @imagecreatefromstring(file_get_contents($file));
if ($testImg === false) {
    // Reject: corrupt or unparseable image
}
imagedestroy($testImg);
```

### 2. Extend Error Frame Detection to All Formats
Currently `detectErrorFrame()` only supports JPEG. For push cameras that upload PNG/WebP:
- Convert to image resource first
- Run same pixel analysis checks
- OR require JPEG uploads only

### 3. Add Completeness Check to Pull Cameras
Pull cameras can also have truncated downloads (timeout, network issue):
- Check JPEG EOI marker after download
- Validate before processing

## Implementation Priority

1. **HIGH**: Add GD validation to push cameras (catches corruption early)
2. **HIGH**: Add completeness check to pull cameras (prevents green bars)
3. **MEDIUM**: Extend error frame detection to PNG/WebP
4. **LOW**: Consider requiring JPEG-only for push cameras (simplifies pipeline)

## Notes

- Push cameras now have MORE checks than pull cameras (incomplete upload detection, timezone normalization)
- Both should converge to identical quality gates after format normalization
- The key difference should only be: WHERE the image comes from, not HOW it's validated
