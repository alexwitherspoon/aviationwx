#!/usr/bin/env php
<?php
/**
 * Benchmark: external subprocess spawns per webcam frame.
 *
 * Runs one real frame through the live variant path and counts which external
 * tools it invoked. Run with a bin directory of counting wrappers prepended to
 * PATH (the Usage block below shows the wrappers); each wrapper appends its
 * name to BENCH_SPAWN_LOG then execs the real binary, so variants still
 * generate.
 *
 * Usage (inside the test container):
 *   mkdir -p /tmp/benc-bin
 *   printf '#!/bin/sh\necho ffmpeg >> "$BENCH_SPAWN_LOG"\nexec /usr/bin/ffmpeg "$@"\n' > /tmp/benc-bin/ffmpeg
 *   printf '#!/bin/sh\necho exiftool >> "$BENCH_SPAWN_LOG"\nexec /usr/bin/exiftool "$@"\n' > /tmp/benc-bin/exiftool
 *   chmod +x /tmp/benc-bin/*
 *   PATH=/tmp/benc-bin:$PATH BENCH_SPAWN_LOG=/tmp/bench-spawns.log php dev/spawn-count-benchmark.php
 */

require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/webcam-format-generation.php';
require_once __DIR__ . '/../lib/webcam-metadata.php';
require_once __DIR__ . '/../lib/exif-utils.php';

$log = getenv('BENCH_SPAWN_LOG') ?: '/tmp/bench-spawns.log';
@unlink($log);

$tmp = sys_get_temp_dir() . '/spawn-bench-' . getmypid();
@mkdir($tmp, 0777, true);

// 640x480 source, tall enough to produce a 360 jpg variant.
$source = $tmp . '/source.jpg';
$img = imagecreatetruecolor(640, 480);
imagefill($img, 0, 0, imagecolorallocate($img, 90, 130, 70));
imagejpeg($img, $source, 90);
imagedestroy($img);

$captureTime = time() - 120;
addExifTimestamp($source, $captureTime, 'UTC', false, [
    'airport_id' => 'bench',
    'cam_index' => 0,
    'source_type' => 'bench',
]);

$airport = 'bench_' . getmypid();
$result = generateVariantsFromOriginal(
    $source,
    $airport,
    0,
    $captureTime,
    ['width' => 640, 'height' => 480]
);

$counts = [];
foreach (is_file($log) ? explode("\n", trim((string) file_get_contents($log))) : [] as $line) {
    $name = strtok($line, ' ');
    $counts[$name] = ($counts[$name] ?? 0) + 1;
}

echo 'Variants produced: ' . count($result['variants']) . "\n";
echo "External spawns during the frame:\n";
foreach (['ffmpeg', 'exiftool', 'touch', 'ffprobe', 'nice'] as $tool) {
    $count = $counts[$tool] ?? 0;
    $note = '';
    if ($tool === 'exiftool') {
        $note = $count === 0 ? ' (target 0, met)' : ' (target 0, NOT met)';
    }
    echo "  {$tool}: {$count}{$note}\n";
}
echo "Per-frame subprocess budget now: " . ($counts['ffmpeg'] ?? 0) . " ffmpeg, 0 exiftool/touch/ffprobe.\n";

// Cleanup the generated cache entries.
@unlink($source);
$cameraDir = getWebcamCameraDir($airport, 0);
foreach (glob($cameraDir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($cameraDir);
$framesDir = getWebcamFramesDir($airport, 0, $captureTime);
foreach (glob($framesDir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($framesDir);
@rmdir($tmp);
