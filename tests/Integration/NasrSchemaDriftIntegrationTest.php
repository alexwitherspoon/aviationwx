<?php
/**
 * Live NASR schema-drift probes (network-dependent).
 *
 * Downloads the current-cycle APT and FRQ zips and asserts the zip layout and
 * CSV header prefixes still match what the parsers expect. A mismatch means FAA
 * changed a URL or column before the scheduled refresh could fail silently.
 *
 * Not part of the default Integration suite (see phpunit.xml exclude).
 * Opt-in via RUN_EXTERNAL_UPSTREAM_TESTS=1 (make test-external-apis).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/discovery.php';
require_once __DIR__ . '/../../lib/nasr/csv-validation.php';
require_once __DIR__ . '/../../lib/nasr/extract.php';
require_once __DIR__ . '/../../lib/nasr/util.php';

class NasrSchemaDriftIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('RUN_EXTERNAL_UPSTREAM_TESTS') !== '1') {
            $this->markTestSkipped(
                'Live NASR schema checks are opt-in. Run: make test-external-apis (see docs/TESTING.md).'
            );
        }
    }

    public function testBuildNasrAptDownloadPlans_CurrentCycleZips_MatchParserSchema(): void
    {
        $plans = buildNasrAptDownloadPlans();
        $this->assertNotSame(
            [],
            $plans,
            'NASR cycle discovery returned no plans; FAA index or zip URL may have drifted'
        );

        $effectiveDate = $plans[0]['effective_date'] ?? null;
        $this->assertNotNull($effectiveDate, 'NASR plan missing effective date');

        $this->assertAptZipMatchesSchema($plans[0]['source_url']);
        $this->assertFrqZipMatchesSchema($effectiveDate);
    }

    private function assertAptZipMatchesSchema(string $sourceUrl): void
    {
        $tmpRoot = $this->makeTempDir('apt');
        try {
            $zipPath = $tmpRoot . '/apt.zip';
            $this->assertTrue(
                nasrHttpDownloadToFile($sourceUrl, $zipPath),
                'NASR APT zip download failed: ' . $sourceUrl
            );
            $this->assertTrue(nasrDownloadedZipFileIsValid($zipPath), 'NASR APT zip download was empty or not a valid zip');

            $extractDir = $tmpRoot . '/csv';
            $this->assertTrue(@mkdir($extractDir, 0700, true) || is_dir($extractDir), 'Failed to create NASR CSV extract dir');
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath) === true, 'NASR APT zip could not be opened');
            $this->assertTrue(
                nasrExtractAllowlistedAptCsvFromZip($zip, $extractDir),
                'NASR APT zip entry names changed'
            );
            $zip->close();

            $this->assertTrue(nasrAptCsvDirectoryIsValid($extractDir), 'NASR APT CSV headers changed');

            // The parser validates APT_RMK.csv when present; probe it too so a
            // header drift there is caught rather than silently skipping remarks.
            if (is_readable($extractDir . '/APT_RMK.csv')) {
                $this->assertTrue(
                    nasrCsvFileIsValid($extractDir . '/APT_RMK.csv', NASR_CSV_HEADER_PREFIX['APT_RMK']),
                    'NASR APT_RMK CSV header changed'
                );
            }
        } finally {
            nasrCleanupDirectory($tmpRoot);
        }
    }

    private function assertFrqZipMatchesSchema(string $effectiveDate): void
    {
        $frqUrl = buildNasrFrqZipUrl($effectiveDate);
        $this->assertNotSame('', $frqUrl, 'NASR FRQ URL could not be built for cycle');

        $tmpRoot = $this->makeTempDir('frq');
        try {
            $zipPath = $tmpRoot . '/frq.zip';
            $this->assertTrue(
                nasrHttpDownloadToFile($frqUrl, $zipPath),
                'NASR FRQ zip download failed: ' . $frqUrl
            );
            $this->assertTrue(nasrDownloadedZipFileIsValid($zipPath), 'NASR FRQ zip download was empty or not a valid zip');

            $extractDir = $tmpRoot . '/csv';
            $this->assertTrue(@mkdir($extractDir, 0700, true) || is_dir($extractDir), 'Failed to create NASR CSV extract dir');
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath) === true, 'NASR FRQ zip could not be opened');
            $this->assertTrue(
                nasrExtractAllowlistedFrqCsvFromZip($zip, $extractDir),
                'NASR FRQ zip entry names changed'
            );
            $zip->close();

            $this->assertTrue(nasrFrqCsvFileIsValid($extractDir . '/FRQ.csv'), 'NASR FRQ CSV header changed');
        } finally {
            nasrCleanupDirectory($tmpRoot);
        }
    }

    private function makeTempDir(string $label): string
    {
        $dir = sys_get_temp_dir() . '/aviationwx-nasr-drift-' . $label . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->assertTrue(@mkdir($dir, 0700, true) || is_dir($dir), 'Failed to create NASR probe temp dir');

        return $dir;
    }
}
