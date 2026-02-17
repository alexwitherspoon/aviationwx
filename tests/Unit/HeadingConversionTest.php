<?php
/**
 * SAFETY-CRITICAL: Heading Conversion (True ↔ Magnetic)
 *
 * Single source of truth for all heading conversions. Tests verify:
 * - convertMagneticToTrue / convertTrueToMagnetic round-trip
 * - Edge cases: 0°, 360°, boundary wrapping
 * - rotatePointTrueToMagnetic: runway 36 (true north) aligns with magnetic north
 * - East vs West declination
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/heading-conversion.php';

class HeadingConversionTest extends TestCase
{
    /**
     * True = Magnetic + Declination (East positive)
     */
    public function testConvertMagneticToTrue_EastDeclination(): void
    {
        $this->assertSame(14.0, convertMagneticToTrue(0.0, 14.0));
        $this->assertSame(360.0, convertMagneticToTrue(346.0, 14.0));
        $this->assertSame(174.0, convertMagneticToTrue(160.0, 14.0));
    }

    /**
     * True = Magnetic + Declination (West negative)
     */
    public function testConvertMagneticToTrue_WestDeclination(): void
    {
        $this->assertSame(346.0, convertMagneticToTrue(0.0, -14.0));
        $this->assertSame(360.0, convertMagneticToTrue(14.0, -14.0));
        $this->assertSame(146.0, convertMagneticToTrue(160.0, -14.0));
    }

    /**
     * Magnetic = True - Declination
     */
    public function testConvertTrueToMagnetic_EastDeclination(): void
    {
        $this->assertSame(360.0, convertTrueToMagnetic(14.0, 14.0)); // 14° true = 0° mag = 360°
        $this->assertSame(346.0, convertTrueToMagnetic(360.0, 14.0));
        $this->assertSame(160.0, convertTrueToMagnetic(174.0, 14.0));
    }

    /**
     * Round-trip: magnetic → true → magnetic
     */
    public function testRoundTrip_MagneticToTrueToMagnetic(): void
    {
        $magnetic = 270.0;
        $declination = 14.5;
        $true = convertMagneticToTrue($magnetic, $declination);
        $back = convertTrueToMagnetic($true, $declination);
        $this->assertSame($magnetic, $back);
    }

    /**
     * Round-trip: true → magnetic → true
     */
    public function testRoundTrip_TrueToMagneticToTrue(): void
    {
        $true = 284.5;
        $declination = 14.5;
        $magnetic = convertTrueToMagnetic($true, $declination);
        $back = convertMagneticToTrue($magnetic, $declination);
        $this->assertSame($true, $back);
    }

    /**
     * 0° and 360° normalized consistently
     */
    public function testZeroAnd360_Normalized(): void
    {
        $this->assertSame(360.0, convertMagneticToTrue(360.0, 0.0));
        $this->assertSame(360.0, convertTrueToMagnetic(360.0, 0.0));
        $this->assertSame(360.0, convertMagneticToTrue(0.0, 0.0));
        $this->assertSame(360.0, convertTrueToMagnetic(0.0, 0.0));
    }

    /**
     * Zero declination: no change
     */
    public function testZeroDeclination_NoChange(): void
    {
        $this->assertSame(270.0, convertMagneticToTrue(270.0, 0.0));
        $this->assertSame(270.0, convertTrueToMagnetic(270.0, 0.0));
    }

    /**
     * rotatePointTrueToMagnetic: point at true north (0,1) with East declination
     * rotates to align with magnetic north (stays at north)
     */
    public function testRotatePoint_TrueNorth_EastDeclination(): void
    {
        // Point at 14° true (runway 36 with 14°E declination) should rotate to (0, 1) = magnetic north
        $x = sin(14 * M_PI / 180);
        $y = cos(14 * M_PI / 180);
        $rotated = rotatePointTrueToMagnetic($x, $y, 14.0);
        $this->assertEqualsWithDelta(0.0, $rotated['x'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $rotated['y'], 0.0001);
    }

    /**
     * rotatePointTrueToMagnetic: point at true north (0,1) stays north with 0 declination
     */
    public function testRotatePoint_ZeroDeclination_NoChange(): void
    {
        $rotated = rotatePointTrueToMagnetic(0.5, 0.5, 0.0);
        $this->assertSame(0.5, $rotated['x']);
        $this->assertSame(0.5, $rotated['y']);
    }

    /**
     * rotatePointTrueToMagnetic: full segment rotation (runway 16/34 at 56S, declination 20°)
     */
    public function testRotatePoint_RunwaySegment(): void
    {
        // Runway 16: ~160° magnetic = ~180° true with 20°E declination
        // Point at 180° true: (0, -1)
        $rotated = rotatePointTrueToMagnetic(0.0, -1.0, 20.0);
        // Should point at 160° magnetic: sin(160°), cos(160°)
        $expectedX = sin(160 * M_PI / 180);
        $expectedY = cos(160 * M_PI / 180);
        $this->assertEqualsWithDelta($expectedX, $rotated['x'], 0.0001);
        $this->assertEqualsWithDelta($expectedY, $rotated['y'], 0.0001);
    }
}
