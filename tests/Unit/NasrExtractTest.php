<?php
/**
 * Unit tests for NASR zip extraction helpers.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/extract.php';

class NasrExtractTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = __DIR__ . '/../Fixtures/nasr';
    }

    public function testNasrExtractAllowlistedAptCsvFromZip_CompleteArchive_ExtractsExpectedCsvs(): void
    {
        $zipPath = sys_get_temp_dir() . '/nasr_apt_extract_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $this->assertTrue($zip->addFile($this->fixtureDir . '/APT_BASE.csv', 'APT_BASE.csv'));
        $this->assertTrue($zip->addFile($this->fixtureDir . '/APT_RWY.csv', 'APT_RWY.csv'));
        $this->assertTrue($zip->addFile($this->fixtureDir . '/APT_RWY_END.csv', 'APT_RWY_END.csv'));
        $this->assertTrue($zip->addFile($this->fixtureDir . '/APT_RMK.csv', 'APT_RMK.csv'));
        $zip->close();

        $extractDir = $this->makeExtractDir('apt_extract');

        try {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath) === true);
            $this->assertTrue(nasrExtractAllowlistedAptCsvFromZip($zip, $extractDir));
            $zip->close();

            $this->assertFileExists($extractDir . '/APT_BASE.csv');
            $this->assertFileExists($extractDir . '/APT_RWY.csv');
            $this->assertFileExists($extractDir . '/APT_RWY_END.csv');
            $this->assertFileExists($extractDir . '/APT_RMK.csv');
        } finally {
            @unlink($zipPath);
            $this->removeTree($extractDir);
        }
    }

    public function testNasrExtractAllowlistedAptCsvFromZip_MissingRequiredCsv_ReturnsFalse(): void
    {
        $zipPath = sys_get_temp_dir() . '/nasr_apt_partial_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $this->assertTrue($zip->addFile($this->fixtureDir . '/APT_BASE.csv', 'APT_BASE.csv'));
        $zip->close();

        $extractDir = $this->makeExtractDir('apt_partial');

        try {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath) === true);
            $this->assertFalse(nasrExtractAllowlistedAptCsvFromZip($zip, $extractDir));
            $zip->close();
        } finally {
            @unlink($zipPath);
            $this->removeTree($extractDir);
        }
    }

    public function testNasrExtractAllowlistedAptCsvFromZip_TraversalEntry_IsSkipped(): void
    {
        $zipPath = sys_get_temp_dir() . '/nasr_apt_traversal_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $this->assertTrue($zip->addFile($this->fixtureDir . '/APT_RWY.csv', 'APT_RWY.csv'));
        $this->assertTrue($zip->addFile($this->fixtureDir . '/APT_RWY_END.csv', 'APT_RWY_END.csv'));

        // APT_BASE.csv only appears as a traversal entry. Its basename matches the
        // allowlist, so without the guard the file would be written; the guard
        // must reject the entry, leaving the required APT_BASE.csv missing.
        $this->assertTrue($zip->addFile($this->fixtureDir . '/APT_BASE.csv', '../APT_BASE.csv'));
        $zip->close();

        $extractDir = $this->makeExtractDir('apt_traversal');

        try {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath) === true);
            $this->assertFalse(nasrExtractAllowlistedAptCsvFromZip($zip, $extractDir));
            $zip->close();

            $this->assertFileDoesNotExist($extractDir . '/APT_BASE.csv');
        } finally {
            @unlink($zipPath);
            $this->removeTree($extractDir);
        }
    }

    public function testNasrExtractAllowlistedFrqCsvFromZip_CompleteArchive_WritesFrqCsv(): void
    {
        $zipPath = sys_get_temp_dir() . '/nasr_frq_extract_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $this->assertTrue($zip->addFile($this->fixtureDir . '/FRQ.csv', 'FRQ.csv'));
        $zip->close();

        $extractDir = $this->makeExtractDir('frq_extract');

        try {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath) === true);
            $this->assertTrue(nasrExtractAllowlistedFrqCsvFromZip($zip, $extractDir));
            $zip->close();

            $this->assertFileExists($extractDir . '/FRQ.csv');
        } finally {
            @unlink($zipPath);
            $this->removeTree($extractDir);
        }
    }

    public function testNasrExtractAllowlistedFrqCsvFromZip_MissingFrqEntry_ReturnsFalse(): void
    {
        $zipPath = sys_get_temp_dir() . '/nasr_frq_missing_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $this->assertTrue($zip->addFile($this->fixtureDir . '/APT_BASE.csv', 'APT_BASE.csv'));
        $zip->close();

        $extractDir = $this->makeExtractDir('frq_missing');

        try {
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath) === true);
            $this->assertFalse(nasrExtractAllowlistedFrqCsvFromZip($zip, $extractDir));
            $zip->close();
        } finally {
            @unlink($zipPath);
            $this->removeTree($extractDir);
        }
    }

    private function makeExtractDir(string $label): string
    {
        $dir = sys_get_temp_dir() . '/nasr_' . $label . '_' . bin2hex(random_bytes(4));
        $this->assertNotFalse(@mkdir($dir, 0700, true), 'Failed to create NASR extract temp dir');

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
