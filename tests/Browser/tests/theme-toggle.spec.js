const { test, expect } = require('@playwright/test');

/**
 * Theme Toggle Feature Tests
 * 
 * Tests the Auto/Day/Dark/Night theme toggle functionality including:
 * - Theme cycling (Auto → Day → Dark → Night → Auto)
 * - Auto mode follows browser preference
 * - Cookie persistence
 * - CSS class application
 * - Button display updates
 */
test.describe('Theme Toggle', () => {
  const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
  const testAirport = 'kspb';

  test.beforeEach(async ({ context }) => {
    // Clear any existing theme cookies before each test
    await context.clearCookies();
  });

  test('Theme toggle button should be visible', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    // Theme toggle button should exist
    const themeToggle = page.locator('#night-mode-toggle');
    await expect(themeToggle).toBeVisible();
  });

  test('Theme should cycle through Auto → Day → Dark → Night → Auto', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#night-mode-toggle');
    const html = page.locator('html');
    const body = page.locator('body');

    // Initial state should be Auto (follows browser preference, no classes if light)
    await expect(themeToggle).toContainText('🔄');

    // Click 1: Auto → Day
    await themeToggle.click();
    await expect(themeToggle).toContainText('☀️');
    await expect(html).not.toHaveClass(/dark-mode/);
    await expect(html).not.toHaveClass(/night-mode/);

    // Click 2: Day → Dark
    await themeToggle.click();
    await expect(themeToggle).toContainText('🌑');
    await expect(html).toHaveClass(/dark-mode/);
    await expect(body).toHaveClass(/dark-mode/);
    await expect(html).not.toHaveClass(/night-mode/);

    // Click 3: Dark → Night
    await themeToggle.click();
    await expect(themeToggle).toContainText('🌙');
    await expect(html).toHaveClass(/night-mode/);
    await expect(body).toHaveClass(/night-mode/);
    await expect(html).not.toHaveClass(/dark-mode/);

    // Click 4: Night → Auto
    await themeToggle.click();
    await expect(themeToggle).toContainText('🔄');
  });

  test('Theme preference should persist via cookie', async ({ page, context }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#night-mode-toggle');

    // Set to Day mode (Auto → Day)
    await themeToggle.click();
    await expect(themeToggle).toContainText('☀️');

    // Verify cookie was set
    const cookies = await context.cookies();
    const themeCookie = cookies.find(c => c.name === 'aviationwx_theme');
    expect(themeCookie).toBeDefined();
    expect(themeCookie.value).toBe('day');

    // Reload page - should still be Day mode
    await page.reload();
    await page.waitForLoadState('load', { timeout: 30000 });

    await expect(themeToggle).toContainText('☀️');
    await expect(page.locator('html')).not.toHaveClass(/dark-mode/);
  });

  test('Night mode should NOT persist via cookie (time-based only)', async ({ page, context }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#night-mode-toggle');

    // First set Dark mode: Auto → Day → Dark
    await themeToggle.click(); // Auto → Day
    await themeToggle.click(); // Day → Dark
    await expect(themeToggle).toContainText('🌑');
    
    // Now set to Night mode
    await themeToggle.click(); // Dark → Night
    await expect(themeToggle).toContainText('🌙');

    // Cookie should still be 'dark' (night is not stored)
    const cookies = await context.cookies();
    const themeCookie = cookies.find(c => c.name === 'aviationwx_theme');
    expect(themeCookie).toBeDefined();
    expect(themeCookie.value).toBe('dark'); // NOT 'night'

    // Reload page - should return to Dark (last saved preference)
    // because night mode is purely time-based
    await page.reload();
    await page.waitForLoadState('load', { timeout: 30000 });

    // During daytime, should show Dark (the saved preference)
    await expect(themeToggle).toContainText('🌑');
    await expect(page.locator('html')).toHaveClass(/dark-mode/);
  });

  test('Day mode preference should persist via cookie', async ({ page, context }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#night-mode-toggle');

    // Set Day explicitly (Auto → Day)
    await themeToggle.click(); // Day
    await expect(themeToggle).toContainText('☀️');

    // Verify cookie was set
    const cookies = await context.cookies();
    const themeCookie = cookies.find(c => c.name === 'aviationwx_theme');
    expect(themeCookie).toBeDefined();
    expect(themeCookie.value).toBe('day');
  });

  test('Theme toggle button should show correct icons', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#night-mode-toggle');

    // Auto mode should show cycle emoji
    await expect(themeToggle).toContainText('🔄');

    // Click to Day mode - should show sun emoji
    await themeToggle.click();
    await expect(themeToggle).toContainText('☀️');

    // Click to Dark mode - should show dark moon emoji
    await themeToggle.click();
    await expect(themeToggle).toContainText('🌑');

    // Click to Night mode - should show night emoji
    await themeToggle.click();
    await expect(themeToggle).toContainText('🌙');

    // Click back to Auto mode
    await themeToggle.click();
    await expect(themeToggle).toContainText('🔄');
  });

  test('Theme classes should apply to both html and body elements', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#night-mode-toggle');
    const html = page.locator('html');
    const body = page.locator('body');

    // Set to Dark mode (Auto → Day → Dark)
    await themeToggle.click(); // Day
    await themeToggle.click(); // Dark

    // Both html and body should have dark-mode class
    await expect(html).toHaveClass(/dark-mode/);
    await expect(body).toHaveClass(/dark-mode/);

    // Set to Night mode
    await themeToggle.click();

    // Both html and body should have night-mode class (not dark-mode)
    await expect(html).toHaveClass(/night-mode/);
    await expect(body).toHaveClass(/night-mode/);
    await expect(html).not.toHaveClass(/dark-mode/);
    await expect(body).not.toHaveClass(/dark-mode/);
  });

  test('Theme toggle should not cause JavaScript errors', async ({ page }) => {
    const jsErrors = [];

    page.on('pageerror', error => {
      jsErrors.push(error.message);
    });

    page.on('console', msg => {
      if (msg.type() === 'error') {
        const text = msg.text();
        // Filter out network errors
        if (!text.includes('Failed to fetch') && 
            !text.includes('network') &&
            !text.includes('net::ERR')) {
          jsErrors.push(text);
        }
      }
    });

    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#night-mode-toggle');

    // Cycle through all themes multiple times (4 modes x 2 = 8 clicks)
    for (let i = 0; i < 8; i++) {
      await themeToggle.click();
      await page.waitForTimeout(100);
    }

    // Should have no JavaScript errors
    expect(jsErrors).toHaveLength(0);
  });

  test('Theme should respect browser dark mode preference on fresh load (Auto mode)', async ({ browser }) => {
    // Create a context with dark color scheme preference
    const context = await browser.newContext({
      colorScheme: 'dark'
    });
    const page = await context.newPage();

    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#night-mode-toggle');
    const html = page.locator('html');

    // Should be in Auto mode (default) showing dark theme because browser prefers dark
    await expect(themeToggle).toContainText('🔄');
    await expect(html).toHaveClass(/dark-mode/);

    await context.close();
  });

  test('Saved preference should override browser preference', async ({ browser }) => {
    // Create a context with dark color scheme preference
    const context = await browser.newContext({
      colorScheme: 'dark'
    });
    const page = await context.newPage();

    // Set a cookie for Day mode preference
    await context.addCookies([{
      name: 'aviationwx_theme',
      value: 'day',
      domain: 'localhost',
      path: '/'
    }]);

    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#night-mode-toggle');
    const html = page.locator('html');

    // Should be Day mode despite browser preferring dark
    await expect(themeToggle).toContainText('☀️');
    await expect(html).not.toHaveClass(/dark-mode/);

    await context.close();
  });

  test('Theme toggle should have accessible title attribute', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#night-mode-toggle');

    // Should have a title attribute for accessibility
    const title = await themeToggle.getAttribute('title');
    expect(title).toBeTruthy();
    expect(title.toLowerCase()).toContain('mode');
  });

  test('Auto mode should follow browser preference changes in real-time', async ({ browser }) => {
    // Create a context with light color scheme preference
    const context = await browser.newContext({
      colorScheme: 'light'
    });
    const page = await context.newPage();

    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#night-mode-toggle');
    const html = page.locator('html');

    // Should start in Auto mode with light theme (browser prefers light)
    await expect(themeToggle).toContainText('🔄');
    await expect(html).not.toHaveClass(/dark-mode/);

    // Emulate browser switching to dark mode
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.waitForTimeout(500); // Allow time for change listener

    // Should still show Auto icon but now with dark theme
    await expect(themeToggle).toContainText('🔄');
    await expect(html).toHaveClass(/dark-mode/);

    // Switch back to light
    await page.emulateMedia({ colorScheme: 'light' });
    await page.waitForTimeout(500);

    // Should still be Auto mode with light theme
    await expect(themeToggle).toContainText('🔄');
    await expect(html).not.toHaveClass(/dark-mode/);

    await context.close();
  });

  test('Manual toggle to explicit mode should not follow browser preference changes', async ({ browser }) => {
    // Create a context with light color scheme preference
    const context = await browser.newContext({
      colorScheme: 'light'
    });
    const page = await context.newPage();

    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#night-mode-toggle');
    const html = page.locator('html');

    // Manually toggle to Dark mode (Auto → Day → Dark)
    await themeToggle.click(); // Day
    await themeToggle.click(); // Dark
    await expect(themeToggle).toContainText('🌑');

    // Emulate browser switching preference
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.waitForTimeout(500);

    // Should remain in Dark mode (explicit preference, not auto)
    await expect(themeToggle).toContainText('🌑');
    await expect(html).toHaveClass(/dark-mode/);

    await context.close();
  });

  test('Auto mode cookie should be saved and restored', async ({ page, context }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#night-mode-toggle');

    // Default should be Auto mode
    await expect(themeToggle).toContainText('🔄');

    // Cycle through all modes back to Auto (Auto → Day → Dark → Night → Auto)
    await themeToggle.click(); // Day
    await themeToggle.click(); // Dark
    await themeToggle.click(); // Night
    await themeToggle.click(); // Auto
    await expect(themeToggle).toContainText('🔄');

    // Verify cookie was set to 'auto'
    const cookies = await context.cookies();
    const themeCookie = cookies.find(c => c.name === 'aviationwx_theme');
    expect(themeCookie).toBeDefined();
    expect(themeCookie.value).toBe('auto');

    // Reload page - should still be Auto mode
    await page.reload();
    await page.waitForLoadState('load', { timeout: 30000 });

    await expect(themeToggle).toContainText('🔄');
  });
});

