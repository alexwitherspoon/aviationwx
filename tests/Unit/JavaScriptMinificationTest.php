<?php
/**
 * Unit Tests for JavaScript Minification
 * Tests the minifyJavaScript function to ensure it preserves template literals,
 * string literals, PHP tags, and doesn't break JavaScript syntax
 */

use PHPUnit\Framework\TestCase;

// Load the JavaScript minification function
require_once __DIR__ . '/../../lib/js-minify.php';

class JavaScriptMinificationTest extends TestCase
{
    /**
     * Test minification preserves template literals
     */
    public function testMinifyJavaScript_PreservesTemplateLiterals()
    {
        $js = 'const url = `${protocol}//${host}/webcam.php?id=${AIRPORT_ID}`;';
        $minified = minifyJavaScript($js);
        
        $this->assertStringContainsString('`${protocol}//${host}/webcam.php?id=${AIRPORT_ID}`', $minified);
        $this->assertStringNotContainsString('___TEMPLATE_', $minified);
    }

    /**
     * Test minification preserves template literals with HTML-like content
     */
    public function testMinifyJavaScript_PreservesTemplateLiteralsWithHtml()
    {
        $js = 'const html = `<div class="test">${variable}</div>`;';
        $minified = minifyJavaScript($js);
        
        $this->assertStringContainsString('`<div class="test">${variable}</div>`', $minified);
    }

    /**
     * Test minification preserves string literals (double quotes)
     */
    public function testMinifyJavaScript_PreservesDoubleQuotedStrings()
    {
        $js = 'const message = "Hello world"; const url = "https://example.com";';
        $minified = minifyJavaScript($js);
        
        $this->assertStringContainsString('"Hello world"', $minified);
        $this->assertStringContainsString('"https://example.com"', $minified);
    }

    /**
     * Test minification preserves string literals (single quotes)
     */
    public function testMinifyJavaScript_PreservesSingleQuotedStrings()
    {
        $js = "const message = 'Hello world'; const url = 'https://example.com';";
        $minified = minifyJavaScript($js);
        
        $this->assertStringContainsString("'Hello world'", $minified);
        $this->assertStringContainsString("'https://example.com'", $minified);
    }

    /**
     * Test minification preserves PHP tags
     */
    public function testMinifyJavaScript_PreservesPhpTags()
    {
        $js = 'const AIRPORT_ID = \'<?= $airportId ?>\'; const DATA = <?= json_encode($airport) ?>;';
        $minified = minifyJavaScript($js);
        
        $this->assertStringContainsString('<?= $airportId ?>', $minified);
        $this->assertStringContainsString('<?= json_encode($airport) ?>', $minified);
    }

    /**
     * Test minification removes single-line comments
     */
    public function testMinifyJavaScript_RemovesSingleLineComments()
    {
        $js = "const x = 1; // This is a comment\nconst y = 2;";
        $minified = minifyJavaScript($js);
        
        $this->assertStringNotContainsString('// This is a comment', $minified);
        $this->assertStringContainsString('const x = 1;', $minified);
        $this->assertStringContainsString('const y = 2;', $minified);
    }

    /**
     * Test minification removes multi-line comments
     */
    public function testMinifyJavaScript_RemovesMultiLineComments()
    {
        $js = "const x = 1; /* This is a\nmulti-line comment */ const y = 2;";
        $minified = minifyJavaScript($js);
        
        $this->assertStringNotContainsString('/* This is a', $minified);
        $this->assertStringNotContainsString('multi-line comment */', $minified);
        $this->assertStringContainsString('const x = 1;', $minified);
        $this->assertStringContainsString('const y = 2;', $minified);
    }

    /**
     * Test minification doesn't break comments inside strings
     */
    public function testMinifyJavaScript_PreservesCommentsInStrings()
    {
        $js = 'const message = "// This is not a comment"; const code = "/* also not */";';
        $minified = minifyJavaScript($js);
        
        $this->assertStringContainsString('"// This is not a comment"', $minified);
        $this->assertStringContainsString('"/* also not */"', $minified);
    }

