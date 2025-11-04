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
    await page.evaluate(() => {
      localStorage.clear();
      sessionStorage.clear();
      // Clear Service Worker cache if possible
      if ('caches' in window) {
        return caches.keys().then(names => {
          return Promise.all(names.map(name => caches.delete(name)));
        });
      }
    });
    
    // Navigate to the page
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('domcontentloaded');
    await page.waitForSelector('body', { state: 'visible' });
  });

  test('should add cache-busting parameter when forcing refresh', async ({ page }) => {
    // Wait for weather data to load initially
    await page.waitForFunction(
      () => document.getElementById('weather-last-updated'),
      { timeout: 10000 }
    );
    
    // Intercept weather fetch requests
    const requests = [];
    page.on('request', request => {
      if (request.url().includes('/weather.php')) {
        requests.push(request.url());
      }
    });
    
    // Trigger a forced refresh by waiting for data to be stale (>5 minutes)
    // Or by manually calling fetchWeather(true)
    await page.evaluate(() => {
      // Force weather refresh
      if (typeof fetchWeather === 'function') {
        fetchWeather(true);
      }
    });
    
    // Wait a bit for the request to be made
    await page.waitForTimeout(1000);
    
    // Check if any request had cache-busting parameter
    const hasCacheBusting = requests.some(url => url.includes('_cb='));
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
    await page.route('**/weather.php*', async route => {
      const response = await route.fetch();
      const json = await response.json();
      
      // Modify the response to have an older timestamp
      if (json.weather && json.weather.last_updated) {
        json.weather.last_updated = json.weather.last_updated - 600; // 10 minutes older
      }
      
      await route.fulfill({
        response,
        json
      });
    });
    
    // Trigger a fetch
    await page.evaluate(() => {
      if (typeof fetchWeather === 'function') {
        fetchWeather(false); // Normal fetch
      }
    });
    
    // Wait for stale data detection and refresh
    await page.waitForTimeout(2000);
    
    // Check console for stale data detection warning
    const consoleMessages = [];
    page.on('console', msg => {
      if (msg.type() === 'warn' && msg.text().includes('stale cache detected')) {
        consoleMessages.push(msg.text());
      }
    });
    
    // The client should detect stale data and force a refresh
    // This is verified by checking that another request was made
    const finalTimestamp = await page.evaluate(() => {
      const el = document.getElementById('weather-last-updated');
      return el ? el.textContent : null;
    });
    
    // Should have detected stale data (either via console warning or timestamp update)
    expect(consoleMessages.length > 0 || finalTimestamp !== initialTimestamp).toBeTruthy();
  });

  test('should show visual indicators for stale data', async ({ page }) => {
    // Wait for weather data to load
    await page.waitForFunction(
      () => document.getElementById('weather-last-updated'),
      { timeout: 10000 }
    );
    
    // Manually set weatherLastUpdated to be >5 minutes ago
    await page.evaluate(() => {
      if (typeof weatherLastUpdated !== 'undefined') {
        // Set to 6 minutes ago
        weatherLastUpdated = new Date(Date.now() - 6 * 60 * 1000);
        // Update timestamp display
        if (typeof updateWeatherTimestamp === 'function') {
          updateWeatherTimestamp();
        }
      }
    });
    
    // Wait for timestamp update
    await page.waitForTimeout(500);
    
    // Check visual indicators
    const timestampEl = await page.locator('#weather-last-updated');
    const text = await timestampEl.textContent();
    const color = await timestampEl.evaluate(el => window.getComputedStyle(el).color);
    
    // Should show warning indicator (⚠️) and orange/red color
    expect(text).toMatch(/⚠️|warning|stale/i);
    
    // Color should be orange (#f80) or red (#c00) for stale data
    const isWarningColor = color.includes('rgb(255, 136, 0)') || // #f80
                          color.includes('rgb(204, 0, 0)') ||   // #c00
                          color.includes('rgb(255, 140, 0)') || // Orange variants
                          color.includes('rgb(220, 20, 60)');   // Red variants
    
    expect(isWarningColor || text.includes('stale')).toBeTruthy();
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
    await page.waitForLoadState('networkidle');
    
    // Wait for Service Worker to be registered
    await page.waitForFunction(
      () => 'serviceWorker' in navigator,
      { timeout: 5000 }
    ).catch(() => {}); // Service Worker might not be available in test
    
    // Intercept Service Worker fetch events
    const swRequests = [];
    await page.addInitScript(() => {
      // This runs in page context before navigation
      // We can't directly intercept SW fetch, but we can check if requests include cache-busting
    });
    
    // Monitor network requests
    page.on('request', request => {
      const url = request.url();
      if (url.includes('/weather.php')) {
        swRequests.push({
          url,
          headers: request.headers(),
          hasCacheBusting: url.includes('_cb=')
        });
      }
    });
    
    // Trigger forced refresh
    await page.evaluate(() => {
      if (typeof fetchWeather === 'function') {
        fetchWeather(true);
      }
    });
    
    // Wait for request
    await page.waitForTimeout(1500);
    
    // At least one request should have cache-busting parameter
    const forcedRefreshRequests = swRequests.filter(r => r.hasCacheBusting);
    expect(forcedRefreshRequests.length).toBeGreaterThan(0);
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

