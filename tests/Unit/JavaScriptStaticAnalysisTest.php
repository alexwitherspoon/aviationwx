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
                
                // Remove PHP code blocks from JavaScript code for analysis
                // PHP blocks should be excluded from JavaScript analysis
                // Use a more aggressive pattern that handles multiline PHP blocks
                $jsCodeWithoutPhp = preg_replace('/<\?php[\s\S]*?\?>/', '', $jsCode);
                $jsCodeWithoutPhp = preg_replace('/<\?=[\s\S]*?\?>/', '', $jsCodeWithoutPhp);
                $jsCodeWithoutPhp = preg_replace('/<\?[\s\S]*?\?>/', '', $jsCodeWithoutPhp);
                
                foreach ($phpFunctions as $func) {
                    // Check if function appears in code (excluding PHP blocks)
                    if (strpos($jsCodeWithoutPhp, $func) !== false) {
                        // Get context around the match
                        $matchIndex = strpos($jsCodeWithoutPhp, $func);
                        $context = substr($jsCodeWithoutPhp, max(0, $matchIndex - 100), 200);
                        
                        // Check if it's in a comment
                        $beforeMatch = substr($jsCodeWithoutPhp, 0, $matchIndex);
                        $isInComment = 
                            (strrpos($beforeMatch, '//') !== false && 
                             strrpos($beforeMatch, "\n", strrpos($beforeMatch, '//')) === false) ||
                            (strrpos($beforeMatch, '/*') !== false && 
                             strrpos($beforeMatch, '*/', strrpos($beforeMatch, '/*')) === false);
                        
                        // Check if it's in a string (simplified check)
                        $isInString = preg_match('/["\'`].*' . preg_quote($func, '/') . '/', $context);
                        
                        // Check if it's in PHP code context (look for $ variables before/after the function)
                        // PHP code would have $variables, so if we see $ before the function, it's likely PHP
                        $contextBefore = substr($jsCodeWithoutPhp, max(0, $matchIndex - 50), 50);
                        $contextAfter = substr($jsCodeWithoutPhp, $matchIndex, 50);
                        $isInPhpContext = preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*\s*[=;]/', $contextBefore) ||
                                          preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*\s*\)/', $contextAfter);
                        
                        // Check if it's a JavaScript method call (like string.substr() or string.trim())
                        // JavaScript methods are called on objects/strings, PHP functions are standalone
                        $isJavaScriptMethod = false;
                        if (in_array($func, ['substr(', 'trim(', 'ltrim(', 'rtrim('])) {
                            // Check if there's a dot before the function (object method call)
                            // Look at the actual context string which includes the match
                            $funcName = rtrim($func, '(');
                            // Check the context which already includes text before the match
                            // The context variable has 200 chars with the match in the middle
                            // Match patterns like: .substr( or ).substr( or ].substr( or string.substr(
                            $isJavaScriptMethod = preg_match('/[a-zA-Z0-9_\]\)"\'`]\s*\.\s*' . preg_quote($funcName, '/') . '\s*\(/', $context);
                        }
                        
                        if (!$isInComment && !$isInString && !$isInPhpContext && !$isJavaScriptMethod) {
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
     * 
     * Note: Files that use PHP output buffering to dynamically generate script tags
     * (e.g., for JS minification) cannot be reliably checked by stripping PHP code.
     * These files are handled separately by checking the raw file for ob_start/ob_get_clean
     * patterns around script blocks.
     */
    public function testJavaScriptCodeBlocksAreClosed()
    {
        $errors = [];
        
        foreach ($this->testFiles as $file) {
            if (!file_exists($file)) {
                continue;
            }
            
            $content = file_get_contents($file);
            $filename = basename($file);
            
            // Check if file uses output buffering for script handling
            // These files dynamically generate </script> tags via PHP, so simple
            // tag counting after PHP removal won't work
            $usesOutputBuffering = (
                strpos($content, 'ob_start()') !== false && 
                strpos($content, 'ob_get_clean()') !== false &&
                preg_match('/ob_start\(\).*?<script.*?ob_get_clean\(\)/is', $content)
            );
            
            if ($usesOutputBuffering) {
                // For files using output buffering: verify the pattern is complete
                // The PHP code must output </script> for each buffered <script>
                $bufferedScriptStarts = preg_match_all('/ob_start\(\);\s*\?>\s*<script/is', $content);
                $bufferEndsWithScriptClose = preg_match_all('/echo.*<\/script>/i', $content);
                
                // Also count any literal </script> that might be added
                $literalScriptCloses = preg_match_all('/\.\s*[\'"]<\/script>[\'"]/i', $content);
                
                // Each buffered script start should have a corresponding PHP echo of </script>
                if ($bufferedScriptStarts > 0 && ($bufferEndsWithScriptClose + $literalScriptCloses) < $bufferedScriptStarts) {
                    $errors[] = sprintf(
                        "%s: Output-buffered script blocks may be missing closing tags (found %d buffered starts, %d PHP-generated closes)",
                        $filename,
                        $bufferedScriptStarts,
                        $bufferEndsWithScriptClose + $literalScriptCloses
                    );
                }
                
                // Skip the simple tag counting for this file
                continue;
            }
            
            // Remove PHP code blocks first (they may contain script tags in strings)
            $contentWithoutPhp = preg_replace('/<\?php.*?\?>/is', '', $content);
            $contentWithoutPhp = preg_replace('/<\?=.*?\?>/is', '', $contentWithoutPhp);
            $contentWithoutPhp = preg_replace('/<\?.*?\?>/is', '', $contentWithoutPhp);
            
            // Start with PHP-stripped content
            $contentForCounting = $contentWithoutPhp;
            
            // Remove single-line comments (they can't contain real HTML tags)
            $contentForCounting = preg_replace('/\/\/.*$/m', '', $contentForCounting);
            
            // Remove multi-line comments (they can't contain real HTML tags)
            $contentForCounting = preg_replace('/\/\*[\s\S]*?\*\//', '', $contentForCounting);
            
            // Note: We intentionally do NOT try to filter script tags inside JS strings.
            // Previous regex patterns were too greedy and consumed legitimate HTML tags.
            // Script tags in JS strings are rare, and if present, would still have
            // matching open/close pairs within the same string.
            
            // Count opening and closing script tags (actual HTML tags)
            preg_match_all('/<script[^>]*>/i', $contentForCounting, $openingTags);
            preg_match_all('/<\/script>/i', $contentForCounting, $closingTags);
            
            $openingCount = count($openingTags[0]);
            $closingCount = count($closingTags[0]);
            
            if ($openingCount !== $closingCount) {
                $errors[] = sprintf(
                    "%s: Mismatched script tags - %d opening, %d closing",
                    $filename,
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
                // Remove PHP code blocks from JavaScript code for analysis
                // PHP blocks should be excluded from JavaScript analysis
                $jsCodeWithoutPhp = preg_replace('/<\?php.*?\?>/is', '', $jsCode);
                $jsCodeWithoutPhp = preg_replace('/<\?=.*?\?>/is', '', $jsCodeWithoutPhp);
                $jsCodeWithoutPhp = preg_replace('/<\?.*?\?>/is', '', $jsCodeWithoutPhp);
                
                // Check for relative API URLs in template literals first
                // Template literals using baseUrl or window.location are OK
                // Pattern: `${baseUrl}/api/weather.php` or `${protocol}//${host}/api/weather.php`
                if (preg_match_all('/`([^`]*weather\.php[^`]*)`/', $jsCodeWithoutPhp, $templateMatches)) {
                    foreach ($templateMatches[1] as $templateContent) {
                        // If template contains baseUrl, window.location, or starts with /api/, it's absolute
                        if (!preg_match('/(baseUrl|window\.location|^\/api\/|\$\{[^}]*protocol|\$\{[^}]*host)/', $templateContent)) {
                            // Check if it's a relative path in template literal
                            if (preg_match('/weather\.php/', $templateContent) && !preg_match('/^\/api\//', $templateContent)) {
                                $errors[] = sprintf(
                                    "%s: Relative API URL found in template literal (script block #%d): `%s`",
                                    basename($file),
                                    $index,
                                    substr($templateContent, 0, 100)
                                );
                            }
                        }
                    }
                }
                
                // Check for relative URLs in string literals (not starting with / or http)
                // But skip if it's part of a template literal construction
                // Match patterns like: "weather.php" or "/api/weather.php" or "api/weather.php"
                // Be more precise - only match if weather.php is the actual URL, not just mentioned
                if (preg_match_all('/["\']([^"\']*(?:\/api\/)?weather\.php[^"\']*)["\']/', $jsCodeWithoutPhp, $urlMatches)) {
                    foreach ($urlMatches[1] as $url) {
                        // Only check if weather.php is actually in the URL (not just mentioned)
                        if (preg_match('/weather\.php/', $url)) {
                            // If URL doesn't start with /, http://, or https://, it's relative
                            if (!preg_match('/^(\/|https?:\/\/)/', $url)) {
                                // Get context to check if this is part of constructing an absolute URL
                                $urlIndex = strpos($jsCodeWithoutPhp, $url);
                                if ($urlIndex !== false) {
                                    $contextStart = max(0, $urlIndex - 200);
                                    $contextEnd = min(strlen($jsCodeWithoutPhp), $urlIndex + strlen($url) + 200);
                                    $context = substr($jsCodeWithoutPhp, $contextStart, $contextEnd - $contextStart);
                                    
                                    // Check if this is part of constructing an absolute URL with baseUrl/window.location
                                    // Look for patterns like: baseUrl + "/api/weather.php" or `${baseUrl}/api/weather.php`
                                    $isConstructingAbsolute = preg_match(
                                        '/(baseUrl|window\.location\.(protocol|host))\s*[+\/]\s*["\']\/api\/weather\.php/',
                                        $context
                                    ) || preg_match(
                                        '/`\s*\$\{[^}]*baseUrl[^}]*\}\s*\/api\/weather\.php/',
                                        $context
                                    );
                                    
                                    // Also check if the URL itself starts with /api/ (absolute path)
                                    $isAbsolutePath = preg_match('/^\/api\//', $url);
                                    
                                    if (!$isConstructingAbsolute && !$isAbsolutePath) {
                                        $errors[] = sprintf(
                                            "%s: Relative API URL found in JavaScript (script block #%d): %s\nContext: %s",
                                            basename($file),
                                            $index,
                                            $url,
                                            substr($context, 0, 150)
                                        );
                                    }
                                }
                            }
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
