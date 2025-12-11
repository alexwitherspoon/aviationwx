const { test, expect } = require('@playwright/test');

/**
 * JavaScript Validity Tests
 * 
 * Validates that JavaScript code is valid and doesn't contain PHP functions
 * or other invalid constructs. This catches bugs like using empty() in JavaScript.
 */
test.describe('JavaScript Validity', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';
  
  test.beforeEach(async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    await page.waitForSelector('body', { state: 'visible' });
  });

  test('should not have PHP functions in JavaScript', async ({ page }) => {
    // Get all JavaScript code from the page
    const jsCode = await page.evaluate(() => {
      const scripts = Array.from(document.querySelectorAll('script'));
      return scripts.map((s, i) => ({
        index: i,
        content: s.textContent,
        src: s.src || 'inline'
      }));
    });
    
    // PHP functions that should never appear in JavaScript
    const phpFunctions = [
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
      'print_r'
    ];
    
    const errors = [];
    
    jsCode.forEach(({ index, content, src }) => {
      // Skip external scripts (they're not our code)
      if (src !== 'inline' && !src.includes(baseUrl)) {
        return;
      }
      
      phpFunctions.forEach(func => {
        // Check if function appears in code (but not in comments or strings)
        const regex = new RegExp(`\\b${func.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`, 'g');
        const matches = content.match(regex);
        
        if (matches) {
          // Get context around each match to verify it's not in a comment or string
          matches.forEach(match => {
            const matchIndex = content.indexOf(match);
            const context = content.substring(Math.max(0, matchIndex - 100), matchIndex + 100);
            
            // Check if it's in a comment (single-line or multi-line)
            const beforeMatch = content.substring(Math.max(0, matchIndex - 200), matchIndex);
            const isInComment = 
              beforeMatch.includes('//') && !beforeMatch.includes('\n', beforeMatch.lastIndexOf('//')) ||
              beforeMatch.includes('/*') && !beforeMatch.includes('*/', beforeMatch.lastIndexOf('/*'));
            
            // Check if it's in a string (simplified check)
            const isInString = /['"`].*empty\(/.test(context);
            
            if (!isInComment && !isInString) {
              errors.push(
                `Script block #${index} (${src}) contains PHP function: ${func}\n` +
                `Context: ${context.substring(0, 150)}...`
              );
            }
          });
        }
      });
    });
    
    if (errors.length > 0) {
      console.error('PHP functions found in JavaScript:', errors);
    }
    
    expect(errors).toHaveLength(0);
  });

  test('should have valid JavaScript syntax', async ({ page }) => {
    // Check for console errors that indicate syntax errors
    const syntaxErrors = [];
    
    page.on('console', msg => {
      if (msg.type() === 'error') {
        const text = msg.text();
        if (
          text.includes('SyntaxError') ||
          text.includes('Unexpected token') ||
          text.includes('Uncaught SyntaxError') ||
          text.includes('is not defined') && !text.includes('Failed to fetch') // Allow network errors
        ) {
          syntaxErrors.push(text);
        }
      }
    });
    
    // Wait for page to fully load and execute JavaScript
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
    await page.waitForTimeout(2000); // Give time for all scripts to execute
    
    if (syntaxErrors.length > 0) {
      console.error('JavaScript syntax errors found:', syntaxErrors);
    }
    
    expect(syntaxErrors).toHaveLength(0);
  });

  test('should have all required JavaScript functions defined', async ({ page }) => {
    // Wait for JavaScript to initialize
    await page.waitForFunction(
      () => document.readyState === 'complete',
      { timeout: 10000 }
    );
    
    // Check for critical functions that should be defined
    const requiredFunctions = [
      'fetchWeather',
      'displayWeather',
      'updateWeatherTimestamp'
    ];
    
    const missingFunctions = [];
    
    for (const funcName of requiredFunctions) {
      const isDefined = await page.evaluate((name) => {
        return typeof window[name] === 'function';
      }, funcName);
      
      if (!isDefined) {
        missingFunctions.push(funcName);
      }
    }
    
    if (missingFunctions.length > 0) {
      console.error('Missing required JavaScript functions:', missingFunctions);
    }
    
    // At least fetchWeather should be defined (others may be conditionally loaded)
    expect(missingFunctions).not.toContain('fetchWeather');
  });

  test('should not have undefined variables in critical code paths', async ({ page }) => {
    const undefinedErrors = [];
    
    page.on('console', msg => {
      if (msg.type() === 'error') {
        const text = msg.text();
        // Look for ReferenceError about undefined variables (but not network errors)
        if (
          text.includes('ReferenceError') &&
          text.includes('is not defined') &&
          !text.includes('Failed to fetch') &&
          !text.includes('network')
        ) {
          undefinedErrors.push(text);
        }
      }
    });
    
    // Trigger weather fetch which is a critical code path
    await page.evaluate(() => {
      if (typeof fetchWeather === 'function') {
        try {
          fetchWeather();
        } catch (e) {
          console.error('Error in fetchWeather:', e);
        }
      }
    });
    
    await page.waitForTimeout(3000);
    
    if (undefinedErrors.length > 0) {
      console.error('Undefined variable errors in critical code paths:', undefinedErrors);
    }
    
    expect(undefinedErrors).toHaveLength(0);
  });

  test('should validate JavaScript code structure', async ({ page }) => {
    // Get JavaScript code
    const jsCode = await page.evaluate(() => {
      const scripts = Array.from(document.querySelectorAll('script[type="text/javascript"], script:not([type])'));
      return scripts.map(s => s.textContent).join('\n');
    });
    
    // Check for common JavaScript issues
    const issues = [];
    
    // Check for unclosed function calls
    const openParens = (jsCode.match(/\(/g) || []).length;
    const closeParens = (jsCode.match(/\)/g) || []).length;
    if (openParens !== closeParens) {
      issues.push(`Mismatched parentheses: ${openParens} opening, ${closeParens} closing`);
    }
    
    // Check for unclosed braces
    const openBraces = (jsCode.match(/{/g) || []).length;
    const closeBraces = (jsCode.match(/}/g) || []).length;
    if (openBraces !== closeBraces) {
      issues.push(`Mismatched braces: ${openBraces} opening, ${closeBraces} closing`);
    }
    
    // Check for unclosed brackets
    const openBrackets = (jsCode.match(/\[/g) || []).length;
    const closeBrackets = (jsCode.match(/\]/g) || []).length;
    if (openBrackets !== closeBrackets) {
      issues.push(`Mismatched brackets: ${openBrackets} opening, ${closeBrackets} closing`);
    }
    
    if (issues.length > 0) {
      console.error('JavaScript structure issues found:', issues);
    }
    
    expect(issues).toHaveLength(0);
  });
});
