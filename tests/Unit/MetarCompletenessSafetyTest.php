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
     * US mixed-number visibility in rawOb (empty visib): "1 1/2SM" must be 1.5 SM, not 1.0 (whole only) or 0.5 (fraction alone).
     */
    public function testParseMETARResponse_EmptyVisibWithOneAndHalfSMInRaw_IsOnePointFiveStatuteMiles(): void
    {
        $response = json_encode([[
            'icaoId' => 'KXXX',
            'rawOb' => 'METAR KXXX 252153Z AUTO 1 1/2SM CLR 10/04 A3021',
            'temp' => 10.0,
            'dewp' => 4.0,
            'altim' => 1023.1,
            'visib' => '',
        ]]);
        $airport = createTestAirport(['metar_station' => 'KXXX']);
        $result = parseMETARResponse($response, $airport);
        $this->assertIsArray($result);
        $this->assertSame(1.5, $result['visibility']);
        $this->assertTrue($result['visibility_reported']);
    }

    /**
     * Mixed number with non-trivial fraction: 2 3/4 SM = 2.75 SM.
     */
    public function testParseMETARResponse_EmptyVisibWithTwoAndThreeQuarterSMInRaw_IsTwoPointSevenFive(): void
    {
        $response = json_encode([[
            'icaoId' => 'KXXX',
            'rawOb' => 'METAR KXXX 252153Z 2 3/4SM FEW025 15/10 A2992',
            'temp' => 15.0,
            'dewp' => 10.0,
            'altim' => 1013.2,
            'visib' => '',
        ]]);
        $airport = createTestAirport(['metar_station' => 'KXXX']);
        $result = parseMETARResponse($response, $airport);
        $this->assertIsArray($result);
        $this->assertSame(2.75, $result['visibility']);
        $this->assertTrue($result['visibility_reported']);
    }

    /**
     * Negative (ordering): plain fraction visibility "1/2SM" without a whole number must remain 0.5 SM, not mixed parsing.
     */
    public function testParseMETARResponse_EmptyVisibWithHalfSMOnlyInRaw_IsPointFive(): void
    {
        $response = json_encode([[
            'icaoId' => 'KXXX',
            'rawOb' => 'METAR KXXX 252153Z 1/2SM FG 06/05 A3013',
            'temp' => 6.0,
            'dewp' => 5.0,
            'altim' => 1020.4,
            'visib' => '',
        ]]);
        $airport = createTestAirport(['metar_station' => 'KXXX']);
        $result = parseMETARResponse($response, $airport);
        $this->assertIsArray($result);
        $this->assertSame(0.5, $result['visibility']);
        $this->assertTrue($result['visibility_reported']);
    }

    /**
     * Vertical visibility (obscuration): vertVis in NOAA JSON sets ceiling in hundreds of feet.
     */
    /**
     * BKN with missing/non-numeric base: no ceiling height → ceiling must not be "reported" as known.
     */
    public function testParseMETARResponse_BknMissingBase_CeilingNotReportedFalse(): void
    {
        $response = json_encode([[
            'icaoId' => 'KXXX',
            'rawOb' => 'METAR KXXX 252153Z 32008KT 10SM BKN/// 11/02 A3021',
            'temp' => 11.0,
            'dewp' => 2.0,
            'altim' => 1023.1,
            'visib' => '10',
            'clouds' => [['cover' => 'BKN', 'base' => null]],
        ]]);
        $airport = createTestAirport(['metar_station' => 'KXXX']);
        $result = parseMETARResponse($response, $airport);
        $this->assertIsArray($result);
        $this->assertNull($result['ceiling']);
        $this->assertFalse($result['ceiling_reported']);
    }

    /**
     * CAVOK: explicit no ceiling restriction without a numeric cloud base in JSON.
     */
    public function testParseMETARResponse_Cavok_CeilingReportedTrue(): void
    {
        $response = json_encode([[
            'icaoId' => 'KXXX',
            'rawOb' => 'METAR KXXX 252153Z 32008KT CAVOK 11/02 A3021',
            'temp' => 11.0,
            'dewp' => 2.0,
            'altim' => 1023.1,
            'visib' => '10',
        ]]);
        $airport = createTestAirport(['metar_station' => 'KXXX']);
        $result = parseMETARResponse($response, $airport);
        $this->assertIsArray($result);
        $this->assertNull($result['ceiling']);
        $this->assertTrue($result['ceiling_reported']);
    }

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
     * Same MVFR rule when _field_source_map shows both fields from METAR.
     */
    public function testCalculateFlightCategory_MetarMvfrWithFieldSourceMap_IsMVFR(): void
    {
        $weather = [
            'visibility' => 10.0,
            'ceiling' => null,
            'metar_visibility_reported' => true,
            'metar_ceiling_reported' => false,
            '_field_source_map' => [
                'visibility' => 'metar',
                'ceiling' => 'metar',
            ],
        ];
        $this->assertSame('MVFR', calculateFlightCategory($weather));
    }

    /**
     * Visibility from a non-METAR source: do not apply METAR-only MVFR even if ceiling METAR flags say not reported.
     */
    public function testCalculateFlightCategory_TempestVisibilityWithMetarCeilingFlags_IsVFR(): void
    {
        $weather = [
            'visibility' => 10.0,
            'ceiling' => null,
            'metar_visibility_reported' => true,
            'metar_ceiling_reported' => false,
            '_field_source_map' => [
                'visibility' => 'tempest',
                'ceiling' => 'metar',
            ],
        ];
        $this->assertSame('VFR', calculateFlightCategory($weather));
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
