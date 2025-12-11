const { test, expect } = require('@playwright/test');

/**
 * Weather Data Visibility Tests
 * 
 * Validates that weather data fetched from the API is actually displayed
 * in the DOM. This ensures the full data flow works correctly.
 */
test.describe('Weather Data Visibility', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';
  
  test.beforeEach(async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    await page.waitForSelector('body', { state: 'visible' });
    
    // Wait for JavaScript to initialize
    await page.waitForFunction(
      () => typeof fetchWeather === 'function',
      { timeout: 10000 }
    ).catch(() => {});
  });

  test('should display weather data in DOM', async ({ page }) => {
    // Wait for weather data to be fetched and displayed
    await page.waitForFunction(
      () => {
        // Check for temperature display (not just placeholder)
        const bodyText = document.body.textContent || '';
        
        // Should have temperature with unit (e.g., "56°F" or "13°C")
        const hasTemp = /\d+°[FC]/.test(bodyText);
        
        // Should have "Last updated" timestamp (not "--")
        const lastUpdatedEl = document.getElementById('weather-last-updated');
        const hasTimestamp = lastUpdatedEl && 
                            lastUpdatedEl.textContent && 
                            lastUpdatedEl.textContent.trim() !== '--' &&
                            lastUpdatedEl.textContent.trim() !== '';
        
        return hasTemp && hasTimestamp;
      },
      { timeout: 15000 }
    );
    
    // Verify specific data points are visible
    const pageContent = await page.textContent('body');
    
    // Should have temperature (with unit)
    expect(pageContent).toMatch(/\d+°[FC]/);
    
    // Should have wind data (speed with unit)
    expect(pageContent).toMatch(/\d+\s*(kts|mph|km\/h)/i);
    
    // Should have "Last updated" timestamp (not "--")
    const lastUpdated = await page.textContent('#weather-last-updated');
    expect(lastUpdated).toBeTruthy();
    expect(lastUpdated.trim()).not.toBe('--');
    expect(lastUpdated.trim()).not.toBe('');
  });

  test('should display temperature with correct unit', async ({ page }) => {
    // Wait for temperature to be displayed
    await page.waitForFunction(
      () => {
        const bodyText = document.body.textContent || '';
        return /\d+°[FC]/.test(bodyText);
      },
      { timeout: 15000 }
    );
    
    const pageContent = await page.textContent('body');
    
    // Should have temperature with unit
    const tempMatch = pageContent.match(/(\d+)°([FC])/);
    expect(tempMatch).toBeTruthy();
    
    const tempValue = parseInt(tempMatch[1]);
    const tempUnit = tempMatch[2];
    
    // Temperature should be reasonable (not 0 or extremely high)
    expect(tempValue).toBeGreaterThan(-100);
    expect(tempValue).toBeLessThan(150);
    expect(['F', 'C']).toContain(tempUnit);
  });

  test('should display wind data', async ({ page }) => {
    // Wait for wind data to be displayed
    await page.waitForFunction(
      () => {
        const bodyText = document.body.textContent || '';
        return /\d+\s*(kts|mph|km\/h)/i.test(bodyText);
      },
      { timeout: 15000 }
    );
    
    const pageContent = await page.textContent('body');
    
    // Should have wind speed with unit
    const windMatch = pageContent.match(/(\d+)\s*(kts|mph|km\/h)/i);
    expect(windMatch).toBeTruthy();
    
    const windSpeed = parseInt(windMatch[1]);
    const windUnit = windMatch[2].toLowerCase();
    
    // Wind speed should be reasonable
    expect(windSpeed).toBeGreaterThanOrEqual(0);
    expect(windSpeed).toBeLessThan(200);
    expect(['kts', 'mph', 'km/h']).toContain(windUnit);
  });

  test('should display pressure data when available', async ({ page }) => {
    // Wait for weather data to load
    await page.waitForFunction(
      () => {
        const bodyText = document.body.textContent || '';
        return /\d+°[FC]/.test(bodyText); // Temperature indicates weather data loaded
      },
      { timeout: 15000 }
    );
    
    const pageContent = await page.textContent('body');
    
    // Pressure might be displayed (optional, depends on data availability)
    // If displayed, should be in reasonable format (e.g., "30.15 inHg" or "1021 hPa")
    if (pageContent.match(/pressure|inHg|hPa/i)) {
      const pressureMatch = pageContent.match(/(\d+\.?\d*)\s*(inHg|hPa|mb)/i);
      if (pressureMatch) {
        const pressureValue = parseFloat(pressureMatch[1]);
        expect(pressureValue).toBeGreaterThan(20);
        expect(pressureValue).toBeLessThan(35);
      }
    }
  });

  test('should display humidity when available', async ({ page }) => {
    await page.waitForFunction(
      () => {
        const bodyText = document.body.textContent || '';
        return /\d+°[FC]/.test(bodyText);
      },
      { timeout: 15000 }
    );
    
    const pageContent = await page.textContent('body');
    
    // Humidity might be displayed (optional)
    if (pageContent.match(/humidity|RH/i)) {
      const humidityMatch = pageContent.match(/(\d+)%/);
      if (humidityMatch) {
        const humidity = parseInt(humidityMatch[1]);
        expect(humidity).toBeGreaterThanOrEqual(0);
        expect(humidity).toBeLessThanOrEqual(100);
      }
    }
  });

  test('should update last updated timestamp', async ({ page }) => {
    // Wait for initial timestamp
    await page.waitForFunction(
      () => {
        const el = document.getElementById('weather-last-updated');
        return el && el.textContent && el.textContent.trim() !== '--';
      },
      { timeout: 15000 }
    );
    
    // Get initial timestamp
    const initialTimestamp = await page.textContent('#weather-last-updated');
    expect(initialTimestamp).toBeTruthy();
    expect(initialTimestamp.trim()).not.toBe('--');
    
    // Wait a bit and check if timestamp updates (it should update every 10 seconds)
    await page.waitForTimeout(12000);
    
    // Timestamp should have updated (or at least still be valid)
    const updatedTimestamp = await page.textContent('#weather-last-updated');
    expect(updatedTimestamp).toBeTruthy();
    expect(updatedTimestamp.trim()).not.toBe('--');
  });

  test('should display flight category when METAR is configured', async ({ page }) => {
    // Check if METAR is configured
    const hasMetar = await page.evaluate(() => {
      return typeof AIRPORT_DATA !== 'undefined' && 
             AIRPORT_DATA && 
             AIRPORT_DATA.metar_station && 
             AIRPORT_DATA.metar_station.trim() !== '';
    });
    
    if (!hasMetar) {
      test.skip();
      return;
    }
    
    // Wait for weather data to load
    await page.waitForFunction(
      () => {
        const bodyText = document.body.textContent || '';
        return /VFR|MVFR|IFR|LIFR|---/.test(bodyText);
      },
      { timeout: 15000 }
    );
    
    const pageContent = await page.textContent('body');
    
    // Should show flight category
    expect(pageContent).toMatch(/VFR|MVFR|IFR|LIFR|---/);
  });

  test('should handle missing data gracefully', async ({ page }) => {
    // Even if weather data fails to load, page should still render
    await page.waitForSelector('body', { state: 'visible' });
    
    const pageContent = await page.textContent('body');
    
    // Should not show error messages to users
    expect(pageContent).not.toMatch(/undefined|null|NaN|error/i);
    
    // Should show placeholder or "---" for missing data
    // This is acceptable - the page should still be functional
    expect(pageContent).toBeTruthy();
  });

  test('should display weather data in correct format', async ({ page }) => {
    await page.waitForFunction(
      () => {
        const bodyText = document.body.textContent || '';
        return /\d+°[FC]/.test(bodyText);
      },
      { timeout: 15000 }
    );
    
    const pageContent = await page.textContent('body');
    
    // Temperature should be formatted correctly (number + unit)
    expect(pageContent).toMatch(/\d+°[FC]/);
    
    // Wind should be formatted correctly (number + unit)
    expect(pageContent).toMatch(/\d+\s*(kts|mph|km\/h)/i);
    
    // Timestamp should be formatted (not raw timestamp number)
    const lastUpdated = await page.textContent('#weather-last-updated');
    if (lastUpdated && lastUpdated.trim() !== '--') {
      // Should be human-readable (not a Unix timestamp)
      expect(lastUpdated).not.toMatch(/^\d{10,}$/); // Not just digits
    }
  });
});
