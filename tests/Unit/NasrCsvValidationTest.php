<?php
/**
 * Unit tests for NASR CSV pre-parse validation.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/csv-validation.php';

class NasrCsvValidationTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = __DIR__ . '/../Fixtures/nasr';
    }

    public function testNasrAptCsvDirectoryIsValidForFixtures(): void
    {
        $this->assertTrue(nasrAptCsvDirectoryIsValid($this->fixtureDir));
    }

    public function testNasrFrqCsvFileIsValidForFixture(): void
    {
        $this->assertTrue(nasrFrqCsvFileIsValid($this->fixtureDir . '/FRQ.csv'));
    }

    public function testNasrCsvFileRejectsHtmlBody(): void
    {
        $path = sys_get_temp_dir() . '/nasr_invalid_' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($path, '<html><body>error</body></html>');

        try {
            $this->assertFalse(nasrFrqCsvFileIsValid($path));
        } finally {
            @unlink($path);
        }
    }

    public function testNasrCsvFileRejectsWrongHeader(): void
    {
        $path = sys_get_temp_dir() . '/nasr_invalid_' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($path, "not,a,valid,header\n");

        try {
            $this->assertFalse(nasrFrqCsvFileIsValid($path));
        } finally {
            @unlink($path);
        }
    }

    public function testNasrCsvFileRejectsZeroByteFile(): void
    {
        $path = sys_get_temp_dir() . '/nasr_zero_' . bin2hex(random_bytes(4)) . '.csv';
        $this->assertNotFalse(file_put_contents($path, ''));

        try {
            $this->assertFalse(nasrFrqCsvFileIsValid($path));
        } finally {
            @unlink($path);
        }
    }

    public function testNasrDownloadedZipRejectsEmptyFile(): void
    {
        $path = sys_get_temp_dir() . '/nasr_empty_' . bin2hex(random_bytes(4)) . '.zip';
        $this->assertNotFalse(file_put_contents($path, ''));

        try {
            $this->assertFalse(nasrDownloadedZipFileIsValid($path));
        } finally {
            @unlink($path);
        }
    }

    public function testNasrDownloadedZipRejectsNonZipMagic(): void
    {
        $path = sys_get_temp_dir() . '/nasr_badzip_' . bin2hex(random_bytes(4)) . '.zip';
        $this->assertNotFalse(file_put_contents($path, str_repeat('x', 64)));

        try {
            $this->assertFalse(nasrDownloadedZipFileIsValid($path));
        } finally {
            @unlink($path);
        }
    }

    public function testNasrDownloadedZipRejectsZipWithNoEntries(): void
    {
        $path = sys_get_temp_dir() . '/nasr_emptyzip_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        $zip->close();

        try {
            $this->assertFalse(nasrDownloadedZipFileIsValid($path));
        } finally {
            @unlink($path);
        }
    }

    public function testNasrDownloadedZipAcceptsZipWithFrqFixture(): void
    {
        $path = sys_get_temp_dir() . '/nasr_goodzip_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        $this->assertTrue($zip->addFile($this->fixtureDir . '/FRQ.csv', 'FRQ.csv'));
        $zip->close();

        try {
            $this->assertTrue(nasrDownloadedZipFileIsValid($path));
        } finally {
            @unlink($path);
        }
    }
}
