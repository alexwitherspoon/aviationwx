<?php
/**
 * Unit Tests for SwobManAdapter
 *
 * Tests SWOB-ML MAN adapter for Canadian manned weather stations.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/adapter/swob-man-v1.php';

class SwobManAdapterTest extends TestCase
{
    /**
     * Valid station_id returns correct MAN URL
     */
    public function testBuildUrl_ValidStationId_ReturnsManUrl(): void
    {
        $url = SwobManAdapter::buildUrl(['station_id' => 'CYVR']);
        $this->assertNotNull($url);
        $this->assertStringContainsString('dd.weather.gc.ca', $url);
        $this->assertStringContainsString('CYVR-MAN-swob.xml', $url);
    }

    /**
     * Lowercase station_id normalized to uppercase
     */
    public function testBuildUrl_LowercaseStationId_NormalizedToUppercase(): void
    {
        $url = SwobManAdapter::buildUrl(['station_id' => 'cyyz']);
        $this->assertNotNull($url);
        $this->assertStringContainsString('CYYZ-MAN-swob.xml', $url);
    }

    /**
     * Invalid station_id returns null
     */
    public function testBuildUrl_InvalidStationId_ReturnsNull(): void
    {
        $this->assertNull(SwobManAdapter::buildUrl(['station_id' => '']));
        $this->assertNull(SwobManAdapter::buildUrl([]));
    }

    /**
     * parseToSnapshot returns valid snapshot from AUTO fixture (same XML structure)
     */
    public function testParseToSnapshot_ValidFixture_ReturnsSnapshot(): void
    {
        $xml = file_get_contents(__DIR__ . '/../Fixtures/swob-cyav-auto.xml');
        $snapshot = SwobManAdapter::parseToSnapshot($xml, ['station_id' => 'CYVR']);
        $this->assertNotNull($snapshot);
        $this->assertEquals('swob_man', $snapshot->source);
        $this->assertTrue($snapshot->isValid);
    }

    /**
     * Adapter constants
     */
    public function testAdapterConstants(): void
    {
        $this->assertEquals('swob_man', SwobManAdapter::getSourceType());
        $this->assertEquals(300, SwobManAdapter::getTypicalUpdateFrequency());
        $this->assertEquals(3600, SwobManAdapter::getMaxAcceptableAge());
    }
}
