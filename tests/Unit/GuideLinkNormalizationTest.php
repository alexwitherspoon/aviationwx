<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/seo.php';

class GuideLinkNormalizationTest extends TestCase
{
    public function testNormalizeGuideLinks_LocalGuideLink_RemovesMdExtension(): void
    {
        $html = '<a href="08-camera-configuration.md">Camera</a>';
        $this->assertSame('<a href="08-camera-configuration">Camera</a>', normalizeGuideLinks($html));
    }

    public function testNormalizeGuideLinks_ReadmeLink_MapsToIndex(): void
    {
        $html = '<a href="README.md">Home</a>';
        $this->assertSame('<a href="/">Home</a>', normalizeGuideLinks($html));
    }

    public function testNormalizeGuideLinks_ExternalUrl_IsUnchanged(): void
    {
        $html = '<a href="https://example.test/08-camera-configuration.md">External</a>';
        $this->assertSame($html, normalizeGuideLinks($html));
    }

    public function testNormalizeGuideLinks_NonGuideDocsLink_IsUnchanged(): void
    {
        $html = '<a href="../docs/CONFIGURATION.md">Config</a>';
        $this->assertSame($html, normalizeGuideLinks($html));
    }

    public function testNormalizeGuideLinks_Fragment_IsPreserved(): void
    {
        $html = '<a href="09-weather-station-configuration.md#dyaconlive">DyaconLive</a>';
        $this->assertSame(
            '<a href="09-weather-station-configuration#dyaconlive">DyaconLive</a>',
            normalizeGuideLinks($html)
        );
    }

    public function testNormalizeGuideLinks_UnknownGuideSlug_IsUnchanged(): void
    {
        $html = '<a href="99-does-not-exist.md">Missing</a>';
        $this->assertSame($html, normalizeGuideLinks($html));
    }
}