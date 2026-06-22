#!/usr/bin/env php
<?php
/**
 * CLI: Verify vendored WMM coefficients match NOAA's published coefficient zip.
 *
 * Discovers the current WMM*COF.zip link from NOAA's coefficients page, downloads it,
 * and compares WMM.COF header fields and SHA-256 against data/wmm/manifest.json.
 *
 * Exit 0 when aligned; exit 1 on drift or fetch failures. Intended for weekly CI
 * ({@see .github/workflows/weekly-wmm-coefficients.yml}) and `make verify-wmm-coefficients`.
 *
 * @package AviationWX
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/wmm/WmmCoefficients.php';
require_once __DIR__ . '/../lib/wmm/WmmNoaaSync.php';

/**
 * @param list<string> $errors
 */
function wmmVerifyFail(array $errors): never
{
    fwrite(STDERR, "WMM coefficient verify failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }
    fwrite(STDERR, "\nRun scripts/update-wmm-coefficients.php and open a reviewed PR when NOAA publishes new coefficients.\n");
    exit(1);
}

try {
    $manifest = WmmNoaaSync::loadBundledManifest();
} catch (\RuntimeException $e) {
    wmmVerifyFail([$e->getMessage()]);
}

$cofPath = WmmCoefficients::getBundledCofPath();
if (!is_readable($cofPath)) {
    wmmVerifyFail(['Bundled WMM.COF is not readable: ' . $cofPath]);
}

$localSha = hash_file('sha256', $cofPath);
if (!is_string($localSha) || strtolower((string) ($manifest['cof_sha256'] ?? '')) !== $localSha) {
    wmmVerifyFail([
        sprintf(
            'Bundled WMM.COF SHA-256 (%s) does not match manifest cof_sha256 (%s)',
            $localSha ?: 'unknown',
            (string) ($manifest['cof_sha256'] ?? 'missing')
        ),
    ]);
}

$sourcePage = isset($manifest['source_page']) && is_string($manifest['source_page'])
    ? $manifest['source_page']
    : WmmNoaaSync::SOURCE_PAGE;

try {
    $html = WmmNoaaSync::fetchSourcePageHtml($sourcePage);
} catch (\RuntimeException $e) {
    wmmVerifyFail([$e->getMessage()]);
}

$discoveredUrl = WmmNoaaSync::discoverCoefficientZipUrl($html);
if ($discoveredUrl === null) {
    wmmVerifyFail(['Could not discover WMM coefficient zip URL on NOAA page: ' . $sourcePage]);
}

$manifestZipUrl = isset($manifest['source_zip_url']) ? (string) $manifest['source_zip_url'] : '';
$zipUrl = $discoveredUrl;
$warnings = [];
if ($manifestZipUrl !== '' && $manifestZipUrl !== $discoveredUrl) {
    $warnings[] = sprintf(
        'NOAA page now links %s (manifest records %s); comparing coefficient bytes from discovered URL',
        $discoveredUrl,
        $manifestZipUrl
    );
}

$tempZip = null;
try {
    $tempZip = WmmNoaaSync::downloadZipToTempFile($zipUrl);
    $extracted = WmmNoaaSync::extractZipContents($tempZip);
} catch (\RuntimeException $e) {
    wmmVerifyFail([$e->getMessage()]);
} finally {
    if ($tempZip !== null) {
        @unlink($tempZip);
    }
}

$result = WmmNoaaSync::compareNoaaCofToManifest($manifest, $extracted['cof']);

foreach ($warnings as $warning) {
    fwrite(STDOUT, "warning: {$warning}\n");
}

if (!$result['ok']) {
    wmmVerifyFail($result['errors']);
}

fwrite(STDOUT, "WMM coefficients verified against NOAA.\n");
fwrite(STDOUT, sprintf(
    "  model=%s epoch=%s release_date=%s sha256=%s\n",
    $result['noaa']['model'],
    (string) $result['noaa']['epoch'],
    $result['noaa']['release_date'],
    $result['noaa']['cof_sha256']
));
fwrite(STDOUT, sprintf("  source_zip_url=%s\n", $zipUrl));

exit(0);
