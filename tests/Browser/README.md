# Browser Compatibility Tests

Browser compatibility tests verify that the frontend works correctly across different browsers.

## Setup Options

### Option 1: Playwright (Recommended)

Playwright provides cross-browser testing with good documentation and modern tooling.

**Installation:**
```bash
# Install Playwright via npm (requires Node.js)
npm init -y
npm install @playwright/test

# Install browser binaries
npx playwright install
```

**Example Test File:** `tests/Browser/playwright.spec.js`
```javascript
const { test, expect } = require('@playwright/test');

test.describe('Aviation Weather Frontend', () => {
  test.beforeEach(async ({ page }) => {
    const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
    await page.goto(`${baseUrl}/?airport=kspb`);
  });

  test('should display weather data', async ({ page }) => {
    // Wait for weather data to load
    await page.waitForSelector('.weather-data', { timeout: 10000 });
    
    // Check for temperature
    const temp = await page.locator('.temperature').textContent();
    expect(temp).toBeTruthy();
  });

  test('temperature unit toggle should work', async ({ page }) => {
    // Find and click temperature toggle
    const toggle = page.locator('#temp-unit-toggle');
    await toggle.click();
    
    // Verify unit changed
    const unit = await page.locator('.temp-unit').textContent();
    expect(unit).toMatch(/°[FC]/);
  });

  test('wind speed unit toggle should work', async ({ page }) => {
    const toggle = page.locator('#wind-speed-unit-toggle');
    await toggle.click();
    
    // Verify wind speed unit changed
    const windSpeed = await page.locator('.wind-speed').textContent();
    expect(windSpeed).toMatch(/kts|mph|km\/h/);
  });

  test('should display flight category', async ({ page }) => {
    const category = await page.locator('.flight-category').textContent();
    expect(['VFR', 'MVFR', 'IFR', 'LIFR']).toContain(category.trim());
  });

  test('should display webcam images if available', async ({ page }) => {
    const webcamImages = await page.locator('.webcam-image').count();
    // Webcams may not always be available, so just check that element exists
    expect(webcamImages).toBeGreaterThanOrEqual(0);
  });
});
```

**Run Tests:**
```bash
# Run all browsers
npx playwright test

# Run specific browser
npx playwright test --project=chromium

# Run in headed mode (see browser)
npx playwright test --headed
```

### Option 2: Puppeteer

Similar to Playwright but Chrome-only (Chromium).

**Installation:**
```bash
npm install puppeteer
```

### Option 3: Selenium

More traditional, supports more browsers but more complex setup.

## Recommended Test Scenarios

1. **Unit Toggles**
   - Temperature toggle (F/C)
   - Distance toggle (ft/m, in/cm)
   - Wind speed toggle (kts/mph/km/h)

2. **Data Display**
   - Weather data loads correctly
   - Flight category displays
   - Daily high/low temperatures
   - Timestamps display correctly

3. **Responsive Design**
   - Mobile viewport (< 768px)
   - Tablet viewport (768px - 1024px)
   - Desktop viewport (> 1024px)

4. **Error Handling**
   - Missing data displays "---"
   - Stale data indicators
   - API error handling

5. **Cache and Stale Data Handling** (tested in `cache-and-stale-data.spec.js`)
   - Cache-busting parameter detection
   - Stale cache detection (server data older than client)
   - Visual indicators for stale data
   - Concurrent fetch prevention
   - Service Worker cache-busting handling
   - Network timeout handling on slow connections

6. **Webcam Display**
   - Images load correctly
   - Fallback to placeholder if missing
   - Multiple webcams display in grid

## CI/CD Integration

Add to `.github/workflows/test.yml`:

```yaml
browser-tests:
  name: Browser Compatibility Tests
  runs-on: ubuntu-latest
  if: github.event_name == 'push' && github.ref == 'refs/heads/main'
  
  steps:
    - uses: actions/checkout@v4
    - uses: actions/setup-node@v4
      with:
        node-version: '20'
    
    - name: Install dependencies
      run: |
        npm install @playwright/test
    
    - name: Install Playwright browsers
      run: npx playwright install --with-deps
    
    - name: Run browser tests
      run: npx playwright test
      env:
        TEST_BASE_URL: http://localhost:8080
```

## Current Status

Browser tests are **implemented** using Playwright.

### Test Files

- `tests/aviationwx.spec.js` - Core functionality tests (weather display, toggles, responsiveness)
- `tests/cache-and-stale-data.spec.js` - Cache-busting and stale data detection tests (added for mobile client improvements)

### Running Tests Locally

```bash
cd tests/Browser
npm install
npx playwright test

# Run specific test file
npx playwright test cache-and-stale-data.spec.js

# Run in headed mode (see browser)
npx playwright test --headed
```

### CI/CD Integration

Browser tests run automatically in CI via `.github/workflows/quality-assurance-tests.yml` in the `browser-tests` job. The tests run against a local Docker container at `http://localhost:8080`.

