<?php
/**
 * NOTAM banner layout rules in styles.css (mobile text wrapping).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class NotamBannerStylesTest extends TestCase
{
    /**
     * Extract the first CSS rule block for a selector (stops at the closing brace).
     *
     * @param string $css    Full stylesheet contents
     * @param string $selector Selector including leading dot
     * @return string Rule block including braces, or empty string when missing
     */
    private function extractRuleBlock(string $css, string $selector): string
    {
        $pattern = '/' . preg_quote($selector, '/') . '\s*\{/';
        if (!preg_match($pattern, $css, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $matches[0][1];
        $brace = strpos($css, '{', $start);
        if ($brace === false) {
            return '';
        }

        $depth = 0;
        $len = strlen($css);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $css[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($css, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }

    /**
     * Extract a full @-rule block (for example @media) using brace balancing.
     *
     * @param string $css            Full stylesheet contents
     * @param string $headerPattern  Regex from the @ through the opening brace (no delimiters)
     * @return string Rule block including the @ prefix and braces, or empty when missing
     */
    private function extractAtRuleBlock(string $css, string $headerPattern): string
    {
        if (!preg_match('/' . $headerPattern . '/', $css, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $matches[0][1];
        $brace = strpos($css, '{', $start);
        if ($brace === false) {
            return '';
        }

        $depth = 0;
        $len = strlen($css);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $css[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($css, $start, $i - $start + 1);
                }
            }
        }

        return '';
    }

    public function testNotamTimeRange_AllowsWrappingForLongEffectiveDates(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/css/styles.css');
        $this->assertIsString($css);

        $rule = $this->extractRuleBlock($css, '.notam-time-range');
        $this->assertNotSame('', $rule, 'Expected .notam-time-range rule in styles.css');

        $this->assertDoesNotMatchRegularExpression('/white-space\s*:\s*nowrap\b/', $rule);
        $this->assertMatchesRegularExpression('/overflow-wrap\s*:\s*break-word/', $rule);
        $this->assertMatchesRegularExpression('/min-width\s*:\s*0(px)?\b/', $rule);
    }

    public function testNotamTimeRange_MobileBlockStacksOnOwnRow(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/css/styles.css');
        $this->assertIsString($css);

        $media = $this->extractAtRuleBlock(
            $css,
            '@media\s*\(\s*max-width\s*:\s*600px\s*\)\s*\{'
        );
        $this->assertNotSame('', $media, 'Expected mobile NOTAM @media block in styles.css');

        $rule = $this->extractRuleBlock($media, '.notam-time-range');
        $this->assertNotSame('', $rule, 'Expected .notam-time-range rule inside mobile @media block');
        $this->assertMatchesRegularExpression('/flex-basis\s*:\s*100%/', $rule);
    }
}
