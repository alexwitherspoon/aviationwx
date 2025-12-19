const { test, expect } = require('@playwright/test');

/**
 * API Endpoint Validation Tests
 * 
 * Validates that JavaScript code calls the correct API endpoints via network requests.
 * This catches bugs like calling /weather.php instead of /api/weather.php.
 * 
 * Note: Source code validation is covered by JavaScriptStaticAnalysisTest.php (unit test).
 */
test.describe('API Endpoint Validation', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';
  
  test.beforeEach(async ({ page }) => {
    // Navigate to the page
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    await page.waitForSelector('body', { state: 'visible' });
    
    // Wait for JavaScript to initialize
    await page.waitForFunction(
      () => typeof fetchWeather === 'function',
      { timeout: 10000 }
    ).catch(() => {
      console.warn('fetchWeather function may not be available yet');
    });
  });

  test('should call correct weather API endpoint', async ({ page }) => {
    const weatherRequests = [];
    
    // Intercept all network requests
    page.on('request', request => {
      const url = request.url();
      if (url.includes('weather.php')) {
        weatherRequests.push(url);
      }
    });
    
    // Trigger weather fetch
    await page.evaluate(() => {
      if (typeof fetchWeather === 'function') {
        fetchWeather(true); // Force refresh to ensure request is made
      }
    });
    
    // Wait for request to complete
    await page.waitForTimeout(3000);
    
    // Verify at least one weather request was made
    expect(weatherRequests.length).toBeGreaterThan(0);
    
    // Verify all weather requests use /api/weather.php
    const invalidRequests = weatherRequests.filter(url => {
      // Should contain /api/weather.php, not just /weather.php
      return url.includes('/weather.php') && !url.includes('/api/weather.php');
    });
    
    if (invalidRequests.length > 0) {
      console.error('Invalid weather API requests found:', invalidRequests);
    }
    
    expect(invalidRequests).toHaveLength(0);
    
    // Verify at least one request uses the correct endpoint
    const validRequests = weatherRequests.filter(url => 
      url.includes('/api/weather.php')
    );
    expect(validRequests.length).toBeGreaterThan(0);
  });

  test('should use absolute URLs for API calls', async ({ page }) => {
    const weatherRequests = [];
    
    page.on('request', request => {
      const url = request.url();
      if (url.includes('weather.php')) {
        weatherRequests.push(url);
      }
    });
    
    // Trigger weather fetch
    await page.evaluate(() => {
      if (typeof fetchWeather === 'function') {
        fetchWeather(true);
      }
    });
    
    await page.waitForTimeout(3000);
    
    // All requests should be absolute URLs (start with http:// or https://)
    weatherRequests.forEach(url => {
      expect(url).toMatch(/^https?:\/\//);
    });
  });

  test('should call webcam API with correct endpoint', async ({ page }) => {
    const webcamRequests = [];
    
    page.on('request', request => {
      const url = request.url();
      if (url.includes('webcam.php')) {
        webcamRequests.push(url);
      }
    });
    
    // Wait for webcam images to load (they load automatically)
    await page.waitForTimeout(5000);
    
    if (webcamRequests.length > 0) {
      // Verify all webcam requests use /api/webcam.php or /webcam.php (both are valid)
      // But should not use incorrect paths
      const invalidRequests = webcamRequests.filter(url => {
        // Should not have double slashes or incorrect paths
        return url.includes('//webcam.php') || url.includes('/webcam/webcam.php');
      });
      
      expect(invalidRequests).toHaveLength(0);
    }
  });
});
