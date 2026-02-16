<?php
/**
 * Unit Tests for SwobAutoAdapter
 *
 * Tests SWOB-ML AUTO adapter for Canadian automated weather stations.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/adapter/swob-auto-v1.php';

class SwobAutoAdapterTest extends TestCase
{
    /**
     * Valid station_id returns correct AUTO URL
     */
    public function testBuildUrl_ValidStationId_ReturnsAutoUrl(): void
    {
        $url = SwobAutoAdapter::buildUrl(['station_id' => 'CYAV']);
        $this->assertNotNull($url);
        $this->assertStringContainsString('dd.weather.gc.ca', $url);
        $this->assertStringContainsString('CYAV-AUTO-swob.xml', $url);
    }

    /**
     * Lowercase station_id normalized to uppercase
     */
    public function testBuildUrl_LowercaseStationId_NormalizedToUppercase(): void
    {
        $url = SwobAutoAdapter::buildUrl(['station_id' => 'cyav']);
        $this->assertNotNull($url);
        $this->assertStringContainsString('CYAV-AUTO-swob.xml', $url);
    }

    /**
     * Invalid station_id returns null
     */
    public function testBuildUrl_InvalidStationId_ReturnsNull(): void
    {
        $this->assertNull(SwobAutoAdapter::buildUrl(['station_id' => '']));
        $this->assertNull(SwobAutoAdapter::buildUrl([]));
        $this->assertNull(SwobAutoAdapter::buildUrl(['station_id' => 'evil/../path']));
    }

    /**
     * parseToSnapshot returns valid snapshot from fixture
     */
    public function testParseToSnapshot_ValidFixture_ReturnsSnapshot(): void
    {
        $xml = file_get_contents(__DIR__ . '/../Fixtures/swob-cyav-auto.xml');
        $snapshot = SwobAutoAdapter::parseToSnapshot($xml, ['station_id' => 'CYAV']);
        $this->assertNotNull($snapshot);
        $this->assertEquals('swob_auto', $snapshot->source);
        $this->assertTrue($snapshot->isValid);
        $this->assertEqualsWithDelta(-1.5, $snapshot->temperature->value, 0.01);
        $this->assertEqualsWithDelta(29.72, $snapshot->pressure->value, 0.01);
        $this->assertTrue($snapshot->wind->speed->hasValue());
    }

    /**
     * parseToSnapshot with invalid XML returns empty snapshot
     */
    public function testParseToSnapshot_InvalidXml_ReturnsEmptySnapshot(): void
    {
        $snapshot = SwobAutoAdapter::parseToSnapshot('not xml', ['station_id' => 'CYAV']);
        $this->assertNotNull($snapshot);
        $this->assertFalse($snapshot->isValid);
    }

    /**
     * Adapter constants
     */
    public function testAdapterConstants(): void
    {
        $this->assertEquals('swob_auto', SwobAutoAdapter::getSourceType());
        $this->assertEquals(300, SwobAutoAdapter::getTypicalUpdateFrequency());
        $this->assertEquals(3600, SwobAutoAdapter::getMaxAcceptableAge());
    }
}
