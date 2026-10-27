<?php
/**
 * Unit Tests for NwsApiAdapter station validation.
 *
 * The NWS station_id is checked against airport ASOS/AWOS ident patterns before
 * a request is made. K-prefixed FAA small-field AWOS idents may contain digits
 * (for example K4S2 at Ken Jernstedt Airfield), so the CONUS pattern allows
 * alphanumerics while still rejecting mesonets, buoys, and other non-airport
 * stations.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/adapter/nws-api-v1.php';

class NwsApiAdapterTest extends TestCase
{
    public function testIsValidAirportStation_AcceptsUsConusIcAo(): void
    {
        $this->assertTrue(NwsApiAdapter::isValidAirportStation('KJFK'));
        $this->assertTrue(NwsApiAdapter::isValidAirportStation('KSPB'));
    }

    /**
     * Regression: FAA small-field AWOS idents keep the K prefix but may contain digits.
     */
    public function testIsValidAirportStation_AcceptsDigitFaaAwosIdent(): void
    {
        $this->assertTrue(NwsApiAdapter::isValidAirportStation('K4S2'));
    }

    public function testIsValidAirportStation_AcceptsOtherAirportRegions(): void
    {
        $this->assertTrue(NwsApiAdapter::isValidAirportStation('PAFA'));
        $this->assertTrue(NwsApiAdapter::isValidAirportStation('PHNL'));
        $this->assertTrue(NwsApiAdapter::isValidAirportStation('TJSJ'));
        $this->assertTrue(NwsApiAdapter::isValidAirportStation('TIST'));
        $this->assertTrue(NwsApiAdapter::isValidAirportStation('PGUM'));
        $this->assertTrue(NwsApiAdapter::isValidAirportStation('PWAK'));
    }

    public function testIsValidAirportStation_RejectsNonAirportStations(): void
    {
        // 5-digit mesonet identifiers
        $this->assertFalse(NwsApiAdapter::isValidAirportStation('47682'));
        // Too long, too short, or wrong prefix
        $this->assertFalse(NwsApiAdapter::isValidAirportStation('K4S21'));
        $this->assertFalse(NwsApiAdapter::isValidAirportStation('K4'));
        $this->assertFalse(NwsApiAdapter::isValidAirportStation('AB76'));
        $this->assertFalse(NwsApiAdapter::isValidAirportStation(''));
    }
}