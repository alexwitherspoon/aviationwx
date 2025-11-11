<?php
/**
 * JavaScript Minification Utility
 * Preserves template literals, string literals, PHP tags, and doesn't break JavaScript syntax
 */

function minifyJavaScript($js) {
    // Protect PHP tags by replacing them with placeholders
    $phpTags = [];
    $placeholder = '___PHP_TAG_' . uniqid() . '___';
    $pattern = '/<\?[=]?php?[^>]*\?>/';
    preg_match_all($pattern, $js, $matches);
    foreach ($matches[0] as $i => $tag) {
        $js = str_replace($tag, $placeholder . $i, $js);
        $phpTags[$i] = $tag;
    }
    
    // Protect template literals (backtick strings) - they can contain any characters
    $templateLiterals = [];
    $templatePlaceholder = '___TEMPLATE_' . uniqid() . '___';
    $templatePattern = '/`(?:[^`\\\\]|\\\\.|`)*`/s';
    preg_match_all($templatePattern, $js, $templateMatches);
    foreach ($templateMatches[0] as $i => $template) {
        $js = str_replace($template, $templatePlaceholder . $i, $js);
        $templateLiterals[$i] = $template;
    }
    
    // Protect string literals (single and double quotes) to avoid breaking them
    $stringLiterals = [];
    $stringPlaceholder = '___STRING_' . uniqid() . '___';
    // Match strings, handling escaped quotes
    $stringPattern = '/(["\'])(?:[^\\\\\1]|\\\\.)*?\1/s';
    preg_match_all($stringPattern, $js, $stringMatches);
    foreach ($stringMatches[0] as $i => $string) {
        $js = str_replace($string, $stringPlaceholder . $i, $js);
        $stringLiterals[$i] = $string;
    }
    
    // Remove single-line comments (but not inside protected strings)
    $js = preg_replace('/\/\/[^\n\r]*/m', '', $js);
    
    // Remove multi-line comments
    $js = preg_replace('/\/\*[\s\S]*?\*\//', '', $js);
    
    // Remove leading/trailing whitespace from lines
    $js = preg_replace('/^\s+|\s+$/m', '', $js);
    
    // Collapse multiple spaces/newlines to single space (but preserve newlines in some contexts)
    $js = preg_replace('/[ \t]+/', ' ', $js);
    $js = preg_replace('/\n\s*\n+/', "\n", $js);
    
    // Restore string literals
    foreach ($stringLiterals as $i => $string) {
        $js = str_replace($stringPlaceholder . $i, $string, $js);
    }
    
    // Restore template literals
    foreach ($templateLiterals as $i => $template) {
        $js = str_replace($templatePlaceholder . $i, $template, $js);
    }
    
    // Restore PHP tags
    foreach ($phpTags as $i => $tag) {
        $js = str_replace($placeholder . $i, $tag, $js);
    }
    
    return trim($js);
}

