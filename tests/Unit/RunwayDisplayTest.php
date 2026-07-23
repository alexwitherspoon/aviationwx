<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/parse.php';
require_once __DIR__ . '/../../lib/nasr/runway-remarks.php';
require_once __DIR__ . '/../../lib/runway-display.php';

class RunwayDisplayTest extends TestCase
{
    public function testSurfaceLabelMapsAsphalt(): void
    {
        $this->assertSame('Asphalt', runwayDisplaySurfaceLabel('ASPH'));
    }

    public function testNasrLightsLabelMapsPeri(): void
    {
        $this->assertSame('On request', nasrRunwayLightsLabel('PERI'));
    }

    public function testParseCalmWindSplitEndsRemark(): void
    {
        $parsed = nasrParseCalmWindDesignationFromRemark(
            'CALM WIND RWY 15 FOR ARRIVALS; RWY 33 FOR DEPARTURES.'
        );
        $this->assertNotNull($parsed);
        $this->assertSame('15', $parsed['arrival']);
        $this->assertSame('33', $parsed['departure']);
    }

    public function testParseCalmWindSingleEndRemark(): void
    {
        $parsed = nasrParseCalmWindDesignationFromRemark(
            'RWY 15 DESIGNATED CALM WIND RWY.'
        );
        $this->assertNotNull($parsed);
        $this->assertSame('15', $parsed['arrival']);
        $this->assertSame('15', $parsed['departure']);
    }

    public function testGetRunwayDisplayReturnsNullWhenNoData(): void
    {
        $this->assertNull(getRunwayDisplayForAirport(['id' => 'nonexistent-strip-xyz'], 'nonexistent-strip-xyz'));
    }
}
