const { test, expect } = require('@playwright/test');

/**
 * Tests for Data Outage Banner Feature
 * 
 * Verifies that the outage banner appears when all data sources are stale
 * and disappears when any source recovers.
 */
test.describe('Data Outage Banner', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';
  
  test.beforeEach(async ({ page, context }) => {
    // Clear all caches before each test
    await context.clearCookies();
    
    // Navigate to the page
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    await page.waitForSelector('body', { state: 'visible' });
    
    // Clear storage
    await page.evaluate(() => {
      try {
        localStorage.clear();
        sessionStorage.clear();
      } catch (e) {
        console.warn('Could not clear storage:', e.message);
      }
    });
    
    // Wait for JavaScript functions to be available
    await page.waitForFunction(
      () => typeof checkAndUpdateOutageBanner === 'function',
      { timeout: 10000 }
    ).catch(() => {
      console.warn('checkAndUpdateOutageBanner function may not be available yet');
    });
  });

  test('should not show outage banner when airport is in maintenance mode', async ({ page }) => {
    // Navigate to an airport in maintenance mode (pdx in test fixtures)
    await page.goto(`${baseUrl}/?airport=pdx`);
    await page.waitForLoadState('networkidle');
    
    // Check that outage banner is not visible (maintenance mode should prevent it)
    const outageBanner = page.locator('#data-outage-banner');
    await expect(outageBanner).toHaveCount(0);
    
    // Maintenance banner should still be visible
    const maintenanceBanner = page.locator('.maintenance-banner');
    await expect(maintenanceBanner).toBeVisible({ timeout: 5000 });
  });

  test('should not show outage banner when data is fresh', async ({ page }) => {
    // Wait for page to fully load
    await page.waitForLoadState('networkidle');
    
    // Check that outage banner is not visible
    const banner = page.locator('#data-outage-banner');
    await expect(banner).toHaveCount(0);
  });

  test('should show outage banner when all sources exceed failclosed threshold', async ({ page }) => {
    // Mock all data sources to be stale (older than STALE_FAILCLOSED_SECONDS = 3 hours by default)
    // Use 4 hours to ensure we're well past the threshold
    const staleTimestamp = Math.floor(Date.now() / 1000) - (4 * 3600); // 4 hours ago
    
    await page.evaluate((timestamp) => {
      // Set weather data to be stale
      if (typeof weatherLastUpdated !== 'undefined') {
        weatherLastUpdated = new Date(timestamp * 1000);
      }
      
      // Set current weather data with stale timestamps
      if (typeof currentWeatherData !== 'undefined') {
        currentWeatherData = {
          last_updated_primary: timestamp,
          obs_time_primary: timestamp,
          last_updated_metar: timestamp,
          obs_time_metar: timestamp
        };
      }
      
      // Set all webcam timestamps to be stale
      if (typeof CAM_TS !== 'undefined') {
        Object.keys(CAM_TS).forEach(index => {
          CAM_TS[index] = timestamp;
        });
      }
      
      // Trigger banner check
      if (typeof checkAndUpdateOutageBanner === 'function') {
        checkAndUpdateOutageBanner();
      }
    }, staleTimestamp);
    
    // Wait a moment for banner to appear
    await page.waitForTimeout(500);
    
    // Check that outage banner is visible
    const banner = page.locator('#data-outage-banner');
    await expect(banner).toBeVisible({ timeout: 2000 });
    
    // Verify banner text
    const bannerText = await banner.textContent();
    expect(bannerText).toContain('Data Outage Detected');
    expect(bannerText).toContain('All local data sources are currently offline');
    expect(bannerText).toContain('may not reflect current conditions');
    
    // Verify banner styling (red background)
    const backgroundColor = await banner.evaluate((el) => {
      return window.getComputedStyle(el).backgroundColor;
    });
    expect(backgroundColor).toBeTruthy();
  });

  test('should hide outage banner when any source recovers', async ({ page }) => {
    // First, set all sources to be stale
    const staleTimestamp = Math.floor(Date.now() / 1000) - (2 * 3600);
    
    await page.evaluate((timestamp) => {
      if (typeof weatherLastUpdated !== 'undefined') {
        weatherLastUpdated = new Date(timestamp * 1000);
      }
      if (typeof currentWeatherData !== 'undefined') {
        currentWeatherData = {
          last_updated_primary: timestamp,
          obs_time_primary: timestamp
        };
      }
      if (typeof CAM_TS !== 'undefined') {
        Object.keys(CAM_TS).forEach(index => {
          CAM_TS[index] = timestamp;
        });
      }
      if (typeof checkAndUpdateOutageBanner === 'function') {
        checkAndUpdateOutageBanner();
      }
    }, staleTimestamp);
    
    await page.waitForTimeout(500);
    
    // Verify banner is visible
    const banner = page.locator('#data-outage-banner');
    await expect(banner).toBeVisible({ timeout: 2000 });
    
    // Now update one source to be fresh
    const freshTimestamp = Math.floor(Date.now() / 1000) - 30; // 30 seconds ago
    
    await page.evaluate((timestamp) => {
      // Update weather to be fresh
      if (typeof weatherLastUpdated !== 'undefined') {
        weatherLastUpdated = new Date(timestamp * 1000);
      }
      if (typeof currentWeatherData !== 'undefined') {
        currentWeatherData.last_updated_primary = timestamp;
        currentWeatherData.obs_time_primary = timestamp;
      }
      
      // Trigger banner check
      if (typeof checkAndUpdateOutageBanner === 'function') {
        checkAndUpdateOutageBanner();
      }
    }, freshTimestamp);
    
    await page.waitForTimeout(500);
    
    // Banner should now be hidden
    await expect(banner).not.toBeVisible({ timeout: 2000 });
  });

  test('should display newest timestamp in banner', async ({ page }) => {
    // Set all sources to be stale with different timestamps
    const oldestTimestamp = Math.floor(Date.now() / 1000) - (3 * 3600); // 3 hours ago
    const newestTimestamp = Math.floor(Date.now() / 1000) - (2 * 3600); // 2 hours ago
    
    await page.evaluate(({ oldest, newest }) => {
      if (typeof weatherLastUpdated !== 'undefined') {
        weatherLastUpdated = new Date(oldest * 1000);
      }
      if (typeof currentWeatherData !== 'undefined') {
        currentWeatherData = {
          last_updated_primary: oldest,
          obs_time_primary: oldest,
          last_updated_metar: newest,
          obs_time_metar: newest
        };
      }
      if (typeof CAM_TS !== 'undefined') {
        Object.keys(CAM_TS).forEach(index => {
          CAM_TS[index] = oldest;
        });
      }
      if (typeof checkAndUpdateOutageBanner === 'function') {
        checkAndUpdateOutageBanner();
      }
    }, { oldest: oldestTimestamp, newest: newestTimestamp });
    
    await page.waitForTimeout(500);
    
    // Check that banner is visible
    const banner = page.locator('#data-outage-banner');
    await expect(banner).toBeVisible({ timeout: 2000 });
    
    // Check that timestamp element exists and has content
    const timestampElem = page.locator('#outage-newest-time');
    await expect(timestampElem).toBeVisible();
    
    const timestampText = await timestampElem.textContent();
    expect(timestampText).toBeTruthy();
    expect(timestampText).not.toBe('--');
    expect(timestampText).not.toBe('unknown time');
  });

  test('should show webcam warning emoji when webcam exceeds STALE_FAILCLOSED_SECONDS', async ({ page }) => {
    // Wait for page to load
    await page.waitForLoadState('networkidle');
    
    // Check if webcams exist
    const webcamCount = await page.locator('.webcam-item').count();
    if (webcamCount === 0) {
      test.skip();
      return;
    }
    
    // Set webcam timestamp to be stale (exceeds STALE_FAILCLOSED_SECONDS = 3 hours by default)
    const staleTimestamp = Math.floor(Date.now() / 1000) - (4 * 3600); // 4 hours ago
    
    await page.evaluate((timestamp) => {
      // Update first webcam timestamp
      if (typeof CAM_TS !== 'undefined' && CAM_TS[0] !== undefined) {
        CAM_TS[0] = timestamp;
      }
      
      // Update timestamp display
      const timestampElem = document.getElementById('webcam-timestamp-0');
      if (timestampElem && typeof updateTimestampDisplay === 'function') {
        updateTimestampDisplay(timestampElem, timestamp);
      }
    }, staleTimestamp);
    
    await page.waitForTimeout(500);
    
    // Check that warning emoji is visible
    const warningElem = page.locator('#webcam-timestamp-warning-0');
    await expect(warningElem).toBeVisible({ timeout: 2000 });
    
    const warningText = await warningElem.textContent();
    expect(warningText).toContain('⚠️');
  });

  test('should hide webcam warning emoji when webcam is fresh', async ({ page }) => {
    // Wait for page to load
    await page.waitForLoadState('networkidle');
    
    // Check if webcams exist
    const webcamCount = await page.locator('.webcam-item').count();
    if (webcamCount === 0) {
      test.skip();
      return;
    }
    
    // First set to stale
    const staleTimestamp = Math.floor(Date.now() / 1000) - (4 * 3600);
    
    await page.evaluate((timestamp) => {
      if (typeof CAM_TS !== 'undefined' && CAM_TS[0] !== undefined) {
        CAM_TS[0] = timestamp;
      }
      const timestampElem = document.getElementById('webcam-timestamp-0');
      if (timestampElem && typeof updateTimestampDisplay === 'function') {
        updateTimestampDisplay(timestampElem, timestamp);
      }
    }, staleTimestamp);
    
    await page.waitForTimeout(500);
    
    // Verify warning is visible
    const warningElem = page.locator('#webcam-timestamp-warning-0');
    await expect(warningElem).toBeVisible({ timeout: 2000 });
    
    // Now set to fresh
    const freshTimestamp = Math.floor(Date.now() / 1000) - 30;
    
    await page.evaluate((timestamp) => {
      if (typeof CAM_TS !== 'undefined' && CAM_TS[0] !== undefined) {
        CAM_TS[0] = timestamp;
      }
      const timestampElem = document.getElementById('webcam-timestamp-0');
      if (timestampElem && typeof updateTimestampDisplay === 'function') {
        updateTimestampDisplay(timestampElem, timestamp);
      }
    }, freshTimestamp);
    
    await page.waitForTimeout(500);
    
    // Warning should now be hidden
    await expect(warningElem).not.toBeVisible({ timeout: 2000 });
  });

  test('should fetch outage status from API endpoint', async ({ page }) => {
    // Wait for page to load
    await page.waitForLoadState('networkidle');
    
    // Intercept API calls to verify endpoint is called
    let apiCalled = false;
    await page.route('**/api/outage-status.php*', async (route) => {
      apiCalled = true;
      // Return mock response indicating no outage
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          in_outage: false,
          newest_timestamp: 0,
          sources: {}
        })
      });
    });
    
    // Trigger API call manually
    await page.evaluate(() => {
      if (typeof fetchOutageStatus === 'function') {
        fetchOutageStatus();
      }
    });
    
    // Wait a moment for the API call
    await page.waitForTimeout(1000);
    
    // Verify API was called
    expect(apiCalled).toBe(true);
  });

  test('should update banner based on API response', async ({ page }) => {
    // Wait for page to load
    await page.waitForLoadState('networkidle');
    
    // First, set up banner to exist (simulate outage state)
    await page.evaluate(() => {
      // Create banner if it doesn't exist
      let banner = document.getElementById('data-outage-banner');
      if (!banner) {
        banner = document.createElement('div');
        banner.id = 'data-outage-banner';
        banner.className = 'data-outage-banner';
        banner.style.display = 'block';
        banner.innerHTML = '⚠️ Data Outage Detected: All local data sources are currently offline due to a local outage. The latest information shown is from <span id="outage-newest-time">--</span>. Data will automatically update once the local site is back online.';
        document.body.insertBefore(banner, document.body.firstChild);
      }
    });
    
    // Intercept API call and return recovery response
    await page.route('**/api/outage-status.php*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          in_outage: false,
          newest_timestamp: 0,
          sources: {
            primary: { timestamp: Math.floor(Date.now() / 1000) - 60, stale: false }
          }
        })
      });
    });
    
    // Trigger API call
    await page.evaluate(() => {
      if (typeof fetchOutageStatus === 'function') {
        fetchOutageStatus();
      }
    });
    
    // Wait for banner to be hidden
    await page.waitForTimeout(1000);
    
    // Verify banner is hidden
    const banner = page.locator('#data-outage-banner');
    const display = await banner.evaluate((el) => window.getComputedStyle(el).display);
    expect(display).toBe('none');
  });
});

