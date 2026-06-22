<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/wmm/WmmNoaaSync.php';

class WmmNoaaSyncTest extends TestCase
{
    private const SAMPLE_HTML = <<<'HTML'
    <a href="https://www.ncei.noaa.gov/sites/default/files/2020-01/WMM2020COF.zip">WMM2020</a>
    <a href="https://www.ncei.noaa.gov/sites/default/files/2024-12/WMM2025COF.zip">WMM2025</a>
    HTML;

    private const SAMPLE_COF_HEADER = "    2025.0            WMM-2025     11/13/2024\n";

    public function testDiscoverCoefficientZipUrl_PicksHighestModelYear(): void
    {
        $url = \WmmNoaaSync::discoverCoefficientZipUrl(self::SAMPLE_HTML);
        $this->assertSame(
            'https://www.ncei.noaa.gov/sites/default/files/2024-12/WMM2025COF.zip',
            $url
        );
    }

    public function testDiscoverCoefficientZipUrl_NoMatch_ReturnsNull(): void
    {
        $this->assertNull(\WmmNoaaSync::discoverCoefficientZipUrl('<html>no zip links</html>'));
    }

    public function testDiscoverCoefficientZipUrl_IgnoresNonNoaaHosts(): void
    {
        $html = <<<'HTML'
        <a href="https://evil.example/WMM2099COF.zip">fake</a>
        <a href="https://www.ncei.noaa.gov/sites/default/files/2024-12/WMM2025COF.zip">real</a>
        HTML;

        $url = \WmmNoaaSync::discoverCoefficientZipUrl($html);
        $this->assertSame(
            'https://www.ncei.noaa.gov/sites/default/files/2024-12/WMM2025COF.zip',
            $url
        );
    }

    public function testParseCofHeaderFromContent_ValidHeader_ReturnsFields(): void
    {
        $header = \WmmNoaaSync::parseCofHeaderFromContent(self::SAMPLE_COF_HEADER);
        $this->assertSame(2025.0, $header['epoch']);
        $this->assertSame('WMM-2025', $header['model']);
        $this->assertSame('11/13/2024', $header['release_date']);
    }

    public function testBuildManifest_IncludesFiveYearValidityWindow(): void
    {
        $manifest = \WmmNoaaSync::buildManifest(
            ['epoch' => 2025.0, 'model' => 'WMM-2025', 'release_date' => '11/13/2024'],
            'abc123',
            'https://example.com/WMM2025COF.zip'
        );

        $this->assertSame('WMM-2025', $manifest['model']);
        $this->assertSame(2030.0, $manifest['valid_through_epoch']);
        $this->assertSame('abc123', $manifest['cof_sha256']);
    }

    public function testCompareNoaaCofToManifest_MatchingManifest_ReturnsOk(): void
    {
        $cof = self::SAMPLE_COF_HEADER;
        $sha = hash('sha256', $cof);
        $manifest = [
            'model' => 'WMM-2025',
            'epoch' => 2025.0,
            'release_date' => '11/13/2024',
            'cof_sha256' => $sha,
        ];

        $result = \WmmNoaaSync::compareNoaaCofToManifest($manifest, $cof);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['errors']);
    }

    public function testCompareNoaaCofToManifest_ShaMismatch_ReturnsErrors(): void
    {
        $manifest = [
            'model' => 'WMM-2025',
            'epoch' => 2025.0,
            'release_date' => '11/13/2024',
            'cof_sha256' => str_repeat('0', 64),
        ];

        $result = \WmmNoaaSync::compareNoaaCofToManifest($manifest, self::SAMPLE_COF_HEADER);
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('SHA-256 mismatch', $result['errors'][0]);
    }

    public function testRefreshGoldenFixtures_UpdatesExpectedValues(): void
    {
        $testValues = <<<'TXT'
# comment
2025.000000      28      89    -121   -99.77    88.47
2025.000000      48      80     -96   -29.91    87.77
TXT;

        $existing = [
            '_meta' => ['tolerance_degrees' => 0.05],
            'fixtures' => [
                [
                    'id' => 'sample',
                    'decimal_year' => 2025.0,
                    'altitude_km' => 28.0,
                    'lat' => 89.0,
                    'lon' => -121.0,
                    'declination' => 0.0,
                    'inclination' => 0.0,
                ],
            ],
        ];

        $result = \WmmNoaaSync::refreshGoldenFixtures($testValues, $existing);
        $this->assertSame([], $result['missing']);
        $this->assertSame(-99.77, $result['fixtures'][0]['declination']);
        $this->assertSame(88.47, $result['fixtures'][0]['inclination']);
    }

    public function testExtractZipContents_ReadsBundledNoaaZip(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension not available');
        }

        $zipPath = sys_get_temp_dir() . '/wmm-test-' . uniqid('', true) . '.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        $zip->addFromString('WMM2025COF/WMM.COF', self::SAMPLE_COF_HEADER);
        $zip->addFromString('WMM2025COF/WMM2025_TestValues.txt', "# header\n");
        $zip->close();

        try {
            $extracted = \WmmNoaaSync::extractZipContents($zipPath);
            $this->assertSame(self::SAMPLE_COF_HEADER, $extracted['cof']);
            $this->assertSame('WMM2025COF/WMM.COF', $extracted['cof_entry']);
        } finally {
            @unlink($zipPath);
        }
    }
}
