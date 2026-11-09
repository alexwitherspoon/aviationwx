<?php

/**
 * Unit tests for runway end ident parsing, including cardinal direction idents.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/runway-end-ident.php';

class RunwayEndIdentTest extends TestCase
{
    #[DataProvider('cardinalHeadingProvider')]
    public function testParseRunwayEndIdentMagneticHeading_Cardinal_ReturnsMagneticHeading(string $endId, float $expected): void
    {
        $this->assertSame($expected, parseRunwayEndIdentMagneticHeading($endId));
    }

    /**
     * @return array<string, array{string, float}>
     */
    public static function cardinalHeadingProvider(): array
    {
        return [
            'north' => ['N', 360.0],
            'northeast' => ['NE', 45.0],
            'east' => ['E', 90.0],
            'southeast' => ['SE', 135.0],
            'south' => ['S', 180.0],
            'southwest' => ['SW', 225.0],
            'west' => ['W', 270.0],
            'northwest' => ['NW', 315.0],
            'lowercase northeast' => ['ne', 45.0],
            'south with whitespace' => ['  s  ', 180.0],
        ];
    }

    public function testParseRunwayEndIdentMagneticHeading_Numeric_Unchanged(): void
    {
        $this->assertSame(90.0, parseRunwayEndIdentMagneticHeading('09'));
        $this->assertSame(360.0, parseRunwayEndIdentMagneticHeading('36'));
        $this->assertSame(280.0, parseRunwayEndIdentMagneticHeading('28L'));
    }

    public function testParseRunwayEndIdentMagneticHeading_NonCardinalNonNumeric_ReturnsNull(): void
    {
        $this->assertNull(parseRunwayEndIdentMagneticHeading('H1'));
        $this->assertNull(parseRunwayEndIdentMagneticHeading('ALL'));
        $this->assertNull(parseRunwayEndIdentMagneticHeading(''));
    }

    public function testParseCardinalRunwayEndHeading_NonCardinal_ReturnsNull(): void
    {
        $this->assertNull(parseCardinalRunwayEndHeading('H1'));
        $this->assertNull(parseCardinalRunwayEndHeading('09'));
        $this->assertNull(parseCardinalRunwayEndHeading('NNE'));
    }

    public function testCardinalRunwayEndIdentsAreReciprocal_Opposite_ReturnsTrue(): void
    {
        $this->assertTrue(cardinalRunwayEndIdentsAreReciprocal('N', 'S'));
        $this->assertTrue(cardinalRunwayEndIdentsAreReciprocal('S', 'N'));
        $this->assertTrue(cardinalRunwayEndIdentsAreReciprocal('NE', 'SW'));
        $this->assertTrue(cardinalRunwayEndIdentsAreReciprocal('SW', 'NE'));
        $this->assertTrue(cardinalRunwayEndIdentsAreReciprocal('E', 'W'));
        $this->assertTrue(cardinalRunwayEndIdentsAreReciprocal('W', 'E'));
        $this->assertTrue(cardinalRunwayEndIdentsAreReciprocal('NW', 'SE'));
        $this->assertTrue(cardinalRunwayEndIdentsAreReciprocal('SE', 'NW'));
    }

    public function testCardinalRunwayEndIdentsAreReciprocal_NonOpposite_ReturnsFalse(): void
    {
        $this->assertFalse(cardinalRunwayEndIdentsAreReciprocal('N', 'E'));
        $this->assertFalse(cardinalRunwayEndIdentsAreReciprocal('N', 'N'));
        $this->assertFalse(cardinalRunwayEndIdentsAreReciprocal('N', '27'));
        $this->assertFalse(cardinalRunwayEndIdentsAreReciprocal('09', '27'));
    }
}
