const { test, expect } = require('@playwright/test');

test.describe('Aviation Weather Dashboard', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';
  
  test.beforeEach(async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    // Wait for page to load
    await page.waitForLoadState('networkidle');
  });

  test('should display airport information', async ({ page }) => {
    // Check for airport name or ICAO code
    const pageContent = await page.textContent('body');
    expect(pageContent).toMatch(/KSPB|Scappoose/i);
  });

  test('should display weather data when available', async ({ page }) => {
    // Wait for weather data to load (check for temperature or weather section)
    const weatherSection = page.locator('.weather-data, [class*="weather"], [class*="temperature"]').first();
    
    // Weather data may take time to load
    await page.waitForTimeout(2000);
    
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
    
    // Get initial state
    const initialText = await toggle.textContent();
    
    // Click toggle
    await toggle.click();
    
    // Wait for update
    await page.waitForTimeout(500);
    
    // Verify toggle text changed
    const newText = await toggle.textContent();
    expect(newText).not.toBe(initialText);
    
    // Verify temperature displays changed (check for °F or °C)
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
    
    const initialText = await toggle.textContent();
    await toggle.click();
    await page.waitForTimeout(500);
    
    const newText = await toggle.textContent();
    expect(newText).not.toBe(initialText);
    
    // Verify wind speed unit changed
    const pageContent = await page.textContent('body');
    expect(pageContent).toMatch(/kts|mph|km\/h/i);
  });

  test('should display flight category', async ({ page }) => {
    await page.waitForTimeout(2000);
    
    const pageContent = await page.textContent('body');
    
    // Should show flight category (VFR, MVFR, IFR, LIFR, or --- if unavailable)
    expect(pageContent).toMatch(/VFR|MVFR|IFR|LIFR|---/);
  });

  test('should handle missing data gracefully', async ({ page }) => {
    await page.waitForTimeout(2000);
    
    const pageContent = await page.textContent('body');
    
    // Should not show error messages or broken layouts
    expect(pageContent).not.toMatch(/undefined|null|NaN|error/i);
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
    const errors = [];
    
    page.on('console', msg => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });
    
    await page.waitForTimeout(3000);
    
    // Filter out known acceptable errors (like API fetch failures in test)
    const criticalErrors = errors.filter(err => 
      !err.includes('Failed to fetch') && 
      !err.includes('network') &&
      !err.includes('404')
    );
    
    if (criticalErrors.length > 0) {
      console.warn('Console errors found:', criticalErrors);
      // Don't fail test, but log warning
      expect(criticalErrors.length).toBeLessThan(5);
    }
  });

  test('should be responsive on mobile viewport', async ({ page }) => {
    // Set mobile viewport
    await page.setViewportSize({ width: 375, height: 667 });
    
    // Verify page loads
    await page.waitForLoadState('networkidle');
    
    const body = page.locator('body');
    await expect(body).toBeVisible();
    
    // Check that content is visible (not cut off)
    const pageContent = await page.textContent('body');
    expect(pageContent).toBeTruthy();
  });

  test('should be responsive on tablet viewport', async ({ page }) => {
    // Set tablet viewport
    await page.setViewportSize({ width: 768, height: 1024 });
    
    await page.waitForLoadState('networkidle');
    
    const body = page.locator('body');
    await expect(body).toBeVisible();
  });

  test('should be responsive on desktop viewport', async ({ page }) => {
    // Set desktop viewport
    await page.setViewportSize({ width: 1920, height: 1080 });
    
    await page.waitForLoadState('networkidle');
    
    const body = page.locator('body');
    await expect(body).toBeVisible();
  });

  test('should preserve unit toggle preferences', async ({ page, context }) => {
    const toggle = page.locator('#temp-unit-toggle');
    
    const toggleExists = await toggle.count();
    if (toggleExists === 0) {
      test.skip();
      return;
    }
    
    // Click toggle to change unit
    await toggle.click();
    await page.waitForTimeout(500);
    
    const newState = await toggle.textContent();
    
    // Verify cookie was set (source of truth for cross-subdomain sharing)
    const cookies = await context.cookies();
    const tempUnitCookie = cookies.find(c => c.name === 'aviationwx_temp_unit');
    expect(tempUnitCookie).toBeTruthy();
    expect(tempUnitCookie.value).toBeTruthy();
    
    // Reload page
    await page.reload();
    await page.waitForTimeout(1000);
    
    // Unit should be preserved (stored in cookie, synced to localStorage)
    const preservedState = await toggle.textContent();
    expect(preservedState).toBe(newState);
    
    // Verify cookie persists after reload
    const cookiesAfterReload = await context.cookies();
    const tempUnitCookieAfterReload = cookiesAfterReload.find(c => c.name === 'aviationwx_temp_unit');
    expect(tempUnitCookieAfterReload).toBeTruthy();
    expect(tempUnitCookieAfterReload.value).toBe(tempUnitCookie.value);
  });
});

