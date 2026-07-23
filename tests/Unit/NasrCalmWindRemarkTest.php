<?php
/**
 * Unit tests for NASR APT_RMK calm wind remark parsing.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/parse.php';
require_once __DIR__ . '/../../lib/nasr/runway-remarks.php';

class NasrCalmWindRemarkTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2?: array<string, mixed>}>
     */
    public static function positiveRemarkCasesProvider(): array
    {
        return [
            'split calm wind arr and dep explicit' => [
                'CALM WIND ARR RWY 09; CALM WIND DEP RWY 27',
                ['arrival' => '09', 'departure' => '27'],
            ],
            'split for arrivals and departures semicolon' => [
                'CALM WIND RWY 15 FOR ARRIVALS; RWY 33 FOR DEPARTURES.',
                ['arrival' => '15', 'departure' => '33'],
            ],
            'split arrivals departures calm wind suffix' => [
                'RWY 04 FOR ARRIVALS; RWY 22 FOR DEPARTURES DURING CALM WIND OPS',
                ['arrival' => '04', 'departure' => '22'],
            ],
            'split tkof and landing abbreviations' => [
                'CALM WIND TKOF RWY 6; LND RWY 24.',
                ['arrival' => '24', 'departure' => '06'],
            ],
            'split landing and tkoff inline' => [
                'RWY 03 CALM WIND RWY FOR LNDG; RWY 21 FOR TKOFF.',
                ['arrival' => '03', 'departure' => '21'],
            ],
            'split time qualified arr and dep' => [
                'RWY 35 DSGND CALM WIND RWY FOR ARRS 2200-0700. RWY 17 DSGND CALM WIND RWY FOR DEPS 2200-0700.',
                ['arrival' => '35', 'departure' => '17'],
            ],
            'split arr and dep same runway' => [
                'RWY 11 DSGND CALM WIND RWY FOR ARRS AND DEPS 0300-1100Z.',
                ['arrival' => '11', 'departure' => '11'],
            ],
            'rwy n calm wind rwy production spb' => [
                'RWY 15 CALM WIND RWY.',
                ['arrival' => '15', 'departure' => '15'],
            ],
            'rwy n calm wind rwy with trailing note' => [
                'RWY 21 CALM WIND RWY. EXP WS & TURB WHEN SFC WINDS EXCEED 10 KT.',
                ['arrival' => '21', 'departure' => '21'],
            ],
            'rwy n is calm wind rwy' => [
                'RWY 24 IS CALM WIND RWY.',
                ['arrival' => '24', 'departure' => '24'],
            ],
            'rwy n dsgnd calm wind rwy' => [
                'RWY 19 DSGND CALM WIND RWY.',
                ['arrival' => '19', 'departure' => '19'],
            ],
            'rwy n designated calm wind rwy' => [
                'RWY 14 DESIGNATED CALM WIND RWY.',
                ['arrival' => '14', 'departure' => '14'],
            ],
            'ry abbreviation is calm wind ry' => [
                'RY 23 IS CALM WIND RY.',
                ['arrival' => '23', 'departure' => '23'],
            ],
            'ry abbreviation calm wind ry' => [
                'RY 30 CALM WIND RY.',
                ['arrival' => '30', 'departure' => '30'],
            ],
            'ry designated calm wind ry' => [
                'RY 13R DESIGNATED CALM WIND RY.',
                ['arrival' => '13R', 'departure' => '13R'],
            ],
            'ry preferred calm wind ry' => [
                'RY 30 PREFERRED CALM WIND RY.',
                ['arrival' => '30', 'departure' => '30'],
            ],
            'calm wind rwy n trailing period' => [
                'CALM WIND RWY 29.',
                ['arrival' => '29', 'departure' => '29'],
            ],
            'calm wind ry n' => [
                'CALM WIND RY 18.',
                ['arrival' => '18', 'departure' => '18'],
            ],
            'calm wind use rwy n' => [
                'CALM WIND USE RWY 34.',
                ['arrival' => '34', 'departure' => '34'],
            ],
            'calm wind use ry n' => [
                'CALM WIND USE RY 26.',
                ['arrival' => '26', 'departure' => '26'],
            ],
            'calm wind rwy is rwy n' => [
                'CALM WIND RWY IS RWY 35.',
                ['arrival' => '35', 'departure' => '35'],
            ],
            'preferred calm wind rwy n' => [
                'PREFERRED CALM WIND RWY 22.',
                ['arrival' => '22', 'departure' => '22'],
            ],
            'less than knots use rwy n' => [
                'CALM WIND LESS THAN 8 KNOTS USE RWY 30.',
                ['arrival' => '30', 'departure' => '30'],
            ],
            'designated as calm wind ry' => [
                'RY 35 DESIGNATED AS CALM WIND RY.',
                ['arrival' => '35', 'departure' => '35'],
            ],
            'will be designated calm wind ry' => [
                'RY 31 WILL BE THE DESIGNATED CALM WIND RY (WIND 5 KNOTS OR LESS).',
                ['arrival' => '31', 'departure' => '31'],
            ],
            'when atct closed qualifier' => [
                'RWY 14 IS CALM WIND RWY WHEN ATCT CLSD.',
                ['arrival' => '14', 'departure' => '14'],
            ],
            'calm wind landing rwy only arrival' => [
                'CALM WIND LNDG RWY 04.',
                ['arrival' => '04'],
            ],
            'pref calm wind rwy for tkof departure only' => [
                'PREF CALM WIND RWY 25 FOR TKOF WITH A RP FOR NOISE ABATEMENT.',
                ['departure' => '25'],
            ],
            'preferred direction is rwy n' => [
                'CALM WIND PREFERRED DRCTN IS RWY 34.',
                ['arrival' => '34', 'departure' => '34'],
            ],
            'left padded runway ident' => [
                'RWY 04L CALM WIND RWY.',
                ['arrival' => '04L', 'departure' => '04L'],
            ],
            'embedded trailing calm wind rwy n' => [
                'DIRT PARKING EAST SIDE OF RWY AT MIDFIELD. CALM WIND RWY 17',
                ['arrival' => '17', 'departure' => '17'],
            ],
            'awos threshold qualifier suffix' => [
                'RY 30 CALM WIND RY WHEN WIND REPORTED BY AWOS LESS THAN 5 KNOTS.',
                ['arrival' => '30', 'departure' => '30'],
            ],
            'rwy n calm wind without trailing rwy word' => [
                'RWY 32 1% DOWN / RWY 14 CALM WIND',
                ['arrival' => '14', 'departure' => '14'],
            ],
            'pref calm wind rwy use rwy n' => [
                'PREF CALM WIND RWY USE RWY 08.',
                ['arrival' => '08', 'departure' => '08'],
            ],
            'drg calm winds use rwy n' => [
                'DRG CALM WINDS USE RWY 17.',
                ['arrival' => '17', 'departure' => '17'],
            ],
            'rwy n pref calm wind rwy' => [
                'RWY 21 PREF CALM WIND RWY; STRAIGHT-IN APCH NOT RCMDD.',
                ['arrival' => '21', 'departure' => '21'],
            ],
            'ry preferred calm wind runway' => [
                'RY 16 PREFERRED CALM WIND RUNWAY.',
                ['arrival' => '16', 'departure' => '16'],
            ],
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, string>}>
     */
    public static function positiveRowCasesProvider(): array
    {
        return [
            'bare calm wind rwy with runway end context' => [
                [
                    'ARPT_ID' => 'MSL',
                    'REF_COL_NAME' => 'RWY_END_ID',
                    'ELEMENT' => '30',
                    'REF_COL_SEQ_NO' => '1',
                    'REMARK' => 'CALM WIND RWY.',
                ],
                ['arrival' => '30', 'departure' => '30'],
            ],
            'bare calm wind ry abbreviation with context' => [
                [
                    'ARPT_ID' => 'TEST',
                    'REF_COL_NAME' => 'RWY_END_ID',
                    'ELEMENT' => '17',
                    'REF_COL_SEQ_NO' => '1',
                    'REMARK' => 'CALM WIND RY.',
                ],
                ['arrival' => '17', 'departure' => '17'],
            ],
            'pref dep calm wind rwy with runway end context' => [
                [
                    'ARPT_ID' => 'VNC',
                    'REF_COL_NAME' => 'RWY_END_ID',
                    'ELEMENT' => '23',
                    'REF_COL_SEQ_NO' => '1',
                    'REMARK' => 'PREF DEP CALM WIND RWY.',
                ],
                ['departure' => '23'],
            ],
            'remark parse preferred when text includes runway number' => [
                [
                    'ARPT_ID' => 'SPB',
                    'REF_COL_NAME' => 'RWY_END_ID',
                    'ELEMENT' => '99',
                    'REF_COL_SEQ_NO' => '1',
                    'REMARK' => 'RWY 15 CALM WIND RWY.',
                ],
                ['arrival' => '15', 'departure' => '15'],
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1?: array<string, mixed>}>
     */
    public static function negativeCasesProvider(): array
    {
        return [
            'empty remark' => [''],
            'no calm wind phrase' => ['RWY 15 PREFERRED FOR NIGHT OPS.'],
            'directional preference without runway' => [
                'CALM WIND PREFERRED TKOF/LNDG TO THE NORTH WHEN POSSIBLE.',
            ],
            'parallel runway pair slash' => ['RY 25L/25R CALM WIND RUNWAY.'],
            'parallel runway pair ampersand' => [
                'RWY 06L & 06R CALM WIND RWY; AFTN WIND MAY FAVOR RWY 24L & 24R.',
            ],
            'bare calm wind rwy without row context' => ['CALM WIND RWY.'],
            'invalid runway number' => ['RWY 99 CALM WIND RWY.'],
            'crosswind conditions not designation' => [
                'ACFT TKOF/LNDG FM RY 03 DURING CALM WIND/CROSSWIND CONDS; DURING ANY QUESTIONABLE PERIOD RY 03 SHALL',
            ],
            'preferred dep without calm wind designation' => [
                'RWY 10 IS THE PREFERRED DEP RWY DURG CALM WINDS.',
            ],
            'preferred rwy in calm wind conditions not designation' => [
                'RWY 18 IS THE PREFERRED RWY IN CALM WIND CONDITIONS.',
            ],
            'bare calm wind row missing runway end id' => [
                'CALM WIND RWY.',
                ['REF_COL_NAME' => 'GENERAL_REMARK', 'REF_COL_SEQ_NO' => ''],
            ],
        ];
    }

    #[DataProvider('positiveRemarkCasesProvider')]
    public function testParseCalmWindDesignationFromRemark_PositiveCases(
        string $remark,
        array $expected
    ): void {
        $parsed = nasrParseCalmWindDesignationFromRemark($remark);
        $this->assertNotNull($parsed, 'Expected remark to parse: ' . $remark);
        $this->assertSame($expected, $parsed);
    }

    #[DataProvider('positiveRowCasesProvider')]
    public function testParseCalmWindDesignationFromAptRmkRow_PositiveCases(
        array $row,
        array $expected
    ): void {
        $parsed = nasrParseCalmWindDesignationFromAptRmkRow($row);
        $this->assertNotNull($parsed, 'Expected row to parse');
        $this->assertSame($expected, $parsed);
    }

    #[DataProvider('negativeCasesProvider')]
    public function testParseCalmWindDesignation_NegativeCases(
        string $remark,
        array $context = []
    ): void {
        $this->assertNull(nasrParseCalmWindDesignationFromRemark($remark, $context));

        $row = array_merge([
            'ARPT_ID' => 'TEST',
            'REF_COL_NAME' => $context['REF_COL_NAME'] ?? '',
            'REF_COL_SEQ_NO' => $context['REF_COL_SEQ_NO'] ?? '',
            'REMARK' => $remark,
        ], $context);
        $this->assertNull(nasrParseCalmWindDesignationFromAptRmkRow($row));
    }

    public function testAttachCalmWindRemarksFromAptRmk_MergesMultipleRowsWithoutOverwriting(): void
    {
        $airports = [
            'TEST' => ['arpt_id' => 'TEST', 'runways' => []],
        ];

        $path = sys_get_temp_dir() . '/aviationwx-apt-rmk-test-' . getmypid() . '.csv';
        $csv = implode("\n", [
            '"EFF_DATE","SITE_NO","SITE_TYPE_CODE","STATE_CODE","ARPT_ID","CITY","COUNTRY_CODE","LEGACY_ELEMENT_NUMBER","TAB_NAME","REF_COL_NAME","ELEMENT","REF_COL_SEQ_NO","REMARK"',
            '"2026/07/09","1.","A","OR","TEST","TEST","US","A1","RUNWAY","RWY_ID","15/33","1","CALM WIND LNDG RWY 15."',
            '"2026/07/09","1.","A","OR","TEST","TEST","US","A2","RUNWAY_END","RWY_END_ID","33","1","PREF DEP CALM WIND RWY."',
        ]);
        file_put_contents($path, $csv . "\n");

        try {
            nasrAttachCalmWindRemarksFromAptRmk($airports, $path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(
            ['arrival' => '15', 'departure' => '33'],
            $airports['TEST']['calm_wind'] ?? null
        );
    }
}
