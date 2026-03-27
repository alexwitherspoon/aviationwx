<?php

declare(strict_types=1);

/**
 * PWA "Add to Home Screen" help assets on the airport map page.
 *
 * Resolves optional AVIF / WebP / JPEG files under public/images/ for picture elements.
 */

/**
 * Resolve optional AVIF, WebP, and JPEG paths for PWA help screenshots.
 *
 * Files use the same basename (e.g. pwa-add-to-home-screen-android.avif).
 * Omitted formats are skipped; at least one file must exist or null is returned.
 *
 * @param string $imageDir Absolute path to the public images directory
 * @param string $baseName Filename without extension
 * @return array{avif: ?string, webp: ?string, jpg: ?string}|null
 */
function getPwaHelpScreenshotSet(string $imageDir, string $baseName): ?array
{
    $avifPath = $imageDir . '/' . $baseName . '.avif';
    $webpPath = $imageDir . '/' . $baseName . '.webp';
    $jpgPath = $imageDir . '/' . $baseName . '.jpg';
    $jpegPath = $imageDir . '/' . $baseName . '.jpeg';

    $set = [
        'avif' => is_file($avifPath) ? '/public/images/' . $baseName . '.avif' : null,
        'webp' => is_file($webpPath) ? '/public/images/' . $baseName . '.webp' : null,
        'jpg' => null,
    ];

    if (is_file($jpgPath)) {
        $set['jpg'] = '/public/images/' . $baseName . '.jpg';
    } elseif (is_file($jpegPath)) {
        $set['jpg'] = '/public/images/' . $baseName . '.jpeg';
    }

    if ($set['avif'] === null && $set['webp'] === null && $set['jpg'] === null) {
        return null;
    }

    return $set;
}

/**
 * Pick the img fallback URL for a PWA screenshot set (prefer JPEG for broad support).
 *
 * @param array{avif: ?string, webp: ?string, jpg: ?string} $set
 * @return string Root-relative URL for the img fallback (JPEG preferred, then WebP, then AVIF)
 */
function getPwaHelpScreenshotImgFallback(array $set): string
{
    if ($set['jpg'] !== null) {
        return $set['jpg'];
    }
    if ($set['webp'] !== null) {
        return $set['webp'];
    }

    return $set['avif'] ?? '';
}
