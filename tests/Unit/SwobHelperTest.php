<?php
/**
 * Unit Tests for SWOB XML Helper
 *
 * Tests parseSwobXmlToWeatherArray for Canadian ECCC SWOB-ML parsing.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/adapter/swob-helper.php';

class SwobHelperTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        $this->fixturePath = __DIR__ . '/../Fixtures/swob-cyav-auto.xml';
    }

    /**
     * Valid CYAV fixture parses to expected weather array
     */
    public function testParseSwobXmlToWeatherArray_ValidFixture_ReturnsExpectedValues(): void
    {
        $xml = file_get_contents($this->fixturePath);
        $this->assertNotFalse($xml, 'Fixture must exist');

        $result = parseSwobXmlToWeatherArray($xml);
        $this->assertNotNull($result);
        $this->assertIsArray($result);

        // Temperature -1.5°C
        $this->assertEqualsWithDelta(-1.5, $result['temperature'] ?? null, 0.01);
        // Dewpoint -3.5°C
        $this->assertEqualsWithDelta(-3.5, $result['dewpoint'] ?? null, 0.01);
        // Humidity 86%
        $this->assertEquals(86, $result['humidity'] ?? null);
        // Altimeter 29.72 inHg
        $this->assertEqualsWithDelta(29.72, $result['pressure'] ?? null, 0.01);
        // Wind dir 299°, speed 14.8 km/h → ~7.99 kt
        $this->assertEquals(299, $result['wind_direction'] ?? null);
        $this->assertEqualsWithDelta(14.8 / 1.852, $result['wind_speed'] ?? null, 0.1);
        // Gust MSNG → null
        $this->assertNull($result['gust_speed'] ?? null);
        // Visibility MSNG → null
        $this->assertNull($result['visibility'] ?? null);
        // date_tm parsed to obs_time
        $this->assertArrayHasKey('obs_time', $result);
        $this->assertIsInt($result['obs_time']);
        $this->assertEquals(strtotime('2026-02-16T01:00:00.000Z'), $result['obs_time']);
    }

    /**
     * MSNG values are treated as null
     */
    public function testParseSwobXmlToWeatherArray_MsngValues_ReturnNull(): void
    {
        $xml = file_get_contents($this->fixturePath);
        $result = parseSwobXmlToWeatherArray($xml);
        $this->assertNotNull($result);
        $this->assertNull($result['visibility'] ?? null);
        $this->assertNull($result['gust_speed'] ?? null);
    }

    /**
     * Invalid XML returns null
     */
    public function testParseSwobXmlToWeatherArray_InvalidXml_ReturnsNull(): void
    {
        $this->assertNull(parseSwobXmlToWeatherArray('not xml'));
        $this->assertNull(parseSwobXmlToWeatherArray('<broken>'));
        $this->assertNull(parseSwobXmlToWeatherArray(''));
    }

    /**
     * Cloud code 2700 mapping: 0→SKC, 1-2→FEW, 3-4→SCT, 5-7→BKN, 8→OVC
     */
    public function testParseSwobXmlToWeatherArray_CloudCodeMapping(): void
    {
        $base = file_get_contents($this->fixturePath);
        $tests = [
            ['code' => '0', 'expected' => 'SKC'],
            ['code' => '1', 'expected' => 'FEW'],
            ['code' => '2', 'expected' => 'FEW'],
            ['code' => '3', 'expected' => 'SCT'],
            ['code' => '4', 'expected' => 'SCT'],
            ['code' => '5', 'expected' => 'BKN'],
            ['code' => '6', 'expected' => 'BKN'],
            ['code' => '7', 'expected' => 'BKN'],
            ['code' => '8', 'expected' => 'OVC'],
        ];

        foreach ($tests as $t) {
            $cloudEl = '<element name="cld_amt_code_1" uom="code" value="' . $t['code'] . '"/>';
            $insert = str_replace('</elements>', $cloudEl . '</elements>', $base);
            $result = parseSwobXmlToWeatherArray($insert);
            $this->assertNotNull($result, "Cloud code {$t['code']} should parse");
            $this->assertEquals($t['expected'], $result['cloud_cover'] ?? null, "Code {$t['code']} → {$t['expected']}");
        }
    }

    /**
     * Visibility km converted to statute miles
     */
    public function testParseSwobXmlToWeatherArray_VisibilityKm_ConvertedToStatuteMiles(): void
    {
        $base = file_get_contents($this->fixturePath);
        $xml = str_replace(
            'name="avg_vis_pst10mts" uom="km" value="MSNG"',
            'name="avg_vis_pst10mts" uom="km" value="10"',
            $base
        );
        $result = parseSwobXmlToWeatherArray($xml);
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(10 * 0.621371, $result['visibility'] ?? null, 0.01);
    }

    /**
     * Cloud height m converted to feet
     */
    public function testParseSwobXmlToWeatherArray_CloudHeightM_ConvertedToFeet(): void
    {
        $base = file_get_contents($this->fixturePath);
        $cloudEl = '<element name="cld_bas_hgt_1" uom="m" value="1000"/>';
        $cloudCode = '<element name="cld_amt_code_1" uom="code" value="6"/>';
        $insert = str_replace('</elements>', $cloudEl . $cloudCode . '</elements>', $base);
        $result = parseSwobXmlToWeatherArray($insert);
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(1000 * 3.28084, $result['ceiling'] ?? null, 1);
    }
}
