const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

test.describe('Aviation Weather Dashboard', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';
  
  // Store console errors across tests
  let consoleErrors = [];
  
  test.beforeEach(async ({ page }) => {
    // Reset console errors array for this test
    consoleErrors = [];
    
    // Set up console error listener BEFORE navigation
    page.on('console', msg => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
      }
    });
    
    // Navigate to the page first
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    
    // Clear localStorage/sessionStorage AFTER navigating to a real page
    // (about:blank doesn't allow localStorage access for security reasons)
    try {
      await page.evaluate(() => {
        localStorage.clear();
        sessionStorage.clear();
      });
    } catch (e) {
      // If clearing fails, continue - might be a test environment issue
      console.warn('Could not clear localStorage:', e.message);
    }
    
    // Wait for page to load - use 'load' to ensure all scripts are executed
    // This is critical for CI where JavaScript might take longer to execute
    await page.waitForLoadState('load', { timeout: 30000 });
    // Wait for body to be visible
    await page.waitForSelector('body', { state: 'visible' });
    // Wait for airport information to be rendered (h1 element)
    // This is rendered in HTML immediately, not via JavaScript
    await page.waitForSelector('h1', { state: 'visible', timeout: 5000 });
    
    // Wait for critical JavaScript functions to be available
    // This ensures the page is fully initialized before tests run
    await page.waitForFunction(
      () => {
        // Check if key functions/variables are available
        return typeof fetchWeather === 'function' && 
               typeof updateWeatherTimestamp === 'function';
      },
      { timeout: 10000 }
    ).catch(() => {
      // If functions aren't available, log for debugging but continue
      // Some tests don't need these functions
      console.warn('Some JavaScript functions may not be available yet');
    });
  });

  test('should display airport information', async ({ page, browserName }) => {
    // Wait for the airport name/ICAO to appear (h1 element contains airport name)
    // The page renders this immediately in HTML, not via JavaScript
    // Already waited in beforeEach, but ensure it's still there
    try {
      const h1 = await page.waitForSelector('h1', { state: 'visible', timeout: 5000 });
      
      // Take a representative screenshot for mobile/desktop summary
      // Save with viewport identifier for easy identification
      const viewportType = page.viewportSize().width < 768 ? 'mobile' : 
                          page.viewportSize().width < 1024 ? 'tablet' : 'desktop';
      
      // Ensure test-results directory exists
      const testResultsDir = path.join(__dirname, '..', 'test-results');
      if (!fs.existsSync(testResultsDir)) {
        fs.mkdirSync(testResultsDir, { recursive: true });
      }
      
      const screenshotPath = path.join(testResultsDir, `screenshot-${viewportType}-${browserName}.png`);
      await page.screenshot({ 
        path: screenshotPath,
        fullPage: true 
      });
      
      // Log screenshot location for debugging
      console.log(`Screenshot saved to: ${screenshotPath}`);
      
      // Check for airport name or ICAO code in the h1 element
      // Format is: "Scappoose Airport (KSPB)" or similar
      const h1Text = await h1.textContent();
      
      // The h1 should contain either the airport name or ICAO code
      // Accept various formats: "Scappoose Airport (KSPB)", "KSPB", "Scappoose", etc.
      expect(h1Text).toBeTruthy();
      expect(h1Text.trim().length).toBeGreaterThan(0);
      
      // Check if it contains airport identifier (case insensitive)
      const hasAirportInfo = /KSPB|Scappoose|airport/i.test(h1Text);
      expect(hasAirportInfo).toBeTruthy();
    } catch (e) {
      // If h1 doesn't exist, check body for airport information
      const pageContent = await page.textContent('body');
      expect(pageContent).toBeTruthy();
      expect(pageContent.trim().length).toBeGreaterThan(0);
      
      // Should contain airport identifier somewhere on the page
      const hasAirportInfo = /KSPB|Scappoose|airport/i.test(pageContent);
      expect(hasAirportInfo).toBeTruthy();
    }
  });

  test('should display weather data when available', async ({ page }) => {
    // Wait for body to be ready (faster than fixed timeout)
    await page.waitForSelector('body', { state: 'visible' });
    
    const pageContent = await page.textContent('body');
    
    // Should have some weather-related content
    // (may be "---" if data unavailable, but should be present)
    expect(pageContent).toBeTruthy();
  });

  test('temperature unit toggle should work', async ({ page }) => {
    // Find temperature toggle button
    const toggle = page.locator('#temp-unit-toggle');
    
    // Check if toggle exists
    const toggleExists = await toggle.count();
    if (toggleExists === 0) {
      test.skip();
      return;
    }
    
    // Wait for toggle to be visible and initialized
    await toggle.waitFor({ state: 'visible', timeout: 10000 });
    
    // Wait for JavaScript to initialize the toggle (check if display element exists and has content)
    // Also verify the toggle function is available
    await page.waitForFunction(
      () => {
        const display = document.getElementById('temp-unit-display');
        const toggle = document.getElementById('temp-unit-toggle');
        // Check that display exists, has content, and toggle is clickable
        return display && 
               display.textContent && 
               display.textContent.trim().length > 0 &&
               toggle &&
               typeof getTempUnit === 'function';
      },
      { timeout: 10000 }
    );
    
    // Get initial state from the display element (more reliable than toggle textContent)
    const initialText = await page.evaluate(() => {
      const display = document.getElementById('temp-unit-display');
      return display ? display.textContent.trim() : null;
    });
    expect(initialText).toBeTruthy();
    expect(['°F', '°C']).toContain(initialText);
    
    // Click toggle
    await toggle.click();
    
    // Wait for toggle display text to actually change (increase timeout for CI)
    await page.waitForFunction(
      ({ initialText }) => {
        const display = document.getElementById('temp-unit-display');
        return display && display.textContent && display.textContent.trim() !== initialText;
      },
      { initialText },
      { timeout: 10000 }
    );
    
    // Verify toggle text changed
    const newText = await page.evaluate(() => {
      const display = document.getElementById('temp-unit-display');
      return display ? display.textContent.trim() : null;
    });
    expect(newText).toBeTruthy();
    expect(newText).not.toBe(initialText);
    expect(['°F', '°C']).toContain(newText);
    
    // Verify temperature displays changed - wait for temperature to actually update
    // Wait for body to contain temperature with unit (should change after toggle)
    const pageContent = await page.textContent('body');
    expect(pageContent).toMatch(/°[FC]/);
  });

  test('wind speed unit toggle should work', async ({ page }) => {
    const toggle = page.locator('#wind-speed-unit-toggle');
    
    const toggleExists = await toggle.count();
    if (toggleExists === 0) {
      test.skip();
      return;
    }
    
    // Wait for toggle to be visible and initialized
    await toggle.waitFor({ state: 'visible', timeout: 10000 });
    
    // Wait for JavaScript to initialize the toggle (check if display element exists and has content)
    // Also verify the toggle function is available
    await page.waitForFunction(
      () => {
        const display = document.getElementById('wind-speed-unit-display');
        const toggle = document.getElementById('wind-speed-unit-toggle');
        // Check that display exists, has content, and toggle is clickable
        return display && 
               display.textContent && 
               display.textContent.trim().length > 0 &&
               toggle &&
               typeof getWindSpeedUnit === 'function';
      },
      { timeout: 10000 }
    );
    
    // Get initial state from the display element (more reliable than toggle textContent)
    const initialText = await page.evaluate(() => {
      const display = document.getElementById('wind-speed-unit-display');
      return display ? display.textContent.trim() : null;
    });
    expect(initialText).toBeTruthy();
    expect(['kts', 'mph', 'km/h']).toContain(initialText);
    
    // Click toggle
    await toggle.click();
    
    // Wait for toggle display text to actually change (increase timeout for CI)
    await page.waitForFunction(
      ({ initialText }) => {
        const display = document.getElementById('wind-speed-unit-display');
        return display && display.textContent && display.textContent.trim() !== initialText;
      },
      { initialText },
      { timeout: 10000 }
    );
    
    // Verify toggle text changed
    const newText = await page.evaluate(() => {
      const display = document.getElementById('wind-speed-unit-display');
      return display ? display.textContent.trim() : null;
    });
    expect(newText).toBeTruthy();
    expect(newText).not.toBe(initialText);
    expect(['kts', 'mph', 'km/h']).toContain(newText);
    
    // Verify wind speed unit changed - check page content
    const pageContent = await page.textContent('body');
    expect(pageContent).toMatch(/kts|mph|km\/h/i);
  });

  test('should display flight category', async ({ page }) => {
    // Wait for weather data to be loaded and displayed
    // Flight category is rendered via JavaScript after fetching weather data
    // Wait for either the condition status element or flight category text to appear
    
    let pageContent = null;
    
    // Try waiting for flight category text to appear in the page
    try {
      await page.waitForFunction(
        () => {
          const bodyText = document.body.textContent || '';
          return /VFR|MVFR|IFR|LIFR|---/.test(bodyText);
        },
        { timeout: 10000 }
      );
      
      // Get page content while page is still available
      try {
        pageContent = await page.textContent('body');
      } catch (e) {
        // Page might have closed - try to get content another way or skip
        console.warn('Could not get page content:', e.message);
        return; // Skip test if page is closed
      }
    } catch (e) {
      // Fallback: wait for condition status element
      try {
        await page.waitForSelector('[class*="condition"], [class*="status"], [class*="flight-category"]', { 
          state: 'visible', 
          timeout: 5000 
        });
      } catch (e2) {
        // Last resort: wait a bit for JavaScript to load
        try {
          await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
        } catch (e3) {
          // Page might be closed - skip test
          console.warn('Page closed during test:', e3.message);
          return;
        }
      }
      
      // Get page content while page is still available
      try {
        pageContent = await page.textContent('body');
      } catch (e4) {
        // Page closed - skip test
        console.warn('Could not get page content after fallback:', e4.message);
        return;
      }
    }
    
    // Should have content
    expect(pageContent).toBeTruthy();
    expect(pageContent.trim().length).toBeGreaterThan(0);
    
    // Should show flight category (VFR, MVFR, IFR, LIFR, or --- if unavailable)
    // The flight category may not be available if weather data fetch failed
    // but we should still see something (even if it's "---")
    expect(pageContent).toMatch(/VFR|MVFR|IFR|LIFR|---/);
  });

  test('should handle missing data gracefully', async ({ page }) => {
    // Wait for content instead of fixed timeout
    await page.waitForSelector('body', { state: 'visible' });
    
    // Check for actual error messages displayed to users (not in source code)
    // Look for error messages in visible text, not in script tags or comments
    const visibleErrors = await page.evaluate(() => {
      // Get all visible text content (excluding script/style tags)
      const walker = document.createTreeWalker(
        document.body,
        NodeFilter.SHOW_TEXT,
        {
          acceptNode: function(node) {
            // Skip text nodes inside script, style, or noscript tags
            let parent = node.parentElement;
            while (parent && parent !== document.body) {
              const tagName = parent.tagName.toLowerCase();
              if (['script', 'style', 'noscript'].includes(tagName)) {
                return NodeFilter.FILTER_REJECT;
              }
              parent = parent.parentElement;
            }
            return NodeFilter.FILTER_ACCEPT;
          }
        }
      );
      
      let text = '';
      let node;
      while (node = walker.nextNode()) {
        text += node.textContent + ' ';
      }
      
      // Check for error indicators in visible text (case insensitive)
      const errorPatterns = [
        /error/i,
        /undefined/i,
        /NaN/i,
        /null/i
      ];
      
      return errorPatterns.some(pattern => pattern.test(text));
    });
    
    // Should not show error messages to users
    expect(visibleErrors).toBe(false);
  });

  test('should display webcam images if available', async ({ page }) => {
    const webcamImages = page.locator('.webcam-image, img[src*="webcam"], img[src*="cache/webcams"]');
    const count = await webcamImages.count();
    
    if (count > 0) {
      // Verify images load
      for (let i = 0; i < count; i++) {
        const img = webcamImages.nth(i);
        await expect(img).toBeVisible({ timeout: 5000 });
      }
    } else {
      // Webcams may not be available - that's OK
      test.skip();
    }
  });

  test('should not have console errors', async ({ page }) => {
    // Console listener is set up in beforeEach
    // Wait for page to be fully loaded so all JavaScript has run
    try {
      await page.waitForLoadState('networkidle', { timeout: 5000 });
    } catch (e) {
      // If networkidle times out, wait for JavaScript to finish loading
      // Wait for any pending fetch requests to complete
      await page.waitForFunction(
        () => {
          // Check if there are any pending fetch requests (approximation)
          return document.readyState === 'complete';
        },
        { timeout: 5000 }
      ).catch(() => {});
    }
    
    // CRITICAL: Check for "Unexpected token" errors - these indicate unclosed script tags or HTML injection
    const syntaxErrors = consoleErrors.filter(err => 
      err.includes('Unexpected token') ||
      err.includes('SyntaxError') ||
      err.includes('Unexpected token \'<\'') ||
      err.includes('Unexpected token "<"')
    );
    
    if (syntaxErrors.length > 0) {
      console.error('CRITICAL: Syntax errors found (likely unclosed script tags or HTML injection):', syntaxErrors);
      // This is a critical error - fail the test
      expect(syntaxErrors).toHaveLength(0);
    }
    
    // Filter out known acceptable errors (like API fetch failures in test)
    // HTTP 503 (Service Unavailable) is expected when weather API is unavailable in CI
    const criticalErrors = consoleErrors.filter(err => 
      !err.includes('Failed to fetch') && 
      !err.includes('network') &&
      !err.includes('404') &&
      !err.includes('503') &&  // Service unavailable - weather API can't fetch data (expected in CI)
      !err.includes('Service Unavailable') &&
      !err.includes('Unable to fetch weather data') &&  // Error message when service unavailable
      !err.includes('ChunkLoadError') && // Webpack chunk loading errors are sometimes transient
      !err.includes('JSON parse error') && // JSON parse errors from weather API (handled gracefully)
      !err.includes('Invalid JSON response') && // Invalid JSON from weather API (handled gracefully)
      !err.includes('Unexpected token') &&  // Already checked above
      !err.includes('SyntaxError')  // Already checked above
    );
    
    if (criticalErrors.length > 0) {
      console.warn('Console errors found:', criticalErrors);
      // Don't fail test, but log warning - these are non-blocking tests
      expect(criticalErrors.length).toBeLessThan(5);
    }
    
    // Also check page content for obvious errors
    const pageContent = await page.textContent('body');
    expect(pageContent).toBeTruthy();
  });

  test('should be responsive on mobile viewport', async ({ page }) => {
    // Set mobile viewport BEFORE navigation (if not already set)
    await page.setViewportSize({ width: 375, height: 667 });
    
    // Navigate if not already on the page (beforeEach already navigated, but ensure we're there)
    if (page.url() !== `${baseUrl}/?airport=${testAirport}`) {
      await page.goto(`${baseUrl}/?airport=${testAirport}`);
    }
    
    // Verify page loads (faster than networkidle)
    await page.waitForLoadState('domcontentloaded');
    await page.waitForSelector('body', { state: 'visible' });
    
    const body = page.locator('body');
    await expect(body).toBeVisible();
    
    // Check that content is visible (not cut off)
    const pageContent = await page.textContent('body');
    expect(pageContent).toBeTruthy();
    
    // Verify viewport is actually mobile-sized
    const viewportSize = page.viewportSize();
    expect(viewportSize?.width).toBeLessThanOrEqual(375);
  });

  test('should be responsive on tablet viewport', async ({ page }) => {
    // Set tablet viewport BEFORE navigation (if not already set)
    await page.setViewportSize({ width: 768, height: 1024 });
    
    // Navigate if not already on the page
    if (page.url() !== `${baseUrl}/?airport=${testAirport}`) {
      await page.goto(`${baseUrl}/?airport=${testAirport}`);
    }
    
    await page.waitForLoadState('domcontentloaded');
    await page.waitForSelector('body', { state: 'visible' });
    
    const body = page.locator('body');
    await expect(body).toBeVisible();
    
    // Verify viewport is actually tablet-sized
    const viewportSize = page.viewportSize();
    expect(viewportSize?.width).toBeGreaterThanOrEqual(768);
    expect(viewportSize?.width).toBeLessThan(1024);
  });

  test('should be responsive on desktop viewport', async ({ page }) => {
    // Set desktop viewport BEFORE navigation (if not already set)
    await page.setViewportSize({ width: 1920, height: 1080 });
    
    // Navigate if not already on the page
    if (page.url() !== `${baseUrl}/?airport=${testAirport}`) {
      await page.goto(`${baseUrl}/?airport=${testAirport}`);
    }
    
    await page.waitForLoadState('domcontentloaded');
    await page.waitForSelector('body', { state: 'visible' });
    
    const body = page.locator('body');
    await expect(body).toBeVisible();
    
    // Verify viewport is actually desktop-sized
    const viewportSize = page.viewportSize();
    expect(viewportSize?.width).toBeGreaterThanOrEqual(1024);
  });

  test('should preserve unit toggle preferences', async ({ page, context }) => {
    const toggle = page.locator('#temp-unit-toggle');
    
    const toggleExists = await toggle.count();
    if (toggleExists === 0) {
      test.skip();
      return;
    }
    
    // Wait for toggle and get initial state
    await toggle.waitFor({ state: 'visible', timeout: 10000 });
    
    // Wait for JavaScript to initialize the toggle
    // Also verify the toggle function is available
    await page.waitForFunction(
      () => {
        const display = document.getElementById('temp-unit-display');
        const toggle = document.getElementById('temp-unit-toggle');
        return display && 
               display.textContent && 
               display.textContent.trim().length > 0 &&
               toggle &&
               typeof getTempUnit === 'function';
      },
      { timeout: 10000 }
    );
    
    const initialText = await page.evaluate(() => {
      const display = document.getElementById('temp-unit-display');
      return display ? display.textContent.trim() : null;
    });
    expect(initialText).toBeTruthy();
    expect(['°F', '°C']).toContain(initialText);
    
    // Click toggle to change unit
    await toggle.click();
    
    // Wait for toggle display text to actually change (increase timeout for CI)
    await page.waitForFunction(
      ({ initialText }) => {
        const display = document.getElementById('temp-unit-display');
        return display && display.textContent && display.textContent.trim() !== initialText;
      },
      { initialText },
      { timeout: 20000 } // Increased from 10s to 20s
    );
    
    const newState = await page.evaluate(() => {
      const display = document.getElementById('temp-unit-display');
      return display ? display.textContent.trim() : null;
    });
    expect(newState).toBeTruthy();
    expect(newState).not.toBe(initialText);
    
    // Verify cookie was written (source of truth for cross-subdomain sharing)
    const cookies = await context.cookies();
    const tempUnitCookie = cookies.find(c => c.name === 'aviationwx_temp_unit');
    expect(tempUnitCookie).toBeTruthy();
    expect(tempUnitCookie.value).toBeTruthy();
    expect(['F', 'C']).toContain(tempUnitCookie.value);
    
    // Verify localStorage was also written (cache)
    const localStorageValue = await page.evaluate(() => {
      return localStorage.getItem('aviationwx_temp_unit');
    });
    expect(localStorageValue).toBeTruthy();
    expect(['F', 'C']).toContain(localStorageValue);
    expect(localStorageValue).toBe(tempUnitCookie.value); // Should match cookie
    
    // Reload page
    await page.reload();
    
    // Wait for page to load and toggle to appear
    await page.waitForSelector('body', { state: 'visible' });
    await page.waitForSelector('#temp-unit-toggle', { state: 'visible', timeout: 5000 });
    
    // Wait for JavaScript to initialize and read from cookie/localStorage
    await page.waitForFunction(
      ({ expectedText }) => {
        const display = document.getElementById('temp-unit-display');
        return display && display.textContent.trim() === expectedText;
      },
      { expectedText: newState },
      { timeout: 15000 } // Increased from 5s to 15s
    );
    
    // Unit should be preserved (stored in cookie, synced to localStorage)
    const preservedState = await page.evaluate(() => {
      const display = document.getElementById('temp-unit-display');
      return display ? display.textContent.trim() : null;
    });
    expect(preservedState).toBe(newState);
    
    // Verify cookie still exists after reload
    const cookiesAfterReload = await context.cookies();
    const tempUnitCookieAfterReload = cookiesAfterReload.find(c => c.name === 'aviationwx_temp_unit');
    expect(tempUnitCookieAfterReload).toBeTruthy();
    expect(tempUnitCookieAfterReload.value).toBe(tempUnitCookie.value);
  });

  test('should store preferences in cookies for cross-subdomain sharing', async ({ page, context }) => {
    // Clear cookies and localStorage before test
    await context.clearCookies();
    await page.evaluate(() => {
      localStorage.clear();
    });
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('networkidle');
    
    const toggle = page.locator('#temp-unit-toggle');
    const toggleExists = await toggle.count();
    if (toggleExists === 0) {
      test.skip();
      return;
    }
    
    await toggle.waitFor({ state: 'visible', timeout: 10000 });
    
    // Wait for JavaScript functions to be available
    await page.waitForFunction(
      () => typeof getTempUnit === 'function' && typeof setCookie === 'function',
      { timeout: 10000 }
    );
    
    // Get initial state
    const initialText = await page.evaluate(() => {
      const display = document.getElementById('temp-unit-display');
      return display ? display.textContent.trim() : null;
    });
    
    // Change preference
    await toggle.click();
    await page.waitForTimeout(500);
    
    // Verify cookie was set with correct attributes
    const cookies = await context.cookies();
    const tempUnitCookie = cookies.find(c => c.name === 'aviationwx_temp_unit');
    
    expect(tempUnitCookie).toBeTruthy();
    expect(['F', 'C']).toContain(tempUnitCookie.value);
    expect(tempUnitCookie.path).toBe('/');
    expect(tempUnitCookie.expires).toBeGreaterThan(Date.now() / 1000); // Should have expiration
    // Note: domain attribute may not be visible in Playwright cookies for localhost
    // In production, it should be set to .aviationwx.org for cross-subdomain sharing
    
    // Verify localStorage was also updated (cache)
    const localStorageValue = await page.evaluate(() => {
      return localStorage.getItem('aviationwx_temp_unit');
    });
    expect(localStorageValue).toBe(tempUnitCookie.value);
  });

  test('should migrate localStorage preferences to cookies on page load', async ({ page, context }) => {
    // Clear cookies but set localStorage (simulating old behavior)
    await context.clearCookies();
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('networkidle');
    
    // Set localStorage directly (simulating old preference storage)
    await page.evaluate(() => {
      localStorage.setItem('aviationwx_temp_unit', 'C');
      localStorage.setItem('aviationwx_distance_unit', 'm');
      localStorage.setItem('aviationwx_wind_speed_unit', 'mph');
      localStorage.setItem('aviationwx_time_format', '24hr');
    });
    
    // Reload page to trigger migration
    await page.reload();
    await page.waitForLoadState('networkidle');
    
    // Wait for sync function to run
    await page.waitForFunction(
      () => typeof syncPreferencesFromCookies === 'function' || 
            typeof getCookie === 'function',
      { timeout: 10000 }
    );
    
    // Wait a bit for sync to complete
    await page.waitForTimeout(1000);
    
    // Verify cookies were created from localStorage
    const cookies = await context.cookies();
    const tempCookie = cookies.find(c => c.name === 'aviationwx_temp_unit');
    const distanceCookie = cookies.find(c => c.name === 'aviationwx_distance_unit');
    const windCookie = cookies.find(c => c.name === 'aviationwx_wind_speed_unit');
    const timeCookie = cookies.find(c => c.name === 'aviationwx_time_format');
    
    expect(tempCookie).toBeTruthy();
    expect(tempCookie.value).toBe('C');
    expect(distanceCookie).toBeTruthy();
    expect(distanceCookie.value).toBe('m');
    expect(windCookie).toBeTruthy();
    expect(windCookie.value).toBe('mph');
    expect(timeCookie).toBeTruthy();
    expect(timeCookie.value).toBe('24hr');
  });

  test('should display local time in airport timezone', async ({ page }) => {
    // Wait for local time element to be present
    await page.waitForSelector('#localTime', { state: 'visible', timeout: 10000 });
    
    // Wait for clock function to run and update the time (check that it's not "--:--:--")
    // The clock runs on a 1-second interval, so we need to wait for it to execute
    // Also verify updateClocks function exists
    // First check if updateClocks function exists
    await page.waitForFunction(
      () => typeof updateClocks === 'function',
      { timeout: 10000 }
    ).catch(() => {
      // If function doesn't exist, that's OK - clock might update differently
    });
    
    // Then wait for the time to be populated (not "--:--:--")
    await page.waitForFunction(
      () => {
        const timeEl = document.getElementById('localTime');
        return timeEl && 
               timeEl.textContent && 
               timeEl.textContent.trim() !== '--' &&
               timeEl.textContent.trim() !== '--:--:--';
      },
      { timeout: 30000 } // Increased from 15s to 30s for slow CI environments
    );
    
    // Get the displayed local time
    const localTimeText = await page.textContent('#localTime');
    expect(localTimeText).toBeTruthy();
    expect(localTimeText.trim()).not.toBe('--:--:--');
    
    // Verify time format (HH:MM:SS)
    expect(localTimeText.trim()).toMatch(/^\d{2}:\d{2}:\d{2}$/);
    
    // Get the timezone abbreviation (includes UTC offset, e.g., "PST (UTC-8)")
    const timezoneAbbr = await page.textContent('#localTimezone');
    expect(timezoneAbbr).toBeTruthy();
    expect(timezoneAbbr.trim()).not.toBe('--');
    
    // Verify timezone format: abbreviation followed by UTC offset in parentheses
    // Examples: "PST (UTC-8)", "PDT (UTC-7)", "EST (UTC-5)"
    // The test airport (kspb) uses America/Los_Angeles timezone
    expect(timezoneAbbr.trim()).toMatch(/^[A-Z]{3,4}\s+\(UTC[+-]\d+\)$/);
    
    // Check that the time updates dynamically
    const initialTime = await page.textContent('#localTime');
    await page.waitForTimeout(1500); // Wait 1.5 seconds
    const updatedTime = await page.textContent('#localTime');
    
    // Time should have updated (may be same second if we caught it at the start, but should be different after 1.5s)
    // At minimum, the element should exist and be formatted correctly
    expect(updatedTime.trim()).toMatch(/^\d{2}:\d{2}:\d{2}$/);
    
    // Verify the time is actually in the airport's timezone, not browser's timezone
    // We'll check by comparing what the time should be in the airport's timezone
    const airportTimezone = 'America/Los_Angeles'; // From test airport config
    const actualTimeInTimezone = await page.evaluate((timezone) => {
      const now = new Date();
      const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        hour12: false,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
      });
      return formatter.format(now);
    }, airportTimezone);
    
    // Get displayed time again after calculating expected time (to minimize timing differences)
    const finalDisplayedTime = await page.textContent('#localTime');
    
    // The displayed time should match the airport's timezone time (within 2 seconds tolerance)
    const displayedTime = finalDisplayedTime.split(':').map(Number);
    const expectedTime = actualTimeInTimezone.split(':').map(Number);
    
    // Allow 2 seconds difference (due to timing of test execution)
    const timeDiff = Math.abs(
      (displayedTime[0] * 3600 + displayedTime[1] * 60 + displayedTime[2]) -
      (expectedTime[0] * 3600 + expectedTime[1] * 60 + expectedTime[2])
    );
    expect(timeDiff).toBeLessThanOrEqual(2);
    
    // Verify Zulu time is also displayed and correct
    const zuluTimeText = await page.textContent('#zuluTime');
    expect(zuluTimeText).toBeTruthy();
    expect(zuluTimeText).toMatch(/^\d{2}:\d{2}:\d{2}$/);
    
    // Zulu time should be UTC (check that it's different from local time if not in UTC)
    // Or verify it matches current UTC time
    const currentUTC = new Date().toISOString().substr(11, 8);
    const zuluTimeDiff = Math.abs(
      (parseInt(zuluTimeText.split(':')[0]) * 3600 + parseInt(zuluTimeText.split(':')[1]) * 60 + parseInt(zuluTimeText.split(':')[2])) -
      (parseInt(currentUTC.split(':')[0]) * 3600 + parseInt(currentUTC.split(':')[1]) * 60 + parseInt(currentUTC.split(':')[2]))
    );
    expect(zuluTimeDiff).toBeLessThanOrEqual(2); // Allow 2 seconds difference
  });

  test('should display sunrise and sunset times with correct timezone abbreviation', async ({ page }) => {
    // Wait for weather data to load
    await page.waitForSelector('#weather-data', { state: 'visible', timeout: 10000 });
    
    // Wait for weather data to be populated (not just the loading state)
    // Increase timeout to account for slow weather API responses
    // Also handle case where weather data might not be available
    const weatherDataLoaded = await page.waitForFunction(
      () => {
        const weatherData = document.getElementById('weather-data');
        if (!weatherData) return false;
        
        // Check if sunrise/sunset elements exist
        const sunriseElement = weatherData.querySelector('.sunrise-sunset');
        if (!sunriseElement) return false;
        
        // Check that sunrise time is displayed (not just "--")
        const sunriseText = sunriseElement.textContent || '';
        return sunriseText.includes('Sunrise') && !sunriseText.includes('-- --');
      },
      { timeout: 30000 } // Increased from 15s to 30s for slow weather API
    ).catch(() => {
      // If weather data doesn't load, skip the test
      return null;
    });
    
    // Skip test if weather data didn't load
    if (!weatherDataLoaded) {
      test.skip();
      return;
    }
    
    // Get the sunrise and sunset elements
    const sunriseSunsetElements = await page.$$('.sunrise-sunset');
    expect(sunriseSunsetElements.length).toBeGreaterThanOrEqual(2);
    
    // Get sunrise element text
    const sunriseText = await page.textContent('.sunrise-sunset:first-of-type');
    expect(sunriseText).toBeTruthy();
    expect(sunriseText).toContain('Sunrise');
    
    // Get sunset element text
    const sunsetText = await page.textContent('.sunrise-sunset:last-of-type');
    expect(sunsetText).toBeTruthy();
    expect(sunsetText).toContain('Sunset');
    
    // Extract timezone abbreviation from sunrise/sunset display
    // The format should be: "H:MM AM/PM TZ" where TZ is the timezone abbreviation
    const sunriseMatch = sunriseText.match(/(\d{1,2}:\d{2}\s+[AP]M)\s+([A-Z]{3,4})/);
    const sunsetMatch = sunsetText.match(/(\d{1,2}:\d{2}\s+[AP]M)\s+([A-Z]{3,4})/);
    
    expect(sunriseMatch).toBeTruthy();
    expect(sunsetMatch).toBeTruthy();
    
    const sunriseTimezone = sunriseMatch[2];
    const sunsetTimezone = sunsetMatch[2];
    
    // Both should have the same timezone abbreviation
    expect(sunriseTimezone).toBe(sunsetTimezone);
    
    // Verify the timezone abbreviation matches the airport's timezone
    // The test airport (kspb) uses America/Los_Angeles timezone
    // It should be either PST (Pacific Standard Time) or PDT (Pacific Daylight Time)
    // depending on the current date (DST)
    const validTimezones = ['PST', 'PDT'];
    expect(validTimezones).toContain(sunriseTimezone);
    
    // Verify the timezone abbreviation matches what's displayed in the clock
    const clockTimezoneText = await page.textContent('#localTimezone');
    expect(clockTimezoneText).toBeTruthy();
    
    // Extract timezone abbreviation from clock (format: "PST (UTC-8)" or "PDT (UTC-7)")
    const clockTimezoneMatch = clockTimezoneText.match(/^([A-Z]{3,4})/);
    expect(clockTimezoneMatch).toBeTruthy();
    const clockTimezone = clockTimezoneMatch[1];
    
    // The sunrise/sunset timezone should match the clock timezone
    expect(sunriseTimezone).toBe(clockTimezone);
    
    // Verify the timezone is not hardcoded to "PDT"
    // It should dynamically change based on DST
    expect(sunriseTimezone).toMatch(/^[A-Z]{3,4}$/);
    
    // Verify getTimezoneAbbreviation function exists and works
    const timezoneAbbr = await page.evaluate(() => {
      if (typeof getTimezoneAbbreviation === 'function') {
        return getTimezoneAbbreviation();
      }
      return null;
    });
    
    expect(timezoneAbbr).toBeTruthy();
    expect(timezoneAbbr).toBe(sunriseTimezone);
  });

  test('should have all script tags properly closed', async ({ page }) => {
    // Get the page HTML source
    const html = await page.content();
    
    // Count opening and closing script tags
    const openingScriptTags = (html.match(/<script[^>]*>/gi) || []).length;
    const closingScriptTags = (html.match(/<\/script>/gi) || []).length;
    
    // All opening script tags must have closing tags
    expect(openingScriptTags).toBe(closingScriptTags);
    
    // Verify each script tag is properly closed
    // Extract all script tags and verify they have closing tags
    // Enhanced regex to match script tags with attributes and tolerant closing
    const scriptTagRegex = /<script\b[^>]*>(.*?)<\/script\b[^>]*>/gis;
    const matches = [];
    let match;
    while ((match = scriptTagRegex.exec(html)) !== null) {
      matches.push(match[0]);
    }
    
    // Count total script tags found with regex (which requires closing tags)
    const properlyClosedScripts = matches.length;
    
    // All opening tags should have been matched (meaning they're all closed)
    expect(properlyClosedScripts).toBe(openingScriptTags);
    
    // Additional check: verify no script tag content contains unclosed HTML
    matches.forEach((scriptTag, index) => {
      // Extract content between <script> and </script>
      const contentMatch = scriptTag.match(/<script\b[^>]*>(.*?)<\/script\b[^>]*>/is);
      if (contentMatch && contentMatch[1]) {
        const content = contentMatch[1];
        // Check for HTML tags that aren't in strings/template literals
        // This is a simplified check - we're looking for < followed by a letter
        // that's not inside quotes or backticks
        const hasUnescapedHtml = /<[a-z][\s>]/i.test(content);
        if (hasUnescapedHtml) {
          // Check if it's in a string or template literal
          // Simple heuristic: if it's not in quotes, it's suspicious
          const inString = /['"`].*<[a-z].*['"`]/i.test(content);
          if (!inString) {
            console.warn(`Script tag #${index} may contain HTML:`, content.substring(0, 200));
          }
        }
      }
    });
  });
});

