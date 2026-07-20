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
}
