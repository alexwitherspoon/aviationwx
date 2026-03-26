<?php
/**
 * SAFETY CRITICAL: METAR ICAO completeness — omission of visibility/sky groups must not
 * imply unlimited visibility or VFR ceiling (Annex 3 / WMO code form).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/TestHelper.php';
require_once __DIR__ . '/../../api/weather.php';

class MetarCompletenessSafetyTest extends TestCase
{
    /**
     * Negative: empty visib in JSON without explicit visibility in rawOb must NOT become unlimited.
     */
    public function testParseMETARResponse_EmptyVisibWithoutRawVisibility_IsNullNotUnlimited(): void
    {
        $response = json_encode([[
            'icaoId' => 'KSPB',
            'rawOb' => 'METAR KSPB 252153Z AUTO 10/04 A3021 RMK AO2',
            'temp' => 10.0,
            'dewp' => 4.0,
            'altim' => 1023.1,
            'visib' => '',
        ]]);
        $airport = createTestAirport(['metar_station' => 'KSPB']);
        $result = parseMETARResponse($response, $airport);
        $this->assertIsArray($result);
        $this->assertNull($result['visibility'], 'Empty visib with no SM/CAVOK in raw must not infer unlimited');
        $this->assertFalse($result['visibility_reported']);
    }

    /**
     * Positive: explicit US visibility in rawOb with empty visib still yields reported unlimited (10+ SM).
     */
    public function testParseMETARResponse_EmptyVisibWith10SMInRaw_IsUnlimitedAndReported(): void
    {
        $response = json_encode([[
            'icaoId' => 'KSPB',
            'rawOb' => 'METAR KSPB 252253Z AUTO 31008G19KT 10SM SCT040 BKN110 11/02 A3021',
            'temp' => 11.0,
            'dewp' => 2.0,
            'altim' => 1023.1,
            'visib' => '',
        ]]);
        $airport = createTestAirport(['metar_station' => 'KSPB']);
        $result = parseMETARResponse($response, $airport);
        $this->assertIsArray($result);
        $this->assertSame(10.0, $result['visibility'], '10SM in raw extracts 10 SM, not unlimited sentinel');
        $this->assertTrue($result['visibility_reported']);
    }

    /**
     * Vertical visibility (obscuration): vertVis in NOAA JSON sets ceiling in hundreds of feet.
     */
    public function testParseMETARResponse_VertVisSetsCeiling(): void
    {
        $response = json_encode([[
            'icaoId' => 'KSPB',
            'rawOb' => 'METAR KSPB 251653Z AUTO 00000KT 1/2SM FG VV002 06/05 A3013',
            'temp' => 5.6,
            'dewp' => 5.0,
            'altim' => 1020.4,
            'visib' => '0.5',
            'vertVis' => 2,
            'wxString' => 'FG',
        ]]);
        $airport = createTestAirport(['metar_station' => 'KSPB']);
        $result = parseMETARResponse($response, $airport);
        $this->assertIsArray($result);
        $this->assertSame(200, $result['ceiling'], 'vertVis=2 → 200 ft AGL');
        $this->assertTrue($result['ceiling_reported']);
    }

    /**
     * Flight category: METAR completeness flags — unknown ceiling with good visibility → MVFR (conservative).
     */
    public function testCalculateFlightCategory_MetarUnknownCeilingWithVfrVisibility_IsMVFR(): void
    {
        $weather = [
            'visibility' => 10.0,
            'ceiling' => null,
            'metar_visibility_reported' => true,
            'metar_ceiling_reported' => false,
        ];
        $this->assertSame('MVFR', calculateFlightCategory($weather));
    }

    /**
     * Legacy path (no METAR completeness keys): VFR vis + null ceiling remains VFR.
     */
    public function testCalculateFlightCategory_LegacyNullCeilingWithVfrVisibility_IsVFR(): void
    {
        $weather = ['visibility' => 10.0, 'ceiling' => null];
        $this->assertSame('VFR', calculateFlightCategory($weather));
    }

    /**
     * Both METAR flags false and null vis/ceil → insufficient data.
     */
    public function testCalculateFlightCategory_MetarBothUnreportedNulls_IsNull(): void
    {
        $weather = [
            'visibility' => null,
            'ceiling' => null,
            'metar_visibility_reported' => false,
            'metar_ceiling_reported' => false,
        ];
        $this->assertNull(calculateFlightCategory($weather));
    }

    /**
     * METAR says visibility not reported: ignore any stray numeric (fail-closed).
     */
    public function testCalculateFlightCategory_MetarVisibilityNotReported_IgnoresValue(): void
    {
        $weather = [
            'visibility' => 10.0,
            'ceiling' => null,
            'metar_visibility_reported' => false,
            'metar_ceiling_reported' => false,
        ];
        $this->assertNull(calculateFlightCategory($weather));
    }
}
