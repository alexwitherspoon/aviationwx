<?php
/**
 * Runway end heading resolution and wind-based departure end selection.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/LoadsNasrAptFixtureCacheTrait.php';
require_once __DIR__ . '/../../lib/weather/da-performance-runway-end.php';

class DaPerformanceRunwayEndTest extends TestCase
{
    use LoadsNasrAptFixtureCacheTrait;

    protected function setUp(): void
    {
        $this->loadNasrAptFixtureCache();
    }

    protected function tearDown(): void
    {
        $this->tearDownNasrAptFixtureCache();
    }

    public function testAngularDifferenceWrapsAcrossNorth(): void
    {
        $this->assertEqualsWithDelta(20.0, angularDifference(350.0, 10.0), 0.001);
        $this->assertEqualsWithDelta(0.0, angularDifference(90.0, 90.0), 0.001);
    }

    public function testResolveRunwayEndMagneticHeadingUsesNasrTrueAlignment(): void
    {
        $nasrRecord = getNasrAirportForConfig(['faa' => '69V']);
        $this->assertNotNull($nasrRecord);
        $runway = nasrSelectLongestActiveLandRunway($nasrRecord);
        $this->assertNotNull($runway);

        $end08 = null;
        foreach ($runway['ends'] as $end) {
            if (($end['end_id'] ?? '') === '08') {
                $end08 = $end;
                break;
            }
        }
        $this->assertNotNull($end08);

        $airport = ['faa' => '69V', 'magnetic_declination' => 14.0];
        $heading = resolveRunwayEndMagneticHeading($end08, $runway, $airport);
        $this->assertNotNull($heading);
        $this->assertEqualsWithDelta(
            convertTrueToMagnetic((float) $end08['true_alignment'], 14.0),
            $heading,
            0.5
        );
    }

    public function testResolveRunwayEndMagneticHeadingFallsBackToEndIdent(): void
    {
        $end = ['end_id' => '26'];
        $runway = ['rwy_id' => '08/26', 'length_ft' => 4000, 'surface' => 'ASPH'];
        $heading = resolveRunwayEndMagneticHeading($end, $runway, []);
        $this->assertSame(260.0, $heading);
    }

    public function testResolveRunwayEndMagneticHeadingMatchesCanonicalRunwayNameIdents(): void
    {
        $end = ['end_id' => '09'];
        $runway = [
            'rwy_id' => '9/27',
            'heading_1' => 95.0,
            'heading_2' => 275.0,
            'length_ft' => 3000,
            'surface' => 'ASPH',
        ];

        $this->assertSame(95.0, resolveRunwayEndMagneticHeading($end, $runway, []));
    }

    public function testResolveRunwayEndMagneticHeadingReturnsNullForMalformedIdent(): void
    {
        $end = ['end_id' => 'XX'];
        $runway = ['rwy_id' => '08/26', 'length_ft' => 4000, 'surface' => 'ASPH'];
        $this->assertNull(resolveRunwayEndMagneticHeading($end, $runway, []));
    }

    public function testPickDepartureEndByWindFromMagneticSelectsIntoWindEnd(): void
    {
        $nasrRecord = getNasrAirportForConfig(['faa' => '69V']);
        $this->assertNotNull($nasrRecord);
        $runway = nasrSelectLongestActiveLandRunway($nasrRecord);
        $this->assertNotNull($runway);

        $airport = ['faa' => '69V', 'magnetic_declination' => 14.0];
        $picked = pickDepartureEndByWindFromMagnetic($runway, $airport, 76.0);
        $this->assertNotNull($picked);
        $this->assertSame('08', $picked['end_id']);
    }
}
