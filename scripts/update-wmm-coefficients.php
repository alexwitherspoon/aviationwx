#!/usr/bin/env php
<?php
/**
 * CLI: Maintainer tool to refresh vendored WMM coefficients from NOAA.
 *
 * Downloads the current WMM*COF.zip, updates data/wmm/WMM.COF and manifest.json,
 * and refreshes golden fixture expected values in tests/Fixtures/wmm-noaa-reference.json.
 *
 * Does not commit or deploy. Run `make test-ci` after updating, then open a PR.
 *
 * Usage:
 *   php scripts/update-wmm-coefficients.php [--dry-run]
 *
 * @package AviationWX
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/wmm/WmmCoefficients.php';
require_once __DIR__ . '/../lib/wmm/WmmNoaaSync.php';

$dryRun = in_array('--dry-run', $argv, true);

$repoRoot = dirname(__DIR__);
$cofPath = WmmCoefficients::getBundledCofPath();
$manifestPath = WmmCoefficients::getBundledManifestPath();
$fixturePath = $repoRoot . '/tests/Fixtures/wmm-noaa-reference.json';

/**
 * @param list<string> $errors
 */
function wmmUpdateFail(array $errors): never
{
    fwrite(STDERR, "WMM coefficient update failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }
    exit(1);
}

try {
    $html = WmmNoaaSync::fetchSourcePageHtml();
} catch (\RuntimeException $e) {
    wmmUpdateFail([$e->getMessage()]);
}

$zipUrl = WmmNoaaSync::discoverCoefficientZipUrl($html);
if ($zipUrl === null) {
    wmmUpdateFail(['Could not discover WMM coefficient zip URL on NOAA coefficients page']);
}

$tempZip = null;
try {
    $tempZip = WmmNoaaSync::downloadZipToTempFile($zipUrl);
    $extracted = WmmNoaaSync::extractZipContents($tempZip);
} catch (\RuntimeException $e) {
    wmmUpdateFail([$e->getMessage()]);
} finally {
    if ($tempZip !== null) {
        @unlink($tempZip);
    }
}

try {
    $header = WmmNoaaSync::parseCofHeaderFromContent($extracted['cof']);
} catch (\InvalidArgumentException $e) {
    wmmUpdateFail(['NOAA WMM.COF header parse failed: ' . $e->getMessage()]);
}

$cofSha256 = hash('sha256', $extracted['cof']);
$manifest = WmmNoaaSync::buildManifest($header, $cofSha256, $zipUrl);
$manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$fixtureJsonRaw = file_get_contents($fixturePath);
if ($fixtureJsonRaw === false) {
    wmmUpdateFail(['Failed to read golden fixture file: ' . $fixturePath]);
}

$fixtureJson = json_decode($fixtureJsonRaw, true);
if (!is_array($fixtureJson) || json_last_error() !== JSON_ERROR_NONE) {
    wmmUpdateFail(['Invalid golden fixture JSON: ' . json_last_error_msg()]);
}

if ($extracted['test_values'] === null) {
    wmmUpdateFail(['NOAA coefficient zip did not include a TestValues.txt file']);
}

try {
    $fixtureUpdates = WmmNoaaSync::refreshGoldenFixtures($extracted['test_values'], $fixtureJson);
} catch (\InvalidArgumentException $e) {
    wmmUpdateFail(['Golden fixture refresh failed: ' . $e->getMessage()]);
}

if ($fixtureUpdates['missing'] !== []) {
    wmmUpdateFail([
        'Golden fixtures missing from NOAA test vectors: ' . implode(', ', $fixtureUpdates['missing']),
    ]);
}

$meta = $fixtureJson['_meta'] ?? [];
if (!is_array($meta)) {
    $meta = [];
}
$meta['coefficient_model'] = $header['model'];
$meta['coefficient_epoch'] = $header['epoch'];
$meta['source_file'] = basename((string) ($extracted['test_values_entry'] ?? 'WMM_TestValues.txt'))
    . ' (NOAA ' . basename($zipUrl) . ')';

$updatedFixture = [
    '_meta' => $meta,
    'fixtures' => $fixtureUpdates['fixtures'],
];
$fixtureOut = json_encode($updatedFixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

fwrite(STDOUT, "NOAA WMM coefficient update summary\n");
fwrite(STDOUT, sprintf("  zip_url=%s\n", $zipUrl));
fwrite(STDOUT, sprintf(
    "  model=%s epoch=%s release_date=%s valid_through=%s\n",
    $header['model'],
    (string) $header['epoch'],
    $header['release_date'],
    (string) $manifest['valid_through_epoch']
));
fwrite(STDOUT, sprintf("  cof_sha256=%s\n", $cofSha256));
$fixtureCount = count($fixtureUpdates['fixtures']);
fwrite(STDOUT, sprintf("  fixtures_refreshed=%d\n", $fixtureCount));

if ($dryRun) {
    fwrite(STDOUT, "\nDry run: no files written.\n");
    exit(0);
}

if (file_put_contents($cofPath, $extracted['cof']) === false) {
    wmmUpdateFail(['Failed to write WMM.COF: ' . $cofPath]);
}

if (file_put_contents($manifestPath, $manifestJson) === false) {
    wmmUpdateFail(['Failed to write manifest.json: ' . $manifestPath]);
}

if (file_put_contents($fixturePath, $fixtureOut) === false) {
    wmmUpdateFail(['Failed to write golden fixtures: ' . $fixturePath]);
}

fwrite(STDOUT, "\nUpdated:\n");
fwrite(STDOUT, "  - data/wmm/WMM.COF\n");
fwrite(STDOUT, "  - data/wmm/manifest.json\n");
fwrite(STDOUT, "  - tests/Fixtures/wmm-noaa-reference.json\n");
fwrite(STDOUT, "\nNext: run make test-ci, then commit and open a reviewed PR.\n");

exit(0);
