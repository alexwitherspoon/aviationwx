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
                'max_stale_hours' => 3,
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

    public function testGlobalConfig_InvalidMaxStaleHours()
    {
        $config = [
            'config' => [
                'max_stale_hours' => -1
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
        $this->assertFalse($result['valid'], 'Global config with invalid max_stale_hours should fail validation');
        $this->assertStringContainsString('max_stale_hours must be a positive integer', implode(' ', $result['errors']));
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
}

