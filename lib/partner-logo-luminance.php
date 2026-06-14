<?php
/**
 * Partner logo luminance analysis for contrast-aware tile styling.
 *
 * Light marks on transparent PNGs disappear on the default light partner
 * card; sampling opaque pixels once (cached beside the image) lets the
 * dashboard pick a readable tile background per theme.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/partner-logo-cache.php';

/**
 * Resolve a partner logo config path or URL to a readable image file.
 *
 * Does not download remote logos; returns null when the file is absent.
 *
 * @param string $logoUrl Local path (e.g. /partner-logos/foo.png) or remote URL
 * @return string|null Absolute filesystem path, or null
 */
function resolvePartnerLogoImagePath(string $logoUrl): ?string
{
    $logoUrl = trim($logoUrl);
    if ($logoUrl === '') {
        return null;
    }

    if (strpos($logoUrl, '/') === 0) {
        $ext = strtolower(pathinfo($logoUrl, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return null;
        }
        if (strpos($logoUrl, '..') !== false || strpos($logoUrl, "\0") !== false) {
            return null;
        }

        $realBase = realpath(__DIR__ . '/..');
        if ($realBase === false) {
            return null;
        }

        $fullPath = __DIR__ . '/..' . $logoUrl;
        if (!file_exists($fullPath)) {
            return null;
        }

        $requestedPath = realpath($fullPath);
        if ($requestedPath === false || strpos($requestedPath, $realBase) !== 0) {
            return null;
        }

        return $requestedPath;
    }

    $cacheFile = getPartnerLogoCacheFile($logoUrl);
    if (!file_exists($cacheFile)) {
        return null;
    }

    return $cacheFile;
}

/**
 * Sidecar path for cached luminance metadata.
 *
 * @param string $imagePath Absolute path to the logo image
 * @return string Path to the .lum.json sidecar
 */
function getPartnerLogoLuminanceMetaPath(string $imagePath): string
{
    return $imagePath . '.lum.json';
}

/**
 * Read cached luminance metadata when still valid for the image mtime.
 *
 * @param string $imagePath Absolute path to the logo image
 * @return array{mean_luminance: float, source_mtime: int}|null
 */
function readPartnerLogoLuminanceMeta(string $imagePath): ?array
{
    $metaPath = getPartnerLogoLuminanceMetaPath($imagePath);
    if (!is_readable($metaPath)) {
        return null;
    }

    $raw = file_get_contents($metaPath);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['mean_luminance'], $decoded['source_mtime'])) {
        return null;
    }

    $mtime = filemtime($imagePath);
    if ($mtime === false || (int) $decoded['source_mtime'] !== $mtime) {
        return null;
    }

    return $decoded;
}

/**
 * Persist luminance metadata beside the image file.
 *
 * @param string $imagePath Absolute path to the logo image
 * @param float $meanLuminance Relative luminance 0..1 of opaque pixels
 * @return void
 */
function writePartnerLogoLuminanceMeta(string $imagePath, float $meanLuminance): void
{
    $mtime = filemtime($imagePath);
    if ($mtime === false) {
        return;
    }

    $payload = json_encode([
        'mean_luminance' => round($meanLuminance, 4),
        'source_mtime' => $mtime,
    ], JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        return;
    }

    @file_put_contents(getPartnerLogoLuminanceMetaPath($imagePath), $payload, LOCK_EX);
}

/**
 * Compute mean relative luminance (0..1) of opaque pixels in an image file.
 *
 * Samples on a coarse grid for speed; sufficient for logo contrast hints.
 *
 * @param string $imagePath Absolute path to a PNG, JPEG, GIF, or WebP file
 * @return float|null Mean luminance, or null when analysis fails
 */
function analyzePartnerLogoMeanLuminance(string $imagePath): ?float
{
    if (!is_readable($imagePath) || !function_exists('imagecreatefrompng')) {
        return null;
    }

    $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
    $img = false;
    if ($ext === 'png') {
        $img = @imagecreatefrompng($imagePath);
    } elseif (in_array($ext, ['jpg', 'jpeg'], true)) {
        $img = @imagecreatefromjpeg($imagePath);
    } elseif ($ext === 'gif') {
        $img = @imagecreatefromgif($imagePath);
    } elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
        $img = @imagecreatefromwebp($imagePath);
    }

    if ($img === false) {
        return null;
    }

    imagesavealpha($img, true);
    $width = imagesx($img);
    $height = imagesy($img);
    if ($width < 1 || $height < 1) {
        return null;
    }

    $step = max(1, (int) floor(max($width, $height) / 64));
    $sum = 0.0;
    $count = 0;
    $alphaCutoff = 100;

    for ($y = 0; $y < $height; $y += $step) {
        for ($x = 0; $x < $width; $x += $step) {
            $rgba = imagecolorat($img, $x, $y);
            $alpha = ($rgba & 0x7F000000) >> 24;
            if ($alpha > $alphaCutoff) {
                continue;
            }
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            $sum += (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
            $count++;
        }
    }

    if ($count === 0) {
        return null;
    }

    return $sum / $count;
}

/**
 * Mean luminance for a partner logo path or URL, cached beside the image file.
 *
 * @param string $logoUrl Local path or remote URL from airports.json
 * @return float|null Relative luminance 0..1, or null when unavailable
 */
function getPartnerLogoMeanLuminance(string $logoUrl): ?float
{
    $imagePath = resolvePartnerLogoImagePath($logoUrl);
    if ($imagePath === null) {
        return null;
    }

    $cached = readPartnerLogoLuminanceMeta($imagePath);
    if ($cached !== null) {
        return (float) $cached['mean_luminance'];
    }

    $mean = analyzePartnerLogoMeanLuminance($imagePath);
    if ($mean === null) {
        return null;
    }

    writePartnerLogoLuminanceMeta($imagePath, $mean);

    return $mean;
}
