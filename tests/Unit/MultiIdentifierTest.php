<?php
/**
 * Unit Tests for Multi-Identifier System
 * 
 * Tests the new multi-identifier system supporting ICAO, IATA, FAA, and custom identifiers.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';

class MultiIdentifierTest extends TestCase
{
    private $testConfig = null;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a test config with multiple identifier types
        $this->testConfig = [
            'airports' => [
                'kspb' => [
                    'name' => 'Scappoose Airport',
                    'icao' => 'KSPB',
                    'iata' => null,
                    'faa' => 'KSPB',
                    'lat' => 45.7710278,
                    'lon' => -122.8618333
                ],
                'pdx' => [
                    'name' => 'Portland International Airport',
                    'icao' => 'KPDX',
                    'iata' => 'PDX',
                    'faa' => 'PDX',
                    'lat' => 45.5897694,
                    'lon' => -122.5950944
                ],
                '03s' => [
                    'name' => 'Sandy River Airport',
                    'icao' => null,
                    'iata' => null,
                    'faa' => '03S',
                    'lat' => 45.395,
                    'lon' => -122.261
                ],
                'custom-airport' => [
                    'name' => 'Custom Airport',
                    'icao' => null,
                    'iata' => null,
                    'faa' => null,
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
    }
    
    /**
     * Test isValidIataFormat - Valid IATA codes
     */
    public function testIsValidIataFormat_Valid()
    {
        $this->assertTrue(isValidIataFormat('PDX'));
        $this->assertTrue(isValidIataFormat('SEA'));
        $this->assertTrue(isValidIataFormat('LAX'));
        $this->assertTrue(isValidIataFormat('JFK'));
    }
    
    /**
     * Test isValidIataFormat - Invalid IATA codes
     */
    public function testIsValidIataFormat_Invalid()
    {
        $this->assertFalse(isValidIataFormat('PD')); // Too short
        $this->assertFalse(isValidIataFormat('PDXX')); // Too long
        $this->assertFalse(isValidIataFormat('')); // Empty
        // Note: 'pdx' (lowercase) gets uppercased to 'PDX' which is valid format
        // The function handles case conversion internally, so lowercase input passes format validation
    }
    
    /**
     * Test isValidFaaFormat - Valid FAA identifiers
     */
    public function testIsValidFaaFormat_Valid()
    {
        $this->assertTrue(isValidFaaFormat('KSPB'));
        $this->assertTrue(isValidFaaFormat('03S'));
        $this->assertTrue(isValidFaaFormat('PDX'));
        $this->assertTrue(isValidFaaFormat('K12'));
    }
    
    /**
     * Test isValidFaaFormat - Invalid FAA identifiers
     */
    public function testIsValidFaaFormat_Invalid()
    {
        $this->assertFalse(isValidFaaFormat('AB'));
        $this->assertFalse(isValidFaaFormat('ABCDE'));
        $this->assertFalse(isValidFaaFormat(''));
        // Note: null handling is done by type hint - PHP will throw TypeError
    }
    
    /**
     * Test isValidCustomIdentifierFormat - Valid custom identifiers
     */
    public function testIsValidCustomIdentifierFormat_Valid()
    {
        $this->assertTrue(isValidCustomIdentifierFormat('sandy-river'));
        $this->assertTrue(isValidCustomIdentifierFormat('private-strip-1'));
        $this->assertTrue(isValidCustomIdentifierFormat('abc'));
        $this->assertTrue(isValidCustomIdentifierFormat('test123'));
    }
    
    /**
     * Test isValidCustomIdentifierFormat - Invalid custom identifiers
     */
    public function testIsValidCustomIdentifierFormat_Invalid()
    {
        $this->assertFalse(isValidCustomIdentifierFormat('ab')); // Too short
        $this->assertFalse(isValidCustomIdentifierFormat('-sandy-river')); // Starts with hyphen
        $this->assertFalse(isValidCustomIdentifierFormat('sandy-river-')); // Ends with hyphen
        $this->assertFalse(isValidCustomIdentifierFormat('')); // Empty
        // Note: 'Sandy-River' with uppercase is actually valid (gets lowercased)
        // Note: null handling is done by type hint - PHP will throw TypeError
    }
    
    /**
     * Test findAirportByIdentifier - Direct key lookup (backward compatibility)
     */
    public function testFindAirportByIdentifier_DirectKey()
    {
        $result = findAirportByIdentifier('kspb', $this->testConfig);
        $this->assertNotNull($result);
        $this->assertArrayHasKey('airport', $result);
        $this->assertArrayHasKey('airportId', $result);
        $this->assertEquals('kspb', $result['airportId']);
        $this->assertEquals('Scappoose Airport', $result['airport']['name']);
    }
    
    /**
     * Test findAirportByIdentifier - ICAO code lookup
     */
    public function testFindAirportByIdentifier_Icao()
    {
        $result = findAirportByIdentifier('KSPB', $this->testConfig);
        $this->assertNotNull($result);
        $this->assertEquals('kspb', $result['airportId']);
        $this->assertEquals('Scappoose Airport', $result['airport']['name']);
        
        // Case insensitive
        $result2 = findAirportByIdentifier('kspb', $this->testConfig);
        $this->assertNotNull($result2);
        $this->assertEquals('kspb', $result2['airportId']);
    }
    
    /**
     * Test findAirportByIdentifier - IATA code lookup
     */
    public function testFindAirportByIdentifier_Iata()
    {
        $result = findAirportByIdentifier('PDX', $this->testConfig);
        $this->assertNotNull($result);
        $this->assertEquals('pdx', $result['airportId']);
        $this->assertEquals('Portland International Airport', $result['airport']['name']);
        
        // Case insensitive
        $result2 = findAirportByIdentifier('pdx', $this->testConfig);
        $this->assertNotNull($result2);
        $this->assertEquals('pdx', $result2['airportId']);
    }
    
    /**
     * Test findAirportByIdentifier - FAA identifier lookup
     */
    public function testFindAirportByIdentifier_Faa()
    {
        $result = findAirportByIdentifier('03S', $this->testConfig);
        $this->assertNotNull($result);
        $this->assertEquals('03s', $result['airportId']);
        $this->assertEquals('Sandy River Airport', $result['airport']['name']);
        
        // Case insensitive
        $result2 = findAirportByIdentifier('03s', $this->testConfig);
        $this->assertNotNull($result2);
        $this->assertEquals('03s', $result2['airportId']);
    }
    
    /**
     * Test findAirportByIdentifier - Priority (ICAO > IATA > FAA)
     */
    public function testFindAirportByIdentifier_Priority()
    {
        // Airport with all three identifiers - ICAO should be preferred
        $result = findAirportByIdentifier('KPDX', $this->testConfig);
        $this->assertNotNull($result);
        $this->assertEquals('pdx', $result['airportId']);
        
        // IATA should also work
        $result2 = findAirportByIdentifier('PDX', $this->testConfig);
        $this->assertNotNull($result2);
        $this->assertEquals('pdx', $result2['airportId']);
    }
    
    /**
     * Test findAirportByIdentifier - Not found
     */
    public function testFindAirportByIdentifier_NotFound()
    {
        $result = findAirportByIdentifier('NONEXISTENT', $this->testConfig);
        $this->assertNull($result);
        
        $result2 = findAirportByIdentifier('', $this->testConfig);
        $this->assertNull($result2);
    }
    
    /**
     * Test findAirportByIdentifier - Airport with no identifiers (uses airport ID)
     */
    public function testFindAirportByIdentifier_NoIdentifiers()
    {
        $result = findAirportByIdentifier('custom-airport', $this->testConfig);
        $this->assertNotNull($result, 'Should find airport by airport ID when no identifiers present');
        $this->assertEquals('custom-airport', $result['airportId']);
        $this->assertEquals('Custom Airport', $result['airport']['name']);
    }
    
    /**
     * Test getPrimaryIdentifier - Priority order
     */
    public function testGetPrimaryIdentifier_Priority()
    {
        // ICAO preferred
        $airport = ['icao' => 'KSPB', 'iata' => 'SPB', 'faa' => 'KSPB'];
        $result = getPrimaryIdentifier('kspb', $airport);
        $this->assertEquals('KSPB', $result);
        
        // IATA if no ICAO
        $airport2 = ['iata' => 'PDX', 'faa' => 'PDX'];
        $result2 = getPrimaryIdentifier('pdx', $airport2);
        $this->assertEquals('PDX', $result2);
        
        // FAA if no ICAO or IATA
        $airport3 = ['faa' => '03S'];
        $result3 = getPrimaryIdentifier('03s', $airport3);
        $this->assertEquals('03S', $result3);
        
        // Airport ID if no identifiers
        $airport4 = [];
        $result4 = getPrimaryIdentifier('custom-airport', $airport4);
        $this->assertEquals('custom-airport', $result4);
    }
    
    /**
     * Test getPrimaryIdentifier - Empty/null values
     */
    public function testGetPrimaryIdentifier_EmptyValues()
    {
        $airport = ['icao' => '', 'iata' => null, 'faa' => 'KSPB'];
        $result = getPrimaryIdentifier('kspb', $airport);
        $this->assertEquals('KSPB', $result); // Should skip empty ICAO and use FAA
    }
    
    /**
     * Test getPrimaryIdentifier - All identifiers null (uses airport ID)
     */
    public function testGetPrimaryIdentifier_AllNull()
    {
        $airport = ['icao' => null, 'iata' => null, 'faa' => null];
        $result = getPrimaryIdentifier('custom-airport', $airport);
        $this->assertEquals('custom-airport', $result, 'Should use airport ID when all identifiers are null');
    }
    
    /**
     * Test getPrimaryIdentifier - No identifier fields (uses airport ID)
     */
    public function testGetPrimaryIdentifier_NoIdentifierFields()
    {
        $airport = [];
        $result = getPrimaryIdentifier('custom-airport', $airport);
        $this->assertEquals('custom-airport', $result, 'Should use airport ID when no identifier fields present');
    }
    
    /**
     * Test uniqueness validation - Duplicate names
     */
    public function testValidateAirportsJsonStructure_DuplicateNames()
    {
        $config = [
            'airports' => [
                'airport1' => [
                    'name' => 'Test Airport',
                    'icao' => 'TEST',
                    'lat' => 45.0,
                    'lon' => -122.0
                ],
                'airport2' => [
                    'name' => 'Test Airport', // Duplicate name
                    'icao' => 'TEST',
                    'lat' => 46.0,
                    'lon' => -123.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        // Check that duplicate name error exists (may be after format errors)
        $hasDuplicateName = false;
        foreach ($result['errors'] as $error) {
            if (strpos($error, 'Duplicate airport name') !== false) {
                $hasDuplicateName = true;
                break;
            }
        }
        $this->assertTrue($hasDuplicateName, 'Should have duplicate name error');
    }
    
    /**
     * Test uniqueness validation - Duplicate ICAO codes
     */
    public function testValidateAirportsJsonStructure_DuplicateIcao()
    {
        $config = [
            'airports' => [
                'airport1' => [
                    'name' => 'Airport One',
                    'icao' => 'KSPB',
                    'lat' => 45.0,
                    'lon' => -122.0
                ],
                'airport2' => [
                    'name' => 'Airport Two',
                    'icao' => 'KSPB', // Duplicate ICAO
                    'lat' => 46.0,
                    'lon' => -123.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        // Uniqueness is checked after format validation
        // Check that duplicate ICAO error exists in errors array
        $hasDuplicateIcao = false;
        foreach ($result['errors'] as $error) {
            if (strpos($error, 'Duplicate ICAO code') !== false) {
                $hasDuplicateIcao = true;
                break;
            }
        }
        $this->assertTrue($hasDuplicateIcao, 'Should have duplicate ICAO error. Errors: ' . implode(', ', $result['errors']));
        $this->assertFalse($result['valid'], 'Validation should fail with duplicate ICAO');
    }
    
    /**
     * Test uniqueness validation - Duplicate IATA codes
     */
    public function testValidateAirportsJsonStructure_DuplicateIata()
    {
        $config = [
            'airports' => [
                'airport1' => [
                    'name' => 'Airport One',
                    'icao' => 'KPDX',
                    'iata' => 'PDX',
                    'lat' => 45.0,
                    'lon' => -122.0
                ],
                'airport2' => [
                    'name' => 'Airport Two',
                    'icao' => 'KSEA',
                    'iata' => 'PDX', // Duplicate IATA
                    'lat' => 46.0,
                    'lon' => -123.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        // Check that duplicate IATA error exists
        $hasDuplicateIata = false;
        foreach ($result['errors'] as $error) {
            if (strpos($error, 'Duplicate IATA code') !== false) {
                $hasDuplicateIata = true;
                break;
            }
        }
        $this->assertTrue($hasDuplicateIata, 'Should have duplicate IATA error. Errors: ' . implode(', ', $result['errors']));
        $this->assertFalse($result['valid'], 'Validation should fail with duplicate IATA');
    }
    
    /**
     * Test uniqueness validation - Duplicate FAA identifiers
     */
    public function testValidateAirportsJsonStructure_DuplicateFaa()
    {
        $config = [
            'airports' => [
                'airport1' => [
                    'name' => 'Airport One',
                    'faa' => '03S',
                    'lat' => 45.0,
                    'lon' => -122.0
                ],
                'airport2' => [
                    'name' => 'Airport Two',
                    'faa' => '03S', // Duplicate FAA
                    'lat' => 46.0,
                    'lon' => -123.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        // Check that duplicate FAA error exists
        $hasDuplicateFaa = false;
        foreach ($result['errors'] as $error) {
            if (strpos($error, 'Duplicate FAA identifier') !== false) {
                $hasDuplicateFaa = true;
                break;
            }
        }
        $this->assertTrue($hasDuplicateFaa, 'Should have duplicate FAA error. Errors: ' . implode(', ', $result['errors']));
        $this->assertFalse($result['valid'], 'Validation should fail with duplicate FAA');
    }
    
    /**
     * Test validation - No identifiers required (airport ID used as identifier)
     */
    public function testValidateAirportsJsonStructure_NoIdentifiersRequired()
    {
        $config = [
            'airports' => [
                'custom-airport' => [
                    'name' => 'Custom Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                    // No identifiers - airport ID 'custom-airport' is used as identifier
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Airport with no identifiers should be valid (uses airport ID)');
    }
    
    /**
     * Test validation - Explicit null identifiers allowed
     */
    public function testValidateAirportsJsonStructure_ExplicitNullIdentifiers()
    {
        $config = [
            'airports' => [
                'custom-airport' => [
                    'name' => 'Custom Airport',
                    'icao' => null,
                    'iata' => null,
                    'faa' => null,
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], 'Airport with explicit null identifiers should be valid (uses airport ID)');
    }
    
    /**
     * Test validation - Valid with only IATA
     */
    public function testValidateAirportsJsonStructure_ValidWithOnlyIata()
    {
        $config = [
            'airports' => [
                'airport1' => [
                    'name' => 'Airport One',
                    'iata' => 'TST',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        // Should pass structure validation (ICAO validation is separate)
        $this->assertTrue($result['valid']);
    }
    
    /**
     * Test validation - Valid with only FAA
     */
    public function testValidateAirportsJsonStructure_ValidWithOnlyFaa()
    {
        $config = [
            'airports' => [
                'airport1' => [
                    'name' => 'Airport One',
                    'faa' => '03S',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid']);
    }
    
    /**
     * Test getIcaoFromIata - Valid IATA code
     * 
     * Note: This test requires network access to download OurAirports data.
     * It may be skipped if the network is unavailable.
     */
    public function testGetIcaoFromIata_ValidIata()
    {
        // Test with PDX (Portland International Airport)
        $result = getIcaoFromIata('PDX');
        
        if ($result === null) {
            $this->markTestSkipped('Could not get ICAO from IATA (network issue or service unavailable)');
            return;
        }
        
        $this->assertEquals('KPDX', $result, 'PDX IATA code should map to KPDX ICAO code');
    }
    
    /**
     * Test getIcaoFromIata - Case insensitive
     */
    public function testGetIcaoFromIata_CaseInsensitive()
    {
        $result1 = getIcaoFromIata('pdx');
        $result2 = getIcaoFromIata('PDX');
        $result3 = getIcaoFromIata('Pdx');
        
        if ($result1 === null) {
            $this->markTestSkipped('Could not get ICAO from IATA (network issue or service unavailable)');
            return;
        }
        
        // All should return the same result
        $this->assertEquals($result1, $result2, 'Should be case insensitive');
        $this->assertEquals($result1, $result3, 'Should be case insensitive');
    }
    
    /**
     * Test getIcaoFromIata - Invalid format
     */
    public function testGetIcaoFromIata_InvalidFormat()
    {
        $this->assertNull(getIcaoFromIata('XX'), '2-letter code should return null');
        $this->assertNull(getIcaoFromIata('XXXX'), '4-letter code should return null');
        $this->assertNull(getIcaoFromIata(''), 'Empty string should return null');
    }
    
    /**
     * Test getIcaoFromIata - Non-existent IATA code
     */
    public function testGetIcaoFromIata_NonExistent()
    {
        // Use a code that's unlikely to exist (but valid format)
        $result = getIcaoFromIata('ZZZ');
        
        // Should return null if not found, or a valid ICAO if it does exist
        // We just check it's either null or a valid ICAO format
        if ($result !== null) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{3,4}$/', $result, 'If found, should be valid ICAO format');
        }
    }
    
    /**
     * Test getIcaoFromFaa - Valid FAA code
     * 
     * Note: This test requires network access to download OurAirports data.
     * It may be skipped if the network is unavailable.
     */
    public function testGetIcaoFromFaa_ValidFaa()
    {
        // Test with PDX (Portland International Airport - FAA identifier)
        $result = getIcaoFromFaa('PDX');
        
        if ($result === null) {
            $this->markTestSkipped('Could not get ICAO from FAA (network issue or service unavailable)');
            return;
        }
        
        $this->assertEquals('KPDX', $result, 'PDX FAA code should map to KPDX ICAO code');
    }
    
    /**
     * Test getIcaoFromFaa - Case insensitive
     */
    public function testGetIcaoFromFaa_CaseInsensitive()
    {
        $result1 = getIcaoFromFaa('pdx');
        $result2 = getIcaoFromFaa('PDX');
        $result3 = getIcaoFromFaa('Pdx');
        
        if ($result1 === null) {
            $this->markTestSkipped('Could not get ICAO from FAA (network issue or service unavailable)');
            return;
        }
        
        // All should return the same result
        $this->assertEquals($result1, $result2, 'Should be case insensitive');
        $this->assertEquals($result1, $result3, 'Should be case insensitive');
    }
    
    /**
     * Test getIcaoFromFaa - Invalid format
     */
    public function testGetIcaoFromFaa_InvalidFormat()
    {
        $this->assertNull(getIcaoFromFaa('XX'), '2-character code should return null');
        $this->assertNull(getIcaoFromFaa('XXXXX'), '5-character code should return null');
        $this->assertNull(getIcaoFromFaa(''), 'Empty string should return null');
    }
    
    /**
     * Test getIcaoFromFaa - Non-existent FAA code
     */
    public function testGetIcaoFromFaa_NonExistent()
    {
        // Use a code that's unlikely to exist (but valid format)
        $result = getIcaoFromFaa('ZZZZ');
        
        // Should return null if not found, or a valid ICAO if it does exist
        // We just check it's either null or a valid ICAO format
        if ($result !== null) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{3,4}$/', $result, 'If found, should be valid ICAO format');
        }
    }
}

