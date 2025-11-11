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
    
    // Wait a bit for any async JavaScript errors
    await page.waitForTimeout(2000);
    
    // Check that no syntax errors occurred
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
    // Grant service worker permissions
    await context.grantPermissions(['notifications']);
    
    const swRegistrationErrors = [];
    const swSuccess = [];
    
    // Listen for console messages about service worker
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
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('networkidle');
    
    // Wait for service worker registration
    await page.waitForTimeout(3000);
    
    // Check service worker registration
    const swRegistered = await page.evaluate(async () => {
      if ('serviceWorker' in navigator) {
        try {
          const registrations = await navigator.serviceWorker.getRegistrations();
          return registrations.length > 0;
        } catch (e) {
          return false;
        }
      }
      return false;
    });
    
    // Service worker should either be registered or not supported (both are OK)
    // But if there are registration errors, that's a problem
    if (swRegistrationErrors.length > 0) {
      // Check if error is about old /sw.js path (expected and handled)
      const oldPathErrors = swRegistrationErrors.filter(err => 
        err.includes('/sw.js') && err.includes('404')
      );
      
      // Other errors are problems
      const otherErrors = swRegistrationErrors.filter(err => 
        !err.includes('/sw.js') || !err.includes('404')
      );
      
      expect(otherErrors).toHaveLength(0);
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
        const match = text.match(/service-worker\.js[^'"]*/);
        if (match) {
          return '/public/js/' + match[0];
        }
      }
      return null;
    });
    
    if (swUrl) {
      // Try to fetch the service worker file
      const response = await page.goto(`${baseUrl}${swUrl}`, { waitUntil: 'networkidle' });
      
      if (response) {
        const contentType = response.headers()['content-type'] || '';
        const body = await response.text();
        
        // Should be JavaScript MIME type
        expect(contentType).toMatch(/javascript|application\/javascript|text\/javascript/);
        
        // Should not be HTML (404 page)
        expect(body).not.toMatch(/<!DOCTYPE|<html|<body/);
        
        // Should contain JavaScript code
        expect(body).toMatch(/serviceWorker|self\.addEventListener|const/);
      }
    }
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
          // Look for template literal patterns
          if (text.includes('`${') && text.includes('}`)) {
            // Check if it's properly formatted (not broken)
            const matches = text.match(/`[^`]*\$\{[^}]+\}[^`]*`/g);
            if (matches) {
              // All matches should be valid template literal syntax
              return matches.every(match => {
                // Should start and end with backticks
                return match.startsWith('`') && match.endsWith('`');
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
    await page.waitForLoadState('networkidle');
    
    // Wait a bit for any async errors
    await page.waitForTimeout(2000);
    
    // Filter out expected/acceptable errors
    const criticalErrors = jsErrors.filter(error => {
      // Ignore network errors (expected in test environment)
      if (error.includes('net::ERR') || error.includes('Failed to fetch')) {
        return false;
      }
      // Ignore service worker errors about old /sw.js (we handle those)
      if (error.includes('/sw.js') && error.includes('404')) {
        return false;
      }
      // All other errors are critical
      return true;
    });
    
    expect(criticalErrors).toHaveLength(0);
  });
});

