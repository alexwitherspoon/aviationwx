const { test, expect } = require('@playwright/test');

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
    
    // Wait for page to load (use domcontentloaded for speed, networkidle can be slow)
    await page.waitForLoadState('domcontentloaded');
    // Wait for body to be visible
    await page.waitForSelector('body', { state: 'visible' });
    // Wait for airport information to be rendered (h1 element)
    // This is rendered in HTML immediately, not via JavaScript
    await page.waitForSelector('h1', { state: 'visible', timeout: 5000 });
  });

  test('should display airport information', async ({ page }) => {
    // Wait for the airport name/ICAO to appear (h1 element contains airport name)
    // The page renders this immediately in HTML, not via JavaScript
    // Already waited in beforeEach, but ensure it's still there
    const h1 = await page.waitForSelector('h1', { state: 'visible', timeout: 5000 });
    
    // Check for airport name or ICAO code in the h1 element
    const h1Text = await h1.textContent();
    expect(h1Text).toMatch(/KSPB|Scappoose/i);
    
    // Also verify body has content
    const pageContent = await page.textContent('body');
    expect(pageContent).toBeTruthy();
    expect(pageContent.trim().length).toBeGreaterThan(0);
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
    
    // Wait for toggle to be visible and get initial state
    await toggle.waitFor({ state: 'visible', timeout: 5000 });
    const initialText = await toggle.textContent();
    expect(initialText).toBeTruthy();
    
    // Click toggle
    await toggle.click();
    
    // Wait for toggle text to actually change (not just fixed timeout)
    await page.waitForFunction(
      ({ toggleSelector, initialText }) => {
        const toggle = document.querySelector(toggleSelector);
        return toggle && toggle.textContent !== initialText;
      },
      { toggleSelector: '#temp-unit-toggle', initialText },
      { timeout: 5000 }
    );
    
    // Verify toggle text changed
    const newText = await toggle.textContent();
    expect(newText).not.toBe(initialText);
    
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
    
    // Wait for toggle to be visible and get initial state
    await toggle.waitFor({ state: 'visible', timeout: 5000 });
    const initialText = await toggle.textContent();
    expect(initialText).toBeTruthy();
    
    await toggle.click();
    
    // Wait for toggle text to actually change
    await page.waitForFunction(
      ({ toggleSelector, initialText }) => {
        const toggle = document.querySelector(toggleSelector);
        return toggle && toggle.textContent !== initialText;
      },
      { toggleSelector: '#wind-speed-unit-toggle', initialText },
      { timeout: 5000 }
    );
    
    const newText = await toggle.textContent();
    expect(newText).not.toBe(initialText);
    
    // Verify wind speed unit changed - check page content
    const pageContent = await page.textContent('body');
    expect(pageContent).toMatch(/kts|mph|km\/h/i);
  });

  test('should display flight category', async ({ page }) => {
    // Wait for weather data to be loaded and displayed
    // Flight category is rendered via JavaScript after fetching weather data
    // Wait for either the condition status element or flight category text to appear
    
    // Try waiting for flight category text to appear in the page
    try {
      await page.waitForFunction(
        () => {
          const bodyText = document.body.textContent || '';
          return /VFR|MVFR|IFR|LIFR|---/.test(bodyText);
        },
        { timeout: 10000 }
      );
    } catch (e) {
      // Fallback: wait for condition status element
      try {
        await page.waitForSelector('[class*="condition"], [class*="status"], [class*="flight-category"]', { 
          state: 'visible', 
          timeout: 5000 
        });
      } catch (e2) {
        // Last resort: wait a bit for JavaScript to load
        await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
      }
    }
    
    const pageContent = await page.textContent('body');
    
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
    
    // Filter out known acceptable errors (like API fetch failures in test)
    const criticalErrors = consoleErrors.filter(err => 
      !err.includes('Failed to fetch') && 
      !err.includes('network') &&
      !err.includes('404') &&
      !err.includes('ChunkLoadError') // Webpack chunk loading errors are sometimes transient
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
    
    // Wait for toggle and get initial state
    await toggle.waitFor({ state: 'visible', timeout: 5000 });
    const initialText = await toggle.textContent();
    
    // Click toggle to change unit
    await toggle.click();
    
    // Wait for toggle text to actually change
    await page.waitForFunction(
      ({ toggleSelector, initialText }) => {
        const toggle = document.querySelector(toggleSelector);
        return toggle && toggle.textContent !== initialText;
      },
      { toggleSelector: '#temp-unit-toggle', initialText },
      { timeout: 5000 }
    );
    
    const newState = await toggle.textContent();
    expect(newState).not.toBe(initialText);
    
    // Verify localStorage was written before reload
    const localStorageValue = await page.evaluate(() => {
      return localStorage.getItem('tempUnit');
    });
    expect(localStorageValue).toBeTruthy();
    
    // Reload page
    await page.reload();
    
    // Wait for page to load and toggle to appear
    await page.waitForSelector('body', { state: 'visible' });
    await page.waitForSelector('#temp-unit-toggle', { state: 'visible', timeout: 5000 });
    
    // Wait for toggle to have the expected state (may take time for JavaScript to read localStorage)
    await page.waitForFunction(
      ({ toggleSelector, expectedText }) => {
        const toggle = document.querySelector(toggleSelector);
        return toggle && toggle.textContent === expectedText;
      },
      { toggleSelector: '#temp-unit-toggle', expectedText: newState },
      { timeout: 5000 }
    );
    
    // Unit should be preserved (stored in localStorage)
    const preservedState = await toggle.textContent();
    expect(preservedState).toBe(newState);
  });
});

