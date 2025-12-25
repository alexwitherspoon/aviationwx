const { test, expect } = require('@playwright/test');

/**
 * Theme Toggle Feature Tests
 * 
 * Tests the Day/Dark/Night theme toggle functionality including:
 * - Theme cycling (Day → Dark → Night → Day)
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
    const themeToggle = page.locator('#theme-toggle');
    await expect(themeToggle).toBeVisible();
  });

  test('Theme should cycle through Day → Dark → Night → Day', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#theme-toggle');
    const html = page.locator('html');
    const body = page.locator('body');

    // Initial state should be Day (no dark-mode or night-mode class)
    await expect(themeToggle).toContainText('Day');
    await expect(html).not.toHaveClass(/dark-mode/);
    await expect(html).not.toHaveClass(/night-mode/);

    // Click 1: Day → Dark
    await themeToggle.click();
    await expect(themeToggle).toContainText('Dark');
    await expect(html).toHaveClass(/dark-mode/);
    await expect(body).toHaveClass(/dark-mode/);
    await expect(html).not.toHaveClass(/night-mode/);

    // Click 2: Dark → Night
    await themeToggle.click();
    await expect(themeToggle).toContainText('Night');
    await expect(html).toHaveClass(/night-mode/);
    await expect(body).toHaveClass(/night-mode/);
    await expect(html).not.toHaveClass(/dark-mode/);

    // Click 3: Night → Day
    await themeToggle.click();
    await expect(themeToggle).toContainText('Day');
    await expect(html).not.toHaveClass(/dark-mode/);
    await expect(html).not.toHaveClass(/night-mode/);
  });

  test('Theme preference should persist via cookie', async ({ page, context }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#theme-toggle');

    // Set to Dark mode
    await themeToggle.click();
    await expect(themeToggle).toContainText('Dark');

    // Verify cookie was set
    const cookies = await context.cookies();
    const themeCookie = cookies.find(c => c.name === 'aviationwx_theme');
    expect(themeCookie).toBeDefined();
    expect(themeCookie.value).toBe('dark');

    // Reload page - should still be Dark mode
    await page.reload();
    await page.waitForLoadState('load', { timeout: 30000 });

    await expect(themeToggle).toContainText('Dark');
    await expect(page.locator('html')).toHaveClass(/dark-mode/);
  });

  test('Night mode preference should persist via cookie', async ({ page, context }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#theme-toggle');

    // Set to Night mode (click twice: Day → Dark → Night)
    await themeToggle.click();
    await themeToggle.click();
    await expect(themeToggle).toContainText('Night');

    // Verify cookie was set
    const cookies = await context.cookies();
    const themeCookie = cookies.find(c => c.name === 'aviationwx_theme');
    expect(themeCookie).toBeDefined();
    expect(themeCookie.value).toBe('night');

    // Reload page - should still be Night mode
    await page.reload();
    await page.waitForLoadState('load', { timeout: 30000 });

    await expect(themeToggle).toContainText('Night');
    await expect(page.locator('html')).toHaveClass(/night-mode/);
  });

  test('Day mode preference should persist via cookie', async ({ page, context }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#theme-toggle');

    // Cycle through to set Day explicitly (Day → Dark → Night → Day)
    await themeToggle.click(); // Dark
    await themeToggle.click(); // Night
    await themeToggle.click(); // Day
    await expect(themeToggle).toContainText('Day');

    // Verify cookie was set
    const cookies = await context.cookies();
    const themeCookie = cookies.find(c => c.name === 'aviationwx_theme');
    expect(themeCookie).toBeDefined();
    expect(themeCookie.value).toBe('day');
  });

  test('Theme toggle button should show correct icons', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#theme-toggle');

    // Day mode should show sun emoji
    await expect(themeToggle).toContainText('☀️');

    // Click to Dark mode - should show moon emoji
    await themeToggle.click();
    await expect(themeToggle).toContainText('🌑');

    // Click to Night mode - should show night emoji
    await themeToggle.click();
    await expect(themeToggle).toContainText('🌙');
  });

  test('Theme classes should apply to both html and body elements', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#theme-toggle');
    const html = page.locator('html');
    const body = page.locator('body');

    // Set to Dark mode
    await themeToggle.click();

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

    const themeToggle = page.locator('#theme-toggle');

    // Cycle through all themes multiple times
    for (let i = 0; i < 6; i++) {
      await themeToggle.click();
      await page.waitForTimeout(100);
    }

    // Should have no JavaScript errors
    expect(jsErrors).toHaveLength(0);
  });

  test('Theme should respect browser dark mode preference on fresh load', async ({ browser }) => {
    // Create a context with dark color scheme preference
    const context = await browser.newContext({
      colorScheme: 'dark'
    });
    const page = await context.newPage();

    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#theme-toggle');
    const html = page.locator('html');

    // Should default to Dark mode when browser prefers dark
    await expect(themeToggle).toContainText('Dark');
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

    const themeToggle = page.locator('#theme-toggle');
    const html = page.locator('html');

    // Should be Day mode despite browser preferring dark
    await expect(themeToggle).toContainText('Day');
    await expect(html).not.toHaveClass(/dark-mode/);

    await context.close();
  });

  test('Theme toggle should have accessible title attribute', async ({ page }) => {
    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });

    const themeToggle = page.locator('#theme-toggle');

    // Should have a title attribute for accessibility
    const title = await themeToggle.getAttribute('title');
    expect(title).toBeTruthy();
    expect(title.toLowerCase()).toContain('theme');
  });
});

