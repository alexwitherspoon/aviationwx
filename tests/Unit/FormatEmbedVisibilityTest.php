<?php
/**
 * Unit Tests for formatEmbedVisibility()
 *
 * Safety-critical: Visibility display affects pilot decision-making.
 * Tests P6SM "greater than" semantics (METAR P prefix) for correct display.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/units.php';
require_once __DIR__ . '/../../lib/embed-templates/shared.php';

class FormatEmbedVisibilityTest extends TestCase
{
    /**
     * P6SM with greaterThan: displays "6+ SM"
     */
    public function testP6SM_GreaterThan_ShowsPlusSuffix(): void
    {
        $result = formatEmbedVisibility(6.0, 'ft', true);
        $this->assertSame('6+ SM', $result);
    }

    /**
     * 6SM without greaterThan: displays "6 SM" (no plus)
     */
    public function test6SM_NoGreaterThan_NoPlusSuffix(): void
    {
        $result = formatEmbedVisibility(6.0, 'ft', false);
        $this->assertSame('6 SM', $result);
    }

    /**
     * P6SM metric: displays "9.7+ km" (6 SM ≈ 9.66 km)
     */
    public function testP6SM_Metric_GreaterThan_ShowsPlusSuffix(): void
    {
        $result = formatEmbedVisibility(6.0, 'm', true);
        $this->assertStringContainsString('+', $result);
        $this->assertStringContainsString('km', $result);
    }

    /**
     * Null visibility returns "--"
     */
    public function testNull_ReturnsDash(): void
    {
        $this->assertSame('--', formatEmbedVisibility(null, 'ft'));
        $this->assertSame('--', formatEmbedVisibility(null, 'm'));
    }

    /**
     * 10+ SM: always shows "10+ SM" (unlimited)
     */
    public function test10SM_Shows10Plus(): void
    {
        $this->assertSame('10+ SM', formatEmbedVisibility(10.0, 'ft'));
        $this->assertSame('10+ SM', formatEmbedVisibility(10.0, 'ft', false));
        $this->assertSame('10+ SM', formatEmbedVisibility(10.0, 'ft', true));
    }
}
