<?php
/**
 * Unit Tests for Configuration Validation
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';

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

    /**
     * Test partners array validation - Valid configurations
     */
    public function testPartnersArray_ValidWithLogo()
    {
        $config = [
            'kspb' => [
                'name' => 'Test Airport',
                'partners' => [
                    [
                        'name' => 'Test Partner',
                        'url' => 'https://example.com/partner',
                        'logo' => 'https://example.com/logo.png',
                        'description' => 'Test description'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid partners array with logo should pass validation');
    }

    public function testPartnersArray_ValidWithoutLogo()
    {
        $config = [
            'kspb' => [
                'name' => 'Test Airport',
                'partners' => [
                    [
                        'name' => 'Test Partner',
                        'url' => 'https://example.com/partner'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid partners array without logo should pass validation');
    }

    public function testPartnersArray_EmptyArray()
    {
        $config = [
            'kspb' => [
                'name' => 'Test Airport',
                'partners' => []
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Empty partners array should be valid');
    }

    public function testPartnersArray_MissingName()
    {
        $config = [
            'kspb' => [
                'name' => 'Test Airport',
                'partners' => [
                    [
                        'url' => 'https://example.com/partner'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Partner missing name should fail validation');
        $this->assertStringContainsString('name', implode(' ', $result['errors']));
    }

    public function testPartnersArray_EmptyName()
    {
        $config = [
            'kspb' => [
                'name' => 'Test Airport',
                'partners' => [
                    [
                        'name' => '',
                        'url' => 'https://example.com/partner'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Partner with empty name should fail validation');
    }

    public function testPartnersArray_MissingUrl()
    {
        $config = [
            'kspb' => [
                'name' => 'Test Airport',
                'partners' => [
                    [
                        'name' => 'Test Partner'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Partner missing url should fail validation');
        $this->assertStringContainsString('url', implode(' ', $result['errors']));
    }

    public function testPartnersArray_InvalidUrl()
    {
        $config = [
            'kspb' => [
                'name' => 'Test Airport',
                'partners' => [
                    [
                        'name' => 'Test Partner',
                        'url' => 'not-a-valid-url'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Partner with invalid url should fail validation');
    }

    public function testPartnersArray_InvalidLogoUrl()
    {
        $config = [
            'kspb' => [
                'name' => 'Test Airport',
                'partners' => [
                    [
                        'name' => 'Test Partner',
                        'url' => 'https://example.com/partner',
                        'logo' => 'not-a-valid-url'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Partner with invalid logo url should fail validation');
    }

    public function testPartnersArray_NotAnArray()
    {
        $config = [
            'kspb' => [
                'name' => 'Test Airport',
                'partners' => 'not-an-array'
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Partners that is not an array should fail validation');
    }

    public function testPartnersArray_InvalidDescription()
    {
        $config = [
            'kspb' => [
                'name' => 'Test Airport',
                'partners' => [
                    [
                        'name' => 'Test Partner',
                        'url' => 'https://example.com/partner',
                        'description' => 123  // Should be string
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Partner with non-string description should fail validation');
    }
}

