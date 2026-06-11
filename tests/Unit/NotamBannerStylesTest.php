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
        $needle = $selector . ' {';
        $start = strpos($css, $needle);
        if ($start === false) {
            return '';
        }

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

        $this->assertStringNotContainsString('white-space: nowrap', $rule);
        $this->assertStringContainsString('overflow-wrap: break-word', $rule);
        $this->assertStringContainsString('min-width: 0', $rule);
    }

    public function testNotamTimeRange_MobileBlockStacksOnOwnRow(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/css/styles.css');
        $this->assertIsString($css);

        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*600px\)\s*\{[^}]*\.notam-time-range\s*\{[^}]*flex-basis:\s*100%/s',
            $css
        );
    }
}
