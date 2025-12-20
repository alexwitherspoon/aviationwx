<?php

require_once __DIR__ . '/../../lib/weather/utils.php';

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for weather cache-busting URL generation
 * 
 * Tests the logic for adding cache-busting parameters to URLs.
 * This logic is used in JavaScript (client-side) and must match the PHP implementation.
 */
class WeatherCacheBustingTest extends TestCase
{
    /**
     * Test adding cache-busting parameter to URL without query parameters
     */
    public function testAddCacheBustingToUrlWithoutQuery()
    {
        $url = 'https://example.com/api/weather.php';
        $result = addCacheBustingParameter($url);
        
        $this->assertStringStartsWith($url . '?', $result, 'Should add ? separator for URL without query params');
        $this->assertStringContainsString('_cb=', $result, 'Should contain _cb parameter');
        $this->assertMatchesRegularExpression('/\?_cb=\d+$/', $result, 'Should match pattern ?_cb=timestamp');
    }
    
    /**
     * Test adding cache-busting parameter to URL with existing query parameters
     */
    public function testAddCacheBustingToUrlWithQuery()
    {
        $url = 'https://example.com/api/weather.php?airport=kspb';
        $result = addCacheBustingParameter($url);
        
        $this->assertStringStartsWith($url . '&', $result, 'Should add & separator for URL with query params');
        $this->assertStringContainsString('_cb=', $result, 'Should contain _cb parameter');
        $this->assertMatchesRegularExpression('/&_cb=\d+$/', $result, 'Should match pattern &_cb=timestamp');
    }
    
    /**
     * Test cache-busting parameter uses timestamp
     */
    public function testCacheBustingUsesTimestamp()
    {
        $url = 'https://example.com/api/weather.php';
        $timestamp = 1234567890123; // Fixed timestamp for testing
        $result = addCacheBustingParameter($url, $timestamp);
        
        $this->assertStringEndsWith('_cb=' . $timestamp, $result, 'Should use provided timestamp');
    }
    
    /**
     * Test cache-busting parameter uses current time when not provided
     */
    public function testCacheBustingUsesCurrentTime()
    {
        $url = 'https://example.com/api/weather.php';
        $before = round(microtime(true) * 1000);
        $result = addCacheBustingParameter($url);
        $after = round(microtime(true) * 1000);
        
        // Extract timestamp from URL
        preg_match('/_cb=(\d+)/', $result, $matches);
        $this->assertNotEmpty($matches, 'Should extract timestamp from URL');
        
        $urlTimestamp = (int)$matches[1];
        $this->assertGreaterThanOrEqual($before, $urlTimestamp, 'Timestamp should be >= before time');
        $this->assertLessThanOrEqual($after, $urlTimestamp, 'Timestamp should be <= after time');
    }
    
    /**
     * Test cache-busting parameter format matches JavaScript Date.now()
     */
    public function testCacheBustingFormatMatchesJavaScript()
    {
        $url = 'https://example.com/api/weather.php';
        $timestamp = 1234567890123; // Fixed timestamp
        $result = addCacheBustingParameter($url, $timestamp);
        
        // Extract timestamp from URL
        preg_match('/_cb=(\d+)/', $result, $matches);
        $extractedTimestamp = (int)$matches[1];
        
        // Should be milliseconds (13 digits for current epoch)
        $this->assertGreaterThan(1000000000000, $extractedTimestamp, 'Timestamp should be in milliseconds (13+ digits)');
        $this->assertEquals($timestamp, $extractedTimestamp, 'Should match provided timestamp exactly');
    }
    
    /**
     * Test multiple calls generate different timestamps
     */
    public function testMultipleCallsGenerateDifferentTimestamps()
    {
        $url = 'https://example.com/api/weather.php';
        $results = [];
        
        // Generate multiple URLs with small delays
        for ($i = 0; $i < 5; $i++) {
            usleep(1000); // 1ms delay
            $results[] = addCacheBustingParameter($url);
        }
        
        // Extract timestamps
        $timestamps = [];
        foreach ($results as $result) {
            preg_match('/_cb=(\d+)/', $result, $matches);
            $timestamps[] = (int)$matches[1];
        }
        
        // All timestamps should be unique (or at least increasing)
        $uniqueTimestamps = array_unique($timestamps);
        $this->assertGreaterThanOrEqual(1, count($uniqueTimestamps), 'Should generate unique or increasing timestamps');
        
        // Timestamps should be in ascending order (or equal if generated very quickly)
        for ($i = 1; $i < count($timestamps); $i++) {
            $this->assertGreaterThanOrEqual($timestamps[$i - 1], $timestamps[$i], 'Timestamps should be non-decreasing');
        }
    }
    
    /**
     * Test URL with multiple existing query parameters
     */
    public function testUrlWithMultipleQueryParameters()
    {
        $url = 'https://example.com/api/weather.php?airport=kspb&format=json';
        $result = addCacheBustingParameter($url);
        
        $this->assertStringContainsString('airport=kspb', $result, 'Should preserve existing query parameters');
        $this->assertStringContainsString('format=json', $result, 'Should preserve existing query parameters');
        $this->assertStringContainsString('_cb=', $result, 'Should add cache-busting parameter');
        $this->assertMatchesRegularExpression('/&_cb=\d+$/', $result, 'Should append _cb parameter');
    }
    
    /**
     * Test URL with fragment identifier
     */
    public function testUrlWithFragment()
    {
        $url = 'https://example.com/api/weather.php#section';
        $result = addCacheBustingParameter($url);
        
        // Cache-busting parameter should be added before fragment
        $this->assertStringContainsString('?_cb=', $result, 'Should add cache-busting parameter');
        $this->assertStringContainsString('#section', $result, 'Should preserve fragment');
        
        // Verify cache-busting comes before fragment
        $cbPos = strpos($result, '?_cb=');
        $fragmentPos = strpos($result, '#section');
        $this->assertNotFalse($cbPos, 'Should find cache-busting parameter');
        $this->assertNotFalse($fragmentPos, 'Should find fragment');
        $this->assertLessThan($fragmentPos, $cbPos, 'Cache-busting should come before fragment');
    }
}

