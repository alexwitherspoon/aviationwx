<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/../../lib/seo.php";

class GuideUrlTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER["HTTPS"] = "on";
        $_SERVER["HTTP_HOST"] = "guides.aviationwx.org";
    }

    protected function tearDown(): void
    {
        unset($_SERVER["HTTPS"], $_SERVER["HTTP_HOST"], $_SERVER["REQUEST_URI"]);
    }

    public function testCanonicalSlug_IndexOnSubdomain(): void
    {
        $_SERVER["REQUEST_URI"] = "/";
        $this->assertSame("", getGuideCanonicalSlug());
    }

    public function testCanonicalSlug_IndexVariantPathsCollapse(): void
    {
        $_SERVER["REQUEST_URI"] = "/README.md";
        $this->assertSame("", getGuideCanonicalSlug());
        $_SERVER["REQUEST_URI"] = "/readme.md/";
        $this->assertSame("", getGuideCanonicalSlug());
    }

    public function testCanonicalSlug_GuidesPrefixStrippedBeforeIndexCheck(): void
    {
        $_SERVER["REQUEST_URI"] = "/guides/README.md";
        $this->assertSame("", getGuideCanonicalSlug());
    }

    public function testCanonicalSlug_GuidesPrefixStripped(): void
    {
        $_SERVER["REQUEST_URI"] = "/guides/08-camera-configuration";
        $this->assertSame("08-camera-configuration", getGuideCanonicalSlug());
    }

    public function testCanonicalSlug_StripsMdAndTrailingSlash(): void
    {
        $_SERVER["REQUEST_URI"] = "/08-camera-configuration.md";
        $this->assertSame("08-camera-configuration", getGuideCanonicalSlug());
        $_SERVER["REQUEST_URI"] = "/08-camera-configuration/";
        $this->assertSame("08-camera-configuration", getGuideCanonicalSlug());
        $_SERVER["REQUEST_URI"] = "/08-camera-configuration.md/";
        $this->assertSame("08-camera-configuration", getGuideCanonicalSlug());
    }

    public function testCanonicalSlug_PreservesQueryForResolution(): void
    {
        $_SERVER["REQUEST_URI"] = "/08-camera-configuration?cam=0&period=day";
        $this->assertSame("08-camera-configuration", getGuideCanonicalSlug());
    }

    public function testResolveGuideFile_FindsExisting(): void
    {
        $this->assertSame("08-camera-configuration", resolveGuideFile("08-camera-configuration"));
    }

    public function testResolveGuideFile_ReturnsNullForMissing(): void
    {
        $this->assertNull(resolveGuideFile("does-not-exist"));
    }

    public function testResolveGuideFile_IndexResolvesWhenReadmePresent(): void
    {
        $this->assertSame("", resolveGuideFile(""));
    }

    public function testCanonicalUrl_Index(): void
    {
        $this->assertSame("https://guides.aviationwx.org/", getGuideCanonicalUrl(""));
    }

    public function testCanonicalUrl_Guide(): void
    {
        $this->assertSame(
            "https://guides.aviationwx.org/08-camera-configuration",
            getGuideCanonicalUrl("08-camera-configuration")
        );
    }
}
