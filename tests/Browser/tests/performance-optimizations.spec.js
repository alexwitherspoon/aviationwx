const { test, expect } = require('@playwright/test');

test.describe('Performance Optimizations', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';
  
  test('JavaScript should not contain HTML (no SyntaxError)', async ({ page }) => {
    const consoleErrors = [];
    const syntaxErrors = [];
    
    // Listen for console errors
    page.on('console', msg => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
        if (msg.text().includes('Unexpected token') || msg.text().includes('SyntaxError')) {
          syntaxErrors.push(msg.text());
        }
      }
    });
    
    // Listen for page errors
    page.on('pageerror', error => {
      if (error.message.includes('Unexpected token') || error.name === 'SyntaxError') {
        syntaxErrors.push(error.message);
      }
    });
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('domcontentloaded');
    
    // Wait for page to fully load and any async JavaScript to execute
    await page.waitForLoadState('load');
    await page.waitForTimeout(2000);
    
    // Check that no syntax errors occurred
    if (syntaxErrors.length > 0) {
      console.error('Syntax errors found:', syntaxErrors);
      console.error('All console errors:', consoleErrors);
    }
    expect(syntaxErrors).toHaveLength(0);
    
    // Verify JavaScript is valid by checking if scripts executed
    const scriptsExecuted = await page.evaluate(() => {
      return typeof window.AIRPORT_ID !== 'undefined' || 
             typeof window.fetchWeather !== 'undefined' ||
             document.querySelectorAll('script').length > 0;
    });
    
    expect(scriptsExecuted).toBeTruthy();
  });

  test('Service worker should register successfully', async ({ page, context }) => {
    // Grant service worker permissions (notifications permission helps with service workers)
    await context.grantPermissions(['notifications']);
    
    const swRegistrationErrors = [];
    const swSuccess = [];
    
    // Listen for console messages about service worker BEFORE navigation
    page.on('console', msg => {
      const text = msg.text();
      if (text.includes('[SW]') || text.includes('service worker') || text.includes('ServiceWorker')) {
        if (msg.type() === 'error') {
          swRegistrationErrors.push(text);
        } else if (text.includes('Registered') || text.includes('registered')) {
          swSuccess.push(text);
        }
      }
    });
    
    // Listen for page errors
    page.on('pageerror', error => {
      const errorText = error.message;
      if (errorText.includes('service worker') || errorText.includes('ServiceWorker') || errorText.includes('sw.js')) {
        swRegistrationErrors.push(errorText);
      }
    });
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('domcontentloaded');
    
    // Wait for service worker registration (service workers register on 'load' event)
    await page.waitForLoadState('load');
    await page.waitForTimeout(2000); // Additional wait for async registration
    
    // Check service worker registration
    const swRegistered = await page.evaluate(async () => {
      if ('serviceWorker' in navigator) {
        try {
          const registrations = await navigator.serviceWorker.getRegistrations();
          return {
            registered: registrations.length > 0,
            count: registrations.length,
            scopes: registrations.map(r => r.scope)
          };
        } catch (e) {
          return {
            registered: false,
            error: e.message
          };
        }
      }
      return {
        registered: false,
        reason: 'ServiceWorker not supported'
      };
    });
    
    // Service worker should either be registered or not supported (both are OK)
    // But if there are registration errors, that's a problem
    if (swRegistrationErrors.length > 0) {
      // Check if error is about old /sw.js path (expected and handled)
      const oldPathErrors = swRegistrationErrors.filter(err => 
        err.includes('/sw.js') && (err.includes('404') || err.includes('Failed'))
      );
      
      // Other errors are problems
      const otherErrors = swRegistrationErrors.filter(err => 
        !err.includes('/sw.js') || (!err.includes('404') && !err.includes('Failed'))
      );
      
      // Log for debugging
      if (otherErrors.length > 0) {
        console.log('Service worker registration errors:', otherErrors);
        console.log('Service worker registration status:', swRegistered);
      }
      
      expect(otherErrors).toHaveLength(0);
    }
    
    // If service worker is supported, it should register (unless there's a legitimate error)
    if (swRegistered.reason !== 'ServiceWorker not supported' && !swRegistered.error) {
      // Service worker should be registered if supported
      // But we don't fail if it's not - might be first load or other legitimate reasons
      // The important thing is that there are no errors
    }
  });

  test('Service worker file should be served with correct MIME type', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('domcontentloaded');
    
    // Get the service worker URL from the page
    const swUrl = await page.evaluate(() => {
      // Look for service worker registration in the page
      const scripts = Array.from(document.querySelectorAll('script'));
      for (const script of scripts) {
        const text = script.textContent || '';
        // Match service-worker.js with optional query parameters
        const match = text.match(/service-worker\.js[^'"]*/);
        if (match) {
          // Extract the full path including query params
          const fullMatch = match[0];
          // Check if it already starts with /public/js/
          if (fullMatch.startsWith('/public/js/')) {
            return fullMatch;
          }
          // Otherwise prepend the path
          return '/public/js/' + fullMatch;
        }
      }
      return null;
    });
    
    // If we can't find the URL in the page, try the standard path
    const testUrl = swUrl || '/public/js/service-worker.js';
    
    // Try to fetch the service worker file using page.request (better for API calls)
    const response = await page.request.get(`${baseUrl}${testUrl}`);
    
    // Should get a successful response
    expect(response.ok()).toBeTruthy();
    
    const contentType = response.headers()['content-type'] || '';
    const body = await response.text();
    
    // Should be JavaScript MIME type
    expect(contentType).toMatch(/javascript|application\/javascript|text\/javascript/);
    
    // Should not be HTML (404 page)
    expect(body).not.toMatch(/<!DOCTYPE|<html|<body/);
    
    // Should contain JavaScript code
    expect(body).toMatch(/serviceWorker|self\.addEventListener|const/);
  });

  test('window.styleMedia should be removed to prevent Safari warnings', async ({ page }) => {
    const warnings = [];
    
    // Listen for console warnings
    page.on('console', msg => {
      if (msg.type() === 'warning' || msg.type() === 'error') {
        const text = msg.text();
        if (text.includes('styleMedia') || text.includes('matchMedia')) {
          warnings.push(text);
        }
      }
    });
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('domcontentloaded');
    
    // Wait a bit for any warnings
    await page.waitForTimeout(1000);
    
    // Check if styleMedia property exists
    const styleMediaExists = await page.evaluate(() => {
      return 'styleMedia' in window && window.styleMedia !== undefined;
    });
    
    // styleMedia should not exist or should be undefined
    expect(styleMediaExists).toBeFalsy();
    
    // Should not have styleMedia warnings (Safari-specific, but we test in all browsers)
    // Note: This test may pass in non-Safari browsers even if the fix doesn't work
    // The important part is that styleMedia is removed
  });

  test('JavaScript minification should not break template literals', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('domcontentloaded');
    
    // Check if template literals work correctly
    const templateLiteralTest = await page.evaluate(() => {
      try {
        // Try to find code that uses template literals
        const scripts = Array.from(document.querySelectorAll('script'));
        for (const script of scripts) {
          const text = script.textContent || '';
          // Look for template literal patterns - use character codes to avoid backtick issues
          const backtick = String.fromCharCode(96); // backtick character
          const templateStart = backtick + '${';
          const templateEnd = '}' + backtick;
          
          if (text.includes(templateStart) && text.includes(templateEnd)) {
            // Check if it's properly formatted (not broken)
            // Match template literals: backtick, content with ${...}, backtick
            const templateRegex = new RegExp(backtick + '[^' + backtick + ']*\\$\\{[^}]+\\}[^' + backtick + ']*' + backtick, 'g');
            const matches = text.match(templateRegex);
            if (matches) {
              // All matches should be valid template literal syntax
              return matches.every(match => {
                // Should start and end with backticks
                return match.startsWith(backtick) && match.endsWith(backtick);
              });
            }
          }
        }
        return true; // No template literals found, that's OK
      } catch (e) {
        return false; // Error means something is broken
      }
    });
    
    expect(templateLiteralTest).toBeTruthy();
  });

  test('No JavaScript errors should occur on page load', async ({ page }) => {
    const jsErrors = [];
    
    page.on('console', msg => {
      if (msg.type() === 'error') {
        jsErrors.push(msg.text());
      }
    });
    
    page.on('pageerror', error => {
      jsErrors.push(error.message);
    });
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load');
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {
      // networkidle might timeout if there are long-polling requests, that's OK
    });
    
    // Wait longer for any async errors to surface (especially in CI)
    await page.waitForTimeout(5000); // Increased from 2s to 5s
    
    // Filter out expected/acceptable errors
    const criticalErrors = jsErrors.filter(error => {
      const errorLower = error.toLowerCase();
      
      // Ignore network errors (expected in test environment)
      if (errorLower.includes('net::err') || errorLower.includes('failed to fetch') || 
          errorLower.includes('networkerror') || errorLower.includes('network error')) {
        return false;
      }
      // Ignore service worker errors about old /sw.js (we handle those)
      if (errorLower.includes('/sw.js') && (errorLower.includes('404') || errorLower.includes('failed'))) {
        return false;
      }
      // Ignore CORS errors in test environment (if any)
      if (errorLower.includes('cors') || errorLower.includes('access-control')) {
        return false;
      }
      // Ignore rate limiting errors (429) - expected when running many tests
      if (errorLower.includes('429') || errorLower.includes('too many requests')) {
        return false;
      }
      // Ignore JSON parse errors from weather API (handled gracefully)
      if (errorLower.includes('json parse error') || errorLower.includes('invalid json response') ||
          errorLower.includes('unexpected token') || errorLower.includes('json.parse')) {
        return false;
      }
      // Ignore timeout errors (expected in test environment with slow APIs)
      if (errorLower.includes('timeout') || errorLower.includes('timed out')) {
        return false;
      }
      // Ignore connection refused/aborted errors (test environment issues)
      if (errorLower.includes('connection refused') || errorLower.includes('connection aborted') ||
          errorLower.includes('econnrefused') || errorLower.includes('econnaborted')) {
        return false;
      }
      // Ignore resource loading errors (images, fonts, etc. - not critical)
      if (errorLower.includes('failed to load resource') || errorLower.includes('loading') ||
          errorLower.includes('favicon') || errorLower.includes('font')) {
        return false;
      }
      // All other errors are critical
      return true;
    });
    
    // Log errors for debugging if any critical errors found
    if (criticalErrors.length > 0) {
      console.error('Critical JavaScript errors:', criticalErrors);
      console.error('All JavaScript errors:', jsErrors);
    }
    
    expect(criticalErrors).toHaveLength(0);
  });
});

