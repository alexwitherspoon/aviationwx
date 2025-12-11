<?php
/**
 * Unit Tests for Address Formatter
 * Tests address parsing and envelope-style formatting
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/address-formatter.php';

class AddressFormatterTest extends TestCase
{
    /**
     * Test formatAddressEnvelope() - Simple City, State
     */
    public function testFormatAddressEnvelope_CityState_ReturnsFormattedAddress()
    {
        $address = 'Portland, Oregon';
        $result = formatAddressEnvelope($address);
        $this->assertStringContainsString('Portland', $result);
        $this->assertStringContainsString('Oregon', $result);
        // City, State on one line is correct for envelope format
        $this->assertNotEmpty($result);
    }

    /**
     * Test formatAddressEnvelope() - City, State ZIP
     */
    public function testFormatAddressEnvelope_CityStateZip_ReturnsFormattedAddress()
    {
        $address = 'Portland, OR 97201';
        $result = formatAddressEnvelope($address);
        $this->assertStringContainsString('Portland', $result);
        $this->assertStringContainsString('OR', $result);
        $this->assertStringContainsString('97201', $result);
    }

    /**
     * Test formatAddressEnvelope() - Street, City, State
     */
    public function testFormatAddressEnvelope_StreetCityState_ReturnsFormattedAddress()
    {
        $address = '123 Main St, Portland, Oregon';
        $result = formatAddressEnvelope($address);
        $this->assertStringContainsString('123 Main St', $result);
        $this->assertStringContainsString('Portland', $result);
        $this->assertStringContainsString('Oregon', $result);
        // Should have line break between street and city/state
        $this->assertGreaterThanOrEqual(1, substr_count($result, '<br>'));
    }

    /**
     * Test formatAddressEnvelope() - Street, City, State ZIP
     */
    public function testFormatAddressEnvelope_StreetCityStateZip_ReturnsFormattedAddress()
    {
        $address = '123 Main St, Portland, OR 97201';
        $result = formatAddressEnvelope($address);
        $this->assertStringContainsString('123 Main St', $result);
        $this->assertStringContainsString('Portland', $result);
        $this->assertStringContainsString('OR', $result);
        $this->assertStringContainsString('97201', $result);
    }

    /**
     * Test formatAddressEnvelope() - Street, City, State, Country
     */
    public function testFormatAddressEnvelope_WithCountry_ReturnsFormattedAddress()
    {
        $address = '123 Main St, Portland, OR 97201, Canada';
        $result = formatAddressEnvelope($address);
        $this->assertStringContainsString('123 Main St', $result);
        $this->assertStringContainsString('Portland', $result);
        $this->assertStringContainsString('OR', $result);
        $this->assertStringContainsString('97201', $result);
        $this->assertStringContainsString('Canada', $result);
    }

    /**
     * Test formatAddressEnvelope() - USA country excluded
     */
    public function testFormatAddressEnvelope_USACountry_ExcludesCountry()
    {
        $address = '123 Main St, Portland, OR 97201, USA';
        $result = formatAddressEnvelope($address);
        $this->assertStringNotContainsString('USA', $result);
        $this->assertStringNotContainsString('US', $result);
    }

    /**
     * Test formatAddressEnvelope() - Empty string
     */
    public function testFormatAddressEnvelope_EmptyString_ReturnsEmptyString()
    {
        $result = formatAddressEnvelope('');
        $this->assertEquals('', $result);
    }

    /**
     * Test formatAddressEnvelope() - Whitespace only
     */
    public function testFormatAddressEnvelope_WhitespaceOnly_ReturnsEmptyString()
    {
        $result = formatAddressEnvelope('   ');
        $this->assertEquals('', $result);
    }

    /**
     * Test formatAddressEnvelope() - HTML escaping
     */
    public function testFormatAddressEnvelope_WithSpecialChars_EscapesHtml()
    {
        $address = "123 Main St, O'Fallon, MO 63368";
        $result = formatAddressEnvelope($address);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('O\'Fallon', $result);
        $this->assertStringContainsString('O&#039;Fallon', $result);
    }

    /**
     * Test formatAddressEnvelope() - ZIP with extension
     */
    public function testFormatAddressEnvelope_ZipWithExtension_HandlesCorrectly()
    {
        $address = 'Portland, OR 97201-1234';
        $result = formatAddressEnvelope($address);
        $this->assertStringContainsString('97201-1234', $result);
    }

    /**
     * Test parseAddressComponents() - Simple City, State
     */
    public function testParseAddressComponents_CityState_ParsesCorrectly()
    {
        $result = parseAddressComponents('Portland, Oregon');
        $this->assertEquals('Portland', $result['city']);
        $this->assertEquals('Oregon', $result['state']);
        $this->assertNull($result['street']);
        $this->assertNull($result['zip']);
    }

    /**
     * Test parseAddressComponents() - City, State ZIP
     */
    public function testParseAddressComponents_CityStateZip_ParsesCorrectly()
    {
        $result = parseAddressComponents('Portland, OR 97201');
        $this->assertEquals('Portland', $result['city']);
        $this->assertEquals('OR', $result['state']);
        $this->assertEquals('97201', $result['zip']);
        $this->assertNull($result['street']);
    }

    /**
     * Test parseAddressComponents() - Street, City, State
     */
    public function testParseAddressComponents_StreetCityState_ParsesCorrectly()
    {
        $result = parseAddressComponents('123 Main St, Portland, Oregon');
        $this->assertEquals('123 Main St', $result['street']);
        $this->assertEquals('Portland', $result['city']);
        $this->assertEquals('Oregon', $result['state']);
        $this->assertNull($result['zip']);
    }

    /**
     * Test parseAddressComponents() - Street, City, State ZIP
     */
    public function testParseAddressComponents_StreetCityStateZip_ParsesCorrectly()
    {
        $result = parseAddressComponents('123 Main St, Portland, OR 97201');
        $this->assertEquals('123 Main St', $result['street']);
        $this->assertEquals('Portland', $result['city']);
        $this->assertEquals('OR', $result['state']);
        $this->assertEquals('97201', $result['zip']);
    }

    /**
     * Test parseAddressComponents() - With Country
     */
    public function testParseAddressComponents_WithCountry_ParsesCorrectly()
    {
        $result = parseAddressComponents('123 Main St, Portland, OR 97201, Canada');
        $this->assertEquals('123 Main St', $result['street']);
        $this->assertEquals('Portland', $result['city']);
        $this->assertEquals('OR', $result['state']);
        $this->assertEquals('97201', $result['zip']);
        $this->assertEquals('Canada', $result['country']);
    }

    /**
     * Test parseAddressComponents() - Complex multi-part address
     */
    public function testParseAddressComponents_ComplexAddress_ParsesCorrectly()
    {
        $address = '123 Main St, Suite 100, Portland, OR 97201, USA';
        $result = parseAddressComponents($address);
        $this->assertNotNull($result['street']);
        $this->assertStringContainsString('123 Main St', $result['street']);
        $this->assertEquals('Portland', $result['city']);
        $this->assertEquals('OR', $result['state']);
        $this->assertEquals('97201', $result['zip']);
    }

    /**
     * Test parseStateZip() - State and ZIP
     */
    public function testParseStateZip_StateAndZip_ParsesCorrectly()
    {
        $result = parseStateZip('OR 97201');
        $this->assertEquals('OR', $result['state']);
        $this->assertEquals('97201', $result['zip']);
    }

    /**
     * Test parseStateZip() - State only
     */
    public function testParseStateZip_StateOnly_ParsesCorrectly()
    {
        $result = parseStateZip('Oregon');
        $this->assertEquals('Oregon', $result['state']);
        $this->assertNull($result['zip']);
    }

    /**
     * Test parseStateZip() - ZIP only
     */
    public function testParseStateZip_ZipOnly_ParsesCorrectly()
    {
        $result = parseStateZip('97201');
        $this->assertNull($result['state']);
        $this->assertEquals('97201', $result['zip']);
    }

    /**
     * Test parseStateZip() - ZIP with extension
     */
    public function testParseStateZip_ZipWithExtension_ParsesCorrectly()
    {
        $result = parseStateZip('OR 97201-1234');
        $this->assertEquals('OR', $result['state']);
        $this->assertEquals('97201-1234', $result['zip']);
    }

    /**
     * Test parseStateZip() - State name with ZIP
     */
    public function testParseStateZip_StateNameWithZip_ParsesCorrectly()
    {
        $result = parseStateZip('Oregon 97201');
        $this->assertEquals('Oregon', $result['state']);
        $this->assertEquals('97201', $result['zip']);
    }

    /**
     * Test formatAddressEnvelope() - Real-world Google Maps format
     */
    public function testFormatAddressEnvelope_RealWorldFormat_HandlesCorrectly()
    {
        $address = 'Scappoose, Oregon';
        $result = formatAddressEnvelope($address);
        $this->assertStringContainsString('Scappoose', $result);
        $this->assertStringContainsString('Oregon', $result);
        $this->assertNotEmpty($result);
    }

    /**
     * Test formatAddressEnvelope() - Fallback for unparseable address
     */
    public function testFormatAddressEnvelope_UnparseableAddress_ReturnsSanitizedOriginal()
    {
        $address = 'Some unusual format that cannot be parsed';
        $result = formatAddressEnvelope($address);
        $this->assertNotEmpty($result);
        $this->assertStringNotContainsString('<script>', $result);
    }
}
