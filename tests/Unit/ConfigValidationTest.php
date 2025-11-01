<?php
/**
 * Unit Tests for Configuration Validation
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config-utils.php';

class ConfigValidationTest extends TestCase
{
    /**
     * Test validateAirportId - Valid IDs
     */
    public function testValidateAirportId_Valid3Character()
    {
        $this->assertTrue(validateAirportId('ksp'));
    }

    public function testValidateAirportId_Valid4Character()
    {
        $this->assertTrue(validateAirportId('kspb'));
    }

    public function testValidateAirportId_ValidUpperCase()
    {
        $this->assertTrue(validateAirportId('KSPB'));  // Should be lowercased
    }

    public function testValidateAirportId_ValidWithNumbers()
    {
        $this->assertTrue(validateAirportId('k12'));
        $this->assertTrue(validateAirportId('kx12'));
    }

    /**
     * Test validateAirportId - Invalid IDs
     */
    public function testValidateAirportId_Empty()
    {
        $this->assertFalse(validateAirportId(''));
        $this->assertFalse(validateAirportId(null));
    }

    public function testValidateAirportId_TooShort()
    {
        $this->assertFalse(validateAirportId('ab'));   // 2 chars
        $this->assertFalse(validateAirportId('a'));     // 1 char
    }

    public function testValidateAirportId_TooLong()
    {
        $this->assertFalse(validateAirportId('toolong'));     // 7 chars
        $this->assertFalse(validateAirportId('toolong123'));   // 10 chars
    }

    public function testValidateAirportId_SpecialCharacters()
    {
        $this->assertFalse(validateAirportId('ks-pb'));   // Hyphen
        $this->assertFalse(validateAirportId('ks_pb'));   // Underscore
        $this->assertFalse(validateAirportId('ks.pb'));   // Period
        $this->assertFalse(validateAirportId('kspb!'));   // Exclamation
    }

    public function testValidateAirportId_Whitespace()
    {
        $this->assertFalse(validateAirportId(' kspb'));   // Leading space
        $this->assertFalse(validateAirportId('kspb '));   // Trailing space
        $this->assertFalse(validateAirportId('ks pb'));   // Space in middle
    }
}

