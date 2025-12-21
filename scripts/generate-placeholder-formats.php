#!/usr/bin/env php
<?php
/**
 * Generate optimized placeholder image formats
 * 
 * Converts placeholder.jpg (source, may be PNG format) to optimized JPEG, WebP, and AVIF formats.
 * These optimized formats are served based on browser Accept header support.
 * The generated JPEG replaces the original placeholder.jpg file.
 * 
 * Usage:
 *   php scripts/generate-placeholder-formats.php
 */

$placeholderSource = __DIR__ . '/../public/images/placeholder.jpg';
$outputDir = __DIR__ . '/../public/images';

if (!file_exists($placeholderSource)) {
    echo "Error: Placeholder source file not found: $placeholderSource\n";
    exit(1);
}

// Check if ffmpeg is available
$ffmpegCheck = shell_exec('which ffmpeg 2>/dev/null');
if (empty($ffmpegCheck)) {
    echo "Error: ffmpeg is required but not found in PATH\n";
    echo "Please install ffmpeg: brew install ffmpeg (macOS) or apt-get install ffmpeg (Linux)\n";
    exit(1);
}

echo "Generating optimized placeholder formats from: $placeholderSource\n\n";

// Generate JPEG (always needed as fallback)
$jpegOutput = $outputDir . '/placeholder.jpg';
$cmdJpeg = sprintf(
    "ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -q:v 30 %s",
    escapeshellarg($placeholderSource),
    escapeshellarg($jpegOutput)
);
echo "Generating JPEG...\n";
exec($cmdJpeg, $output, $returnCode);
if ($returnCode !== 0) {
    echo "Error: Failed to generate JPEG placeholder\n";
    exit(1);
}
$jpegSize = filesize($jpegOutput);
echo "  ✓ JPEG created: " . number_format($jpegSize) . " bytes\n\n";

// Generate WebP
$webpOutput = $outputDir . '/placeholder.webp';
$cmdWebp = sprintf(
    "ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -q:v 30 -compression_level 6 -preset default %s",
    escapeshellarg($placeholderSource),
    escapeshellarg($webpOutput)
);
echo "Generating WebP...\n";
exec($cmdWebp, $output, $returnCode);
if ($returnCode !== 0) {
    echo "Warning: Failed to generate WebP placeholder (continuing anyway)\n";
} else {
    $webpSize = filesize($webpOutput);
    echo "  ✓ WebP created: " . number_format($webpSize) . " bytes\n";
    $savings = round((1 - $webpSize / $jpegSize) * 100, 1);
    echo "    (${savings}% smaller than JPEG)\n\n";
}

// Generate AVIF
$avifOutput = $outputDir . '/placeholder.avif';
$cmdAvif = sprintf(
    "ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -c:v libaom-av1 -crf 30 -b:v 0 -cpu-used 4 %s",
    escapeshellarg($placeholderSource),
    escapeshellarg($avifOutput)
);
echo "Generating AVIF...\n";
exec($cmdAvif, $output, $returnCode);
if ($returnCode !== 0) {
    echo "Warning: Failed to generate AVIF placeholder (continuing anyway)\n";
} else {
    $avifSize = filesize($avifOutput);
    echo "  ✓ AVIF created: " . number_format($avifSize) . " bytes\n";
    $savings = round((1 - $avifSize / $jpegSize) * 100, 1);
    echo "    (${savings}% smaller than JPEG)\n\n";
}

echo "✓ Placeholder format generation complete!\n";
echo "\nGenerated files:\n";
echo "  - placeholder.jpg (fallback, always served if others unavailable)\n";
if (file_exists($webpOutput)) {
    echo "  - placeholder.webp (served to browsers that support WebP)\n";
}
if (file_exists($avifOutput)) {
    echo "  - placeholder.avif (served to browsers that support AVIF)\n";
}
echo "\nNote: The original placeholder.jpg (PNG) will be replaced with the optimized JPEG version.\n";
echo "You can delete the original PNG file if desired.\n";

