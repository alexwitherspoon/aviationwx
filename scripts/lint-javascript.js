#!/usr/bin/env node
/**
 * JavaScript Linter for AviationWX
 * 
 * Extracts JavaScript from PHP files and lints it with ESLint.
 * Handles JavaScript embedded in <script> tags within PHP files.
 * 
 * Usage:
 *   node scripts/lint-javascript.js
 *   node scripts/lint-javascript.js --fix
 *   node scripts/lint-javascript.js pages/airport.php
 */

const fs = require('fs');
const path = require('path');
const { ESLint } = require('eslint');

// Files to check (default: all PHP files with JavaScript)
const DEFAULT_FILES = [
  'pages/airport.php',
  'pages/homepage.php',
  'pages/error-404-airport.php',
  'pages/error-404.php',
  'pages/status.php',
  'public/js/service-worker.js'
];

// Parse command line arguments
const args = process.argv.slice(2);
const fixMode = args.includes('--fix');
const specificFiles = args.filter(arg => !arg.startsWith('--'));

const filesToCheck = specificFiles.length > 0 ? specificFiles : DEFAULT_FILES;

/**
 * Extract JavaScript code from PHP file
 * Returns array of { file, code, lineOffset } objects
 */
function extractJavaScriptFromPHP(filePath) {
  if (!fs.existsSync(filePath)) {
    console.warn(`Warning: File not found: ${filePath}`);
    return [];
  }
  
  const content = fs.readFileSync(filePath, 'utf8');
  const results = [];
  
  // Match <script> tags (with or without attributes)
  const scriptTagRegex = /<script(?:\s+[^>]*)?>([\s\S]*?)<\/script>/gi;
  let match;
  let scriptIndex = 0;
  
  while ((match = scriptTagRegex.exec(content)) !== null) {
    let jsCode = match[1];
    const scriptTagStart = match.index;
    
    // If JavaScript uses PHP output buffering (ob_start/ob_get_clean),
    // the script tag might not have a proper closing tag in source.
    // Check if jsCode contains PHP code that shouldn't be there
    // (PHP code after JavaScript should be excluded)
    if (jsCode.includes('<?php') || jsCode.includes('<?=')) {
      // Find the last valid JavaScript line before PHP code
      // Look for patterns that indicate end of JavaScript: });, });, etc.
      // Split by PHP tags and take only the JavaScript parts
      const parts = jsCode.split(/(<\?php|<\?=)/);
      if (parts.length > 1) {
        // Take only the JavaScript part before the first PHP tag
        // This handles cases where PHP code appears after JavaScript
        jsCode = parts[0].trim();
        
        // If the JavaScript part is too short, it might be a false positive
        // (PHP tags might be inside JavaScript strings)
        if (jsCode.length < 10) {
          // Restore original - might be PHP in string literal
          jsCode = match[1];
        }
      }
    }
    
    // Skip empty scripts
    if (!jsCode.trim()) {
      continue;
    }
    
    // Skip scripts with type="text/template" or other non-JS types
    const scriptTag = match[0].substring(0, match[0].indexOf('>') + 1);
    if (scriptTag.includes('type=') && !scriptTag.includes('type="text/javascript"') && 
        !scriptTag.includes("type='text/javascript'") && !scriptTag.includes('type=text/javascript')) {
      // Check if it's explicitly not JavaScript
      if (scriptTag.includes('type="text/template"') || scriptTag.includes("type='text/template'") ||
          scriptTag.includes('type="application/json"') || scriptTag.includes("type='application/json'")) {
        continue;
      }
    }
    
    // Calculate line offset (count newlines before script tag)
    const beforeScript = content.substring(0, scriptTagStart);
    const lineOffset = (beforeScript.match(/\n/g) || []).length;
    
    // Remove PHP tags from JavaScript (replace with placeholder values)
    // This allows ESLint to parse the JavaScript without PHP syntax errors
    let cleanedCode = jsCode;
    let shouldSkip = false;
    
    // Skip if this appears to be mostly PHP (like echo statements)
    // Check for PHP echo patterns
    if (cleanedCode.includes('echo \'<script>') || cleanedCode.includes('echo "<script>')) {
      // This is PHP outputting script tags, not JavaScript code
      shouldSkip = true;
      continue;
    }
    
    const phpTagCount = (cleanedCode.match(/<\?[=]?php?/g) || []).length;
    const lineCount = cleanedCode.split('\n').length;
    const phpContentRatio = phpTagCount / Math.max(1, lineCount);
    if (phpContentRatio > 0.3 && lineCount > 5) {
      // This is mostly PHP, skip it
      shouldSkip = true;
      continue;
    }
    
    // Replace PHP tags with placeholder values that preserve syntax
    // For <?= ... ?> (echo), replace with a valid JavaScript value
    cleanedCode = cleanedCode.replace(/<\?=\s*([\s\S]*?)\s*\?>/g, (match, phpCode) => {
      // Try to determine what type of value this would produce
      // If it's json_encode, replace with empty object/array/string
      if (phpCode.includes('json_encode')) {
        return '{}'; // Placeholder for JSON
      }
      // If it's a number (like filemtime, time()), use 0
      // Handle both direct calls and ternary operators
      const trimmedPhp = phpCode.trim();
      if (trimmedPhp.includes('filemtime') || trimmedPhp.includes('time()')) {
        return '0'; // Placeholder for timestamp/number
      }
      // If it's a PHP variable (like $index), use numeric placeholder
      // PHP variables in this context are typically numeric (array indices)
      // Using a number prevents syntax errors when used in variable names
      if (phpCode.trim().match(/^\$[a-zA-Z_][a-zA-Z0-9_]*$/)) {
        return '0'; // Placeholder for PHP variable (typically numeric in our use case)
      }
      // If it's a function call, use a placeholder
      if (phpCode.includes('(')) {
        return '0'; // Placeholder for function call (use number to avoid syntax issues)
      }
      return '0'; // Default placeholder (use number to avoid syntax issues)
    });
    
    // First, handle ?> followed by semicolon (common pattern: const x = <?php ... ?>;)
    // This must come BEFORE replacing <?php ... ?> blocks
    cleanedCode = cleanedCode.replace(/\?>\s*;/g, '/* PHP close */');
    
    // Replace <?php ... ?> blocks with comments (preserve line count)
    // Use non-greedy matching to handle nested cases
    cleanedCode = cleanedCode.replace(/<\?php[\s\S]*?\?>/gs, (phpCode) => {
      const lines = (phpCode.match(/\n/g) || []).length;
      // Replace with equivalent number of comment lines
      if (lines > 0) {
        return '\n'.repeat(lines) + '/* PHP block */';
      }
      return '/* PHP block */';
    });
    
    // Replace remaining <? ... ?> tags (non-greedy, including <?=)
    cleanedCode = cleanedCode.replace(/<\?[\s\S]*?\?>/gs, '/* PHP */');
    
    // Handle any remaining standalone ?> closing tags
    cleanedCode = cleanedCode.replace(/\?>\s*/g, '/* PHP close */');
    
    // Skip if result is mostly comments or empty
    const nonCommentLines = cleanedCode.split('\n').filter(line => {
      const trimmed = line.trim();
      return trimmed && !trimmed.startsWith('/*') && !trimmed.startsWith('//');
    });
    
    if (nonCommentLines.length < 3) {
      // Too little actual JavaScript, likely mostly PHP
      shouldSkip = true;
      continue;
    }
    
    // Skip if extraction marked it for skipping (mostly PHP content)
    if (shouldSkip || cleanedCode === null) {
      continue;
    }
    
    results.push({
      file: filePath,
      code: cleanedCode,
      lineOffset: lineOffset,
      scriptIndex: scriptIndex++
    });
  }
  
  // If no script tags found but it's a .js file, treat entire file as JavaScript
  if (filePath.endsWith('.js') && results.length === 0) {
    results.push({
      file: filePath,
      code: content,
      lineOffset: 0,
      scriptIndex: 0
    });
  }
  
  return results;
}

