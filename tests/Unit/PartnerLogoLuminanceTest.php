<?php
/**
 * Unit tests for partner logo luminance analysis and contrast metadata.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/partner-logo-luminance.php';

class PartnerLogoLuminanceTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = __DIR__ . '/../Fixtures/partner-logos';
        $this->assertDirectoryExists($this->fixtureDir);
    }

    public function testAnalyzePartnerLogoContrastHints_LightMarkOnTransparent_LowOpaqueCoverage(): void
    {
        $path = $this->fixtureDir . '/light-on-transparent.png';
        $hints = analyzePartnerLogoContrastHints($path);

        $this->assertNotNull($hints);
        $this->assertLessThan(PARTNER_LOGO_OPAQUE_COVERAGE_THRESHOLD, $hints['opaque_coverage']);
        $this->assertGreaterThan(PARTNER_LOGO_LUMINANCE_LIGHT_THRESHOLD, $hints['mean_luminance']);
    }

    public function testAnalyzePartnerLogoMeanLuminance_LightMarkOnTransparent_ExceedsLightThreshold(): void
    {
        $path = $this->fixtureDir . '/light-on-transparent.png';
        $lum = analyzePartnerLogoMeanLuminance($path);

        $this->assertNotNull($lum);
        $this->assertGreaterThan(PARTNER_LOGO_LUMINANCE_LIGHT_THRESHOLD, $lum);
    }

    public function testAnalyzePartnerLogoMeanLuminance_DarkMarkOnTransparent_BelowDarkThreshold(): void
    {
        $path = $this->fixtureDir . '/dark-on-transparent.png';
        $lum = analyzePartnerLogoMeanLuminance($path);

        $this->assertNotNull($lum);
        $this->assertLessThan(PARTNER_LOGO_LUMINANCE_DARK_THRESHOLD, $lum);
    }

    public function testAnalyzePartnerLogoMeanLuminance_PaletteGif_BelowDarkThreshold(): void
    {
        $path = $this->fixtureDir . '/palette.gif';
        $this->assertFileExists($path);

        $lum = analyzePartnerLogoMeanLuminance($path);
        $this->assertNotNull($lum);
        $this->assertLessThan(PARTNER_LOGO_LUMINANCE_DARK_THRESHOLD, $lum);
    }

    public function testGetPartnerLogoMeanLuminance_OpaqueWhiteBackgroundJpeg_ReturnsNull(): void
    {
        $lum = getPartnerLogoMeanLuminance('/tests/Fixtures/partner-logos/opaque-white-background.jpeg');
        $this->assertNull($lum);
    }

    public function testGetPartnerLogoMeanLuminance_OpaqueWhiteBackgroundPng_ReturnsNull(): void
    {
        $lum = getPartnerLogoMeanLuminance('/tests/Fixtures/partner-logos/opaque-white-background.png');
        $this->assertNull($lum);
    }

    public function testGetPartnerLogoMeanLuminance_OpaqueWhiteBackgroundJpeg_WritesCacheMetadata(): void
    {
        $resolved = resolvePartnerLogoImagePath('/tests/Fixtures/partner-logos/opaque-white-background.jpeg');
        $this->assertNotNull($resolved);
        $metaPath = getPartnerLogoLuminanceCachePath($resolved);
        @unlink($metaPath);

        $this->assertNull(getPartnerLogoMeanLuminance('/tests/Fixtures/partner-logos/opaque-white-background.jpeg'));
        $this->assertFileExists($metaPath);

        $decoded = json_decode((string) file_get_contents($metaPath), true);
        $this->assertIsArray($decoded);
        $this->assertGreaterThanOrEqual(PARTNER_LOGO_OPAQUE_COVERAGE_THRESHOLD, $decoded['opaque_coverage']);

        $second = getPartnerLogoMeanLuminance('/tests/Fixtures/partner-logos/opaque-white-background.jpeg');
        $this->assertNull($second);

        @unlink($metaPath);
    }

    public function testReadPartnerLogoLuminanceMeta_MissingOpaqueCoverage_ReturnsNull(): void
    {
        $resolved = resolvePartnerLogoImagePath('/tests/Fixtures/partner-logos/light-on-transparent.png');
        $this->assertNotNull($resolved);
        $metaPath = getPartnerLogoLuminanceCachePath($resolved);
        @unlink($metaPath);

        file_put_contents(
            $metaPath,
            json_encode(['mean_luminance' => 0.9, 'source_mtime' => filemtime($resolved)])
        );
        $this->assertNull(readPartnerLogoLuminanceMeta($resolved));

        @unlink($metaPath);
    }

    public function testGetPartnerLogoMeanLuminance_FixtureAirportLogo_ExceedsLightThreshold(): void
    {
        $configPath = __DIR__ . '/../Fixtures/airports.json.test';
        $config = json_decode((string) file_get_contents($configPath), true);
        $logo = $config['airports']['kspb']['partners'][0]['logo'] ?? '';
        $this->assertNotSame('', $logo);

        $lum = getPartnerLogoMeanLuminance($logo);
        $this->assertNotNull($lum);
        $this->assertGreaterThan(PARTNER_LOGO_LUMINANCE_LIGHT_THRESHOLD, $lum);
    }

    public function testGetPartnerLogoMeanLuminance_SecondCall_ReadsWritableCache(): void
    {
        $path = $this->fixtureDir . '/light-on-transparent.png';
        $resolved = resolvePartnerLogoImagePath('/tests/Fixtures/partner-logos/light-on-transparent.png');
        $this->assertNotNull($resolved);
        $metaPath = getPartnerLogoLuminanceCachePath($resolved);
        @unlink($metaPath);

        $first = getPartnerLogoMeanLuminance('/tests/Fixtures/partner-logos/light-on-transparent.png');
        $this->assertNotNull($first);
        $this->assertFileExists($metaPath);
        $this->assertStringContainsString('/partners/lum/', $metaPath);

        $raw = file_get_contents($metaPath);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertSame((int) filemtime($path), $decoded['source_mtime']);
        $this->assertArrayHasKey('opaque_coverage', $decoded);

        $second = getPartnerLogoMeanLuminance('/tests/Fixtures/partner-logos/light-on-transparent.png');
        $this->assertEqualsWithDelta($first, $second, 0.0001);

        @unlink($metaPath);
    }

    public function testReadPartnerLogoLuminanceMeta_RejectsInvalidCachePayload(): void
    {
        $resolved = resolvePartnerLogoImagePath('/tests/Fixtures/partner-logos/light-on-transparent.png');
        $this->assertNotNull($resolved);
        $metaPath = getPartnerLogoLuminanceCachePath($resolved);
        @unlink($metaPath);

        file_put_contents($metaPath, '{"mean_luminance":"bad","source_mtime":"x"}');
        $this->assertNull(readPartnerLogoLuminanceMeta($resolved));

        file_put_contents(
            $metaPath,
            json_encode(['mean_luminance' => 1.5, 'source_mtime' => filemtime($resolved)])
        );
        $this->assertNull(readPartnerLogoLuminanceMeta($resolved));

        @unlink($metaPath);
    }

    public function testResolvePartnerLogoImagePath_RejectsTraversal(): void
    {
        $this->assertNull(resolvePartnerLogoImagePath('/partner-logos/../secrets/airports.json'));
    }

    public function testIsResolvedPathUnderBase_RejectsSiblingDirectoryPrefix(): void
    {
        $this->assertTrue(isResolvedPathUnderBase('/var/www/html/partner-logos/logo.png', '/var/www/html'));
        $this->assertFalse(isResolvedPathUnderBase('/var/www/html2/partner-logos/logo.png', '/var/www/html'));
    }

    public function testGetPartnerLogoMeanLuminance_ReturnsNullForMissingFile(): void
    {
        $this->assertNull(getPartnerLogoMeanLuminance('/partner-logos/does-not-exist.png'));
    }
}
