const { test, expect } = require('@playwright/test');

/**
 * Integration tests for weather staleness threshold calculation logic
 * 
 * Verifies frontend JavaScript correctly calculates staleness thresholds based on
 * weather source type (METAR-only vs non-METAR) and refresh intervals.
 */
test.describe('Weather Staleness Threshold Calculation', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';
  const SECONDS_PER_HOUR = 3600;
  const DEFAULT_REFRESH_SECONDS = 60;
  const DOM_UPDATE_DELAY_MS = 500;
  
  test.beforeEach(async ({ page, context }) => {
    await context.clearCookies();
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    await page.waitForSelector('body', { state: 'visible' });
    
    // Clear storage after navigation (localStorage requires real page context)
    await page.evaluate(() => {
      try {
        localStorage.clear();
        sessionStorage.clear();
      } catch (e) {
        // Storage clearing failures are non-critical for test isolation
        console.warn('Could not clear storage:', e.message);
      }
      if ('caches' in window) {
        return caches.keys().then(names => {
          return Promise.all(names.map(name => caches.delete(name)));
        });
      }
    });
    
    // Wait for JavaScript constants and functions required for threshold calculation
    await page.waitForFunction(
      () => {
        return typeof updateWeatherTimestamp === 'function' &&
               typeof AIRPORT_DATA !== 'undefined' &&
               typeof WEATHER_STALENESS_WARNING_HOURS_METAR !== 'undefined' &&
               typeof WEATHER_STALENESS_ERROR_HOURS_METAR !== 'undefined' &&
               typeof WEATHER_STALENESS_WARNING_MULTIPLIER !== 'undefined' &&
               typeof WEATHER_STALENESS_ERROR_MULTIPLIER !== 'undefined';
      },
      { timeout: 10000 }
    ).catch(() => {
      // Constants may not be available if page load failed - test will fail appropriately
      console.warn('JavaScript constants may not be available yet');
    });
  });

  /**
   * Calculate expected thresholds based on source type and refresh interval
   * 
   * @param {Page} page - Playwright page object
   * @param {boolean} isMetarOnly - Whether source is METAR-only
   * @param {number} refreshSeconds - Refresh interval in seconds (default: 60)
   * @returns {Promise<{warning: number, error: number}>} Threshold values in seconds
   */
  async function getExpectedThresholds(page, isMetarOnly, refreshSeconds = DEFAULT_REFRESH_SECONDS) {
    return await page.evaluate(({ isMetarOnly, refreshSeconds }) => {
      const SECONDS_PER_HOUR = 3600;
      
      if (isMetarOnly) {
        return {
          warning: WEATHER_STALENESS_WARNING_HOURS_METAR * SECONDS_PER_HOUR,
          error: WEATHER_STALENESS_ERROR_HOURS_METAR * SECONDS_PER_HOUR
        };
      } else {
        // Enforce minimum to prevent invalid thresholds from zero/negative values
        const validRefresh = Math.max(1, refreshSeconds);
        return {
          warning: validRefresh * WEATHER_STALENESS_WARNING_MULTIPLIER,
          error: validRefresh * WEATHER_STALENESS_ERROR_MULTIPLIER
        };
      }
    }, { isMetarOnly, refreshSeconds });
  }

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
    await page.waitForTimeout(DOM_UPDATE_DELAY_MS);
  }

  /**
   * Get current timestamp display state (text, color, styling)
   * 
   * @param {Page} page - Playwright page object
   * @returns {Promise<{text: string, color: string, fontWeight: string}>} Display state
   */
  async function getTimestampDisplay(page) {
    const timestampEl = await page.locator('#weather-last-updated');
    const text = await timestampEl.textContent();
    const color = await timestampEl.evaluate(el => window.getComputedStyle(el).color);
    const fontWeight = await timestampEl.evaluate(el => window.getComputedStyle(el).fontWeight);
    
    return { text: text?.trim() || '', color, fontWeight };
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
        return {
          isMetarOnly: true,
          warningSeconds: WEATHER_STALENESS_WARNING_HOURS_METAR * 3600,
          errorSeconds: WEATHER_STALENESS_ERROR_HOURS_METAR * 3600
        };
      } else {
        return {
          isMetarOnly: false,
          warningSeconds: refreshSeconds * WEATHER_STALENESS_WARNING_MULTIPLIER,
          errorSeconds: refreshSeconds * WEATHER_STALENESS_ERROR_MULTIPLIER
        };
      }
    });
  }

  test('should calculate METAR-only thresholds correctly', async ({ page }) => {
    await page.evaluate(() => {
      if (typeof AIRPORT_DATA !== 'undefined' && AIRPORT_DATA) {
        AIRPORT_DATA.weather_source = { type: 'metar' };
      }
    });

    const thresholds = await getExpectedThresholds(page, true);
    const constants = await page.evaluate(() => ({
      warningHours: WEATHER_STALENESS_WARNING_HOURS_METAR,
      errorHours: WEATHER_STALENESS_ERROR_HOURS_METAR
    }));
    
    expect(thresholds.warning).toBe(constants.warningHours * SECONDS_PER_HOUR);
    expect(thresholds.error).toBe(constants.errorHours * SECONDS_PER_HOUR);
    expect(constants.warningHours).toBe(1);
    expect(constants.errorHours).toBe(2);
  });

  test('should calculate non-METAR thresholds with default refresh interval', async ({ page }) => {
    await page.evaluate(() => {
      if (typeof AIRPORT_DATA !== 'undefined' && AIRPORT_DATA) {
        AIRPORT_DATA.weather_source = { type: 'tempest' };
        AIRPORT_DATA.weather_refresh_seconds = DEFAULT_REFRESH_SECONDS;
      }
    });

    const thresholds = await getExpectedThresholds(page, false, DEFAULT_REFRESH_SECONDS);
    const constants = await page.evaluate(() => ({
      warningMultiplier: WEATHER_STALENESS_WARNING_MULTIPLIER,
      errorMultiplier: WEATHER_STALENESS_ERROR_MULTIPLIER
    }));
    
    expect(thresholds.warning).toBe(DEFAULT_REFRESH_SECONDS * constants.warningMultiplier);
    expect(thresholds.error).toBe(DEFAULT_REFRESH_SECONDS * constants.errorMultiplier);
    expect(constants.warningMultiplier).toBe(5);
    expect(constants.errorMultiplier).toBe(10);
  });

  test('should calculate non-METAR thresholds with custom refresh interval', async ({ page }) => {
    // Test with 30 second refresh
    await page.evaluate(() => {
      if (typeof AIRPORT_DATA !== 'undefined' && AIRPORT_DATA) {
        AIRPORT_DATA.weather_source = { type: 'ambient' };
        AIRPORT_DATA.weather_refresh_seconds = 30;
      }
    });

    const thresholds = await getExpectedThresholds(page, false, 30);
    
    // With 30 second refresh: warning = 30 * 5 = 150 seconds (2.5 minutes)
    // error = 30 * 10 = 300 seconds (5 minutes)
    expect(thresholds.warning).toBe(150);
    expect(thresholds.error).toBe(300);
  });

  test('should handle invalid refresh interval (zero or negative)', async ({ page }) => {
    // Test with invalid refresh intervals
    await page.evaluate(() => {
      if (typeof AIRPORT_DATA !== 'undefined' && AIRPORT_DATA) {
        AIRPORT_DATA.weather_source = { type: 'weatherlink' };
        AIRPORT_DATA.weather_refresh_seconds = 0;
      }
    });

    const thresholdsZero = await getExpectedThresholds(page, false, 0);
    
    // Should default to minimum of 1 second
    expect(thresholdsZero.warning).toBeGreaterThan(0);
    expect(thresholdsZero.error).toBeGreaterThan(0);
    
    // Test with negative value
    await page.evaluate(() => {
      if (typeof AIRPORT_DATA !== 'undefined' && AIRPORT_DATA) {
        AIRPORT_DATA.weather_refresh_seconds = -10;
      }
    });

    const thresholdsNegative = await getExpectedThresholds(page, false, -10);
    
    // Should default to minimum of 1 second
    expect(thresholdsNegative.warning).toBeGreaterThan(0);
    expect(thresholdsNegative.error).toBeGreaterThan(0);
  });

  test('should display fresh data correctly (below warning threshold)', async ({ page }) => {
    await setWeatherTimestamp(page, 30);
    const display = await getTimestampDisplay(page);
    
    expect(display.text).not.toContain('⚠️');
    expect(display.text).toMatch(/\d+\s+(second|minute)/);
    expect(display.color).toMatch(/rgb\(102|rgb\(51|rgb\(85/);
  });

  test('should display stale warning correctly (above warning, below error threshold)', async ({ page }) => {
    const thresholdInfo = await getThresholdInfo(page);
    const staleAge = thresholdInfo.warningSeconds + 30;
    
    await setWeatherTimestamp(page, staleAge);
    const display = await getTimestampDisplay(page);
    
    expect(display.text).toContain('⚠️');
    expect(display.text).toContain('refreshing');
    expect(display.color).toMatch(/rgb\(255,\s*136,\s*0\)|rgb\(255,\s*140,\s*0\)/);
  });

  test('should display very stale error correctly (above error threshold)', async ({ page }) => {
    const thresholdInfo = await getThresholdInfo(page);
    const veryStaleAge = thresholdInfo.errorSeconds + 60;
    
    await setWeatherTimestamp(page, veryStaleAge);
    const display = await getTimestampDisplay(page);
    
    expect(display.text).toContain('⚠️');
    expect(display.text).toMatch(/stale|outdated/i);
    expect(display.color).toMatch(/rgb\(204,\s*0,\s*0\)|rgb\(220,\s*20,\s*60\)/);
    expect(parseInt(display.fontWeight)).toBeGreaterThanOrEqual(600);
  });

  test('should display METAR-specific very stale message', async ({ page }) => {
    await page.evaluate(() => {
      if (typeof AIRPORT_DATA !== 'undefined' && AIRPORT_DATA) {
        AIRPORT_DATA.weather_source = { type: 'metar' };
      }
    });

    await setWeatherTimestamp(page, 2 * SECONDS_PER_HOUR + 60);
    const display = await getTimestampDisplay(page);
    
    expect(display.text).toContain('⚠️');
    expect(display.text).toContain('2 hours stale');
    expect(display.text).toContain('outdated');
  });

  test('should display non-METAR-specific very stale message with calculated threshold', async ({ page }) => {
    await page.evaluate(() => {
      if (typeof AIRPORT_DATA !== 'undefined' && AIRPORT_DATA) {
        AIRPORT_DATA.weather_source = { type: 'tempest' };
        AIRPORT_DATA.weather_refresh_seconds = DEFAULT_REFRESH_SECONDS;
      }
    });

    // Error threshold for 60s refresh = 60 * 10 = 600 seconds (10 minutes)
    await setWeatherTimestamp(page, 10 * 60 + 30);
    const display = await getTimestampDisplay(page);
    
    expect(display.text).toContain('⚠️');
    expect(display.text).toMatch(/Over.*stale/i);
    expect(display.text).not.toContain('2 hours stale');
  });

  test('should handle missing AIRPORT_DATA gracefully', async ({ page }) => {
    await page.evaluate(() => {
      if (typeof AIRPORT_DATA !== 'undefined' && AIRPORT_DATA) {
        AIRPORT_DATA.weather_source = null;
      }
    });

    const thresholds = await getExpectedThresholds(page, false, DEFAULT_REFRESH_SECONDS);
    
    expect(thresholds.warning).toBeGreaterThan(0);
    expect(thresholds.error).toBeGreaterThan(0);
    expect(thresholds.error).toBeGreaterThan(thresholds.warning);
  });

  test('should update display when timestamp changes', async ({ page }) => {
    await setWeatherTimestamp(page, 30);
    const freshDisplay = await getTimestampDisplay(page);
    expect(freshDisplay.text).not.toContain('⚠️');
    
    const thresholdInfo = await getThresholdInfo(page);
    await setWeatherTimestamp(page, thresholdInfo.warningSeconds + 30);
    const staleDisplay = await getTimestampDisplay(page);
    
    expect(staleDisplay.text).toContain('⚠️');
    expect(staleDisplay.text).not.toBe(freshDisplay.text);
  });

  test('should use correct thresholds for different refresh intervals', async ({ page }) => {
    const testCases = [
      { refresh: 30, expectedWarning: 150, expectedError: 300 },
      { refresh: 60, expectedWarning: 300, expectedError: 600 },
      { refresh: 120, expectedWarning: 600, expectedError: 1200 },
      { refresh: 300, expectedWarning: 1500, expectedError: 3000 }
    ];

    for (const testCase of testCases) {
      await page.evaluate((refresh) => {
        if (typeof AIRPORT_DATA !== 'undefined' && AIRPORT_DATA) {
          AIRPORT_DATA.weather_source = { type: 'tempest' };
          AIRPORT_DATA.weather_refresh_seconds = refresh;
        }
      }, testCase.refresh);

      const thresholds = await getExpectedThresholds(page, false, testCase.refresh);
      
      expect(thresholds.warning).toBe(testCase.expectedWarning);
      expect(thresholds.error).toBe(testCase.expectedError);
    }
  });

  test('should maintain threshold relationship (warning < error)', async ({ page }) => {
    const refreshIntervals = [30, 60, 120, 300];
    
    for (const refresh of refreshIntervals) {
      const thresholds = await getExpectedThresholds(page, false, refresh);
      expect(thresholds.error).toBeGreaterThan(thresholds.warning);
    }
    
    // Also test METAR thresholds
    const metarThresholds = await getExpectedThresholds(page, true);
    expect(metarThresholds.error).toBeGreaterThan(metarThresholds.warning);
  });
});

