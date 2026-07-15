const { test, expect } = require('@playwright/test');

/**
 * Browser regression tests for density altitude (DA) performance tier display.
 *
 * Dashboard paths mock api/weather.php (no live external APIs in CI).
 * Card embed uses the ?embed&render=1 URL pattern with a routed document
 * response so tier markup is deterministic.
 */

const baseUrl = process.env.TEST_BASE_URL || 'http://localhost:8080';
const testAirport = 'kspb';

const CAUTION_TOOLTIP = 'Density altitude is higher than normal. Verify performance numbers before flight.';
const WARNING_TOOLTIP = 'Density altitude is dangerously high for average GA aircraft. Verify performance numbers before flight.';

const AMBER_RGB = 'rgb(230, 126, 34)';

/**
 * Build a realistic density_altitude_performance payload (weather-format.php shape).
 *
 * @param {'caution'|'warning'} tier
 * @param {object} [overrides]
 * @returns {object}
 */
function buildDensityAltitudePerformance(tier, overrides = {}) {
  const base = tier === 'warning'
    ? {
      tier: 'warning',
      risk_factor: 2.45,
      worst_end_risk: 2.45,
      best_end_risk: 1.85,
      selection_basis: 'both_ends',
      fallback: false,
      reason: 'reference_models',
      reference: 'reference_models_config',
    }
    : {
      tier: 'caution',
      risk_factor: 1.65,
      worst_end_risk: 2.1,
      best_end_risk: 1.65,
      selection_basis: 'asymmetric_heuristic',
      operational_end_id: '32',
      scored_end_risk: 1.65,
      fallback: false,
      reason: 'reference_models',
      reference: 'reference_models_config',
    };

  return { ...base, ...overrides };
}

/**
 * Build internal API weather payload with required fields for displayWeather().
 *
 * @param {object} [overrides]
 * @returns {object}
 */
function buildMockWeather(overrides = {}) {
  const now = Math.floor(Date.now() / 1000);

  return {
    temperature: 20.1,
    temperature_f: 68,
    dewpoint: 10.0,
    dewpoint_spread: 10.1,
    humidity: 65,
    pressure: 30.12,
    wind_speed: 8,
    wind_direction: {
      true_north: 230,
      magnetic_north: 215,
      variable: false,
    },
    gust_speed: 12,
    gust_factor: 4,
    visibility: 10,
    ceiling: 5000,
    flight_category: 'VFR',
    flight_category_class: 'status-vfr',
    density_altitude: 500,
    pressure_altitude: 200,
    precip_accum: 0,
    last_updated: now,
    last_updated_primary: now,
    obs_time_metar: now,
    last_updated_metar: now,
    ...overrides,
  };
}

/**
 * Build api/weather.php success response.
 *
 * @param {object} weatherOverrides
 * @returns {{ success: boolean, weather: object }}
 */
function buildWeatherApiResponse(weatherOverrides = {}) {
  return {
    success: true,
    weather: buildMockWeather(weatherOverrides),
  };
}

/**
 * Intercept weather API with fixture data.
 *
 * @param {import('@playwright/test').Page} page
 * @param {object} weatherOverrides
 */
async function mockWeatherApi(page, weatherOverrides = {}) {
  const body = JSON.stringify(buildWeatherApiResponse(weatherOverrides));

  await page.route('**/api/weather.php*', (route) => {
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body,
    });
  });
}

/**
 * Locate the dashboard density altitude weather row.
 *
 * @param {import('@playwright/test').Page} page
 */
function getDashboardDaRow(page) {
  return page.locator('.weather-item').filter({
    has: page.locator('.label', { hasText: 'Density Altitude' }),
  });
}

/**
 * Assert dashboard DA row tier presentation.
 *
 * @param {import('@playwright/test').Locator} daRow
 * @param {object} options
 */
async function assertDashboardDaTier(daRow, options) {
  const {
    tier,
    densityAltitudeFt,
    emoji,
    tooltipSnippet,
    ariaSnippet,
  } = options;

  await expect(daRow).toBeVisible();

  const valueEl = daRow.locator('.weather-value');
  await expect(valueEl).toHaveText(String(densityAltitudeFt));

  if (tier === 'normal') {
    await expect(daRow).not.toHaveClass(/density-altitude-warning/);
    await expect(daRow).not.toContainText('⚠️');
    await expect(daRow).not.toContainText('🚩');
    const ariaLabel = await daRow.getAttribute('aria-label');
    expect(ariaLabel).toMatch(new RegExp(`Density altitude ${densityAltitudeFt.toLocaleString()} feet`));
    expect(ariaLabel).not.toMatch(/Caution:|Warning:/);
    return;
  }

  await expect(daRow).toHaveClass(/density-altitude-warning/);
  await expect(daRow).toContainText(emoji);

  const title = await daRow.getAttribute('title');
  expect(title).toContain(tooltipSnippet);

  const ariaLabel = await daRow.getAttribute('aria-label');
  expect(ariaLabel).toContain(ariaSnippet);
  expect(ariaLabel).toMatch(new RegExp(`Density altitude ${densityAltitudeFt.toLocaleString()} feet`));

  const color = await valueEl.evaluate((el) => window.getComputedStyle(el).color);
  expect(color).toBe(AMBER_RGB);
}

/**
 * Build minimal card embed HTML for a mocked DA tier tile (matches getCompactWidgetMetrics markup).
 *
 * @param {string} assetBase
 * @param {object} options
 * @returns {string}
 */
