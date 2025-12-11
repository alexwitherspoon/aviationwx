<?php
/**
 * JavaScript Static Analysis Tests
 * 
 * Validates JavaScript code in PHP files for common issues:
 * - PHP functions used in JavaScript
 * - Incorrect API endpoint URLs
 * - JavaScript syntax issues
 */

use PHPUnit\Framework\TestCase;

class JavaScriptStaticAnalysisTest extends TestCase
{
    private $testFiles = [];
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Files that contain JavaScript code
        $this->testFiles = [
            __DIR__ . '/../../pages/airport.php',
            __DIR__ . '/../../pages/homepage.php',
            __DIR__ . '/../../pages/error-404-airport.php',
        ];
    }
    
    /**
     * Test that no PHP functions appear in JavaScript code blocks
     */
    public function testNoPhpFunctionsInJavaScript()
    {
        $phpFunctions = [
            'empty(',
            'isset(',
            'array_',
            'str_',
            'preg_',
            'json_encode',
            'json_decode',
            'htmlspecialchars',
            'urlencode',
            'count(',
            'sizeof(',
            'in_array(',
            'array_key_exists',
            'var_dump',
            'print_r',
            'explode(',
            'implode(',
            'substr(',
            'strlen(',
            'trim(',
            'ltrim(',
            'rtrim(',
        ];
        
        $errors = [];
        
        foreach ($this->testFiles as $file) {
            if (!file_exists($file)) {
                continue;
            }
            
            $content = file_get_contents($file);
            
            // Extract JavaScript code blocks
            preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $content, $matches);
            
            foreach ($matches[1] as $index => $jsCode) {
                // Skip empty scripts
                if (trim($jsCode) === '') {
                    continue;
                }
                
                foreach ($phpFunctions as $func) {
                    // Check if function appears in code
                    if (strpos($jsCode, $func) !== false) {
                        // Get context around the match
                        $matchIndex = strpos($jsCode, $func);
                        $context = substr($jsCode, max(0, $matchIndex - 100), 200);
                        
                        // Check if it's in a comment
                        $beforeMatch = substr($jsCode, 0, $matchIndex);
                        $isInComment = 
                            (strrpos($beforeMatch, '//') !== false && 
                             strrpos($beforeMatch, "\n", strrpos($beforeMatch, '//')) === false) ||
                            (strrpos($beforeMatch, '/*') !== false && 
                             strrpos($beforeMatch, '*/', strrpos($beforeMatch, '/*')) === false);
                        
                        // Check if it's in a string (simplified check)
                        $isInString = preg_match('/["\'`].*' . preg_quote($func, '/') . '/', $context);
                        
                        if (!$isInComment && !$isInString) {
                            $errors[] = sprintf(
                                "%s: PHP function '%s' found in JavaScript (script block #%d)\nContext: %s",
                                basename($file),
                                $func,
                                $index,
                                substr($context, 0, 150)
                            );
                        }
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            $this->fail("PHP functions found in JavaScript:\n" . implode("\n\n", $errors));
        }
        
        $this->assertTrue(true);
    }
    
    /**
     * Test that weather API calls use correct endpoint
     */
    public function testWeatherApiEndpointIsCorrect()
    {
        $errors = [];
        
        foreach ($this->testFiles as $file) {
            if (!file_exists($file)) {
                continue;
            }
            
            $content = file_get_contents($file);
            
            // Extract JavaScript code blocks
            preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $content, $matches);
            
            foreach ($matches[1] as $index => $jsCode) {
                // Check for incorrect weather.php calls
                // Should use /api/weather.php, not /weather.php
                $incorrectPatterns = [
                    '/["\']\/weather\.php\?/',  // "/weather.php?"
                    '/["\']weather\.php\?/',     // "weather.php?" (relative)
                    '/`\/weather\.php\?/',       // `/weather.php?` (template literal)
                    '/`weather\.php\?/'         // `weather.php?` (template literal, relative)
                ];
                
                foreach ($incorrectPatterns as $pattern) {
                    if (preg_match($pattern, $jsCode, $matches)) {
                        // Check if it's actually part of /api/weather.php (which is correct)
                        $matchIndex = strpos($jsCode, $matches[0]);
                        $context = substr($jsCode, max(0, $matchIndex - 50), 150);
                        
                        // If context doesn't contain /api/weather.php, it's an error
                        if (strpos($context, '/api/weather.php') === false && 
                            strpos($context, 'api/weather.php') === false) {
                            $errors[] = sprintf(
                                "%s: Incorrect weather API endpoint in JavaScript (script block #%d)\nFound: %s\nContext: %s",
                                basename($file),
                                $index,
                                $matches[0],
                                substr($context, 0, 100)
                            );
                        }
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            $this->fail("Incorrect weather API endpoints found:\n" . implode("\n\n", $errors));
        }
        
        // Verify correct endpoint is used
        $foundCorrectEndpoint = false;
        foreach ($this->testFiles as $file) {
            if (!file_exists($file)) {
                continue;
            }
            
            $content = file_get_contents($file);
            if (strpos($content, '/api/weather.php') !== false || 
                strpos($content, 'api/weather.php') !== false) {
                $foundCorrectEndpoint = true;
                break;
            }
        }
        
        $this->assertTrue($foundCorrectEndpoint, "Correct weather API endpoint (/api/weather.php) not found in any test file");
    }
    
    /**
     * Test that JavaScript code blocks are properly closed
     */
    public function testJavaScriptCodeBlocksAreClosed()
    {
        $errors = [];
        
        foreach ($this->testFiles as $file) {
            if (!file_exists($file)) {
                continue;
            }
            
            $content = file_get_contents($file);
            
            // Count opening and closing script tags
            preg_match_all('/<script[^>]*>/i', $content, $openingTags);
            preg_match_all('/<\/script>/i', $content, $closingTags);
            
            $openingCount = count($openingTags[0]);
            $closingCount = count($closingTags[0]);
            
            if ($openingCount !== $closingCount) {
                $errors[] = sprintf(
                    "%s: Mismatched script tags - %d opening, %d closing",
                    basename($file),
                    $openingCount,
                    $closingCount
                );
            }
        }
        
        if (!empty($errors)) {
            $this->fail("Unclosed script tags found:\n" . implode("\n", $errors));
        }
        
        $this->assertTrue(true);
    }
    
    /**
     * Test that JavaScript uses absolute URLs for API calls
     */
    public function testApiCallsUseAbsoluteUrls()
    {
        $errors = [];
        
        foreach ($this->testFiles as $file) {
            if (!file_exists($file)) {
                continue;
            }
            
            $content = file_get_contents($file);
            
            // Extract JavaScript code blocks
            preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $content, $matches);
            
            foreach ($matches[1] as $index => $jsCode) {
                // Check for relative API URLs (should be absolute)
                // Look for patterns like: fetch('weather.php') or fetch("api/weather.php")
                // But allow: fetch('/api/weather.php') (absolute path) or fetch('http://...')
                
                // Pattern for relative URLs (not starting with / or http)
                if (preg_match_all('/["\']([^"\']*weather\.php[^"\']*)["\']/', $jsCode, $urlMatches)) {
                    foreach ($urlMatches[1] as $url) {
                        // If URL doesn't start with /, http://, or https://, it's relative
                        if (!preg_match('/^(\/|https?:\/\/)/', $url)) {
                            $errors[] = sprintf(
                                "%s: Relative API URL found in JavaScript (script block #%d): %s",
                                basename($file),
                                $index,
                                $url
                            );
                        }
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            $this->fail("Relative API URLs found (should use absolute URLs):\n" . implode("\n", $errors));
        }
        
        $this->assertTrue(true);
    }
}
