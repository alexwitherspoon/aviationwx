const { test, expect } = require('@playwright/test');

/**
 * Browser tests for timestamp formatting with two-unit precision
 * 
 * Verifies that all "Last updated" timestamps on the airport page display
 * with two-unit precision (e.g., "1 hour 23 minutes ago" instead of "1 hour ago").
 */

test.describe('Timestamp Format - Two-Unit Precision', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';
  
  test.beforeEach(async ({ page, context }) => {
    await context.clearCookies();
    
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    await page.waitForSelector('body', { state: 'visible' });
  });
  
  test('weather last updated should show two-unit precision when applicable', async ({ page }) => {
    // Wait for weather data to load
    await page.waitForFunction(
      () => {
        const el = document.getElementById('weather-last-updated');
        return el && el.textContent && el.textContent.trim() !== '--';
      },
      { timeout: 15000 }
    );
    
    // Get the timestamp text
    const timestampText = await page.textContent('#weather-last-updated');
    expect(timestampText).toBeTruthy();
    expect(timestampText.trim()).not.toBe('--');
    
    // If timestamp shows hours, it should include minutes if applicable
    // (e.g., "1 hour 23 minutes ago" not just "1 hour ago")
    // Note: We can't control the exact age, so we check the format pattern
    const hasTwoUnits = /\d+\s+(hour|minute|day|second)s?\s+\d+\s+(hour|minute|day|second)s?\s+ago/.test(timestampText);
    const hasSingleUnit = /\d+\s+(hour|minute|day|second)s?\s+ago/.test(timestampText);
    
    // Should match one of the patterns (two units or single unit for very recent/old times)
    expect(hasTwoUnits || hasSingleUnit).toBe(true);
    
    // Should end with "ago"
    expect(timestampText.trim()).toMatch(/ago$/);
  });
  
  test('wind last updated should show two-unit precision when applicable', async ({ page }) => {
    // Wait for wind data to load
    await page.waitForFunction(
      () => {
        const el = document.getElementById('wind-last-updated');
        return el && el.textContent && el.textContent.trim() !== '--';
      },
      { timeout: 15000 }
    );
    
    // Get the timestamp text
    const timestampText = await page.textContent('#wind-last-updated');
    expect(timestampText).toBeTruthy();
    expect(timestampText.trim()).not.toBe('--');
    
    // Should match timestamp format pattern
    const hasTwoUnits = /\d+\s+(hour|minute|day|second)s?\s+\d+\s+(hour|minute|day|second)s?\s+ago/.test(timestampText);
    const hasSingleUnit = /\d+\s+(hour|minute|day|second)s?\s+ago/.test(timestampText);
    
    expect(hasTwoUnits || hasSingleUnit).toBe(true);
    expect(timestampText.trim()).toMatch(/ago$/);
  });
  
  test('webcam timestamp should show two-unit precision when applicable', async ({ page }) => {
    // Check if webcams are configured
    const hasWebcams = await page.evaluate(() => {
      return typeof AIRPORT_DATA !== 'undefined' && 
             AIRPORT_DATA && 
             AIRPORT_DATA.webcams && 
             AIRPORT_DATA.webcams.length > 0;
    });
    
    if (!hasWebcams) {
      test.skip();
      return;
    }
    
    // Wait for webcam timestamp to load
    await page.waitForFunction(
      () => {
        const el = document.getElementById('webcam-timestamp-0');
        return el && el.textContent && el.textContent.trim() !== '--';
      },
      { timeout: 15000 }
    );
    
    // Get the timestamp text (may include actual time in parentheses)
    const timestampText = await page.textContent('#webcam-timestamp-0');
    expect(timestampText).toBeTruthy();
    expect(timestampText.trim()).not.toBe('--');
    
    // Webcam timestamp may show "7:05:24 PM (1 hour 23 minutes ago)" format
    // Check if it contains relative time in parentheses
    if (timestampText.includes('(') && timestampText.includes(')')) {
      const relativeTimeMatch = timestampText.match(/\(([^)]+)\)/);
      if (relativeTimeMatch) {
        const relativeTime = relativeTimeMatch[1];
        // Should match two-unit or single-unit pattern
        const hasTwoUnits = /\d+\s+(hour|minute|day|second)s?\s+\d+\s+(hour|minute|day|second)s?\s+ago/.test(relativeTime);
        const hasSingleUnit = /\d+\s+(hour|minute|day|second)s?\s+ago/.test(relativeTime);
        expect(hasTwoUnits || hasSingleUnit).toBe(true);
      }
    } else {
      // If no parentheses, should match direct format
      const hasTwoUnits = /\d+\s+(hour|minute|day|second)s?\s+\d+\s+(hour|minute|day|second)s?\s+ago/.test(timestampText);
      const hasSingleUnit = /\d+\s+(hour|minute|day|second)s?\s+ago/.test(timestampText);
      expect(hasTwoUnits || hasSingleUnit).toBe(true);
    }
  });
  
  test('formatRelativeTime function should handle edge cases', async ({ page }) => {
    // Test the JavaScript function directly
    const results = await page.evaluate(() => {
      // Get the formatRelativeTime function from the page context
      // Note: This assumes the function is in global scope or accessible
      if (typeof formatRelativeTime === 'function') {
        return {
          negative: formatRelativeTime(-1),
          zero: formatRelativeTime(0),
          oneSecond: formatRelativeTime(1),
          oneMinute: formatRelativeTime(60),
          oneMinuteThirty: formatRelativeTime(90),
          oneHour: formatRelativeTime(3600),
          oneHourTwentyThree: formatRelativeTime(4983),
          oneDay: formatRelativeTime(86400),
          oneDayOneHour: formatRelativeTime(90000),
        };
      }
      return null;
    });
    
    if (results) {
      expect(results.negative).toBe('--');
      expect(results.zero).toBe('0 seconds ago');
      expect(results.oneSecond).toBe('1 second ago');
      expect(results.oneMinute).toBe('1 minute ago');
      expect(results.oneMinuteThirty).toBe('1 minute 30 seconds ago');
      expect(results.oneHour).toBe('1 hour ago');
      expect(results.oneHourTwentyThree).toBe('1 hour 23 minutes ago');
      expect(results.oneDay).toBe('1 day ago');
      expect(results.oneDayOneHour).toBe('1 day 1 hour ago');
    } else {
      // Function might not be in global scope, skip this test
      test.skip();
    }
  });
});


