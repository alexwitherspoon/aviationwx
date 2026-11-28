#!/usr/bin/env php
<?php
/**
 * Build cache/runways/runway_fusion.json: the fused runway inventory for
 * configured airports (union of manual config, NASR, and OurAirports rows,
 * plus derived through-center segments for pairs without merge-cache geometry).
 *
 * Run by the scheduler (first eligible loop iteration after startup, then at
 * most hourly evaluation). Rebuilds when the artifact is missing, invalid,
 * older than RUNWAY_FUSION_MAX_AGE, or any input (NASR cache, runways merge
 * cache, airports.json) is newer than the recorded input stamps.
 *
 * Fails open per input: a missing NASR cache builds without official rows and
 * logs a warning. Fails closed on publish: zero airport rows means no write.
 * A change the publish gate detects (cache mtime or config digest, checked
 * right before the write) leaves the existing artifact in place for the next
 * loop. A same-mtime content change is invisible to the mtime stamps; it
 * surfaces on the next rebuild, which the config sha comparison triggers.
 * The write is atomic (temp file plus rename) but does not coordinate with
 * concurrent config or cache updates.
 *
 * Usage:
 *   php scripts/build-runway-fusion.php
 *
 * Exit codes: 0 success (including not-due skip), 1 failure
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

chdir(__DIR__ . '/..');

define('AVIATIONWX_SKIP_COUNTRY_RESOLUTION_MERGE', true);

// Test seam: disposable cache root (tests/bootstrap.php convention).
$testCacheDir = getenv('TEST_CACHE_DIR');
if (is_string($testCacheDir) && $testCacheDir !== '') {
    define('CACHE_BASE_DIR', rtrim($testCacheDir, '/'));
}

require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/worker-timeout.php';
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/runway-fusion.php';

initWorkerTimeout(RUNWAY_FUSION_WORKER_TIMEOUT, 'runway_fusion');

$config = loadConfig();
if ($config === null || !isset($config['airports']) || !is_array($config['airports'])) {
    fwrite(STDERR, "build-runway-fusion: loadConfig() failed or no airports\n");
    exit(1);
}

$configPath = $GLOBALS['AVIATIONWX_CONFIG_FILE_PATH'] ?? null;
if (!is_string($configPath) || $configPath === '' || !is_readable($configPath)) {
    fwrite(STDERR, "build-runway-fusion: resolved config path unavailable\n");
    exit(1);
}

// loadConfig() recorded the digest of the bytes it parsed. If the file no
// longer holds those bytes, the rows below would carry a stamp from a
// different config generation. Keep the existing artifact and retry.
$stamps = runwayFusionInputStamps($configPath);
$parsedSha = $GLOBALS['AVIATIONWX_CONFIG_PARSED_SHA'] ?? null;
if (!runwayFusionConfigStampMatchesParsed(is_string($parsedSha) ? $parsedSha : null, $stamps)) {
    aviationwx_log('warning', 'runway_fusion: config changed during load; keeping existing artifact', [
        'parsed_sha' => is_string($parsedSha) ? $parsedSha : null,
        'stamp_sha' => $stamps['config_sha'] ?? null,
    ], 'app');
    exit(0);
}

$artifact = runwayFusionLoadArtifact();
if (!runwayFusionShouldRebuild($artifact, $stamps)) {
    aviationwx_log('info', 'runway_fusion: artifact current, skipping rebuild', [
        'path' => CACHE_RUNWAY_FUSION_FILE,
    ], 'app');
    exit(0);
}

$nasrPayload = loadNasrAptCache();
if ($nasrPayload === null) {
    aviationwx_log('warning', 'runway_fusion: NASR APT cache missing; building without official rows', [], 'app');
} elseif (!isset($nasrPayload['effective_date'])) {
    // Configured slices carry no cycle date; stamp it from NASR meta so the
    // fused rows keep their official effective date.
    $nasrMeta = loadNasrAptMeta();
    $nasrPayload['effective_date'] = is_array($nasrMeta) && is_string($nasrMeta['effective_date'] ?? null)
        ? $nasrMeta['effective_date']
        : null;
}

$runwaysData = loadRunwaysCacheDataFromDisk();
if ($runwaysData === null) {
    aviationwx_log('info', 'runway_fusion: runways merge cache missing; building without OurAirports rows or geometry', [], 'app');
}

// The rows above came from one pass over the config and cache files; the
// stamps came from the file read in runwayFusionInputStamps(). A change
// between those reads would pair rows from one generation with the stamp
// of another, and the freshness gate would serve it as current. Keep the
// existing artifact and let the next loop retry.
$driftKey = runwayFusionInputDriftKey($stamps, runwayFusionInputStamps($configPath));
if ($driftKey !== null) {
    aviationwx_log('warning', 'runway_fusion: input changed during build; keeping existing artifact', [
        'changed' => $driftKey,
    ], 'app');
    exit(0);
}

$airportsOut = [];
foreach ($config['airports'] as $aid => $ap) {
    if (!is_string($aid) || $aid === '' || !is_array($ap)) {
        continue;
    }
    if (!isAirportEnabled($ap)) {
        continue;
    }
    $entry = runwayFusionBuildAirportEntry($aid, $ap, $nasrPayload, $runwaysData);
    if ($entry !== null) {
        $airportsOut[strtolower($aid)] = $entry;
    }
}

if ($airportsOut === []) {
    aviationwx_log('error', 'runway_fusion: no configured airport produced rows; keeping existing artifact', [
        'config_airport_count' => count($config['airports']),
    ], 'app');
    exit(1);
}

// Final gate plus publish. The airport loop above ran against in-memory
// payloads; runwayFusionWorkerPublishIfStable re-reads the input stamps
// right before writing and keeps the existing artifact if any moved, or if
// the config no longer holds the bytes loadConfig() parsed.
$publishStatus = runwayFusionWorkerPublishIfStable(
    $airportsOut,
    $stamps,
    $configPath,
    is_string($parsedSha) ? $parsedSha : null,
    is_array($nasrPayload) ? ($nasrPayload['effective_date'] ?? null) : null
);
if ($publishStatus === 2) {
    fwrite(STDERR, "build-runway-fusion: failed to write artifact\n");
    exit(1);
}
if ($publishStatus === 1) {
    // Kept; the seam logged the reason.
    exit(0);
}

aviationwx_log('info', 'runway_fusion: wrote artifact', [
    'path' => CACHE_RUNWAY_FUSION_FILE,
    'airport_count' => count($airportsOut),
    'nasr_effective_date' => is_array($nasrPayload) ? ($nasrPayload['effective_date'] ?? null) : null,
], 'app');

exit(0);
