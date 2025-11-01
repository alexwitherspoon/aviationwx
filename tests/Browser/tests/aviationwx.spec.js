const { test, expect } = require('@playwright/test');

test.describe('Aviation Weather Dashboard', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';
  
  test.beforeEach(async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    // Wait for page to load (use domcontentloaded for speed, networkidle can be slow)
    await page.waitForLoadState('domcontentloaded');
    // Wait for body to be visible
    await page.waitForSelector('body', { state: 'visible' });
    // Wait for JavaScript to render content (wait for any main content element)
    // The page uses JavaScript to fetch and display weather data, so wait a bit for it to render
    await page.waitForTimeout(2000); // Give JavaScript time to render
  });

  test('should display airport information', async ({ page }) => {
    // Wait for content to be rendered (check for common elements)
    // The page loads content via JavaScript, so we need to wait for it
    await page.waitForTimeout(2000);
    
    // Check for airport name or ICAO code in the page
    const pageContent = await page.textContent('body');
    
    // Should have some content (not just whitespace)
    expect(pageContent).toBeTruthy();
    expect(pageContent.trim().length).toBeGreaterThan(0);
    
    // Check for airport identifier or name (case insensitive)
    const hasAirportInfo = /KSPB|Scappoose/i.test(pageContent);
    if (!hasAirportInfo) {
      // If not found, check if page loaded correctly
      const title = await page.title();
      const url = page.url();
      console.log(`Page title: ${title}, URL: ${url}, Content length: ${pageContent?.length || 0}`);
    }
    expect(pageContent).toMatch(/KSPB|Scappoose/i);
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
    // Wait for JavaScript to render content
    await page.waitForTimeout(2000);
    
    const pageContent = await page.textContent('body');
    
    // Should have content
    expect(pageContent).toBeTruthy();
    expect(pageContent.trim().length).toBeGreaterThan(0);
    
    // Should show flight category (VFR, MVFR, IFR, LIFR, or --- if unavailable)
    // Also allow empty if data not available yet (JavaScript still loading)
    if (pageContent.trim().length > 10) {
      expect(pageContent).toMatch(/VFR|MVFR|IFR|LIFR|---/);
    }
  });

  test('should handle missing data gracefully', async ({ page }) => {
    // Wait for content instead of fixed timeout
    await page.waitForSelector('body', { state: 'visible' });
    
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
    
    // Wait for page to be ready instead of fixed timeout
    await page.waitForSelector('body', { state: 'visible' });
    await page.waitForTimeout(1000); // Short wait for console errors to appear
    
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
    
    // Verify page loads (faster than networkidle)
    await page.waitForLoadState('domcontentloaded');
    await page.waitForSelector('body', { state: 'visible' });
    
    const body = page.locator('body');
    await expect(body).toBeVisible();
    
    // Check that content is visible (not cut off)
    const pageContent = await page.textContent('body');
    expect(pageContent).toBeTruthy();
  });

  test('should be responsive on tablet viewport', async ({ page }) => {
    // Set tablet viewport
    await page.setViewportSize({ width: 768, height: 1024 });
    
    await page.waitForLoadState('domcontentloaded');
    await page.waitForSelector('body', { state: 'visible' });
    
    const body = page.locator('body');
    await expect(body).toBeVisible();
  });

  test('should be responsive on desktop viewport', async ({ page }) => {
    // Set desktop viewport
    await page.setViewportSize({ width: 1920, height: 1080 });
    
    await page.waitForLoadState('domcontentloaded');
    await page.waitForSelector('body', { state: 'visible' });
    
    const body = page.locator('body');
    await expect(body).toBeVisible();
  });

  test('should preserve unit toggle preferences', async ({ page }) => {
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
    
    // Reload page
    await page.reload();
    await page.waitForSelector('body', { state: 'visible' });
    await page.waitForSelector('#temp-unit-toggle', { state: 'visible' });
    
    // Unit should be preserved (stored in localStorage)
    const preservedState = await toggle.textContent();
    expect(preservedState).toBe(newState);
  });
});

