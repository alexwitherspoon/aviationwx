<?php
/**
 * Unit tests for internal NOTAM map layer API access control.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/map-api-access.php';

class NotamMapApiAccessTest extends TestCase {
    public function testReferer_AirportsSubdomain(): void {
        $this->assertTrue(
            notamMapLayerApiRefererIsTrustedMapPage('https://airports.example.org/map', 'example.org')
        );
    }

    public function testReferer_ApexPathAirports(): void {
        $this->assertTrue(
            notamMapLayerApiRefererIsTrustedMapPage('https://example.org/airports', 'example.org')
        );
    }

    public function testReferer_LocalhostPathAirports(): void {
        $this->assertTrue(
            notamMapLayerApiRefererIsTrustedMapPage('http://localhost:8080/airports', 'example.org')
        );
    }

    public function testReferer_RejectWrongPathOnApex(): void {
        $this->assertFalse(
            notamMapLayerApiRefererIsTrustedMapPage('https://example.org/', 'example.org')
        );
    }

    public function testReferer_RejectExternalHost(): void {
        $this->assertFalse(
            notamMapLayerApiRefererIsTrustedMapPage('https://evil.example/airports', 'example.org')
        );
    }

    public function testReferer_RejectEmpty(): void {
        $this->assertFalse(notamMapLayerApiRefererIsTrustedMapPage('', 'example.org'));
    }

    public function testHost_AllowsAirportsSubdomain(): void {
        $this->assertTrue(notamMapLayerApiRequestHostIsAllowedForMapLayerJson('airports.example.org', 'example.org'));
    }

    public function testHost_AllowsApexAndWww(): void {
        $this->assertTrue(notamMapLayerApiRequestHostIsAllowedForMapLayerJson('example.org', 'example.org'));
        $this->assertTrue(notamMapLayerApiRequestHostIsAllowedForMapLayerJson('www.example.org', 'example.org'));
    }

    public function testHost_AllowsLocalhost(): void {
        $this->assertTrue(notamMapLayerApiRequestHostIsAllowedForMapLayerJson('localhost:8080', 'example.org'));
    }

    public function testHost_RejectsAirportDashboardSubdomain(): void {
        $this->assertFalse(notamMapLayerApiRequestHostIsAllowedForMapLayerJson('kspb.example.org', 'example.org'));
    }

    public function testHost_RejectsEmbedSubdomain(): void {
        $this->assertFalse(notamMapLayerApiRequestHostIsAllowedForMapLayerJson('embed.example.org', 'example.org'));
    }
}
