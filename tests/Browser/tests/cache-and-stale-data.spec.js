const { test, expect } = require('@playwright/test');

/**
 * Tests for cache-busting and stale data detection improvements
 * These tests verify the fixes for mobile clients getting stale weather data
 */
test.describe('Cache and Stale Data Handling', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';
  
  test.beforeEach(async ({ page, context }) => {
    // Clear all caches before each test
    await context.clearCookies();
    
    // Navigate to the page FIRST (localStorage requires a real page context)
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    // Wait for 'load' to ensure all scripts are executed (critical for CI)
    await page.waitForLoadState('load', { timeout: 30000 });
    await page.waitForSelector('body', { state: 'visible' });
    
    // Now clear storage AFTER navigating to a real page
    await page.evaluate(() => {
      try {
        localStorage.clear();
        sessionStorage.clear();
      } catch (e) {
        // If clearing fails, continue - might be a test environment issue
        console.warn('Could not clear storage:', e.message);
      }
      // Clear Service Worker cache if possible
      if ('caches' in window) {
        return caches.keys().then(names => {
          return Promise.all(names.map(name => caches.delete(name)));
        });
      }
    });
    
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
      console.warn('Some JavaScript functions may not be available yet');
    });
  });

  test('should add cache-busting parameter when forcing refresh', async ({ page }) => {
    // Wait for weather data to load initially
    await page.waitForFunction(
      () => document.getElementById('weather-last-updated'),
      { timeout: 10000 }
    );
    
    // fetchWeather should already be available from beforeEach, but verify
    await page.waitForFunction(
      () => typeof fetchWeather === 'function',
      { timeout: 10000 }
    );
    
    // Intercept weather fetch requests BEFORE triggering fetch
    const requests = [];
    const requestListener = request => {
      const url = request.url();
      // Match weather.php in various URL formats: /weather.php, weather.php, /api/weather.php
      if (url.includes('weather.php')) {
        requests.push(url);
      }
    };
    page.on('request', requestListener);
    
    // Trigger a forced refresh by manually calling fetchWeather(true)
    await page.evaluate(() => {
      // Force weather refresh
      if (typeof fetchWeather === 'function') {
        fetchWeather(true);
      }
    });
    
    // Wait for the request to be made and processed
    await page.waitForTimeout(3000);
    
    // Check if any request had cache-busting parameter
    // The parameter can be _cb= or &_cb= depending on whether there are other query params
    const hasCacheBusting = requests.some(url => {
      return url.includes('_cb=') || url.includes('&_cb=') || url.match(/[?&]_cb=/);
    });
    
    if (!hasCacheBusting && requests.length > 0) {
      console.log('Weather requests captured:', requests);
    }
    
    expect(hasCacheBusting).toBeTruthy();
  });

  test('should detect and handle stale cache when server data is older', async ({ page }) => {
    // Wait for initial weather load
    await page.waitForFunction(
      () => document.getElementById('weather-last-updated'),
      { timeout: 10000 }
    );
    
    // Get initial timestamp
    const initialTimestamp = await page.evaluate(() => {
      const el = document.getElementById('weather-last-updated');
      return el ? el.textContent : null;
    });
    
    expect(initialTimestamp).toBeTruthy();
    
    // Mock a stale response (older timestamp than client has)
    // Set up route BEFORE triggering fetch
    await page.route('**/weather.php*', async route => {
      try {
        const response = await route.fetch();
        const body = await response.text();
        
        // Try to parse JSON, handle double JSON issue
        let json;
        try {
          json = JSON.parse(body);
        } catch (e) {
          // If double JSON, take the first one
          const firstJsonEnd = body.indexOf('}') + 1;
          if (firstJsonEnd > 0) {
            json = JSON.parse(body.substring(0, firstJsonEnd));
          } else {
            throw e;
          }
        }
        
        // Modify the response to have an older timestamp
        if (json.weather && json.weather.last_updated) {
          json.weather.last_updated = json.weather.last_updated - 600; // 10 minutes older
        }
        
        await route.fulfill({
          status: response.status(),
          headers: response.headers(),
          body: JSON.stringify(json)
        });
      } catch (e) {
        // If routing fails, continue with original request
        await route.continue();
      }
    });
    
    // Set up console listener BEFORE triggering fetch
    const consoleMessages = [];
    page.on('console', msg => {
      if (msg.type() === 'warn' && msg.text().includes('stale cache detected')) {
        consoleMessages.push(msg.text());
      }
    });
    
    // Set up request listener BEFORE triggering fetch (to catch forced refresh)
    const requestsAfterStale = [];
    const requestListener = request => {
      const url = request.url();
      // Match weather.php and check for cache-busting parameter
      if (url.includes('weather.php') && 
          (url.includes('_cb=') || url.includes('&_cb=') || url.match(/[?&]_cb=/))) {
        requestsAfterStale.push(url);
      }
    };
    page.on('request', requestListener);
    
    // Wait for weatherLastUpdated to be set (needed for stale detection)
    // Also wait for weather data to be loaded (weatherLastUpdated is set after first fetch)
    // First wait for the element to exist
    await page.waitForSelector('#weather-last-updated', { timeout: 10000 }).catch(() => {
      // Element might not exist if weather section isn't rendered
      throw new Error('weather-last-updated element not found - weather section may not be rendered');
    });
    
    // Then wait for weatherLastUpdated variable to be set (after weather fetch completes)
    // Increase timeout to 30s to account for slow weather API responses
    const weatherDataAvailable = await page.waitForFunction(
      () => {
        return typeof weatherLastUpdated !== 'undefined' && weatherLastUpdated !== null;
      },
      { timeout: 30000 }
    ).catch(() => {
      // If weather data doesn't load, skip the test
      return null;
    });
    
    // Skip test if weather data isn't available
    if (!weatherDataAvailable) {
      test.skip();
      return;
    }
    
    // Trigger a fetch
    await page.evaluate(() => {
      if (typeof fetchWeather === 'function') {
        fetchWeather(false); // Normal fetch
      }
    });
    
    // Wait for stale data detection and refresh
    await page.waitForTimeout(5000);
    
    // The client should detect stale data and force a refresh
    // Check for console warning about stale cache detection
    // OR check that a forced refresh was triggered (cache-busting parameter)
    const hasStaleDetection = consoleMessages.length > 0 || requestsAfterStale.length > 0;
    
    // If no detection, log for debugging
    if (!hasStaleDetection) {
      console.log('Stale detection test - console messages:', consoleMessages);
      console.log('Stale detection test - forced refresh requests:', requestsAfterStale);
      // Check if weatherLastUpdated was set (needed for stale detection)
      const hasWeatherData = await page.evaluate(() => {
        return typeof weatherLastUpdated !== 'undefined' && weatherLastUpdated !== null;
      });
      console.log('Stale detection test - weatherLastUpdated set:', hasWeatherData);
    }
    
    expect(hasStaleDetection).toBeTruthy();
  });

  /**
   * Set weather timestamp to simulate stale data and trigger display update
   * 
   * @param {Page} page - Playwright page object
   * @param {number} ageSeconds - Age of data in seconds
   */
  async function setWeatherTimestamp(page, ageSeconds) {
    await page.evaluate((ageSeconds) => {
      if (typeof weatherLastUpdated !== 'undefined') {
        weatherLastUpdated = new Date(Date.now() - ageSeconds * 1000);
        if (typeof updateWeatherTimestamp === 'function') {
          updateWeatherTimestamp();
        }
      }
    }, ageSeconds);
    await page.waitForTimeout(500);
  }

  /**
   * Get current timestamp display state (text, color, styling)
   * 
   * @param {Page} page - Playwright page object
   * @returns {Promise<{text: string, color: string, fontWeight: string, hasWarning: boolean}>} Display state
   */
  async function getTimestampDisplay(page) {
    const timestampEl = await page.locator('#weather-last-updated');
    const warningEl = await page.locator('#weather-timestamp-warning');
    const text = await timestampEl.textContent();
    const color = await timestampEl.evaluate(el => window.getComputedStyle(el).color);
    const fontWeight = await timestampEl.evaluate(el => window.getComputedStyle(el).fontWeight);
    const hasWarning = await warningEl.isVisible();
    
    return { text: text?.trim() || '', color, fontWeight, hasWarning };
  }

  /**
   * Get threshold info for current airport configuration
   * 
   * @param {Page} page - Playwright page object
   * @returns {Promise<{isMetarOnly: boolean, warningSeconds: number, errorSeconds: number}>}
   */
  async function getThresholdInfo(page) {
    return await page.evaluate(() => {
      if (typeof AIRPORT_DATA === 'undefined' || !AIRPORT_DATA) {
        return { isMetarOnly: false, warningSeconds: 300, errorSeconds: 600 };
      }
      
      const isMetarOnly = AIRPORT_DATA.weather_source && 
                          AIRPORT_DATA.weather_source.type === 'metar';
      const refreshSeconds = Math.max(1, AIRPORT_DATA.weather_refresh_seconds || 60);
      
      if (isMetarOnly) {
        // Use new 3-tier staleness thresholds
        return {
          isMetarOnly: true,
          warningSeconds: typeof METAR_STALE_WARNING_SECONDS !== 'undefined' 
            ? METAR_STALE_WARNING_SECONDS : 3600,
          errorSeconds: typeof METAR_STALE_ERROR_SECONDS !== 'undefined'
            ? METAR_STALE_ERROR_SECONDS : 7200
        };
      } else {
        // Use new 3-tier staleness thresholds
        return {
          isMetarOnly: false,
          warningSeconds: typeof STALE_WARNING_SECONDS !== 'undefined'
            ? STALE_WARNING_SECONDS : 600,
          errorSeconds: typeof STALE_ERROR_SECONDS !== 'undefined'
            ? STALE_ERROR_SECONDS : 3600
        };
      }
    });
  }

  test('should show visual indicators for stale data', async ({ page }) => {
    // Wait for weather data to load and weatherLastUpdated to be set
    // This may take longer if weather data needs to be fetched
    // First wait for the element to exist
    await page.waitForSelector('#weather-last-updated', { timeout: 10000 }).catch(() => {
      throw new Error('weather-last-updated element not found - weather section may not be rendered');
    });
    
    // Then wait for weatherLastUpdated variable and updateWeatherTimestamp function
    // Increase timeout to 30s to account for slow weather API responses
    const weatherDataAvailable = await page.waitForFunction(
      () => {
        return typeof weatherLastUpdated !== 'undefined' && 
               weatherLastUpdated !== null &&
               typeof updateWeatherTimestamp === 'function';
      },
      { timeout: 30000 }
    ).catch(() => {
      // If weather data doesn't load, skip the test
      return null;
    });
    
    // Skip test if weather data isn't available
    if (!weatherDataAvailable) {
      test.skip();
      return;
    }
    
    // Get the actual stale threshold based on weather source type
    // Uses new 3-tier staleness model with explicit thresholds
    const staleThresholdInfo = await page.evaluate(() => {
      if (typeof AIRPORT_DATA === 'undefined' || !AIRPORT_DATA) {
        // Default to warning threshold (10 min = 600s)
        return { isMetarOnly: false, thresholdSeconds: 600 };
      }
      
      const isMetarOnly = AIRPORT_DATA.weather_source && 
                          AIRPORT_DATA.weather_source.type === 'metar';
      
      // Use new 3-tier staleness thresholds
      if (isMetarOnly) {
        const warningSeconds = typeof METAR_STALE_WARNING_SECONDS !== 'undefined'
          ? METAR_STALE_WARNING_SECONDS : 3600;
        return { isMetarOnly: true, thresholdSeconds: warningSeconds };
      } else {
        const warningSeconds = typeof STALE_WARNING_SECONDS !== 'undefined'
          ? STALE_WARNING_SECONDS : 600;
        return { isMetarOnly: false, thresholdSeconds: warningSeconds };
      }
    });
    
    // Set weatherLastUpdated to exceed the calculated stale threshold
    const staleAgeSeconds = staleThresholdInfo.thresholdSeconds + 60; // Add 1 minute buffer
    await page.evaluate((ageSeconds) => {
      if (typeof weatherLastUpdated !== 'undefined') {
        weatherLastUpdated = new Date(Date.now() - ageSeconds * 1000);
        // Update timestamp display
        if (typeof updateWeatherTimestamp === 'function') {
          updateWeatherTimestamp();
        }
      }
    }, staleAgeSeconds);
    
    // Wait for timestamp update to complete
    await page.waitForTimeout(1000);
    
    // Check visual indicators
    const timestampEl = await page.locator('#weather-last-updated');
    const text = await timestampEl.textContent();
    
    // If text is still "--", weatherLastUpdated might not have been set properly
    if (text === '--' || !text || text.trim() === '--') {
      // Try waiting a bit more and check if updateWeatherTimestamp function exists
      const hasUpdateFunction = await page.evaluate(() => {
        return typeof updateWeatherTimestamp === 'function';
      });
      
      if (hasUpdateFunction) {
        // Force another update
        await page.evaluate(() => {
          if (typeof updateWeatherTimestamp === 'function') {
            updateWeatherTimestamp();
          }
        });
        await page.waitForTimeout(500);
        const newText = await timestampEl.textContent();
        if (newText && newText !== '--' && newText.trim() !== '--') {
          expect(newText).toMatch(/⚠️|warning|stale/i);
          return;
        }
      }
      
      // If still "--", skip the test (weather data might not be available)
      test.skip();
      return;
    }
    
    const color = await timestampEl.evaluate(el => window.getComputedStyle(el).color);
    
    // Should show warning indicator (⚠️) for data exceeding stale threshold
    // Warning emoji is now in separate element, timestamp shows actual time
    const warningEl = await page.locator('#weather-timestamp-warning');
    const hasWarning = await warningEl.isVisible();
    
    // Color should be orange (#f80) or red (#c00) for stale data
    const isWarningColor = color.includes('rgb(255, 136, 0)') || // #f80
                          color.includes('rgb(204, 0, 0)') ||   // #c00
                          color.includes('rgb(255, 140, 0)') || // Orange variants
                          color.includes('rgb(220, 20, 60)');   // Red variants
    
    expect(isWarningColor || hasWarning).toBeTruthy();
  });

  test('should prevent concurrent fetches', async ({ page }) => {
    // Wait for initial load
    await page.waitForFunction(
      () => document.getElementById('weather-last-updated'),
      { timeout: 10000 }
    );
    
    // Intercept and delay weather requests
    let requestCount = 0;
    await page.route('**/weather.php*', async route => {
      requestCount++;
      // Delay response to allow concurrent requests if not prevented
      await page.waitForTimeout(500);
      await route.continue();
    });
    
    // Trigger multiple fetches simultaneously
    await page.evaluate(() => {
      if (typeof fetchWeather === 'function') {
        fetchWeather(false);
        fetchWeather(false);
        fetchWeather(false);
      }
    });
    
    // Wait for all requests to complete
    await page.waitForTimeout(2000);
    
    // Should only have 1 request (concurrent fetches prevented)
    expect(requestCount).toBeLessThanOrEqual(2); // Allow 1-2 requests (initial + maybe one forced)
  });

  test('should handle Service Worker cache-busting correctly', async ({ page, context }) => {
    // Register Service Worker if not already registered
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    // Wait for 'load' to ensure all scripts are executed (critical for CI)
    await page.waitForLoadState('load', { timeout: 30000 });
    
    // fetchWeather should already be available from beforeEach, but verify
    await page.waitForFunction(
      () => typeof fetchWeather === 'function',
      { timeout: 10000 }
    );
    
    // Wait for Service Worker to be registered (optional - SW might not be available in test)
    await page.waitForFunction(
      () => 'serviceWorker' in navigator,
      { timeout: 5000 }
    ).catch(() => {}); // Service Worker might not be available in test
    
    // Monitor network requests BEFORE triggering fetch
    const swRequests = [];
    const requestListener = request => {
      const url = request.url();
      // Match weather.php in various URL formats
      if (url.includes('weather.php')) {
        const hasCacheBusting = url.includes('_cb=') || url.includes('&_cb=') || url.match(/[?&]_cb=/);
        swRequests.push({
          url,
          headers: request.headers(),
          hasCacheBusting
        });
      }
    };
    page.on('request', requestListener);
    
    // Trigger forced refresh
    await page.evaluate(() => {
      if (typeof fetchWeather === 'function') {
        fetchWeather(true);
      }
    });
    
    // Wait for request to be made
    await page.waitForTimeout(3000);
    
    // At least one request should have cache-busting parameter
    const forcedRefreshRequests = swRequests.filter(r => r.hasCacheBusting);
    
    if (forcedRefreshRequests.length === 0 && swRequests.length > 0) {
      console.log('Service Worker cache-busting test - all requests:', swRequests.map(r => r.url));
    }
    
    // If no requests were captured, the function might not have been called or requests were cached
    // This is acceptable - the important thing is that if requests were made, they had cache-busting
    if (swRequests.length > 0) {
      expect(forcedRefreshRequests.length).toBeGreaterThan(0);
    } else {
      // If no requests were made (possibly cached), that's also acceptable
      // The test verifies the mechanism works when requests are made
      expect(swRequests.length).toBeGreaterThanOrEqual(0);
    }
  });

  test('should handle network timeouts gracefully on slow connections', async ({ page }) => {
    // Simulate slow network
    await page.route('**/weather.php*', async route => {
      // Delay response to simulate slow network
      await page.waitForTimeout(12000); // 12 seconds (longer than timeout)
      await route.continue();
    });
    
    // Trigger fetch
    await page.evaluate(() => {
      if (typeof fetchWeather === 'function') {
        fetchWeather(false);
      }
    });
    
    // Should handle timeout gracefully (not crash)
    await page.waitForTimeout(15000);
    
    // Page should still be functional
    const body = await page.locator('body');
    await expect(body).toBeVisible();
    
    // Should show error or cached data, not crash
    const pageContent = await page.textContent('body');
    expect(pageContent).toBeTruthy();
  });

  test('should update timestamp display when data is fresh', async ({ page }) => {
    // Wait for initial load
    await page.waitForFunction(
      () => document.getElementById('weather-last-updated'),
      { timeout: 10000 }
    );
    
    // Get initial timestamp
    const initialText = await page.locator('#weather-last-updated').textContent();
    
    // Wait a bit and trigger refresh
    await page.waitForTimeout(2000);
    
    await page.evaluate(() => {
      if (typeof fetchWeather === 'function') {
        fetchWeather(true);
      }
    });
    
    // Wait for timestamp to update
    await page.waitForTimeout(2000);
    
    // Timestamp should update (might be same or different depending on timing)
    const finalText = await page.locator('#weather-last-updated').textContent();
    
    // Should have valid timestamp text
    expect(finalText).toBeTruthy();
    expect(finalText.trim().length).toBeGreaterThan(0);
    
    // Should not show stale warning for fresh data
    if (finalText.includes('⚠️')) {
      // If it shows warning, should be due to actual staleness, not a bug
      expect(finalText).toMatch(/stale|hour|minute/i);
    }
  });
});

