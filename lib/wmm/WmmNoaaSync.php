<?php

declare(strict_types=1);

/**
 * NOAA WMM coefficient discovery, download, and vendored manifest alignment.
 *
 * Used by maintainer scripts and weekly CI verify (no runtime production fetch).
 *
 * @see https://www.ncei.noaa.gov/products/world-magnetic-model/wmm-coefficients
 */
final class WmmNoaaSync
{
    public const SOURCE_PAGE = 'https://www.ncei.noaa.gov/products/world-magnetic-model/wmm-coefficients';

    /** WMM models are published with a five-year validity window (decimal years). */
    public const VALIDITY_SPAN_DECIMAL_YEARS = 5.0;

    private const USER_AGENT = 'AviationWX-wmm-sync/1.0 (+https://aviationwx.org)';

    /**
     * Discover the coefficient zip URL from NOAA WMM coefficients page HTML.
     *
     * When multiple COF archives are linked, selects the highest model year
     * (for example WMM2025COF.zip over WMM2020COF.zip).
     *
     * @param string $html Page HTML from {@see SOURCE_PAGE}
     * @return string|null Absolute HTTPS zip URL, or null when none found
     */
    public static function discoverCoefficientZipUrl(string $html): ?string
    {
        if (!preg_match_all(
            '#https://(?:www\.)?ncei\.noaa\.gov/[^"\s<>]+WMM(\d+)COF\.zip#i',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            return null;
        }

        usort($matches, static function (array $a, array $b): int {
            return (int) $b[1] <=> (int) $a[1];
        });

        return $matches[0][0];
    }

    /**
     * Fetch NOAA WMM coefficients page HTML.
     *
     * @param string|null $sourcePage Override page URL (defaults to {@see SOURCE_PAGE})
     * @return string Raw HTML body
     * @throws \RuntimeException When the page cannot be fetched
     */
    public static function fetchSourcePageHtml(?string $sourcePage = null): string
    {
        $url = $sourcePage ?? self::SOURCE_PAGE;
        $html = self::httpGet($url);
        if ($html === null) {
            throw new \RuntimeException('Failed to fetch NOAA WMM coefficients page: ' . $url);
        }

        return $html;
    }

    /**
     * Download a coefficient zip archive to a temporary file.
     *
     * @param string $zipUrl HTTPS URL to WMM*COF.zip
     * @return string Absolute path to the downloaded temp file (caller should unlink)
     * @throws \RuntimeException When download fails
     */
    public static function downloadZipToTempFile(string $zipUrl): string
    {
        $body = self::httpGet($zipUrl);
        if ($body === null || $body === '') {
            throw new \RuntimeException('Failed to download WMM coefficient zip: ' . $zipUrl);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'wmm-cof-');
        if ($tempPath === false) {
            throw new \RuntimeException('Failed to allocate temp file for WMM zip download');
        }

        if (file_put_contents($tempPath, $body) === false) {
            @unlink($tempPath);
            throw new \RuntimeException('Failed to write WMM zip to temp file: ' . $tempPath);
        }

        return $tempPath;
    }

    /**
     * Extract WMM.COF and optional NOAA test vectors from a coefficient zip.
     *
     * @param string $zipPath Absolute path to WMM*COF.zip
     * @return array{
     *     cof: string,
     *     test_values: string|null,
     *     cof_entry: string,
     *     test_values_entry: string|null
     * }
     * @throws \RuntimeException When the archive is invalid or WMM.COF is missing
     */
    public static function extractZipContents(string $zipPath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP zip extension (ZipArchive) is required');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Failed to open WMM coefficient zip: ' . $zipPath);
        }

