<?php
/**
 * Integration Tests for Version API Endpoint
 * 
 * Tests the /api/v1/version.php endpoint that provides version information
 * for client-side dead man's switch detection.
 */

use PHPUnit\Framework\TestCase;

class VersionApiTest extends TestCase
{
    private static string $baseUrl;
    private static bool $serverAvailable = false;
    
    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        
        // Check if server is running
        $ch = curl_init(self::$baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        self::$serverAvailable = ($httpCode > 0);
    }
    
    private function skipIfServerUnavailable(): void
    {
        if (!self::$serverAvailable) {
            $this->markTestSkipped('Test server not running at ' . self::$baseUrl);
        }
    }
    
    /**
     * Make request to version API
     */
    private function fetchVersion(): array
    {
        $url = self::$baseUrl . '/api/v1/version.php';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        curl_setopt($ch, CURLOPT_HEADER, true);
        
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Parse headers
        $headers = [];
        foreach (explode("\r\n", $headerStr) as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        
        return [
            'code' => $httpCode,
            'headers' => $headers,
            'body' => $body,
            'json' => json_decode($body, true)
        ];
    }
    
    public function testVersionEndpoint_ReturnsValidJson(): void
    {
        $this->skipIfServerUnavailable();
        
        $response = $this->fetchVersion();
        
        $this->assertEquals(200, $response['code'], 'Version endpoint should return 200');
        $this->assertNotNull($response['json'], 'Response should be valid JSON');
    }
    
    public function testVersionEndpoint_ContainsRequiredFields(): void
    {
        $this->skipIfServerUnavailable();
        
        $response = $this->fetchVersion();
        $json = $response['json'];
        
        $this->assertArrayHasKey('hash', $json, 'Response should contain hash');
        $this->assertArrayHasKey('timestamp', $json, 'Response should contain timestamp');
        $this->assertArrayHasKey('force_cleanup', $json, 'Response should contain force_cleanup');
        $this->assertArrayHasKey('max_no_update_days', $json, 'Response should contain max_no_update_days');
    }
    
    public function testVersionEndpoint_HashIsValidFormat(): void
    {
        $this->skipIfServerUnavailable();
        
        $response = $this->fetchVersion();
        $hash = $response['json']['hash'] ?? null;
        
        $this->assertNotNull($hash, 'Hash should not be null');
        $this->assertIsString($hash, 'Hash should be a string');
        
        // Hash should be either 'unknown' or a valid short git hash (7+ hex chars)
        if ($hash !== 'unknown') {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{7,}$/i',
                $hash,
                'Hash should be a valid git short hash'
            );
        }
    }
    
    public function testVersionEndpoint_TimestampIsReasonable(): void
    {
        $this->skipIfServerUnavailable();
        
        $response = $this->fetchVersion();
        $timestamp = $response['json']['timestamp'] ?? null;
        
        $this->assertNotNull($timestamp, 'Timestamp should not be null');
        $this->assertIsInt($timestamp, 'Timestamp should be an integer');
        
        // Timestamp should be after 2024-01-01 and not in the future
        $minTimestamp = strtotime('2024-01-01');
        $maxTimestamp = time() + 86400; // Allow 1 day in future for timezone issues
        
        $this->assertGreaterThan($minTimestamp, $timestamp, 'Timestamp should be after 2024-01-01');
        $this->assertLessThan($maxTimestamp, $timestamp, 'Timestamp should not be far in the future');
    }
    
    public function testVersionEndpoint_ForceCleanupIsBoolean(): void
    {
        $this->skipIfServerUnavailable();
        
        $response = $this->fetchVersion();
        $forceCleanup = $response['json']['force_cleanup'] ?? null;
        
        $this->assertIsBool($forceCleanup, 'force_cleanup should be a boolean');
        $this->assertFalse($forceCleanup, 'force_cleanup should default to false');
    }
    
    public function testVersionEndpoint_MaxNoUpdateDaysIsPositiveInteger(): void
    {
        $this->skipIfServerUnavailable();
        
        $response = $this->fetchVersion();
        $maxDays = $response['json']['max_no_update_days'] ?? null;
        
        $this->assertIsInt($maxDays, 'max_no_update_days should be an integer');
        $this->assertGreaterThan(0, $maxDays, 'max_no_update_days should be positive');
        $this->assertLessThanOrEqual(30, $maxDays, 'max_no_update_days should be reasonable (<= 30)');
    }
    
    public function testVersionEndpoint_HasNoCacheHeaders(): void
    {
        $this->skipIfServerUnavailable();
        
        $response = $this->fetchVersion();
        $headers = $response['headers'];
        
        // Check Cache-Control header
        $cacheControl = $headers['cache-control'] ?? '';
        $this->assertStringContainsString('no-store', $cacheControl, 'Should have no-store in Cache-Control');
        $this->assertStringContainsString('no-cache', $cacheControl, 'Should have no-cache in Cache-Control');
    }
    
    public function testVersionEndpoint_HasCorsHeaders(): void
    {
        $this->skipIfServerUnavailable();
        
        $response = $this->fetchVersion();
        $headers = $response['headers'];
        
        $this->assertArrayHasKey('access-control-allow-origin', $headers, 'Should have CORS origin header');
        $this->assertEquals('*', $headers['access-control-allow-origin'], 'Should allow all origins');
    }
    
    public function testVersionEndpoint_RejectsPostRequests(): void
    {
        $this->skipIfServerUnavailable();
        
        $url = self::$baseUrl . '/api/v1/version.php';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        $this->assertEquals(405, $httpCode, 'POST requests should return 405 Method Not Allowed');
    }
    
    /**
     * Test that airport page sets version cookie
     */
    public function testAirportPage_SetsVersionCookie(): void
    {
        $this->skipIfServerUnavailable();
        
        $url = self::$baseUrl . '/?airport=kspb';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        
        if ($httpCode !== 200) {
            $this->markTestSkipped('Airport page not available');
            return;
        }
        
        $headers = substr($response, 0, $headerSize);
        
        // Check for version cookie in Set-Cookie header
        $hasVersionCookie = preg_match('/Set-Cookie:\s*aviationwx_v=[a-f0-9]+\.\d+/i', $headers);
        
        $this->assertTrue((bool)$hasVersionCookie, 'Airport page should set aviationwx_v cookie');
    }
    
    /**
     * Test that version cookie format matches expected pattern
     */
    public function testAirportPage_VersionCookieFormat(): void
    {
        $this->skipIfServerUnavailable();
        
        $url = self::$baseUrl . '/?airport=kspb';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        
        if ($httpCode !== 200) {
            $this->markTestSkipped('Airport page not available');
            return;
        }
        
        $headers = substr($response, 0, $headerSize);
        
        // Extract cookie value
        if (preg_match('/Set-Cookie:\s*aviationwx_v=([^;]+)/i', $headers, $matches)) {
            $cookieValue = $matches[1];
            
            // Should be in format: hash.timestamp
            $parts = explode('.', $cookieValue);
            $this->assertCount(2, $parts, 'Cookie value should have two parts (hash.timestamp)');
            
            // First part should be hex hash (7 chars)
            $this->assertMatchesRegularExpression('/^[a-f0-9]{7}$/i', $parts[0], 'First part should be 7-char hex hash');
            
            // Second part should be Unix timestamp
            $timestamp = (int)$parts[1];
            $this->assertGreaterThan(1700000000, $timestamp, 'Timestamp should be recent');
            $this->assertLessThan(time() + 86400, $timestamp, 'Timestamp should not be in far future');
        } else {
            $this->fail('Could not find aviationwx_v cookie in response');
        }
    }
}

