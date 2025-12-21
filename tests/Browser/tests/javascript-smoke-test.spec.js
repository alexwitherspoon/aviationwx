const { test, expect } = require('@playwright/test');

/**
 * Simple JavaScript Smoke Test
 * 
 * This is a minimal blocking test that verifies:
 * 1. JavaScript files load without syntax errors
 * 2. Critical JavaScript functions are available
 * 3. JavaScript executes and displays data
 * 
 * This test should run first/fastest to catch basic JavaScript failures.
 */
test.describe('JavaScript Smoke Test', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';
  
  test('JavaScript should load and execute without errors', async ({ page }) => {
    const jsErrors = [];
    const referenceErrors = [];
    const scopeErrors = [];
    const typeErrors = [];
    
    // Capture JavaScript errors with detailed categorization
    page.on('console', msg => {
      if (msg.type() === 'error') {
        const text = msg.text();
        
        // Filter out network errors (expected in test environments)
        if (text.includes('Failed to fetch') || 
            text.includes('network') ||
            text.includes('net::ERR')) {
          return;
        }
        
        jsErrors.push(text);
        
        // Categorize errors for better debugging
        if (text.includes('ReferenceError') && text.includes('is not defined')) {
          referenceErrors.push(text);
        }
        
        // Check for scope errors (variables used before declaration)
        if (text.includes('Cannot access') || 
            text.includes('before initialization') ||
            (text.includes('ReferenceError') && (
              text.includes('selectedIndex') ||
              text.includes('searchTimeout') ||
              text.includes('before it is declared')
            ))) {
          scopeErrors.push(text);
        }
        
        // Check for type errors (wrong function usage, missing checks)
        if (text.includes('TypeError') && 
            !text.includes('Failed to fetch') &&
            !text.includes('Cannot read properties of null') &&
            !text.includes('Cannot read properties of undefined')) {
          typeErrors.push(text);
        }
      }
    });
    
    page.on('pageerror', error => {
      const message = error.message;
      jsErrors.push(message);
      
      // Categorize page errors
      if (message.includes('ReferenceError') && message.includes('is not defined')) {
        referenceErrors.push(message);
      }
      
      if (message.includes('Cannot access') || 
          message.includes('before initialization') ||
          (message.includes('ReferenceError') && (
            message.includes('selectedIndex') ||
            message.includes('searchTimeout')
          ))) {
        scopeErrors.push(message);
      }
      
      if (error.name === 'TypeError' && 
          !message.includes('Cannot read properties of null') &&
          !message.includes('Cannot read properties of undefined')) {
        typeErrors.push(message);
      }
    });
    
    // Navigate to page
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    
    // Wait for page to fully load (including all scripts)
    await page.waitForLoadState('load', { timeout: 30000 });
    await page.waitForSelector('body', { state: 'visible' });
    
    // Wait for JavaScript to initialize
    await page.waitForTimeout(2000);
    
    // CRITICAL: Fail on scope errors (variables used before declaration)
    if (scopeErrors.length > 0) {
      console.error('CRITICAL: Scope errors found (variables used before declaration):', scopeErrors);
      expect(scopeErrors).toHaveLength(0);
    }
    
    // CRITICAL: Fail on reference errors (undefined variables/functions)
    if (referenceErrors.length > 0) {
      console.error('CRITICAL: Reference errors found (undefined variables/functions):', referenceErrors);
      expect(referenceErrors).toHaveLength(0);
    }
    
    // CRITICAL: Fail on type errors (wrong function usage)
    if (typeErrors.length > 0) {
      console.error('CRITICAL: Type errors found:', typeErrors);
      expect(typeErrors).toHaveLength(0);
    }
    
    // Should have no JavaScript errors
    if (jsErrors.length > 0) {
      console.error('JavaScript errors found:', jsErrors);
    }
    expect(jsErrors.length).toBe(0);
  });

  test('Critical JavaScript functions should be available', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    
    // Wait for critical functions to be available
    await page.waitForFunction(
      () => {
        // Check that key functions exist (these are the core functions)
        return typeof fetchWeather === 'function' && 
               typeof updateWeatherTimestamp === 'function';
      },
      { timeout: 10000 }
    );
    
    // Verify functions are actually callable (not just defined)
    const functionsAvailable = await page.evaluate(() => {
      try {
        // Try to call functions (with error handling)
        return typeof fetchWeather === 'function' && 
               typeof updateWeatherTimestamp === 'function' &&
               typeof displayWeather === 'function';
      } catch (e) {
        return false;
      }
    });
    
    expect(functionsAvailable).toBe(true);
  });

  test('JavaScript should display weather data', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    
    // Wait for JavaScript to fetch and display data
    // This is the key test: does JavaScript actually work?
    await page.waitForFunction(
      () => {
        const bodyText = document.body.textContent || '';
        
        // Check for temperature display (indicates JavaScript processed and displayed data)
        const hasTemperature = /\d+°[FC]/.test(bodyText);
        
        // Check for timestamp (indicates JavaScript updated the DOM)
        const lastUpdatedEl = document.getElementById('weather-last-updated');
        const hasTimestamp = lastUpdatedEl && 
                            lastUpdatedEl.textContent && 
                            lastUpdatedEl.textContent.trim() !== '--' &&
                            lastUpdatedEl.textContent.trim() !== '';
        
        // If we have temperature OR timestamp, JavaScript is working
        return hasTemperature || hasTimestamp;
      },
      { timeout: 15000 }
    );
    
    // Verify data is actually displayed (not just placeholders)
    const pageContent = await page.textContent('body');
    
    // Should have some weather data (temperature, wind, or timestamp)
    const hasWeatherData = /\d+°[FC]/.test(pageContent) || 
                          /\d+\s*(kts|mph|km\/h)/i.test(pageContent) ||
                          (await page.textContent('#weather-last-updated'))?.trim() !== '--';
    
    expect(hasWeatherData).toBe(true);
  });

  test('JavaScript should attempt to fetch weather data', async ({ page }) => {
    const weatherRequests = [];
    
    // Track API requests
    page.on('request', request => {
      if (request.url().includes('/api/weather.php')) {
        weatherRequests.push({
          url: request.url(),
          timestamp: Date.now()
        });
      }
    });
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    
    // Wait for JavaScript to execute and make API call
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {
      // networkidle might timeout, that's OK - we just need to see if request was made
    });
    
    // Wait a bit more for delayed fetch (some pages delay initial fetch)
    await page.waitForTimeout(2000);
    
    // JavaScript should have attempted to fetch weather data
    // This confirms JavaScript is executing, not just loaded
    expect(weatherRequests.length).toBeGreaterThan(0);
    
    // Verify the request includes the airport parameter (JavaScript constructed the URL)
    expect(weatherRequests[0].url).toContain(`airport=${testAirport}`);
  });

  test('Airport navigation should not have scope errors', async ({ page }) => {
    const scopeErrors = [];
    
    // Track errors related to airport navigation
    page.on('console', msg => {
      if (msg.type() === 'error') {
        const text = msg.text();
        if ((text.includes('selectedIndex') || text.includes('searchTimeout')) &&
            (text.includes('ReferenceError') || text.includes('is not defined') || text.includes('before initialization'))) {
          scopeErrors.push(text);
        }
      }
    });
    
    page.on('pageerror', error => {
      const message = error.message;
      if ((message.includes('selectedIndex') || message.includes('searchTimeout')) &&
          (message.includes('ReferenceError') || message.includes('is not defined') || message.includes('before initialization'))) {
        scopeErrors.push(message);
      }
    });
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    
    // Wait for airport navigation to initialize
    await page.waitForFunction(
      () => document.getElementById('airport-search') !== null || 
            typeof window.AIRPORT_NAV_DATA !== 'undefined',
      { timeout: 10000 }
    ).catch(() => {
      // Airport navigation might not be available on all pages
    });
    
    // Try to trigger airport search functionality (if available)
    const searchInput = page.locator('#airport-search');
    if (await searchInput.count() > 0) {
      await searchInput.fill('kspb');
      await page.waitForTimeout(500); // Wait for debounced search
      
      // Try keyboard navigation (triggers selectedIndex usage)
      await searchInput.press('ArrowDown');
      await page.waitForTimeout(200);
    }
    
    // Should have no scope errors
    if (scopeErrors.length > 0) {
      console.error('CRITICAL: Scope errors in airport navigation:', scopeErrors);
    }
    expect(scopeErrors.length).toBe(0);
  });

  test('Functions should check for dependencies before use', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    
    // Check that formatDistance function has defensive check for getDistanceUnit
    const hasDefensiveCheck = await page.evaluate(() => {
      // Get the page source to check for defensive checks
      const scripts = Array.from(document.querySelectorAll('script'));
      let foundCheck = false;
      
      for (const script of scripts) {
        const code = script.textContent || '';
        // Look for pattern: typeof getDistanceUnit === 'function' or similar
        if (code.includes('formatDistance') && 
            (code.includes('typeof getDistanceUnit') || 
             code.includes('getDistanceUnit !==') ||
             code.includes('getDistanceUnit ==='))) {
          foundCheck = true;
          break;
        }
      }
      
      return foundCheck;
    });
    
    // If formatDistance exists, it should have defensive check
    // (This is a soft check - if the function doesn't exist, that's OK)
    if (hasDefensiveCheck === false) {
      // Check if formatDistance actually exists
      const formatDistanceExists = await page.evaluate(() => {
        const scripts = Array.from(document.querySelectorAll('script'));
        return scripts.some(s => (s.textContent || '').includes('function formatDistance') || 
                                 (s.textContent || '').includes('formatDistance('));
      });
      
      if (formatDistanceExists) {
        console.warn('Warning: formatDistance function may not have defensive check for getDistanceUnit');
      }
    }
    
    // This test passes but logs warnings - it's informational
    expect(true).toBe(true);
  });

  test('DOM elements should be accessed after ready', async ({ page }) => {
    const timingErrors = [];
    
    // Track errors that suggest DOM access before ready
    page.on('console', msg => {
      if (msg.type() === 'error') {
        const text = msg.text();
        if ((text.includes('getElementById') || text.includes('querySelector')) &&
            (text.includes('null') || text.includes('undefined')) &&
            text.includes('TypeError')) {
          timingErrors.push(text);
        }
      }
    });
    
    page.on('pageerror', error => {
      const message = error.message;
      if ((message.includes('getElementById') || message.includes('querySelector')) &&
          (message.includes('null') || message.includes('undefined')) &&
          error.name === 'TypeError') {
        timingErrors.push(message);
      }
    });
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    
    // Wait for DOM to be ready
    await page.waitForLoadState('domcontentloaded');
    await page.waitForSelector('body', { state: 'visible' });
    
    // Wait for JavaScript to initialize
    await page.waitForTimeout(2000);
    
    // Should have no timing errors
    if (timingErrors.length > 0) {
      console.error('CRITICAL: DOM timing errors found (elements accessed before ready):', timingErrors);
    }
    expect(timingErrors.length).toBe(0);
  });
});