    /**
     * Test minification collapses whitespace
     */
    public function testMinifyJavaScript_CollapsesWhitespace()
    {
        $js = "const   x    =    1;    \n\n\nconst    y    =    2;";
        $minified = minifyJavaScript($js);
        
        // Should have reduced whitespace but preserve structure
        $this->assertStringContainsString('const x = 1;', $minified);
        $this->assertStringContainsString('const y = 2;', $minified);
        // Should not have excessive spaces
        $this->assertStringNotContainsString('     ', $minified);
    }

    /**
     * Test minification with complex template literal
     */
    public function testMinifyJavaScript_ComplexTemplateLiteral()
    {
        $js = 'const html = `<div class="${className}">${content}</div>`; const url = `${protocol}//${host}/path?q=${query}`;';
        $minified = minifyJavaScript($js);
        
        $this->assertStringContainsString('`<div class="${className}">${content}</div>`', $minified);
        $this->assertStringContainsString('`${protocol}//${host}/path?q=${query}`', $minified);
    }

    /**
     * Test minification with escaped characters in strings
     */
    public function testMinifyJavaScript_PreservesEscapedCharacters()
    {
        $js = 'const message = "Hello \\"world\\""; const path = \'C:\\\\Users\\\\test\';';
        $minified = minifyJavaScript($js);
        
        $this->assertStringContainsString('"Hello \\"world\\""', $minified);
        $this->assertStringContainsString("'C:\\\\Users\\\\test'", $minified);
    }

    /**
     * Test minification doesn't produce HTML
     */
    public function testMinifyJavaScript_DoesNotProduceHtml()
    {
        $js = 'const x = 1; const y = 2;';
        $minified = minifyJavaScript($js);
        
        // Should not contain HTML tags
        $this->assertStringNotContainsString('<', $minified);
        $this->assertStringNotContainsString('>', $minified);
    }

    /**
     * Test minification with real-world example (webcam URL)
     */
    public function testMinifyJavaScript_RealWorldWebcamUrl()
    {
        $js = 'const jpgUrl = `${protocol}//${host}/webcam.php?id=${AIRPORT_ID}&cam=${camIndex}&fmt=jpg&v=${hashHex}`;';
        $minified = minifyJavaScript($js);
        
        $this->assertStringContainsString('`${protocol}//${host}/webcam.php?id=${AIRPORT_ID}&cam=${camIndex}&fmt=jpg&v=${hashHex}`', $minified);
        // Should not break the URL structure
        $this->assertStringContainsString('webcam.php', $minified);
        $this->assertStringContainsString('&fmt=jpg', $minified);
    }

    /**
     * Test minification with arrow functions
     */
    public function testMinifyJavaScript_PreservesArrowFunctions()
    {
        $js = 'const fn = (x) => x + 1; const arr = [1, 2, 3].map((n) => n * 2);';
        $minified = minifyJavaScript($js);
        
        $this->assertStringContainsString('=>', $minified);
        $this->assertStringContainsString('(x) => x + 1', $minified);
    }

    /**
     * Test minification with comparison operators
     */
    public function testMinifyJavaScript_PreservesComparisonOperators()
    {
        $js = 'if (x < 10 && y > 5) { return x <= y && y >= x; }';
        $minified = minifyJavaScript($js);
        
        $this->assertStringContainsString('<', $minified);
        $this->assertStringContainsString('>', $minified);
        $this->assertStringContainsString('<=', $minified);
        $this->assertStringContainsString('>=', $minified);
    }

    /**
     * Test minification with empty input
     */
    public function testMinifyJavaScript_EmptyInput()
    {
        $js = '';
        $minified = minifyJavaScript($js);
        
        $this->assertEquals('', trim($minified));
    }

    /**
     * Test minification with only whitespace
     */
    public function testMinifyJavaScript_WhitespaceOnly()
    {
        $js = "   \n\n   \t\t   ";
        $minified = minifyJavaScript($js);
        
        $this->assertEquals('', trim($minified));
    }
}