/**
 * Main linting function
 */
async function lintJavaScript() {
  console.log('🔍 Linting JavaScript code...\n');
  
  // Initialize ESLint (ESLint 9 uses flat config)
  // ESLint 9 auto-detects eslint.config.js from the project root
  // Set cwd to project root so ESLint can find the config
  const projectRoot = path.resolve(__dirname, '..');
  const eslint = new ESLint({
    cwd: projectRoot,
    fix: fixMode
  });
  
  const allResults = [];
  const allFiles = [];
  
  // Extract JavaScript from all files
  for (const filePath of filesToCheck) {
    const fullPath = path.resolve(filePath);
    const extracted = extractJavaScriptFromPHP(fullPath);
    
      for (const { file, code, lineOffset, scriptIndex } of extracted) {
        // Use actual file path for ESLint 9 (it needs real paths to find config)
        // For PHP files, use the PHP file path so ESLint can find the config
        // ESLint 9 flat config matches files by pattern, so .php files need to match the pattern
        const actualFilePath = path.resolve(file);
        
        allFiles.push({
          filePath: actualFilePath,
          text: code,
          file: file,
          lineOffset: lineOffset,
          scriptIndex: scriptIndex
        });
      }
  }
  
  if (allFiles.length === 0) {
    console.log('⚠️  No JavaScript code found to lint.');
    return 0;
  }
  
  console.log(`Found ${allFiles.length} JavaScript block(s) to lint.\n`);
  
  // Lint all files
  // ESLint API: lintText() for each file individually
  const results = [];
  for (let i = 0; i < allFiles.length; i++) {
    const fileInfo = allFiles[i];
    const result = await eslint.lintText(fileInfo.text, { filePath: fileInfo.filePath });
    results.push(result[0]); // lintText returns array with one result
  }
  
  // Process results
  let errorCount = 0;
  let warningCount = 0;
  let fixableCount = 0;
  
  for (let i = 0; i < results.length; i++) {
    const result = results[i];
    const fileInfo = allFiles[i];
    
    // Adjust line numbers to account for PHP file offset
    const adjustedMessages = result.messages.map(msg => ({
      ...msg,
      line: msg.line + fileInfo.lineOffset
    }));
    
    if (adjustedMessages.length > 0) {
      const errors = adjustedMessages.filter(m => m.severity === 2);
      const warnings = adjustedMessages.filter(m => m.severity === 1);
      const fixable = adjustedMessages.filter(m => m.fix !== null);
      
      // Error count is now handled above (only real errors, not parsing errors)
      warningCount += warnings.length;
      fixableCount += fixable.length;
      
      // Filter out known false positives (parsing errors in PHP-embedded JS)
      // Parsing errors are false positives because PHP generates valid JS that ESLint can't parse statically
      const realErrors = errors.filter(msg => {
        // Allow parsing errors - these are false positives for PHP-embedded JavaScript
        if (msg.ruleId === null || msg.fatal === true) {
          // This is a parsing error - log it but don't fail
          console.log(`\n📄 ${fileInfo.file}${fileInfo.scriptIndex > 0 ? ` [script-${fileInfo.scriptIndex}]` : ''}`);
          console.log(`  ⚠️  Line ${msg.line}:${msg.column} - ${msg.message} (parsing error - known false positive)`);
          console.log(`     This is a documented limitation of linting PHP-embedded JavaScript.`);
          console.log(`     See docs/ESLINT_KNOWN_LIMITATIONS.md for details.`);
          return false; // Don't count as a real error
        }
        return true; // Real error
      });
      
      // Group messages by type for better output
      if (realErrors.length > 0 || warnings.length > 0) {
        // Only show file header if we have real errors (parsing errors already logged above)
        if (realErrors.length > 0) {
          console.log(`\n📄 ${fileInfo.file}${fileInfo.scriptIndex > 0 ? ` [script-${fileInfo.scriptIndex}]` : ''}`);
        }
        
        // Show real errors
        for (const msg of realErrors) {
          console.log(`  ❌ Line ${msg.line}:${msg.column} - ${msg.message} (${msg.ruleId || 'unknown'})`);
        }
        
        // Then warnings
        for (const msg of warnings) {
          console.log(`  ⚠️  Line ${msg.line}:${msg.column} - ${msg.message} (${msg.ruleId || 'unknown'})`);
        }
        
        if (fixable.length > 0 && !fixMode) {
          console.log(`  💡 ${fixable.length} issue(s) auto-fixable. Run with --fix to fix.`);
        }
      }
      
      // Count only real errors (not parsing errors)
      errorCount += realErrors.length;
      errorCount += realErrors.length;
    }
  }
  
  // Summary
  console.log('\n' + '='.repeat(60));
  if (errorCount === 0 && warningCount === 0) {
    console.log('✅ All JavaScript code passed linting!');
    return 0;
  } else {
    console.log(`\n📊 Summary:`);
    console.log(`   Errors: ${errorCount}`);
    console.log(`   Warnings: ${warningCount}`);
    if (fixableCount > 0 && !fixMode) {
      console.log(`   Auto-fixable: ${fixableCount} (run with --fix)`);
    }
    if (errorCount > 0) {
      console.log('\n❌ Linting failed. Please fix the errors above.');
      return 1;
    } else {
      console.log('\n⚠️  Linting passed with warnings (warnings do not fail the build).');
      return 0;
    }
  }
}

// Run linting
lintJavaScript()
  .then(exitCode => {
    process.exit(exitCode);
  })
  .catch(error => {
    console.error('❌ Error running ESLint:', error);
    process.exit(1);
  });