function buildMockEmbedCardPageHtml(assetBase, options) {
  const {
    tier,
    densityAltitudeFt,
    emoji,
    tooltip,
    ariaLabel,
    formattedValue,
  } = options;

  const tileClass = tier === 'normal' ? 'tile' : 'tile density-altitude-warning';
  const valueClass = tier === 'normal' ? 'tv' : 'tv density-altitude-warning';
  const titleAttr = tooltip ? ` title="${tooltip.replace(/"/g, '&quot;')}"` : '';
  const ariaAttr = ` aria-label="${ariaLabel.replace(/"/g, '&quot;')}"`;

  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mock embed card</title>
  <link rel="stylesheet" href="${assetBase}/public/css/embed-widgets.css">
</head>
<body class="theme-light">
  <div class="embed-container theme-light">
    <div class="style-card style-card-wf">
      <div class="wf-metrics">
        <div class="col-h">Conditions</div>
        <div class="wf-tiles">
          <div class="${tileClass}"${titleAttr}${ariaAttr}>
            <span class="tl">DA</span>
            <span class="${valueClass}">${formattedValue}${emoji ? ` ${emoji}` : ''}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>`;
}

/**
 * Navigate to card embed URL with a mocked document (fixture weather only).
 *
 * @param {import('@playwright/test').Page} page
 * @param {object} options
 */
async function gotoMockEmbedCard(page, options) {
  const embedUrl = `${baseUrl}/?embed&airport=${testAirport}&style=card&theme=light&render=1`;
  const html = buildMockEmbedCardPageHtml(baseUrl, options);

  await page.route(embedUrl, (route) => {
    route.fulfill({
      status: 200,
      contentType: 'text/html; charset=utf-8',
      body: html,
    });
  });

  await page.goto(embedUrl);
  await page.waitForLoadState('load', { timeout: 30000 });
}

/**
 * Locate DA tile in card embed markup.
 *
 * @param {import('@playwright/test').Page} page
 */
function getEmbedDaTile(page) {
  return page.locator('.wf-tiles .tile').filter({
    has: page.locator('.tl', { hasText: 'DA' }),
  });
}

test.describe('Dashboard density altitude performance tiers', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('about:blank');
  });

  test('shows normal DA without tier styling when performance is omitted', async ({ page }) => {
    await mockWeatherApi(page, {
      density_altitude: 500,
    });

    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    await page.waitForSelector('.weather-item .label:text("Density Altitude")', { timeout: 15000 });

    const daRow = getDashboardDaRow(page);
    await assertDashboardDaTier(daRow, {
      tier: 'normal',
      densityAltitudeFt: 500,
    });
  });

  test('shows caution tier with warning emoji, amber styling, tooltip, and aria-label', async ({ page }) => {
    const densityAltitudeFt = 5500;

    await mockWeatherApi(page, {
      density_altitude: densityAltitudeFt,
      pressure_altitude: 4570,
      temperature: 20.1,
      density_altitude_performance: buildDensityAltitudePerformance('caution'),
    });

    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    await page.waitForSelector('.weather-item .label:text("Density Altitude")', { timeout: 15000 });

    const daRow = getDashboardDaRow(page);
    await assertDashboardDaTier(daRow, {
      tier: 'caution',
      densityAltitudeFt,
      emoji: '⚠️',
      tooltipSnippet: CAUTION_TOOLTIP,
      ariaSnippet: 'Caution: higher than normal',
    });
  });

  test('shows warning tier with flag emoji, amber styling, tooltip, and aria-label', async ({ page }) => {
    const densityAltitudeFt = 6280;

    await mockWeatherApi(page, {
      density_altitude: densityAltitudeFt,
      pressure_altitude: 4570,
      temperature: 20.1,
      density_altitude_performance: buildDensityAltitudePerformance('warning', {
        fallback: false,
      }),
    });

    await page.goto(`${baseUrl}/?airport=${testAirport}`);
    await page.waitForLoadState('load', { timeout: 30000 });
    await page.waitForSelector('.weather-item .label:text("Density Altitude")', { timeout: 15000 });

    const daRow = getDashboardDaRow(page);
    await assertDashboardDaTier(daRow, {
      tier: 'warning',
      densityAltitudeFt,
      emoji: '🚩',
      tooltipSnippet: WARNING_TOOLTIP,
      ariaSnippet: 'Warning: dangerously high',
    });
  });
});

test.describe('Card embed density altitude performance tiers', () => {
  test('shows warning tier on DA tile with amber styling, tooltip, and aria-label', async ({ page }) => {
    const densityAltitudeFt = 6280;
    const formattedValue = '6,280 ft';
    const ariaLabel = `Density altitude ${densityAltitudeFt.toLocaleString()} feet. Warning: dangerously high for average GA aircraft; verify performance numbers before flight. Cue reflects reference takeoff performance for both departure directions on the longest runway.`;

    await gotoMockEmbedCard(page, {
      tier: 'warning',
      densityAltitudeFt,
      emoji: '🚩',
      tooltip: WARNING_TOOLTIP + ' Cue reflects reference takeoff performance for both departure directions on the longest runway.',
      ariaLabel,
      formattedValue,
    });

    const daTile = getEmbedDaTile(page);
    await expect(daTile).toBeVisible();
    await expect(daTile).toHaveClass(/density-altitude-warning/);
    await expect(daTile).toContainText('🚩');
    await expect(daTile).toContainText(formattedValue);

    const title = await daTile.getAttribute('title');
    expect(title).toContain(WARNING_TOOLTIP);

    const aria = await daTile.getAttribute('aria-label');
    expect(aria).toContain('Warning: dangerously high');

    const valueEl = daTile.locator('.tv');
    const color = await valueEl.evaluate((el) => window.getComputedStyle(el).color);
    expect(color).toBe(AMBER_RGB);
  });
});
