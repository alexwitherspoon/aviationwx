<?php
/**
 * Unit Tests for AwosnetAdapter
 *
 * Safety-critical: AWOSnet station_id is interpolated into URLs and headers.
 * Tests validate injection prevention and config validation.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/adapter/awosnet-v1.php';

class AwosnetAdapterTest extends TestCase
{
    /**
     * Valid station_id returns correct URL
     */
    public function testBuildUrl_ValidStationId_ReturnsUrl(): void
    {
        $url = AwosnetAdapter::buildUrl(['station_id' => 'ks40']);
        $this->assertNotNull($url);
        $this->assertStringContainsString('ks40.awosnet.com', $url);
        $this->assertStringContainsString('awiAwosNet.php', $url);
    }

    /**
     * Valid station_id with uppercase is normalized to lowercase
     */
    public function testBuildUrl_UpperCaseStationId_NormalizedToLowercase(): void
    {
        $url = AwosnetAdapter::buildUrl(['station_id' => 'KS40']);
        $this->assertNotNull($url);
        $this->assertStringContainsString('ks40.awosnet.com', $url);
    }

    /**
     * Invalid: special characters rejected
     */
    public function testBuildUrl_InvalidStationId_SpecialChars_ReturnsNull(): void
    {
        $url = AwosnetAdapter::buildUrl(['station_id' => 'ks40/../evil']);
        $this->assertNull($url);
    }

    /**
     * Invalid: newline/CRLF rejected (header injection)
     */
    public function testBuildUrl_InvalidStationId_Newline_ReturnsNull(): void
    {
        $url = AwosnetAdapter::buildUrl(['station_id' => "ks40\nEvil-Header: value"]);
        $this->assertNull($url);
    }

    /**
     * Invalid: too short (1 char)
     */
    public function testBuildUrl_InvalidStationId_TooShort_ReturnsNull(): void
    {
        $url = AwosnetAdapter::buildUrl(['station_id' => 'k']);
        $this->assertNull($url);
    }

    /**
     * Invalid: too long
     */
    public function testBuildUrl_InvalidStationId_TooLong_ReturnsNull(): void
    {
        $url = AwosnetAdapter::buildUrl(['station_id' => str_repeat('a', 21)]);
        $this->assertNull($url);
    }

    /**
     * Invalid: empty station_id
     */
    public function testBuildUrl_EmptyStationId_ReturnsNull(): void
    {
        $this->assertNull(AwosnetAdapter::buildUrl(['station_id' => '']));
        $this->assertNull(AwosnetAdapter::buildUrl([]));
    }

    /**
     * getHeaders with valid station_id returns Referer
     */
    public function testGetHeaders_ValidStationId_ReturnsReferer(): void
    {
        $headers = AwosnetAdapter::getHeaders(['station_id' => 'ks40']);
        $this->assertIsArray($headers);
        $this->assertStringContainsString('Referer:', implode(' ', $headers));
        $this->assertStringContainsString('ks40.awosnet.com', implode(' ', $headers));
    }

    /**
     * getHeaders with invalid station_id omits Referer (no injection)
     * Defensive: invalid config could reach getHeaders without buildUrl
     */
    public function testGetHeaders_InvalidStationId_OmitsReferer(): void
    {
        $headers = AwosnetAdapter::getHeaders(['station_id' => "ks40\r\nX-Injected: evil"]);
        $headerStr = implode("\n", $headers);
        $this->assertStringNotContainsString('Referer:', $headerStr);
        $this->assertStringNotContainsString('X-Injected', $headerStr);
    }

    /**
     * P6SM in METAR: parseToSnapshot preserves visibility_greater_than in snapshot
     * Safety-critical: P prefix indicates "greater than" semantics for display
     */
    public function testParseToSnapshot_P6SM_PreservesVisibilityGreaterThan(): void
    {
        $response = $this->minimalXmlWithMetar('METAR KSPB 132345Z 09007KT P6SM CLR 05/05 A3003');
        $snapshot = AwosnetAdapter::parseToSnapshot($response, []);
        $this->assertNotNull($snapshot);
        $this->assertEquals(6.0, $snapshot->visibility->value);
        $this->assertTrue($snapshot->visibility->greaterThan, 'P6SM must preserve greaterThan in snapshot');
    }

    /**
     * Plain 6SM: no greater-than flag
     */
    public function testParseToSnapshot_6SM_NoGreaterThan(): void
    {
        $response = $this->minimalXmlWithMetar('METAR KSPB 132345Z 09007KT 6SM CLR 05/05 A3003');
        $snapshot = AwosnetAdapter::parseToSnapshot($response, []);
        $this->assertNotNull($snapshot);
        $this->assertEquals(6.0, $snapshot->visibility->value);
        $this->assertFalse($snapshot->visibility->greaterThan);
    }

    /**
     * Minimal XML response with embedded METAR for parseToSnapshot tests
     * extractMetarFromHtml finds METAR via regex in the raw response string
     */
    private function minimalXmlWithMetar(string $metar): string
    {
        return "<?xml version=\"1.0\"?><response><METAR>$metar</METAR></response>";
    }
}
