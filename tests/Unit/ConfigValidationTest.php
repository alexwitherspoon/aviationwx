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
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'partners' => [
                        [
                            'name' => 'Test Partner',
                            'url' => 'https://example.com/partner',
                            'logo' => 'https://example.com/logo.png',
                            'description' => 'Test description'
                        ]
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
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'partners' => [
                        [
                            'name' => 'Test Partner',
                            'url' => 'https://example.com/partner'
                        ]
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
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'partners' => []
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Empty partners array should be valid');
    }

    public function testPartnersArray_MissingName()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'partners' => [
                        [
                            'url' => 'https://example.com/partner'
                        ]
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
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'partners' => [
                        [
                            'name' => '',
                            'url' => 'https://example.com/partner'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Partner with empty name should fail validation');
        $this->assertStringContainsString('name', implode(' ', $result['errors']));
    }

    public function testPartnersArray_MissingUrl()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'partners' => [
                        [
                            'name' => 'Test Partner'
                        ]
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
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'partners' => [
                        [
                            'name' => 'Test Partner',
                            'url' => 'not-a-valid-url'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Partner with invalid url should fail validation');
        $this->assertStringContainsString('url', implode(' ', $result['errors']));
    }

    public function testPartnersArray_InvalidLogoUrl()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'partners' => [
                        [
                            'name' => 'Test Partner',
                            'url' => 'https://example.com/partner',
                            'logo' => 'not-a-valid-url'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Partner with invalid logo url should fail validation');
        $this->assertStringContainsString('logo', implode(' ', $result['errors']));
    }

    public function testPartnersArray_NotAnArray()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'partners' => 'not-an-array'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Partners that is not an array should fail validation');
        $this->assertStringContainsString('partners must be an array', implode(' ', $result['errors']));
    }

    public function testPartnersArray_InvalidDescription()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'partners' => [
                        [
                            'name' => 'Test Partner',
                            'url' => 'https://example.com/partner',
                            'description' => 123  // Should be string
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Partner with non-string description should fail validation');
        $this->assertStringContainsString('description', implode(' ', $result['errors']));
    }

    /**
     * Test services validation - Valid configurations
     */
    public function testServices_ValidWithFuelAndRepairs()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'services' => [
                        'fuel' => '100LL, Jet-A',
                        'repairs_available' => true
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid services with fuel string and repairs boolean should pass validation');
    }

    public function testServices_ValidWithFuelOnly()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'services' => [
                        'fuel' => '100LL'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid services with fuel only should pass validation');
    }

    public function testServices_ValidWithRepairsOnly()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'services' => [
                        'repairs_available' => false
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid services with repairs only should pass validation');
    }

    public function testServices_RejectsUnknownFields()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'services' => [
                        'fuel' => '100LL',
                        'repairs_available' => true,
                        'future_service' => 'some value'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Services with unknown fields should fail validation');
        $this->assertStringContainsString("unknown field 'future_service'", implode(' ', $result['errors']));
    }

    public function testServices_RejectsFuelAvailable()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'services' => [
                        'fuel' => '100LL, Jet-A',
                        'fuel_available' => true
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Services with fuel_available should fail validation');
        $this->assertStringContainsString("unknown field 'fuel_available'", implode(' ', $result['errors']));
    }

    public function testServices_Rejects100ll()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'services' => [
                        'fuel' => '100LL',
                        '100ll' => true
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Services with 100ll should fail validation');
        $this->assertStringContainsString("unknown field '100ll'", implode(' ', $result['errors']));
    }

    public function testServices_RejectsJetA()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'services' => [
                        'fuel' => 'Jet-A',
                        'jet_a' => true
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Services with jet_a should fail validation');
        $this->assertStringContainsString("unknown field 'jet_a'", implode(' ', $result['errors']));
    }

    public function testServices_EmptyObject()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'services' => []
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Empty services object should be valid');
    }

    /**
     * Test services validation - Invalid configurations
     */
    public function testServices_InvalidFuelType()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'services' => [
                        'fuel' => true  // Should be string
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Services with non-string fuel should fail validation');
        $this->assertStringContainsString("service 'fuel' must be a string", implode(' ', $result['errors']));
    }

    public function testServices_InvalidRepairsType()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'services' => [
                        'repairs_available' => 'yes'  // Should be boolean
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Services with non-boolean repairs_available should fail validation');
        $this->assertStringContainsString("service 'repairs_available' must be a boolean", implode(' ', $result['errors']));
    }

    public function testServices_NotAnObject()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'services' => 'not-an-object'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Services that is not an object should fail validation');
        $this->assertStringContainsString('services must be an object', implode(' ', $result['errors']));
    }

    /**
     * Test webcam validation - Reject unknown fields for pull cameras
     */
    public function testWebcam_RejectsPositionForPullCamera()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Test Camera',
                            'url' => 'https://example.com/cam.jpg',
                            'position' => 'north'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Pull camera with position field should fail validation');
        $this->assertStringContainsString("unknown field 'position'", implode(' ', $result['errors']));
    }

    public function testWebcam_RejectsUsernameForPullCamera()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Test Camera',
                            'url' => 'https://example.com/cam.jpg',
                            'username' => 'admin'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Pull camera with username field should fail validation');
        $this->assertStringContainsString("unknown field 'username'", implode(' ', $result['errors']));
    }

    public function testWebcam_RejectsPasswordForPullCamera()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Test Camera',
                            'url' => 'https://example.com/cam.jpg',
                            'password' => 'secret'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Pull camera with password field should fail validation');
        $this->assertStringContainsString("unknown field 'password'", implode(' ', $result['errors']));
    }

    /**
     * Test airport structure validation - Root level
     */
    public function testAirportStructure_MissingAirportsKey()
    {
        $config = [
            'config' => [
                'default_timezone' => 'UTC'
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Config missing airports key should fail validation');
        $this->assertStringContainsString("Missing 'airports' key", implode(' ', $result['errors']));
    }

    public function testAirportStructure_AirportsNotAnArray()
    {
        $config = [
            'airports' => 'not-an-array'
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Airports that is not an array should fail validation');
        $this->assertStringContainsString("'airports' must be an object", implode(' ', $result['errors']));
    }

    /**
     * Test airport structure validation - Required fields
     */
    public function testAirportStructure_MissingName()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Airport missing name should fail validation');
        $this->assertStringContainsString("missing required field: 'name'", implode(' ', $result['errors']));
    }

    public function testAirportStructure_MissingLat()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Airport missing lat should fail validation');
        $this->assertStringContainsString("missing required field: 'lat'", implode(' ', $result['errors']));
    }

    public function testAirportStructure_MissingLon()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Airport missing lon should fail validation');
        $this->assertStringContainsString("missing required field: 'lon'", implode(' ', $result['errors']));
    }

    public function testAirportStructure_MissingAccessType()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'tower_status' => 'non_towered'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Airport missing access_type should fail validation');
        $this->assertStringContainsString("missing required field: 'access_type'", implode(' ', $result['errors']));
    }

    public function testAirportStructure_MissingTowerStatus()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'access_type' => 'public'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Airport missing tower_status should fail validation');
        $this->assertStringContainsString("missing required field: 'tower_status'", implode(' ', $result['errors']));
    }

    public function testAirportStructure_InvalidAccessType()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'access_type' => 'invalid',
                    'tower_status' => 'non_towered'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Airport with invalid access_type should fail validation');
        $this->assertStringContainsString("invalid access_type", implode(' ', $result['errors']));
    }

    public function testAirportStructure_InvalidTowerStatus()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'access_type' => 'public',
                    'tower_status' => 'invalid'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Airport with invalid tower_status should fail validation');
        $this->assertStringContainsString("invalid tower_status", implode(' ', $result['errors']));
    }

    public function testAirportStructure_PrivateMissingPermissionRequired()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'access_type' => 'private',
                    'tower_status' => 'non_towered'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Private airport missing permission_required should fail validation');
        $this->assertStringContainsString("permission_required field set", implode(' ', $result['errors']));
    }

    public function testAirportStructure_PermissionRequiredWithPublicAccess()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'access_type' => 'public',
                    'tower_status' => 'non_towered',
                    'permission_required' => true
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Public airport with permission_required should fail validation');
        $this->assertStringContainsString("permission_required set but access_type is not 'private'", implode(' ', $result['errors']));
    }

    public function testAirportStructure_ValidPublicNonTowered()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'access_type' => 'public',
                    'tower_status' => 'non_towered'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid public non-towered airport should pass validation');
    }

    public function testAirportStructure_ValidPrivateWithPermission()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'access_type' => 'private',
                    'permission_required' => true,
                    'tower_status' => 'non_towered'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid private airport with permission required should pass validation');
    }

    public function testAirportStructure_ValidPrivateWithoutPermission()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'access_type' => 'private',
                    'permission_required' => false,
                    'tower_status' => 'towered'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid private airport without permission required should pass validation');
    }

    public function testAirportStructure_InvalidLatitude()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 91.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Airport with invalid latitude should fail validation');
        $this->assertStringContainsString('invalid latitude', implode(' ', $result['errors']));
    }

    public function testAirportStructure_InvalidLongitude()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => 181.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Airport with invalid longitude should fail validation');
        $this->assertStringContainsString('invalid longitude', implode(' ', $result['errors']));
    }

    public function testAirportStructure_InvalidLatitudeType()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 'not-a-number',
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Airport with non-numeric latitude should fail validation');
        $this->assertStringContainsString('invalid latitude', implode(' ', $result['errors']));
    }

    /**
     * Test webcam validation - Valid configurations
     */
    public function testWebcam_ValidPullCamera()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Test Camera',
                            'url' => 'https://example.com/cam.jpg'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid pull camera should pass validation');
    }

    public function testWebcam_ValidPullCameraWithType()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Test Camera',
                            'url' => 'https://example.com/cam.jpg',
                            'type' => 'mjpeg',
                            'refresh_seconds' => 60
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid pull camera with type should pass validation');
    }

    public function testWebcam_ValidRtspCamera()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'RTSP Camera',
                            'type' => 'rtsp',
                            'url' => 'rtsp://camera.example.com:554/stream',
                            'rtsp_transport' => 'tcp',
                            'refresh_seconds' => 60
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid RTSP camera should pass validation');
    }

    public function testWebcam_ValidPushCamera()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Push Camera',
                            'type' => 'push',
                            'refresh_seconds' => 60,
                            'push_config' => [
                                'protocol' => 'sftp',
                                'username' => 'kspbCam0Push01',
                                'password' => 'SecurePass1234',
                                'max_file_size_mb' => 25,
                                'allowed_extensions' => ['jpg', 'jpeg']
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid push camera should pass validation');
    }

    /**
     * Test webcam validation - Invalid configurations
     */
    public function testWebcam_MissingUrlForPullCamera()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Test Camera'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Pull camera missing url should fail validation');
        $this->assertStringContainsString("missing required 'url' field", implode(' ', $result['errors']));
    }

    public function testWebcam_InvalidUrl()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Test Camera',
                            'url' => 'not-a-valid-url'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Webcam with invalid url should fail validation');
        $this->assertStringContainsString('invalid url', implode(' ', $result['errors']));
    }

    public function testWebcam_InvalidRefreshSeconds()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Test Camera',
                            'url' => 'https://example.com/cam.jpg',
                            'refresh_seconds' => -1
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Webcam with invalid refresh_seconds should fail validation');
        $this->assertStringContainsString('refresh_seconds must be positive integer', implode(' ', $result['errors']));
    }

    public function testWebcam_RtspMissingTransport()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'RTSP Camera',
                            'type' => 'rtsp',
                            'url' => 'rtsp://camera.example.com:554/stream'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'RTSP camera missing rtsp_transport should fail validation');
        $this->assertStringContainsString("missing 'rtsp_transport' field", implode(' ', $result['errors']));
    }

    public function testWebcam_RtspMissingUrl()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'RTSP Camera',
                            'type' => 'rtsp',
                            'rtsp_transport' => 'tcp'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'RTSP camera missing url should fail validation');
        $this->assertStringContainsString("missing 'url' field", implode(' ', $result['errors']));
    }

    public function testWebcam_RtspRejectsUnknownFields()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'RTSP Camera',
                            'type' => 'rtsp',
                            'url' => 'rtsp://camera.example.com:554/stream',
                            'rtsp_transport' => 'tcp',
                            'unknown_field' => 'value'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'RTSP camera with unknown field should fail validation');
        $this->assertStringContainsString("unknown field 'unknown_field'", implode(' ', $result['errors']));
    }

    public function testWebcam_PushMissingConfig()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Push Camera',
                            'type' => 'push'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Push camera missing push_config should fail validation');
        $this->assertStringContainsString("missing 'push_config'", implode(' ', $result['errors']));
    }

    public function testWebcam_PushRejectsUnknownFields()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Push Camera',
                            'type' => 'push',
                            'push_config' => [
                                'protocol' => 'sftp',
                                'username' => 'kspbCam0Push01',
                                'password' => 'SecurePass1234',
                                'max_file_size_mb' => 25,
                                'allowed_extensions' => ['jpg', 'jpeg']
                            ],
                            'unknown_field' => 'value'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Push camera with unknown field should fail validation');
        $this->assertStringContainsString("unknown field 'unknown_field'", implode(' ', $result['errors']));
    }

    public function testWebcam_NotAnArray()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => 'not-an-array'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Webcams that is not an array should fail validation');
        $this->assertStringContainsString('webcams must be an array', implode(' ', $result['errors']));
    }

    /**
     * Test weather source validation - Valid configurations
     */
    public function testWeatherSource_ValidTempest()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'tempest',
                        'station_id' => '149918',
                        'api_key' => 'test-api-key'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid tempest weather source should pass validation');
    }

    public function testWeatherSource_ValidAmbient()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'ambient',
                        'api_key' => 'test-api-key',
                        'application_key' => 'test-app-key'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid ambient weather source should pass validation');
    }

    public function testWeatherSource_ValidAmbientWithMacAddress()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'ambient',
                        'api_key' => 'test-api-key',
                        'application_key' => 'test-app-key',
                        'mac_address' => 'AA:BB:CC:DD:EE:FF'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid ambient weather source with mac_address should pass validation');
    }

    public function testWeatherSource_ValidWeatherlink()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'weatherlink',
                        'api_key' => 'test-api-key',
                        'api_secret' => 'test-api-secret',
                        'station_id' => '123456'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid weatherlink weather source should pass validation');
    }

    public function testWeatherSource_ValidPwsweather()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'pwsweather',
                        'station_id' => 'KORSCAPP1',
                        'client_id' => 'test-client-id',
                        'client_secret' => 'test-client-secret'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid pwsweather weather source should pass validation');
    }

    public function testWeatherSource_ValidMetar()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'metar'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid metar weather source should pass validation');
    }

    public function testWeatherSource_ValidSynopticdata()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'synopticdata',
                        'station_id' => 'AT297',
                        'api_token' => 'test-api-token'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid synopticdata weather source should pass validation');
    }

    /**
     * Test weather source validation - Invalid configurations
     */
    public function testWeatherSource_InvalidType()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'invalid-type'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Weather source with invalid type should fail validation');
        $this->assertStringContainsString('invalid type', implode(' ', $result['errors']));
    }

    public function testWeatherSource_MissingType()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'station_id' => '149918'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Weather source missing type should fail validation');
        $this->assertStringContainsString("missing 'type' field", implode(' ', $result['errors']));
    }

    public function testWeatherSource_TempestMissingStationId()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'tempest',
                        'api_key' => 'test-api-key'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Tempest missing station_id should fail validation');
        $this->assertStringContainsString("missing 'station_id'", implode(' ', $result['errors']));
    }

    public function testWeatherSource_TempestMissingApiKey()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'tempest',
                        'station_id' => '149918'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Tempest missing api_key should fail validation');
        $this->assertStringContainsString("missing 'api_key'", implode(' ', $result['errors']));
    }

    public function testWeatherSource_AmbientMissingApiKey()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'ambient',
                        'application_key' => 'test-app-key'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Ambient missing api_key should fail validation');
        $this->assertStringContainsString("missing 'api_key'", implode(' ', $result['errors']));
    }

    public function testWeatherSource_AmbientMissingApplicationKey()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'ambient',
                        'api_key' => 'test-api-key'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Ambient missing application_key should fail validation');
        $this->assertStringContainsString("missing 'application_key'", implode(' ', $result['errors']));
    }

    public function testWeatherSource_WeatherlinkMissingApiKey()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'weatherlink',
                        'api_secret' => 'test-secret',
                        'station_id' => '123456'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Weatherlink missing api_key should fail validation');
        $this->assertStringContainsString("missing 'api_key'", implode(' ', $result['errors']));
    }

    public function testWeatherSource_WeatherlinkMissingApiSecret()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'weatherlink',
                        'api_key' => 'test-api-key',
                        'station_id' => '123456'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Weatherlink missing api_secret should fail validation');
        $this->assertStringContainsString("missing 'api_secret'", implode(' ', $result['errors']));
    }

    public function testWeatherSource_WeatherlinkMissingStationId()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'weatherlink',
                        'api_key' => 'test-api-key',
                        'api_secret' => 'test-secret'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Weatherlink missing station_id should fail validation');
        $this->assertStringContainsString("missing 'station_id'", implode(' ', $result['errors']));
    }

    public function testWeatherSource_PwsweatherMissingStationId()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'pwsweather',
                        'client_id' => 'test-client-id',
                        'client_secret' => 'test-client-secret'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Pwsweather missing station_id should fail validation');
        $this->assertStringContainsString("missing 'station_id'", implode(' ', $result['errors']));
    }

    public function testWeatherSource_PwsweatherMissingClientId()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'pwsweather',
                        'station_id' => 'KORSCAPP1',
                        'client_secret' => 'test-client-secret'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Pwsweather missing client_id should fail validation');
        $this->assertStringContainsString("missing 'client_id'", implode(' ', $result['errors']));
    }

    public function testWeatherSource_PwsweatherMissingClientSecret()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'pwsweather',
                        'station_id' => 'KORSCAPP1',
                        'client_id' => 'test-client-id'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Pwsweather missing client_secret should fail validation');
        $this->assertStringContainsString("missing 'client_secret'", implode(' ', $result['errors']));
    }

    public function testWeatherSource_SynopticdataMissingStationId()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'synopticdata',
                        'api_token' => 'test-api-token'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Synopticdata missing station_id should fail validation');
        $this->assertStringContainsString("missing 'station_id'", implode(' ', $result['errors']));
    }

    public function testWeatherSource_SynopticdataMissingApiToken()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => [
                        'type' => 'synopticdata',
                        'station_id' => 'AT297'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Synopticdata missing api_token should fail validation');
        $this->assertStringContainsString("missing 'api_token'", implode(' ', $result['errors']));
    }

    public function testWeatherSource_NotAnObject()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'weather_source' => 'not-an-object'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Weather source that is not an object should fail validation');
        $this->assertStringContainsString('weather_source must be an object', implode(' ', $result['errors']));
    }

    /**
     * Test push config validation - Valid configurations
     */
    public function testPushConfig_ValidSftp()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Push Camera',
                            'type' => 'push',
                            'push_config' => [
                                'protocol' => 'sftp',
                                'username' => 'kspbCam0Push01',
                                'password' => 'SecurePass1234',
                                'max_file_size_mb' => 25,
                                'allowed_extensions' => ['jpg', 'jpeg']
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid SFTP push config should pass validation');
    }

    public function testPushConfig_ValidWithPort()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Push Camera',
                            'type' => 'push',
                            'push_config' => [
                                'protocol' => 'ftps',
                                'username' => 'kspbCam0Push01',
                                'password' => 'SecurePass1234',
                                'port' => 2121,
                                'max_file_size_mb' => 25,
                                'allowed_extensions' => ['jpg', 'jpeg']
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid push config with port should pass validation');
    }

    /**
     * Test push config validation - Invalid configurations
     */
    public function testPushConfig_MissingProtocol()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Push Camera',
                            'type' => 'push',
                            'push_config' => [
                                'username' => 'kspbCam0Push01',
                                'password' => 'SecurePass1234',
                                'max_file_size_mb' => 25,
                                'allowed_extensions' => ['jpg', 'jpeg']
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Push config missing protocol should fail validation');
        $this->assertStringContainsString("missing 'protocol'", implode(' ', $result['errors']));
    }

    public function testPushConfig_MissingUsername()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Push Camera',
                            'type' => 'push',
                            'push_config' => [
                                'protocol' => 'sftp',
                                'password' => 'SecurePass1234',
                                'max_file_size_mb' => 25,
                                'allowed_extensions' => ['jpg', 'jpeg']
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Push config missing username should fail validation');
        $this->assertStringContainsString("missing 'username'", implode(' ', $result['errors']));
    }

    public function testPushConfig_MissingPassword()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Push Camera',
                            'type' => 'push',
                            'push_config' => [
                                'protocol' => 'sftp',
                                'username' => 'kspbCam0Push01',
                                'max_file_size_mb' => 25,
                                'allowed_extensions' => ['jpg', 'jpeg']
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Push config missing password should fail validation');
        $this->assertStringContainsString("missing 'password'", implode(' ', $result['errors']));
    }

    public function testPushConfig_InvalidPort()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Push Camera',
                            'type' => 'push',
                            'push_config' => [
                                'protocol' => 'sftp',
                                'username' => 'kspbCam0Push01',
                                'password' => 'SecurePass1234',
                                'port' => 70000,
                                'max_file_size_mb' => 25,
                                'allowed_extensions' => ['jpg', 'jpeg']
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Push config with invalid port should fail validation');
        $this->assertStringContainsString('port must be 1-65535', implode(' ', $result['errors']));
    }

    public function testPushConfig_InvalidMaxFileSize()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Push Camera',
                            'type' => 'push',
                            'push_config' => [
                                'protocol' => 'sftp',
                                'username' => 'kspbCam0Push01',
                                'password' => 'SecurePass1234',
                                'max_file_size_mb' => 0,
                                'allowed_extensions' => ['jpg', 'jpeg']
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Push config with invalid max_file_size_mb should fail validation');
        $this->assertStringContainsString('max_file_size_mb must be positive integer', implode(' ', $result['errors']));
    }

    public function testPushConfig_InvalidAllowedExtensions()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Push Camera',
                            'type' => 'push',
                            'push_config' => [
                                'protocol' => 'sftp',
                                'username' => 'kspbCam0Push01',
                                'password' => 'SecurePass1234',
                                'max_file_size_mb' => 25,
                                'allowed_extensions' => 'not-an-array'
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Push config with invalid allowed_extensions should fail validation');
        $this->assertStringContainsString('allowed_extensions must be an array', implode(' ', $result['errors']));
    }

    public function testPushConfig_NotAnObject()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcams' => [
                        [
                            'name' => 'Push Camera',
                            'type' => 'push',
                            'push_config' => 'not-an-object'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Push config that is not an object should fail validation');
        $this->assertStringContainsString('push_config must be an object', implode(' ', $result['errors']));
    }


    /**
     * Test runways validation - Valid configurations
     */
    public function testRunways_ValidConfiguration()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'runways' => [
                        [
                            'name' => '15/33',
                            'heading_1' => 152,
                            'heading_2' => 332
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid runway configuration should pass validation');
    }

    public function testRunways_InvalidHeading1()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'runways' => [
                        [
                            'name' => '15/33',
                            'heading_1' => 361,
                            'heading_2' => 332
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Runway with invalid heading_1 should fail validation');
        $this->assertStringContainsString('invalid heading_1', implode(' ', $result['errors']));
    }

    public function testRunways_MissingName()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'runways' => [
                        [
                            'heading_1' => 152,
                            'heading_2' => 332
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Runway missing name should fail validation');
        $this->assertStringContainsString("missing or invalid 'name' field", implode(' ', $result['errors']));
    }

    public function testRunways_NotAnArray()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'runways' => 'not-an-array'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Runways that is not an array should fail validation');
        $this->assertStringContainsString('runways must be an array', implode(' ', $result['errors']));
    }

    public function testRunways_InvalidHeading2()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'runways' => [
                        [
                            'name' => '15/33',
                            'heading_1' => 152,
                            'heading_2' => 361
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Runway with invalid heading_2 should fail validation');
        $this->assertStringContainsString('invalid heading_2', implode(' ', $result['errors']));
    }

    public function testRunways_NegativeHeading()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'runways' => [
                        [
                            'name' => '15/33',
                            'heading_1' => -1,
                            'heading_2' => 332
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Runway with negative heading should fail validation');
        $this->assertStringContainsString('invalid heading_1', implode(' ', $result['errors']));
    }

    /**
     * Test frequencies validation - Valid configurations
     */
    public function testFrequencies_ValidConfiguration()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'frequencies' => [
                        'ctaf' => '122.8',
                        'asos' => '135.875'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid frequency configuration should pass validation');
    }

    public function testFrequencies_InvalidFrequency()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'frequencies' => [
                        'ctaf' => '100.0'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Frequency out of range should fail validation');
        $this->assertStringContainsString('invalid value', implode(' ', $result['errors']));
    }

    public function testFrequencies_NotAnObject()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'frequencies' => 'not-an-object'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Frequencies that is not an object should fail validation');
        $this->assertStringContainsString('frequencies must be an object', implode(' ', $result['errors']));
    }

    public function testFrequencies_TooHigh()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'frequencies' => [
                        'ctaf' => '137.0'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Frequency too high should fail validation');
        $this->assertStringContainsString('invalid value', implode(' ', $result['errors']));
    }

    public function testFrequencies_TooLow()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'frequencies' => [
                        'ctaf' => '117.0'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Frequency too low should fail validation');
        $this->assertStringContainsString('invalid value', implode(' ', $result['errors']));
    }

    /**
     * Test links validation - Valid configurations
     */
    public function testLinks_ValidConfiguration()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'links' => [
                        [
                            'label' => 'Airport Website',
                            'url' => 'https://example.com/airport'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid links configuration should pass validation');
    }

    public function testLinks_InvalidUrl()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'links' => [
                        [
                            'label' => 'Airport Website',
                            'url' => 'not-a-valid-url'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Link with invalid url should fail validation');
        $this->assertStringContainsString('invalid url', implode(' ', $result['errors']));
    }

    public function testLinks_NotAnArray()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'links' => 'not-an-array'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Links that is not an array should fail validation');
        $this->assertStringContainsString('links must be an array', implode(' ', $result['errors']));
    }

    public function testLinks_MissingLabel()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'links' => [
                        [
                            'url' => 'https://example.com/airport'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Link missing label should fail validation');
        $this->assertStringContainsString("missing or invalid 'label' field", implode(' ', $result['errors']));
    }

    public function testLinks_MissingUrl()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'links' => [
                        [
                            'label' => 'Airport Website'
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Link missing url should fail validation');
        $this->assertStringContainsString("missing 'url' field", implode(' ', $result['errors']));
    }

    /**
     * Test global config validation - Valid configurations
     */
    public function testGlobalConfig_ValidConfiguration()
    {
        $config = [
            'config' => [
                'default_timezone' => 'UTC',
                'base_domain' => 'aviationwx.org',
                'dead_man_switch_days' => 7,
                'force_cleanup' => false,
                'stuck_client_cleanup' => false,
                'stale_warning_seconds' => 600,
                'stale_error_seconds' => 3600,
                'stale_failclosed_seconds' => 10800,
                'webcam_refresh_default' => 60,
                'weather_refresh_default' => 60
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid global config should pass validation');
    }

    /**
     * Test that legacy max_stale_hours field is ignored (replaced by 3-tier staleness model)
     * The validator should NOT fail on unknown fields - they're simply ignored.
     */
    public function testGlobalConfig_LegacyMaxStaleHoursIgnored()
    {
        $config = [
            'config' => [
                'max_stale_hours' => -1  // Invalid value, but should be ignored
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        // Legacy field is ignored, not validated - config should pass
        $this->assertTrue($result['valid'], 'Legacy max_stale_hours should be ignored (replaced by 3-tier staleness model)');
    }

    public function testGlobalConfig_InvalidBaseDomain()
    {
        $config = [
            'config' => [
                'base_domain' => 'not-a-domain'
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Global config with invalid base_domain should fail validation');
        $this->assertStringContainsString('base_domain must be a valid domain string', implode(' ', $result['errors']));
    }

    public function testGlobalConfig_InvalidWebcamRefreshDefault()
    {
        $config = [
            'config' => [
                'webcam_refresh_default' => 0
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Global config with invalid webcam_refresh_default should fail validation');
        $this->assertStringContainsString('webcam_refresh_default must be a positive integer', implode(' ', $result['errors']));
    }

    public function testGlobalConfig_InvalidWeatherRefreshDefault()
    {
        $config = [
            'config' => [
                'weather_refresh_default' => -1
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Global config with invalid weather_refresh_default should fail validation');
        $this->assertStringContainsString('weather_refresh_default must be a positive integer', implode(' ', $result['errors']));
    }

    /**
     * Test format generation flags validation - Valid booleans
     */
    public function testGlobalConfig_FormatFlags_ValidBooleans()
    {
        $config = [
            'config' => [
                'webcam_generate_webp' => true,
                'webcam_generate_avif' => false
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid boolean flags should pass validation');
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test format generation flags validation - Invalid types
     */
    public function testGlobalConfig_FormatFlags_InvalidTypes()
    {
        $config = [
            'config' => [
                'webcam_generate_webp' => 'true',  // String, not boolean
                'webcam_generate_avif' => 1        // Integer, not boolean
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Invalid type flags should fail validation');
        $this->assertCount(2, $result['errors']);
        $this->assertStringContainsString('webcam_generate_webp must be a boolean', implode(' ', $result['errors']));
        $this->assertStringContainsString('webcam_generate_avif must be a boolean', implode(' ', $result['errors']));
    }

    /**
     * Test format generation flags validation - Optional (can be omitted)
     */
    public function testGlobalConfig_FormatFlags_Optional()
    {
        $config = [
            'config' => [
                // Format flags omitted - should default to false
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Omitted format flags should pass validation (defaults to false)');
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test format generation flags validation - Both true
     */
    public function testGlobalConfig_FormatFlags_BothTrue()
    {
        $config = [
            'config' => [
                'webcam_generate_webp' => true,
                'webcam_generate_avif' => true
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Both flags true should pass validation');
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test format generation flags validation - Both false
     */
    public function testGlobalConfig_FormatFlags_BothFalse()
    {
        $config = [
            'config' => [
                'webcam_generate_webp' => false,
                'webcam_generate_avif' => false
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Both flags false should pass validation');
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test webcam history config validation - Valid settings
     * 
     * Note: webcam_history_enabled is deprecated. History is enabled when max_frames >= 2.
     */
    public function testGlobalConfig_WebcamHistory_ValidSettings()
    {
        $config = [
            'config' => [
                'webcam_history_max_frames' => 24  // >= 2 enables history
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid webcam history settings should pass validation');
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test webcam history config validation - Invalid types
     */
    public function testGlobalConfig_WebcamHistory_InvalidTypes()
    {
        $config = [
            'config' => [
                'webcam_history_max_frames' => '12'  // String, not integer
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Invalid type webcam history settings should fail validation');
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('webcam_history_max_frames must be a positive integer', implode(' ', $result['errors']));
    }

    /**
     * Test webcam history config validation - Invalid max_frames (zero or negative)
     */
    public function testGlobalConfig_WebcamHistory_InvalidMaxFrames()
    {
        $config = [
            'config' => [
                'webcam_history_max_frames' => 0  // Must be positive
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Zero max_frames should fail validation');
        $this->assertStringContainsString('webcam_history_max_frames must be a positive integer', implode(' ', $result['errors']));
    }

    /**
     * Test per-airport webcam history settings - Valid overrides
     * 
     * Note: webcam_history_enabled is deprecated. Use max_frames >= 2 to enable.
     */
    public function testAirport_WebcamHistory_ValidOverrides()
    {
        $config = [
            'config' => [
                'webcam_history_max_frames' => 12  // Global default
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcam_history_max_frames' => 48  // Per-airport override
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid per-airport webcam history overrides should pass validation');
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test per-airport webcam history settings - Invalid types
     */
    public function testAirport_WebcamHistory_InvalidTypes()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'webcam_history_max_frames' => -5  // Negative
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Invalid per-airport webcam history settings should fail validation');
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('webcam_history_max_frames', implode(' ', $result['errors']));
    }

    public function testGlobalConfig_InvalidDefaultTimezone()
    {
        $config = [
            'config' => [
                'default_timezone' => 123
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Global config with invalid default_timezone should fail validation');
        $this->assertStringContainsString('default_timezone must be a string', implode(' ', $result['errors']));
    }

    /**
     * Test metar_station validation - Valid ICAO codes
     * 
     * METAR stations must be 4-character ICAO codes:
     * - Standard ICAO: 4 uppercase letters (e.g., KSEA, EGLL)
     * - US pseudo-ICAO: K + 3 alphanumeric (e.g., K56S for small US airports)
     */
    public function testMetarStation_ValidStandardIcao()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'metar_station' => 'KSEA'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Standard 4-letter ICAO code should pass validation');
    }

    public function testMetarStation_ValidInternationalIcao()
    {
        $config = [
            'airports' => [
                'egll' => [
                    'name' => 'London Heathrow',
                    'lat' => 51.47,
                    'lon' => -0.46,
                    'metar_station' => 'EGLL'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'International 4-letter ICAO code should pass validation');
    }

    public function testMetarStation_ValidUsPseudoIcao()
    {
        $config = [
            'airports' => [
                'k56s' => [
                    'name' => 'Seaside Municipal Airport',
                    'lat' => 46.0,
                    'lon' => -123.9,
                    'metar_station' => 'K56S'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'US pseudo-ICAO code (K + alphanumeric) should pass validation');
    }

    public function testMetarStation_ValidUsPseudoIcaoStartingWithNumber()
    {
        $config = [
            'airports' => [
                'k03s' => [
                    'name' => 'Small Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'metar_station' => 'K03S'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'US pseudo-ICAO code starting with number should pass validation');
    }

    /**
     * Test metar_station validation - Invalid codes
     */
    public function testMetarStation_InvalidFaaIdentifier()
    {
        $config = [
            'airports' => [
                'test' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'metar_station' => '56S'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'FAA identifier without K prefix should fail validation');
        $this->assertStringContainsString('FAA identifier', implode(' ', $result['errors']));
        $this->assertStringContainsString('K56S', implode(' ', $result['errors']));
    }

    public function testMetarStation_InvalidIataCode()
    {
        $config = [
            'airports' => [
                'test' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'metar_station' => 'SEA'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'IATA code (3 letters) should fail validation');
        $this->assertStringContainsString('IATA code', implode(' ', $result['errors']));
    }

    public function testMetarStation_InvalidTooShort()
    {
        $config = [
            'airports' => [
                'test' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'metar_station' => 'KS'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], '2-character code should fail validation');
        $this->assertStringContainsString('not a valid ICAO code', implode(' ', $result['errors']));
    }

    public function testMetarStation_InvalidTooLong()
    {
        $config = [
            'airports' => [
                'test' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'metar_station' => 'KSEAA'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], '5-character code should fail validation');
        $this->assertStringContainsString('not a valid ICAO code', implode(' ', $result['errors']));
    }

    public function testMetarStation_InvalidNonKPrefixWithNumbers()
    {
        $config = [
            'airports' => [
                'test' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'metar_station' => 'A1B2'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Non-K prefix with numbers should fail validation');
        $this->assertStringContainsString('not a valid ICAO code', implode(' ', $result['errors']));
    }

    public function testMetarStation_InvalidAllNumbers()
    {
        $config = [
            'airports' => [
                'test' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'metar_station' => '1234'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'All-numeric code should fail validation');
        $this->assertStringContainsString('not a valid ICAO code', implode(' ', $result['errors']));
    }

    public function testMetarStation_ValidLowerCaseConverted()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'metar_station' => 'ksea'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Lowercase ICAO code should be accepted (converted to uppercase)');
    }

    public function testMetarStation_NotSet()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Airport without metar_station should pass validation');
    }

    /**
     * Test global default_preferences validation - Valid settings
     */
    public function testGlobalConfig_DefaultPreferences_ValidSettings()
    {
        $config = [
            'config' => [
                'default_preferences' => [
                    'time_format' => '24hr',
                    'temp_unit' => 'C',
                    'distance_unit' => 'm',
                    'baro_unit' => 'hPa',
                    'wind_speed_unit' => 'km/h'
                ]
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid global default_preferences should pass validation');
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test global default_preferences validation - Partial settings
     */
    public function testGlobalConfig_DefaultPreferences_PartialSettings()
    {
        $config = [
            'config' => [
                'default_preferences' => [
                    'temp_unit' => 'C',
                    'baro_unit' => 'hPa'
                ]
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Partial default_preferences should pass validation');
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test global default_preferences validation - Invalid values
     */
    public function testGlobalConfig_DefaultPreferences_InvalidValues()
    {
        $config = [
            'config' => [
                'default_preferences' => [
                    'time_format' => 'invalid',
                    'temp_unit' => 'K'
                ]
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Invalid default_preferences values should fail validation');
        $this->assertCount(2, $result['errors']);
        $this->assertStringContainsString('time_format', implode(' ', $result['errors']));
        $this->assertStringContainsString('temp_unit', implode(' ', $result['errors']));
    }

    /**
     * Test global default_preferences validation - Unknown fields
     */
    public function testGlobalConfig_DefaultPreferences_UnknownFields()
    {
        $config = [
            'config' => [
                'default_preferences' => [
                    'temp_unit' => 'C',
                    'unknown_field' => 'value'
                ]
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Unknown fields in default_preferences should fail validation');
        $this->assertStringContainsString('unknown_field', implode(' ', $result['errors']));
    }

    /**
     * Test global default_preferences validation - Invalid type (not object)
     */
    public function testGlobalConfig_DefaultPreferences_InvalidType()
    {
        $config = [
            'config' => [
                'default_preferences' => 'not_an_object'
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'default_preferences as string should fail validation');
        $this->assertStringContainsString('must be an object', implode(' ', $result['errors']));
    }

    /**
     * Test per-airport default_preferences validation - Valid overrides
     */
    public function testAirport_DefaultPreferences_ValidOverrides()
    {
        $config = [
            'config' => [
                'default_preferences' => [
                    'temp_unit' => 'F',
                    'baro_unit' => 'inHg'
                ]
            ],
            'airports' => [
                'egll' => [
                    'name' => 'London Heathrow',
                    'lat' => 51.47,
                    'lon' => -0.46,
                    'default_preferences' => [
                        'temp_unit' => 'C',
                        'baro_unit' => 'hPa'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid per-airport default_preferences overrides should pass validation');
        $this->assertEmpty($result['errors']);
    }

    /**
     * Test per-airport default_preferences validation - Invalid values
     */
    public function testAirport_DefaultPreferences_InvalidValues()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0,
                    'default_preferences' => [
                        'wind_speed_unit' => 'invalid'
                    ]
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Invalid per-airport default_preferences values should fail validation');
        $this->assertStringContainsString('wind_speed_unit', implode(' ', $result['errors']));
    }

    /**
     * Test validateDefaultPreferences function - Valid preferences
     */
    public function testValidateDefaultPreferences_Valid()
    {
        $prefs = [
            'time_format' => '12hr',
            'temp_unit' => 'F',
            'distance_unit' => 'ft',
            'baro_unit' => 'inHg',
            'wind_speed_unit' => 'kts'
        ];
        
        $errors = validateDefaultPreferences($prefs, 'test');
        $this->assertEmpty($errors, 'Valid preferences should return no errors');
    }

    /**
     * Test validateDefaultPreferences function - All valid option values
     */
    public function testValidateDefaultPreferences_AllValidOptions()
    {
        // Time format options
        $this->assertEmpty(validateDefaultPreferences(['time_format' => '12hr'], 'test'));
        $this->assertEmpty(validateDefaultPreferences(['time_format' => '24hr'], 'test'));
        
        // Temperature unit options
        $this->assertEmpty(validateDefaultPreferences(['temp_unit' => 'F'], 'test'));
        $this->assertEmpty(validateDefaultPreferences(['temp_unit' => 'C'], 'test'));
        
        // Distance unit options
        $this->assertEmpty(validateDefaultPreferences(['distance_unit' => 'ft'], 'test'));
        $this->assertEmpty(validateDefaultPreferences(['distance_unit' => 'm'], 'test'));
        
        // Barometer unit options
        $this->assertEmpty(validateDefaultPreferences(['baro_unit' => 'inHg'], 'test'));
        $this->assertEmpty(validateDefaultPreferences(['baro_unit' => 'hPa'], 'test'));
        $this->assertEmpty(validateDefaultPreferences(['baro_unit' => 'mmHg'], 'test'));
        
        // Wind speed unit options
        $this->assertEmpty(validateDefaultPreferences(['wind_speed_unit' => 'kts'], 'test'));
        $this->assertEmpty(validateDefaultPreferences(['wind_speed_unit' => 'mph'], 'test'));
        $this->assertEmpty(validateDefaultPreferences(['wind_speed_unit' => 'km/h'], 'test'));
    }

    /**
     * Test getDefaultPreferencesForAirport function - Merges correctly
     */
    public function testGetDefaultPreferencesForAirport_MergesCorrectly()
    {
        // This test requires a mock config, but we can at least verify
        // the function returns an array and handles missing config gracefully
        $result = getDefaultPreferencesForAirport('nonexistent');
        $this->assertIsArray($result, 'Should return an array even for nonexistent airport');
    }

    // =========================================================================
    // Client Version Management Settings Validation
    // =========================================================================

    /**
     * Test dead_man_switch_days validation - Valid values
     */
    public function testDeadManSwitchDays_Valid()
    {
        // Valid: 0 (disabled)
        $config = $this->createMinimalConfig();
        $config['config']['dead_man_switch_days'] = 0;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'dead_man_switch_days = 0 should be valid');

        // Valid: positive integer
        $config['config']['dead_man_switch_days'] = 7;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'dead_man_switch_days = 7 should be valid');

        // Valid: large positive integer
        $config['config']['dead_man_switch_days'] = 365;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'dead_man_switch_days = 365 should be valid');
    }

    /**
     * Test dead_man_switch_days validation - Invalid values
     */
    public function testDeadManSwitchDays_Invalid()
    {
        // Invalid: negative
        $config = $this->createMinimalConfig();
        $config['config']['dead_man_switch_days'] = -1;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Negative dead_man_switch_days should be invalid');
        $this->assertStringContainsString('dead_man_switch_days', implode(' ', $result['errors']));

        // Invalid: string
        $config['config']['dead_man_switch_days'] = '7';
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'String dead_man_switch_days should be invalid');

        // Invalid: float
        $config['config']['dead_man_switch_days'] = 7.5;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Float dead_man_switch_days should be invalid');
    }

    /**
     * Test force_cleanup validation - Valid values
     */
    public function testForceCleanup_Valid()
    {
        $config = $this->createMinimalConfig();
        
        // Valid: true
        $config['config']['force_cleanup'] = true;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'force_cleanup = true should be valid');

        // Valid: false
        $config['config']['force_cleanup'] = false;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'force_cleanup = false should be valid');
    }

    /**
     * Test force_cleanup validation - Invalid values
     */
    public function testForceCleanup_Invalid()
    {
        $config = $this->createMinimalConfig();

        // Invalid: integer (truthy)
        $config['config']['force_cleanup'] = 1;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Integer force_cleanup should be invalid');
        $this->assertStringContainsString('force_cleanup', implode(' ', $result['errors']));

        // Invalid: string
        $config['config']['force_cleanup'] = 'true';
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'String force_cleanup should be invalid');
    }

    /**
     * Test stuck_client_cleanup validation - Valid values
     */
    public function testStuckClientCleanup_Valid()
    {
        $config = $this->createMinimalConfig();
        
        // Valid: true
        $config['config']['stuck_client_cleanup'] = true;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'stuck_client_cleanup = true should be valid');

        // Valid: false
        $config['config']['stuck_client_cleanup'] = false;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'stuck_client_cleanup = false should be valid');
    }

    /**
     * Test stuck_client_cleanup validation - Invalid values
     */
    public function testStuckClientCleanup_Invalid()
    {
        $config = $this->createMinimalConfig();

        // Invalid: integer
        $config['config']['stuck_client_cleanup'] = 0;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Integer stuck_client_cleanup should be invalid');
        $this->assertStringContainsString('stuck_client_cleanup', implode(' ', $result['errors']));

        // Invalid: string
        $config['config']['stuck_client_cleanup'] = 'false';
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'String stuck_client_cleanup should be invalid');
    }

    // =========================================================================
    // Worker Pool Settings Validation
    // =========================================================================

    /**
     * Test weather_worker_pool_size validation
     */
    public function testWeatherWorkerPoolSize_Valid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['weather_worker_pool_size'] = 5;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'weather_worker_pool_size = 5 should be valid');

        $config['config']['weather_worker_pool_size'] = 1;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'weather_worker_pool_size = 1 should be valid');
    }

    public function testWeatherWorkerPoolSize_Invalid()
    {
        $config = $this->createMinimalConfig();
        
        // Invalid: zero
        $config['config']['weather_worker_pool_size'] = 0;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'weather_worker_pool_size = 0 should be invalid');
        $this->assertStringContainsString('weather_worker_pool_size', implode(' ', $result['errors']));

        // Invalid: negative
        $config['config']['weather_worker_pool_size'] = -1;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Negative weather_worker_pool_size should be invalid');

        // Invalid: string
        $config['config']['weather_worker_pool_size'] = '5';
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'String weather_worker_pool_size should be invalid');
    }

    /**
     * Test webcam_worker_pool_size validation
     */
    public function testWebcamWorkerPoolSize_Valid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['webcam_worker_pool_size'] = 5;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'webcam_worker_pool_size = 5 should be valid');
    }

    public function testWebcamWorkerPoolSize_Invalid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['webcam_worker_pool_size'] = 0;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'webcam_worker_pool_size = 0 should be invalid');
        $this->assertStringContainsString('webcam_worker_pool_size', implode(' ', $result['errors']));
    }

    /**
     * Test worker_timeout_seconds validation
     */
    public function testWorkerTimeoutSeconds_Valid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['worker_timeout_seconds'] = 90;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'worker_timeout_seconds = 90 should be valid');
    }

    public function testWorkerTimeoutSeconds_Invalid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['worker_timeout_seconds'] = 0;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'worker_timeout_seconds = 0 should be invalid');
        $this->assertStringContainsString('worker_timeout_seconds', implode(' ', $result['errors']));
    }

    // =========================================================================
    // Scheduler Settings Validation
    // =========================================================================

    /**
     * Test minimum_refresh_seconds validation
     */
    public function testMinimumRefreshSeconds_Valid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['minimum_refresh_seconds'] = 5;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'minimum_refresh_seconds = 5 should be valid');
    }

    public function testMinimumRefreshSeconds_Invalid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['minimum_refresh_seconds'] = 0;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'minimum_refresh_seconds = 0 should be invalid');
        $this->assertStringContainsString('minimum_refresh_seconds', implode(' ', $result['errors']));
    }

    /**
     * Test scheduler_config_reload_seconds validation
     */
    public function testSchedulerConfigReloadSeconds_Valid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['scheduler_config_reload_seconds'] = 60;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'scheduler_config_reload_seconds = 60 should be valid');
    }

    public function testSchedulerConfigReloadSeconds_Invalid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['scheduler_config_reload_seconds'] = 0;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'scheduler_config_reload_seconds = 0 should be invalid');
        $this->assertStringContainsString('scheduler_config_reload_seconds', implode(' ', $result['errors']));
    }

    // =========================================================================
    // NOTAM Settings Validation
    // =========================================================================

    /**
     * Test notam_refresh_seconds validation
     */
    public function testNotamRefreshSeconds_Valid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['notam_refresh_seconds'] = 600;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'notam_refresh_seconds = 600 should be valid');

        $config['config']['notam_refresh_seconds'] = 60;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'notam_refresh_seconds = 60 (minimum) should be valid');
    }

    public function testNotamRefreshSeconds_Invalid()
    {
        $config = $this->createMinimalConfig();
        
        // Invalid: below minimum (60)
        $config['config']['notam_refresh_seconds'] = 30;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'notam_refresh_seconds = 30 should be invalid');
        $this->assertStringContainsString('notam_refresh_seconds', implode(' ', $result['errors']));

        // Invalid: zero
        $config['config']['notam_refresh_seconds'] = 0;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'notam_refresh_seconds = 0 should be invalid');
    }

    /**
     * Test notam_cache_ttl_seconds validation
     */
    public function testNotamCacheTtlSeconds_Valid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['notam_cache_ttl_seconds'] = 3600;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'notam_cache_ttl_seconds = 3600 should be valid');
    }

    public function testNotamCacheTtlSeconds_Invalid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['notam_cache_ttl_seconds'] = 30;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'notam_cache_ttl_seconds = 30 should be invalid');
        $this->assertStringContainsString('notam_cache_ttl_seconds', implode(' ', $result['errors']));
    }

    /**
     * Test notam_worker_pool_size validation
     */
    public function testNotamWorkerPoolSize_Valid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['notam_worker_pool_size'] = 1;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'notam_worker_pool_size = 1 should be valid');
    }

    public function testNotamWorkerPoolSize_Invalid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['notam_worker_pool_size'] = 0;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'notam_worker_pool_size = 0 should be invalid');
        $this->assertStringContainsString('notam_worker_pool_size', implode(' ', $result['errors']));
    }

    /**
     * Test notam_api_client_id validation
     */
    public function testNotamApiClientId_Valid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['notam_api_client_id'] = 'some-client-id-123';
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'String notam_api_client_id should be valid');

        // Empty string is valid (means not configured)
        $config['config']['notam_api_client_id'] = '';
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Empty notam_api_client_id should be valid');
    }

    public function testNotamApiClientId_Invalid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['notam_api_client_id'] = 12345;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Integer notam_api_client_id should be invalid');
        $this->assertStringContainsString('notam_api_client_id', implode(' ', $result['errors']));
    }

    /**
     * Test notam_api_client_secret validation
     */
    public function testNotamApiClientSecret_Valid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['notam_api_client_secret'] = 'secret-key-xyz';
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'String notam_api_client_secret should be valid');
    }

    public function testNotamApiClientSecret_Invalid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['notam_api_client_secret'] = 12345;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Integer notam_api_client_secret should be invalid');
        $this->assertStringContainsString('notam_api_client_secret', implode(' ', $result['errors']));
    }

    /**
     * Test notam_api_base_url validation
     */
    public function testNotamApiBaseUrl_Valid()
    {
        $config = $this->createMinimalConfig();
        
        // Valid HTTPS URL
        $config['config']['notam_api_base_url'] = 'https://api.example.com';
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'HTTPS notam_api_base_url should be valid');

        // Valid HTTP URL (for dev environments)
        $config['config']['notam_api_base_url'] = 'http://localhost:8080';
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'HTTP notam_api_base_url should be valid');
    }

    public function testNotamApiBaseUrl_Invalid()
    {
        $config = $this->createMinimalConfig();

        // Invalid: no protocol
        $config['config']['notam_api_base_url'] = 'api.example.com';
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'notam_api_base_url without protocol should be invalid');
        $this->assertStringContainsString('notam_api_base_url', implode(' ', $result['errors']));

        // Invalid: ftp protocol
        $config['config']['notam_api_base_url'] = 'ftp://example.com';
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'FTP notam_api_base_url should be invalid');
    }

    // =========================================================================
    // Public API Settings Validation
    // =========================================================================

    /**
     * Test public_api section - Valid complete config
     */
    public function testPublicApi_ValidComplete()
    {
        $config = $this->createMinimalConfig();
        $config['config']['public_api'] = [
            'enabled' => true,
            'version' => '1',
            'rate_limits' => [
                'anonymous' => [
                    'requests_per_minute' => 20,
                    'requests_per_hour' => 200,
                    'requests_per_day' => 2000
                ],
                'partner' => [
                    'requests_per_minute' => 120,
                    'requests_per_hour' => 5000,
                    'requests_per_day' => 50000
                ]
            ],
            'bulk_max_airports' => 10,
            'weather_history_enabled' => true,
            'weather_history_retention_hours' => 24,
            'attribution_text' => 'Weather data from AviationWX.org',
            'partner_keys' => [
                'ak_live_example1234567890abcd' => [
                    'name' => 'Example Partner',
                    'contact' => 'developer@example.com',
                    'enabled' => true,
                    'created' => '2024-01-01',
                    'notes' => 'Example partner API key'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid complete public_api config should pass: ' . implode(', ', $result['errors']));
    }

    /**
     * Test public_api.enabled validation
     */
    public function testPublicApiEnabled_Invalid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['public_api'] = ['enabled' => 'yes'];
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'String public_api.enabled should be invalid');
        $this->assertStringContainsString('public_api.enabled', implode(' ', $result['errors']));
    }

    /**
     * Test public_api.version validation
     */
    public function testPublicApiVersion_Invalid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['public_api'] = ['version' => 1];
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Integer public_api.version should be invalid');
        $this->assertStringContainsString('public_api.version', implode(' ', $result['errors']));
    }

    /**
     * Test public_api.bulk_max_airports validation
     */
    public function testPublicApiBulkMaxAirports_Invalid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['public_api'] = ['bulk_max_airports' => 0];
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Zero bulk_max_airports should be invalid');
        $this->assertStringContainsString('bulk_max_airports', implode(' ', $result['errors']));
    }

    /**
     * Test public_api.rate_limits validation
     */
    public function testPublicApiRateLimits_Invalid()
    {
        $config = $this->createMinimalConfig();
        
        // Invalid: rate_limits must be object
        $config['config']['public_api'] = ['rate_limits' => 'invalid'];
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'String rate_limits should be invalid');
        $this->assertStringContainsString('rate_limits', implode(' ', $result['errors']));

        // Invalid: nested rate_limit value not positive
        $config['config']['public_api'] = [
            'rate_limits' => [
                'anonymous' => ['requests_per_minute' => 0]
            ]
        ];
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Zero requests_per_minute should be invalid');
        $this->assertStringContainsString('requests_per_minute', implode(' ', $result['errors']));
    }

    /**
     * Test public_api.partner_keys validation - Invalid key format
     */
    public function testPublicApiPartnerKeys_InvalidKeyFormat()
    {
        $config = $this->createMinimalConfig();
        $config['config']['public_api'] = [
            'partner_keys' => [
                'invalid_key_format' => [
                    'name' => 'Test Partner'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Invalid partner key format should fail');
        $this->assertStringContainsString('ak_live_', implode(' ', $result['errors']));
    }

    /**
     * Test public_api.partner_keys validation - Missing required name
     */
    public function testPublicApiPartnerKeys_MissingName()
    {
        $config = $this->createMinimalConfig();
        $config['config']['public_api'] = [
            'partner_keys' => [
                'ak_live_valid123' => [
                    'contact' => 'test@example.com'
                    // Missing 'name'
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'Missing partner key name should fail');
        $this->assertStringContainsString('name', implode(' ', $result['errors']));
    }

    /**
     * Test public_api.partner_keys validation - Valid test key prefix
     */
    public function testPublicApiPartnerKeys_ValidTestKey()
    {
        $config = $this->createMinimalConfig();
        $config['config']['public_api'] = [
            'partner_keys' => [
                'ak_test_devkey123' => [
                    'name' => 'Development Key',
                    'enabled' => false
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Valid ak_test_ key should pass: ' . implode(', ', $result['errors']));
    }

    // =========================================================================
    // Existing Config Fields - Ensure Still Validated
    // =========================================================================

    /**
     * Test webcam_refresh_default validation
     */
    public function testWebcamRefreshDefault_Valid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['webcam_refresh_default'] = 60;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'webcam_refresh_default = 60 should be valid');
    }

    public function testWebcamRefreshDefault_Invalid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['webcam_refresh_default'] = 0;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'webcam_refresh_default = 0 should be invalid');
        $this->assertStringContainsString('webcam_refresh_default', implode(' ', $result['errors']));
    }

    /**
     * Test weather_refresh_default validation
     */
    public function testWeatherRefreshDefault_Valid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['weather_refresh_default'] = 60;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'weather_refresh_default = 60 should be valid');
    }

    public function testWeatherRefreshDefault_Invalid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['weather_refresh_default'] = 0;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'weather_refresh_default = 0 should be invalid');
        $this->assertStringContainsString('weather_refresh_default', implode(' ', $result['errors']));
    }

    /**
     * Test metar_refresh_seconds validation
     */
    public function testMetarRefreshSeconds_Valid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['metar_refresh_seconds'] = 60;
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'metar_refresh_seconds = 60 should be valid');
    }

    public function testMetarRefreshSeconds_Invalid()
    {
        $config = $this->createMinimalConfig();
        $config['config']['metar_refresh_seconds'] = 30;
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid'], 'metar_refresh_seconds = 30 should be invalid');
        $this->assertStringContainsString('metar_refresh_seconds', implode(' ', $result['errors']));
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create a minimal valid config for testing
     */
    private function createMinimalConfig(): array
    {
        return [
            'config' => [
                'default_timezone' => 'UTC',
                'base_domain' => 'aviationwx.org'
            ],
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
    }
}

