// @ts-check
const { test, expect } = require('@playwright/test');

const AIRPORT = 'id76';

async function waitForRunwaySection(page) {
    const section = page.locator('#runway-display-section');
    await expect(section).toBeVisible({ timeout: 20000 });
    await section.scrollIntoViewIfNeeded();
    await expect(page.locator('.runway-hybrid-card')).toHaveCount(1, { timeout: 5000 });
}

test.describe('Runway display (safety-critical layout)', () => {
    test('desktop shows one layout block and two wind rows per runway', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(`/?airport=${AIRPORT}`);
        await waitForRunwaySection(page);

        const card = page.locator('.runway-hybrid-card').first();
        await expect(card.locator('.runway-hybrid-headline .value')).toHaveText('09/27');
        await expect(card.locator('.runway-hybrid-desktop-only')).toBeVisible();
        await expect(card.locator('.runway-hybrid-mobile-only')).toBeHidden();
        await expect(card.locator('.runway-hybrid-desktop-only .runway-hybrid-wind-row, .runway-hybrid-desktop-only .runway-dense-end')).toHaveCount(2);
        const desktopText = await card.locator('.runway-hybrid-desktop-only').innerText();
        expect(desktopText).not.toMatch(/GravelLights/);
        await expect(card.locator('.runway-hybrid-mobile-only')).not.toBeInViewport();
    });

    test('mobile shows compact layout only', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`/?airport=${AIRPORT}`);
        await waitForRunwaySection(page);

        const card = page.locator('.runway-hybrid-card').first();
        await expect(card.locator('.runway-hybrid-desktop-only')).toBeHidden();
        await expect(card.locator('.runway-hybrid-mobile-only')).toBeVisible();
        await expect(card.locator('.runway-hybrid-mobile-only .runway-hybrid-wind-row, .runway-hybrid-mobile-only .runway-dense-end')).toHaveCount(2);
        await expect(card.locator('.runway-hybrid-specs')).toBeVisible();
    });

    test('page loads unminified dashboard CSS in development', async ({ page }) => {
        const response = await page.goto(`/?airport=${AIRPORT}`);
        expect(response && response.ok()).toBeTruthy();
        const cssLink = page.locator('link[rel="stylesheet"][href*="styles"]');
        await expect(cssLink).toHaveAttribute('href', /styles\.css/);
    });

    test('dark mode runway text matches dashboard contrast', async ({ page, context }) => {
        await context.addCookies([{
            name: 'aviationwx_theme',
            value: 'dark',
            domain: 'localhost',
            path: '/',
        }]);
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(`/?airport=${AIRPORT}`);
        await waitForRunwaySection(page);

        const headlineRgb = await page.locator('.runway-hybrid-headline .value').evaluate((el) => {
            return window.getComputedStyle(el).color;
        });
        expect(headlineRgb).toBe('rgb(255, 255, 255)');

        const labelRgb = await page.locator('.runway-hybrid-meta .label').first().evaluate((el) => {
            return window.getComputedStyle(el).color;
        });
        expect(labelRgb).toBe('rgb(160, 160, 160)');
    });

    test('night mode runway text matches dashboard contrast', async ({ page, context }) => {
        await context.addCookies([{
            name: 'aviationwx_theme',
            value: 'night',
            domain: 'localhost',
            path: '/',
        }]);
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(`/?airport=${AIRPORT}`);
        await waitForRunwaySection(page);

        const headlineRgb = await page.locator('.runway-hybrid-headline .value').evaluate((el) => {
            return window.getComputedStyle(el).color;
        });
        expect(headlineRgb).toBe('rgb(255, 85, 85)');

        await page.setViewportSize({ width: 390, height: 844 });
        await page.locator('#runway-display-section').scrollIntoViewIfNeeded();
        const specsRgb = await page.locator('.runway-hybrid-specs .spec-val').first().evaluate((el) => {
            return window.getComputedStyle(el).color;
        });
        expect(specsRgb).toBe('rgb(255, 85, 85)');
    });
});

test.describe('Runway display API contract', () => {
    test('weather API includes runway_display for ID76', async ({ request }) => {
        const response = await request.get(`/api/weather.php?airport=${AIRPORT}`);
        expect(response.ok()).toBeTruthy();
        const body = await response.json();
        expect(body.success).toBe(true);
        expect(body.weather.runway_display).toBeDefined();
        expect(body.weather.runway_display.runway_source).toBe('nasr');
        expect(body.weather.runway_display.runways).toHaveLength(1);
        expect(body.weather.runway_display.runways[0].rwy_id).toBe('09/27');
        expect(body.weather.runway_display.runways[0].ends).toHaveLength(2);
    });
});
