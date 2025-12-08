<?php
/**
 * Integration Tests for Multi-Identifier API Endpoints
 * 
 * Tests that API endpoints correctly handle ICAO, IATA, FAA, and airport ID identifiers.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/logger.php';

class MultiIdentifierApiTest extends TestCase
{
    private $originalGet;
    private $originalServer;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Save original superglobals
        $this->originalGet = $_GET ?? [];
        $this->originalServer = $_SERVER ?? [];
        
        // Set test environment
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'test.aviationwx.org';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }
    
    protected function tearDown(): void
    {
        // Restore original superglobals
        $_GET = $this->originalGet;
        $_SERVER = $this->originalServer;
        parent::tearDown();
    }
    
    /**
     * Test findAirportByIdentifier with real config
     */
    public function testFindAirportByIdentifier_WithRealConfig()
    {
        // Use test fixture
        $configPath = getenv('CONFIG_PATH') ?: __DIR__ . '/../Fixtures/airports.json.test';
        if (!file_exists($configPath)) {
            $this->markTestSkipped("Test fixture not found: $configPath");
            return;
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        if (!$config || !isset($config['airports'])) {
            $this->markTestSkipped('Invalid test fixture');
            return;
        }
        
        // Test ICAO lookup
        if (isset($config['airports']['kspb']['icao'])) {
            $icao = $config['airports']['kspb']['icao'];
            $result = findAirportByIdentifier($icao, $config);
            $this->assertNotNull($result, "Should find airport by ICAO: $icao");
            $this->assertEquals('kspb', $result['airportId']);
        }
        
        // Test IATA lookup if available
        if (isset($config['airports']['pdx']['iata'])) {
            $iata = $config['airports']['pdx']['iata'];
            $result = findAirportByIdentifier($iata, $config);
            $this->assertNotNull($result, "Should find airport by IATA: $iata");
            $this->assertEquals('pdx', $result['airportId']);
        }
        
        // Test FAA lookup if available
        if (isset($config['airports']['03s']['faa'])) {
            $faa = $config['airports']['03s']['faa'];
            $result = findAirportByIdentifier($faa, $config);
            $this->assertNotNull($result, "Should find airport by FAA: $faa");
            $this->assertEquals('03s', $result['airportId']);
        }
    }
    
    /**
     * Test getAirportIdFromRequest with query parameter
     */
    public function testGetAirportIdFromRequest_QueryParameter()
    {
        $_GET['airport'] = 'KSPB';
        $result = getAirportIdFromRequest();
        // Should find airport by ICAO and return airport ID
        $this->assertNotEmpty($result);
    }
    
    /**
     * Test getAirportIdFromRequest with subdomain
     */
    public function testGetAirportIdFromRequest_Subdomain()
    {
        unset($_GET['airport']);
        $_SERVER['HTTP_HOST'] = 'kspb.aviationwx.org';
        $result = getAirportIdFromRequest();
        // Should extract from subdomain
        $this->assertNotEmpty($result);
    }
    
    /**
     * Test getPrimaryIdentifier with various configurations
     */
    public function testGetPrimaryIdentifier_VariousConfigs()
    {
        // Airport with all identifiers
        $airport1 = ['icao' => 'KSPB', 'iata' => 'SPB', 'faa' => 'KSPB'];
        $result1 = getPrimaryIdentifier('kspb', $airport1);
        $this->assertEquals('KSPB', $result1);
        
        // Airport with only IATA
        $airport2 = ['iata' => 'PDX'];
        $result2 = getPrimaryIdentifier('pdx', $airport2);
        $this->assertEquals('PDX', $result2);
        
        // Airport with only FAA
        $airport3 = ['faa' => '03S'];
        $result3 = getPrimaryIdentifier('03s', $airport3);
        $this->assertEquals('03S', $result3);
        
        // Airport with no identifiers
        $airport4 = [];
        $result4 = getPrimaryIdentifier('custom', $airport4);
        $this->assertEquals('custom', $result4);
    }
    
    /**
     * Test case-insensitive identifier lookup
     */
    public function testFindAirportByIdentifier_CaseInsensitive()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'icao' => 'KSPB',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        // Test various case combinations
        $testCases = ['KSPB', 'kspb', 'Kspb', 'kSpB'];
        foreach ($testCases as $identifier) {
            $result = findAirportByIdentifier($identifier, $config);
            $this->assertNotNull($result, "Should find airport with identifier: $identifier");
            $this->assertEquals('kspb', $result['airportId']);
        }
    }
}