        $cofEntry = null;
        $testValuesEntry = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || str_ends_with($name, '/')) {
                continue;
            }
            $basename = basename($name);
            if ($basename === 'WMM.COF') {
                $cofEntry = $name;
            } elseif (preg_match('/TestValues\.txt$/i', $basename)) {
                $testValuesEntry = $name;
            }
        }

        if ($cofEntry === null) {
            $zip->close();
            throw new \RuntimeException('WMM.COF not found inside coefficient zip: ' . $zipPath);
        }

        $cofContent = $zip->getFromName($cofEntry);
        if ($cofContent === false) {
            $zip->close();
            throw new \RuntimeException('Failed to read WMM.COF from zip entry: ' . $cofEntry);
        }

        $testValuesContent = null;
        if ($testValuesEntry !== null) {
            $raw = $zip->getFromName($testValuesEntry);
            if ($raw !== false) {
                $testValuesContent = $raw;
            }
        }

        $zip->close();

        return [
            'cof' => $cofContent,
            'test_values' => $testValuesContent,
            'cof_entry' => $cofEntry,
            'test_values_entry' => $testValuesEntry,
        ];
    }

    /**
     * Parse epoch, model name, and release date from WMM.COF header line.
     *
     * @param string $cofContent Raw WMM.COF file contents
     * @return array{epoch: float, model: string, release_date: string}
     * @throws \InvalidArgumentException When header is missing or malformed
     */
    public static function parseCofHeaderFromContent(string $cofContent): array
    {
        foreach (preg_split('/\R/', $cofContent) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '9999')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if ($parts === false || $parts === []) {
                continue;
            }

            if (!is_numeric($parts[0]) || !isset($parts[1]) || !preg_match('/^WMM-/', $parts[1])) {
                throw new \InvalidArgumentException('WMM coefficient file has invalid or missing header');
            }

            return [
                'epoch' => (float) $parts[0],
                'model' => $parts[1],
                'release_date' => $parts[2] ?? '',
            ];
        }

        throw new \InvalidArgumentException('WMM coefficient file missing header line');
    }

    /**
     * Build manifest metadata for a NOAA coefficient release.
     *
     * @param array{epoch: float, model: string, release_date: string} $header Parsed COF header
     * @param string $cofSha256 Lowercase hex SHA-256 of WMM.COF bytes
     * @param string $zipUrl NOAA coefficient zip URL used for this release
     * @return array<string, mixed> Manifest fields for data/wmm/manifest.json
     */
    public static function buildManifest(array $header, string $cofSha256, string $zipUrl): array
    {
        return [
            'model' => $header['model'],
            'epoch' => $header['epoch'],
            'release_date' => $header['release_date'],
            'valid_through_epoch' => $header['epoch'] + self::VALIDITY_SPAN_DECIMAL_YEARS,
            'source_page' => self::SOURCE_PAGE,
            'source_zip_url' => $zipUrl,
            'cof_file' => 'WMM.COF',
            'cof_sha256' => strtolower($cofSha256),
        ];
    }

    /**
     * Compare NOAA WMM.COF bytes against vendored manifest expectations.
     *
     * @param array<string, mixed> $manifest Parsed manifest.json
     * @param string $noaaCofContent Raw WMM.COF from NOAA zip
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     noaa: array{epoch: float, model: string, release_date: string, cof_sha256: string}
     * }
     */
    public static function compareNoaaCofToManifest(array $manifest, string $noaaCofContent): array
    {
        $errors = [];

        try {
            $header = self::parseCofHeaderFromContent($noaaCofContent);
        } catch (\InvalidArgumentException $e) {
            return [
                'ok' => false,
                'errors' => ['NOAA WMM.COF header parse failed: ' . $e->getMessage()],
                'noaa' => [
                    'epoch' => 0.0,
                    'model' => '',
                    'release_date' => '',
                    'cof_sha256' => hash('sha256', $noaaCofContent),
                ],
            ];
        }

        $noaaSha256 = hash('sha256', $noaaCofContent);
        $noaaSummary = [
            'epoch' => $header['epoch'],
            'model' => $header['model'],
            'release_date' => $header['release_date'],
            'cof_sha256' => $noaaSha256,
        ];

        foreach (['model', 'epoch', 'release_date', 'cof_sha256'] as $key) {
            if (!array_key_exists($key, $manifest)) {
                $errors[] = "Vendored manifest missing required key: {$key}";
                continue;
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'noaa' => $noaaSummary];
        }

        if ((string) $manifest['model'] !== $header['model']) {
            $errors[] = sprintf(
                'Model mismatch: vendored=%s NOAA=%s',
                (string) $manifest['model'],
                $header['model']
            );
        }

        if (!is_numeric($manifest['epoch']) || abs((float) $manifest['epoch'] - $header['epoch']) > 0.0001) {
            $errors[] = sprintf(
                'Epoch mismatch: vendored=%s NOAA=%s',
                (string) $manifest['epoch'],
                (string) $header['epoch']
            );
        }

        if ((string) $manifest['release_date'] !== $header['release_date']) {
            $errors[] = sprintf(
                'Release date mismatch: vendored=%s NOAA=%s',
                (string) $manifest['release_date'],
                $header['release_date']
            );
        }

        $expectedSha = strtolower((string) $manifest['cof_sha256']);
        if ($expectedSha !== $noaaSha256) {
            $errors[] = sprintf(
                'SHA-256 mismatch: vendored=%s NOAA=%s',
                $expectedSha,
                $noaaSha256
            );
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'noaa' => $noaaSummary,
        ];
    }

    /**
     * Refresh golden fixture expected values from NOAA test vectors.
     *
     * Matches existing fixtures by decimal year, altitude, latitude, and longitude.
     *
     * @param string $testValuesContent Raw WMM*_TestValues.txt from NOAA zip
     * @param array<string, mixed> $existingFixtureJson Parsed wmm-noaa-reference.json
     * @return array{fixtures: array<int, array<string, mixed>>, missing: list<string>}
     * @throws \InvalidArgumentException When fixture JSON is missing a fixtures array
     */
    public static function refreshGoldenFixtures(string $testValuesContent, array $existingFixtureJson): array
    {
        $vectors = self::parseNoaaTestValues($testValuesContent);
        $fixtures = $existingFixtureJson['fixtures'] ?? [];
        if (!is_array($fixtures)) {
            throw new \InvalidArgumentException('Fixture JSON missing fixtures array');
        }

        $missing = [];
        $updated = [];
        foreach ($fixtures as $fixture) {
            if (!is_array($fixture)) {
                continue;
            }

            $key = self::fixtureMatchKey(
                (float) ($fixture['decimal_year'] ?? 0),
                (float) ($fixture['altitude_km'] ?? 0),
                (float) ($fixture['lat'] ?? 0),
                (float) ($fixture['lon'] ?? 0)
            );

            if (!isset($vectors[$key])) {
                $missing[] = $key;
                $updated[] = $fixture;
                continue;
            }

            [$declination, $inclination] = $vectors[$key];
            $fixture['declination'] = $declination;
            $fixture['inclination'] = $inclination;
            $updated[] = $fixture;
        }

        return [
            'fixtures' => $updated,
            'missing' => $missing,
        ];
    }

    /**
     * Load and validate bundled manifest.json.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException When manifest is unreadable or invalid
     */
    public static function loadBundledManifest(): array
    {
        $path = WmmCoefficients::getBundledManifestPath();
        if (!is_readable($path)) {
            throw new \RuntimeException('Bundled WMM manifest is not readable: ' . $path);
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Failed to read bundled WMM manifest: ' . $path);
        }

        $manifest = json_decode($json, true);
        if (!is_array($manifest) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid bundled WMM manifest JSON: ' . json_last_error_msg());
        }

        return $manifest;
    }

    /**
     * @return array<string, array{0: float, 1: float}>
     */
    private static function parseNoaaTestValues(string $testValuesContent): array
    {
        $vectors = [];
        foreach (preg_split('/\R/', $testValuesContent) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if ($parts === false || count($parts) < 6) {
                continue;
            }

            if (
                !is_numeric($parts[0])
                || !is_numeric($parts[1])
                || !is_numeric($parts[2])
                || !is_numeric($parts[3])
                || !is_numeric($parts[4])
                || !is_numeric($parts[5])
            ) {
                continue;
            }

            $key = self::fixtureMatchKey(
                (float) $parts[0],
                (float) $parts[1],
                (float) $parts[2],
                (float) $parts[3]
            );
            $vectors[$key] = [(float) $parts[4], (float) $parts[5]];
        }

        return $vectors;
    }

    private static function fixtureMatchKey(float $decimalYear, float $altKm, float $lat, float $lon): string
    {
        return sprintf('%.1f|%d|%.0f|%.0f', $decimalYear, (int) round($altKm), $lat, $lon);
    }

    private static function httpGet(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP curl extension is required for NOAA WMM sync');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 400) {
            return null;
        }

        return $body;
    }
}
