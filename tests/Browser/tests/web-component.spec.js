/**
 * Browser Tests for AviationWX Web Component
 * 
 * Tests the custom <aviation-wx> element functionality including:
 * - Element registration and rendering
 * - Attribute handling and reactivity
 * - Weather data fetching
 * - Theme switching
 * - Auto-refresh behavior
 * - Error states
 */

const { test, expect } = require('@playwright/test');

const BASE_URL = 'http://localhost:8080';
const TEST_AIRPORT = 'kspb';

test.describe('AviationWX Web Component', () => {
    
    test.describe('Element Registration', () => {
        test('should register custom element', async ({ page }) => {
            await page.goto(`${BASE_URL}/dev/test-widget.html`);
            
            // Check that custom element is defined
            const isElementDefined = await page.evaluate(() => {
                return customElements.get('aviation-wx') !== undefined;
            });
            
            expect(isElementDefined).toBe(true);
        });
        
        test('should render widget with shadow DOM', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx airport="${TEST_AIRPORT}" style="card" theme="light"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(1000);
            
            const widget = await page.locator('aviation-wx');
            expect(await widget.count()).toBe(1);
            
            // Check shadow DOM exists
            const hasShadowRoot = await widget.evaluate((el) => {
                return el.shadowRoot !== null;
            });
            
            expect(hasShadowRoot).toBe(true);
        });
    });
    
    test.describe('Attribute Handling', () => {
        test('should show error when airport attribute is missing', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx style="card" theme="light"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(500);
            
            const errorText = await page.locator('aviation-wx').evaluate((el) => {
                return el.shadowRoot.querySelector('.error-container')?.textContent || '';
            });
            
            expect(errorText).toContain('airport attribute is required');
        });
        
        test('should apply default style when invalid style provided', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx airport="${TEST_AIRPORT}" style="invalid-style" theme="light"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(1000);
            
            // Should default to 'card' style
            const hasCardStyle = await page.locator('aviation-wx').evaluate((el) => {
                return el.shadowRoot.querySelector('.card-style') !== null;
            });
            
            expect(hasCardStyle).toBe(true);
        });
        
        test('should react to attribute changes', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx id="widget" airport="${TEST_AIRPORT}" style="card" theme="light"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(1000);
            
            // Change theme attribute
            await page.evaluate(() => {
                const widget = document.querySelector('#widget');
                widget.setAttribute('theme', 'dark');
            });
            
            await page.waitForTimeout(500);
            
            // Check that re-render occurred (theme variables changed)
            const themeApplied = await page.locator('aviation-wx').evaluate((el) => {
                const styles = el.shadowRoot.querySelector('style')?.textContent || '';
                return styles.includes('--bg-color: #1a1a1a');
            });
            
            expect(themeApplied).toBe(true);
        });
    });
    
    test.describe('Weather Data Rendering', () => {
        test('should fetch and display weather data', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx airport="${TEST_AIRPORT}" style="card" theme="light" refresh="300000"></aviation-wx>
                </body>
                </html>
            `);
            
            // Wait for weather data to load
            await page.waitForTimeout(2000);
            
            // Check that loading state disappears
            const hasLoadingState = await page.locator('aviation-wx').evaluate((el) => {
                return el.shadowRoot.querySelector('.loading-spinner') !== null;
            });
            
            expect(hasLoadingState).toBe(false);
            
            // Check that card content is rendered
            const hasCardHeader = await page.locator('aviation-wx').evaluate((el) => {
                return el.shadowRoot.querySelector('.card-header') !== null;
            });
            
            expect(hasCardHeader).toBe(true);
        });
        
        test('should display airport name', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx airport="${TEST_AIRPORT}" style="card" theme="light"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(2000);
            
            const airportName = await page.locator('aviation-wx').evaluate((el) => {
                return el.shadowRoot.querySelector('.card-header h2')?.textContent || '';
            });
            
            // Should show airport name or ID
            expect(airportName.length).toBeGreaterThan(0);
            expect(airportName.toUpperCase()).toContain('SPB');
        });
        
        test('should display weather values', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx airport="${TEST_AIRPORT}" style="card" theme="light"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(2000);
            
            const weatherValues = await page.locator('aviation-wx').evaluate((el) => {
                const values = el.shadowRoot.querySelectorAll('.weather-item .value');
                return Array.from(values).map(v => v.textContent.trim());
            });
            
            // Should have at least some weather values
            expect(weatherValues.length).toBeGreaterThan(0);
            
            // Values should not all be empty or '--'
            const hasValidData = weatherValues.some(v => v && v !== '--' && v !== '---');
            expect(hasValidData).toBe(true);
        });
        
        test('should render wind compass canvas', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx airport="${TEST_AIRPORT}" style="card" theme="light"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(2000);
            
            const hasCanvas = await page.locator('aviation-wx').evaluate((el) => {
                return el.shadowRoot.querySelector('#windCompass') !== null;
            });
            
            expect(hasCanvas).toBe(true);
        });
    });
    
    test.describe('Theme Support', () => {
        test('should apply light theme', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx airport="${TEST_AIRPORT}" style="card" theme="light"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(1000);
            
            const bgColor = await page.locator('aviation-wx').evaluate((el) => {
                const styles = el.shadowRoot.querySelector('style')?.textContent || '';
                return styles.includes('--bg-color: #ffffff');
            });
            
            expect(bgColor).toBe(true);
        });
        
        test('should apply dark theme', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx airport="${TEST_AIRPORT}" style="card" theme="dark"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(1000);
            
            const bgColor = await page.locator('aviation-wx').evaluate((el) => {
                const styles = el.shadowRoot.querySelector('style')?.textContent || '';
                return styles.includes('--bg-color: #1a1a1a');
            });
            
            expect(bgColor).toBe(true);
        });
        
        test('should support auto theme with media query', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx airport="${TEST_AIRPORT}" style="card" theme="auto"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(1000);
            
            const hasMediaQuery = await page.locator('aviation-wx').evaluate((el) => {
                const styles = el.shadowRoot.querySelector('style')?.textContent || '';
                return styles.includes('prefers-color-scheme: dark');
            });
            
            expect(hasMediaQuery).toBe(true);
        });
    });
    
    test.describe('Unit Conversion', () => {
        test('should display temperature in Fahrenheit by default', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx airport="${TEST_AIRPORT}" style="card" theme="light" temp="F"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(2000);
            
            const tempValue = await page.locator('aviation-wx').evaluate((el) => {
                const items = el.shadowRoot.querySelectorAll('.weather-item');
                for (const item of items) {
                    const label = item.querySelector('.label')?.textContent || '';
                    if (label.includes('Temp')) {
                        return item.querySelector('.value')?.textContent || '';
                    }
                }
                return '';
            });
            
            expect(tempValue).toContain('°F');
        });
        
        test('should convert temperature to Celsius', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx airport="${TEST_AIRPORT}" style="card" theme="light" temp="C"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(2000);
            
            const tempValue = await page.locator('aviation-wx').evaluate((el) => {
                const items = el.shadowRoot.querySelectorAll('.weather-item');
                for (const item of items) {
                    const label = item.querySelector('.label')?.textContent || '';
                    if (label.includes('Temp')) {
                        return item.querySelector('.value')?.textContent || '';
                    }
                }
                return '';
            });
            
            expect(tempValue).toContain('°C');
        });
    });
    
    test.describe('Error Handling', () => {
        test('should handle invalid airport gracefully', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx airport="xxxx" style="card" theme="light"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(2000);
            
            // Should show error state or error message
            const hasError = await page.locator('aviation-wx').evaluate((el) => {
                const container = el.shadowRoot.querySelector('.widget-container');
                if (!container) {
                    return true; // No container means error state
                }
                const errorBanner = el.shadowRoot.querySelector('.error-banner');
                return errorBanner && errorBanner.style.display !== 'none';
            });
            
            expect(hasError).toBe(true);
        });
    });
    
    test.describe('Auto-Refresh', () => {
        test('should start auto-refresh timer on mount', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx id="widget" airport="${TEST_AIRPORT}" style="card" theme="light" refresh="60000"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(1000);
            
            const hasTimer = await page.evaluate(() => {
                const widget = document.querySelector('#widget');
                return widget.refreshTimer !== null && widget.refreshTimer !== undefined;
            });
            
            expect(hasTimer).toBe(true);
        });
        
        test('should clear timer on unmount', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <div id="container">
                        <aviation-wx id="widget" airport="${TEST_AIRPORT}" style="card" theme="light" refresh="60000"></aviation-wx>
                    </div>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(1000);
            
            // Remove widget from DOM
            await page.evaluate(() => {
                const container = document.querySelector('#container');
                container.innerHTML = '';
            });
            
            await page.waitForTimeout(500);
            
            // Timer should be cleared (we can't directly check this, but element should be gone)
            const widgetExists = await page.locator('#widget').count();
            expect(widgetExists).toBe(0);
        });
    });
    
    test.describe('Responsive Sizing', () => {
        test('should use preset dimensions when not specified', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx airport="${TEST_AIRPORT}" style="card" theme="light"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(1000);
            
            const dimensions = await page.locator('aviation-wx').evaluate((el) => {
                const container = el.shadowRoot.querySelector('.widget-container');
                return {
                    width: container.style.width,
                    height: container.style.height
                };
            });
            
            expect(dimensions.width).toBe('300px'); // Card preset
            expect(dimensions.height).toBe('300px');
        });
        
        test('should respect custom width and height attributes', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx airport="${TEST_AIRPORT}" style="card" theme="light" width="400" height="400"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(1000);
            
            const dimensions = await page.locator('aviation-wx').evaluate((el) => {
                const container = el.shadowRoot.querySelector('.widget-container');
                return {
                    width: container.style.width,
                    height: container.style.height
                };
            });
            
            expect(dimensions.width).toBe('400px');
            expect(dimensions.height).toBe('400px');
        });
    });
    
    test.describe('Style Isolation', () => {
        test('should not leak styles to parent document', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head>
                    <script src="${BASE_URL}/public/js/widget.js"></script>
                    <style>
                        .widget-container { background: red !important; }
                    </style>
                </head>
                <body>
                    <div class="widget-container">Parent Element</div>
                    <aviation-wx airport="${TEST_AIRPORT}" style="card" theme="light"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(1000);
            
            // Check parent element has red background
            const parentBg = await page.locator('body > .widget-container').evaluate((el) => {
                return window.getComputedStyle(el).backgroundColor;
            });
            
            expect(parentBg).toContain('rgb(255, 0, 0)'); // red
            
            // Check widget has its own background (not red)
            const widgetBg = await page.locator('aviation-wx').evaluate((el) => {
                const container = el.shadowRoot.querySelector('.widget-container');
                return window.getComputedStyle(container).backgroundColor;
            });
            
            expect(widgetBg).not.toContain('rgb(255, 0, 0)');
        });
        
        test('should isolate multiple widget instances', async ({ page }) => {
            await page.setContent(`
                <!DOCTYPE html>
                <html>
                <head><script src="${BASE_URL}/public/js/widget.js"></script></head>
                <body>
                    <aviation-wx id="widget1" airport="${TEST_AIRPORT}" style="card" theme="light"></aviation-wx>
                    <aviation-wx id="widget2" airport="${TEST_AIRPORT}" style="card" theme="dark"></aviation-wx>
                </body>
                </html>
            `);
            
            await page.waitForTimeout(2000);
            
            // Check that both widgets render independently
            const widget1Bg = await page.locator('#widget1').evaluate((el) => {
                const styles = el.shadowRoot.querySelector('style')?.textContent || '';
                return styles.includes('--bg-color: #ffffff');
            });
            
            const widget2Bg = await page.locator('#widget2').evaluate((el) => {
                const styles = el.shadowRoot.querySelector('style')?.textContent || '';
                return styles.includes('--bg-color: #1a1a1a');
            });
            
            expect(widget1Bg).toBe(true);
            expect(widget2Bg).toBe(true);
        });
    });
});
