<?php
/**
 * Unit Tests for embed link helper functions
 *
 * Tests buildHistoryPlayerUrl and buildEmbedLinkAttrs used for per-webcam
 * links (history player) and dashboard links in embed widgets.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/embed-templates/shared.php';

class EmbedLinkHelpersTest extends TestCase
{
    /**
     * buildHistoryPlayerUrl returns dashboard URL with ?cam= param
     */
    public function testBuildHistoryPlayerUrl_ValidInput_ReturnsUrlWithCamParam(): void
    {
        $url = buildHistoryPlayerUrl('https://khio.aviationwx.org', 0);
        $this->assertSame('https://khio.aviationwx.org?cam=0', $url);

        $url = buildHistoryPlayerUrl('https://kspb.aviationwx.org', 1);
        $this->assertSame('https://kspb.aviationwx.org?cam=1', $url);
    }

    /**
     * buildHistoryPlayerUrl handles multiple camera indices
     */
    public function testBuildHistoryPlayerUrl_MultipleCamIndices_ReturnsCorrectUrls(): void
    {
        $base = 'https://khio.aviationwx.org';
        $this->assertSame($base . '?cam=0', buildHistoryPlayerUrl($base, 0));
        $this->assertSame($base . '?cam=1', buildHistoryPlayerUrl($base, 1));
        $this->assertSame($base . '?cam=3', buildHistoryPlayerUrl($base, 3));
    }

    /**
     * buildEmbedLinkAttrs with _blank includes rel="noopener"
     */
    public function testBuildEmbedLinkAttrs_BlankTarget_IncludesNoopener(): void
    {
        $attrs = buildEmbedLinkAttrs('_blank');
        $this->assertStringContainsString('target="_blank"', $attrs);
        $this->assertStringContainsString('rel="noopener"', $attrs);
    }

    /**
     * buildEmbedLinkAttrs with null defaults to _blank
     */
    public function testBuildEmbedLinkAttrs_NullTarget_DefaultsToBlankWithNoopener(): void
    {
        $attrs = buildEmbedLinkAttrs(null);
        $this->assertStringContainsString('target="_blank"', $attrs);
        $this->assertStringContainsString('rel="noopener"', $attrs);
    }

    /**
     * buildEmbedLinkAttrs with _self omits rel="noopener"
     */
    public function testBuildEmbedLinkAttrs_SelfTarget_NoNoopener(): void
    {
        $attrs = buildEmbedLinkAttrs('_self');
        $this->assertStringContainsString('target="_self"', $attrs);
        $this->assertStringNotContainsString('rel="noopener"', $attrs);
    }

    /**
     * buildEmbedLinkAttrs escapes target value to prevent XSS (quotes become &quot;)
     */
    public function testBuildEmbedLinkAttrs_MaliciousTarget_EscapesQuotes(): void
    {
        $attrs = buildEmbedLinkAttrs('_blank" onclick="alert(1)');
        $this->assertStringContainsString('&quot;', $attrs, 'Quotes should be HTML-escaped');
        $this->assertStringNotContainsString('" onclick="', $attrs, 'Unescaped quotes would allow attribute injection');
    }
}
