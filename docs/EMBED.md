# Embed Generator

The Embed Generator allows you to create embeddable weather widgets for any AviationWX airport. These widgets can be added to external websites like WordPress, Google Sites, Squarespace, or any HTML page.

## Quick Start

### Option 1: Web Component (Recommended for Modern Sites)

Use the [Embed Configurator](https://embed.aviationwx.org) to generate embed code; it includes a version query param (`?v=...`) for cache busting so embeds get updates within minutes of deploy.

```html
<!-- Include the widget script once per page -->
<script src="https://aviationwx.org/public/js/widget.js"></script>

<!-- Add widgets anywhere -->
<aviation-wx airport="kspb" style="card" theme="auto"></aviation-wx>
```

### Option 2: iframe Embed (Universal Compatibility)
1. Visit [embed.aviationwx.org](https://embed.aviationwx.org)
2. Search and select an airport
3. Choose a widget style
4. Customize options (theme, cameras, units)
5. Copy the embed code
6. Paste into your website

## Airport website embeds

Airport and municipal sites often embed AviationWX weather and webcams on a dedicated airport page (for example a county aviation department site with a runway webcam and current conditions). The widgets reflow to the width of your content column, so you can drop them into a main content area or a sidebar without custom CSS.

Use the [Embed Configurator](https://embed.aviationwx.org) to pick an airport, style, and theme, then copy the generated code. The snippets below show the patterns we recommend for airport pages.

### Recommended styles

| Placement | Style | Why |
|-----------|-------|-----|
| Main weather section (webcam + full metrics) | `full-single` | Large webcam, dashboard-parity wind diagram, and four metric columns |
| Two runway views + weather | `full-dual` | Side-by-side webcams with the same wind block and metrics |
| Multiple cameras + weather | `full-multi` | Up to four webcams in a grid with full weather detail |
| Sidebar or narrow column | `card` | Wind-forward compact card; compass is the hero, metrics reflow below |

For most airport home pages, start with **`full-single`** when you have one primary webcam, or **`card`** when space is tight.

### Theme: use `auto`

Set **`theme=auto`** so the widget follows the visitor's system light/dark preference. That keeps the embed readable on both light municipal sites and dark OS settings without maintaining two embed codes.

```html
<aviation-wx airport="kspb" style="full-single" theme="auto" width="100%"></aviation-wx>
```

Use `theme=light` or `theme=dark` only when your page has a fixed background and you want the widget to match it exactly.

### Sizing

- **Web component:** Set `width="100%"` so the widget fills its container column; height adjusts to content. Omit `height` unless you need a fixed box. Without `width="100%"`, the widget uses the style's default pixel width (for example 800px for `full-single`).
- **iframe:** Use `width="100%"` and `responsive=1` (default) so height tracks content. Give the iframe a generous initial `height` (for example `800` for `full-single`); the embed posts its measured height to the parent.

```html
<iframe
  src="https://embed.aviationwx.org/?render=1&airport=kspb&style=full-single&theme=auto&responsive=1&target=_blank"
  width="100%"
  height="800"
  frameborder="0"
  loading="lazy"
  title="Airport weather - AviationWX">
</iframe>
```

### Responsive reflow (what to expect)

Embeds use **container queries** on the widget width, not the browser viewport. When the column narrows:

- **`card`:** Below ~480px wide, the layout stacks (compass on top, wind facts and condition tiles below). Below ~360px, condition tiles become dense rows and secondary wind rows hide behind the compass summary line.
- **`full-single` / `full-dual` / `full-multi`:** Below ~700px, metric columns wrap to two per row; below ~500px, the wind block stacks above metrics and dual/multi webcams stack vertically; below ~400px, the wind section stacks compass above wind facts.
- **Footer:** Below ~450px, footer lines stack vertically.

Test at the width of your actual content column (not only full-screen) so you know how the embed will look in a sidebar or mobile layout.

### Copy-paste recipe (web component)

Include the script once per page (use the versioned URL from the [Embed Configurator](https://embed.aviationwx.org) for cache busting), then add one or more widgets with `width="100%"` for column-filling layouts:

```html
<script src="https://aviationwx.org/public/js/widget.js?v=EXAMPLE"></script>

<!-- Primary airport weather block -->
<aviation-wx airport="YOUR_ICAO" style="full-single" theme="auto" width="100%" refresh="300000"></aviation-wx>

<!-- Optional: compact card in a sidebar -->
<aviation-wx airport="YOUR_ICAO" style="card" theme="auto" width="100%"></aviation-wx>
```

Replace `YOUR_ICAO` with your airport id (lowercase, e.g. `kspb`). Generate a tailored snippet with camera indices and units in the [Embed Configurator](https://embed.aviationwx.org).

## Widget Styles

### Weather Card (400×435)
A compact card showing essential weather data including wind, temperature, altimeter, visibility, and density altitude. Includes a mini wind compass visualization. Best for sidebars or small spaces.

**Style Code:** `card`

### Webcam Only Single (450×380)
Displays a single webcam feed with footer. Webcams and footer only (no weather metrics).

**Style Code:** `webcam-only`

### Webcam Only Dual (600×250)
Shows two webcams side by side with footer. Webcams and footer only (no weather metrics).

**Style Code:** `dual-only`

### Webcam Only Quad (600×400)
Displays up to four webcams in a compact grid with footer. Webcams and footer only (no weather metrics).

**Style Code:** `multi-only`

### Full Single (800×740)
Full-featured widget with a large single webcam, detailed wind visualization, and complete weather metrics organized in columns. Best for dedicated weather sections with one primary camera view.

**Style Code:** `full-single`

### Full Dual (800×550)
Full-featured widget showing two webcams with detailed weather data and wind visualization. Perfect for airports wanting to highlight two specific runway approaches.

**Style Code:** `full-dual`

### Full Quad (800×750)
The most complete widget featuring up to four webcams in a grid, comprehensive wind compass visualization, and full weather metrics organized in columns (Wind, Temperature, Conditions, Altitude). Best for airports with multiple camera positions requiring maximum detail.

**Style Code:** `full-multi`

## Customization Options

### Theme
- **Light**: White background, optimized for light websites
- **Dark**: Dark background, optimized for dark websites
- **Auto**: Automatically adapts to the user's system color scheme preference

### Webcam Selection
For multi-camera widgets (Webcam Only Dual, Webcam Only Quad, Full Dual, Full Quad), you can choose which camera appears in each slot. This allows you to prioritize specific runway views or angles.

### Units
Configure measurement units to match your preference:
- **Temperature**: °F or °C
- **Altitude/Distance**: ft or m
- **Wind Speed**: kt, mph, or km/h
- **Barometer**: inHg, hPa, or mmHg

### Link Behavior
Choose whether dashboard links open in a new tab or the same tab.

## Embed Types

### Web Component (Recommended for modern sites)

A JavaScript custom element that integrates directly with your page. Best for sites that support JavaScript.

- **Direct rendering** - No iframe overhead, faster performance
- **Auto-refresh** - Configurable refresh intervals (default: 5 minutes)
- **Theme support** - Light, dark, and auto (follows system preference)
- **Unit conversion** - Temperature, distance, wind speed, barometer units
- **Style isolation** - Shadow DOM prevents style conflicts
- **Responsive sizing** - Fills container width by default; height adjusts automatically

**Usage:**

```html
<!-- Include the widget script once per page -->
<script src="https://aviationwx.org/public/js/widget.js"></script>

<!-- Add widgets anywhere on your page -->
<aviation-wx 
    airport="kspb" 
    style="card" 
    theme="auto"
    temp="F"
    wind="kt"
    refresh="300000">
</aviation-wx>
```

### iframe Embed (Universal compatibility)

Works on platforms that restrict JavaScript (e.g., some CMS blocks). Add `responsive=1` to have the widget height adjust to content.

```html
<iframe
  src="https://embed.aviationwx.org/?render=1&airport=kspb&style=card&theme=light&responsive=1&target=_blank"
  width="100%"
  height="600"
  frameborder="0"
  loading="lazy"
  title="KSPB Weather - AviationWX">
</iframe>
```

Works on most platforms including:
- WordPress (use Custom HTML block)
- Google Sites (use Embed URL)
- Squarespace (use Code block)
- Any HTML page

**Responsive mode:** Use `responsive=1` in the URL. The iframe will post its height to the parent so the container can auto-resize. Omit for fixed dimensions.

### Web Component Attributes

| Attribute | Values | Description |
|-----------|--------|-------------|
| `airport` | Airport ID | **Required** - Airport identifier (e.g., `kspb`) |
| `style` | `card`, `webcam-only`, `dual-only`, `multi-only`, `full-single`, `full-dual`, `full-multi` | Widget style (default: `card`) |
| `theme` | `light`, `dark`, `auto` | Color theme (default: `auto`) |
| `webcam` | `0`, `1`, `2`, ... | Camera index for single-cam styles (default: `0`) |
| `cams` | `0,1,2,3` | Comma-separated camera indices for multi-cam styles |
| `temp` | `F`, `C` | Temperature unit (default: `F`) |
| `dist` | `ft`, `m` | Distance/altitude unit (default: `ft`) |
| `wind` | `kt`, `mph`, `kmh` | Wind speed unit (default: `kt`) |
| `baro` | `inHg`, `hPa`, `mmHg` | Barometer unit (default: `inHg`) |
| `target` | `_blank`, `_self` | Link target (default: `_blank`) |
| `refresh` | Number (ms) | Auto-refresh interval in milliseconds (default: `300000` = 5 min, minimum: `60000` = 1 min) |
| `width` | Number (px) | Widget width in pixels (optional, uses style defaults) |
| `height` | Number (px) | Widget height in pixels (optional, uses style defaults) |

**Default Dimensions by Style:**

| Style | Width | Height |
|-------|-------|--------|
| `card` | 300px | 300px |
| `webcam-only` | 450px | 380px |
| `dual-only` | 600px | 250px |
| `multi-only` | 600px | 400px |
| `full-single` | 800px | 740px |
| `full-dual` | 800px | 550px |
| `full-multi` | 800px | 750px |

**Examples:**

```html
<!-- Metric units with dark theme -->
<aviation-wx 
    airport="kspb" 
    style="card" 
    theme="dark"
    temp="C"
    dist="m"
    wind="kmh"
    baro="hPa">
</aviation-wx>

<!-- Custom dimensions -->
<aviation-wx 
    airport="kspb" 
    style="card" 
    theme="light"
    width="400"
    height="400">
</aviation-wx>

<!-- Fast refresh (2 minutes) -->
<aviation-wx 
    airport="kspb" 
    style="card" 
    theme="auto"
    refresh="120000">
</aviation-wx>
```

**Browser Compatibility:**

- Chrome/Edge 67+
- Firefox 63+
- Safari 10.1+
- Native Custom Elements support (no polyfills needed)

## URL Parameters

When embedding directly, you can customize the widget using URL parameters:

| Parameter | Values | Description |
|-----------|--------|-------------|
| `render` | `1` | **Required** - Triggers widget rendering (vs configurator) |
| `airport` | Airport ID | **Required** - Airport identifier (e.g., `kspb`) |
| `style` | `card`, `webcam-only`, `dual-only`, `multi-only`, `full-single`, `full-dual`, `full-multi` | Widget style |
| `theme` | `light`, `dark`, `auto` | Color theme |
| `responsive` | `1` (default), `0` | Iframe height auto-adjusts to content (postMessage to parent). Default is `1`; use `0` for fixed height. |
| `webcam` | `0`, `1`, `2`, ... | Camera index (for single webcam styles) |
| `cams` | `0,1,2,3` | Comma-separated camera indices (for multi-cam styles) |
| `target` | `_blank`, `_self` | Link target |
| `temp` | `F`, `C` | Temperature unit |
| `dist` | `ft`, `m` | Distance unit |
| `wind` | `kt`, `mph`, `kmh` | Wind speed unit |
| `baro` | `inHg`, `hPa`, `mmHg` | Barometer unit |

Example URL:
```
https://embed.aviationwx.org/?render=1&airport=kspb&style=dual-only&theme=light&cams=0,1&target=_blank&temp=F&wind=kt
```

## Embed API (JSON)

For programmatic access, use the Embed API endpoint:

```
GET /api/v1/airports/{id}/embed
GET /api/v1/airports/{id}/embed?refresh=1
```

**Full payload** (no `refresh` or `refresh=0`): Returns weather, airport metadata, and observed timestamps. Use on first load or when returning to focus.

**Differential payload** (`refresh=1`): Returns only topics that changed since the last refresh cycle (value-diff). Client merges into cached state. Use for polling between full fetches.

**Response structure:**
- `data.embed`: Full or differential payload
- `data.diff`: `true` when differential, `false` when full
- Topics: `weather`, `weather_observed_at`, `airport`, `airport_observed_at`
- Airport topic includes: `id`, `name`, `icao`, `lat`, `lon`, `elevation_ft`, `timezone`, `webcams`, `runways`, `weather_sources` (all data needed for embed widgets)
- Explicit observed times for staleness validation

**CORS:** Responses include permissive `Access-Control-Allow-Origin` for third-party embedding.

**Embed host routing:** `widget.js` calls the Public API on **`embed.aviationwx.org`** (same origin as the script). Nginx maps `/api/v1/...` on that host to `api/v1/router.php` without redirecting the browser to **`api.aviationwx.org`** first, so the initial response carries CORS headers required by `fetch()` from embedded contexts. Configuration lives in **`docker/nginx.conf`** (embed `server` block).

## Local Development

To test the embed generator locally:

```bash
# Start the local development server
make up

# Access the iframe configurator
http://localhost:8080/?embed

# Access an embed widget directly (iframe)
http://localhost:8080/?embed&render=1&airport=kspb&style=card&theme=light

# Test the web component
http://localhost:8080/public/widget-demo.html
```

## Nginx Configuration

Defined in **`docker/nginx.conf`**. The **`embed.aviationwx.org`** `server` block relaxes framing (`frame-ancestors *`, no `X-Frame-Options`) and CSP compared to the main site so widgets load in third-party iframes. Public API v1 paths use internal rewrite + **`location /v1/`** so embed-origin `fetch()` stays on the embed host (see [Embed API JSON](#embed-api-json)). Production deploy bind-mounts this file from the repository (see **`docs/DEPLOYMENT.md`**).

## Content Security Policy (CSP)

If your site uses a Content Security Policy, add these directives so embeds work:

**Web component** (script + fetch):
- **script-src**: include `https://aviationwx.org` and `https://embed.aviationwx.org` (widget script)
- **connect-src**: include `https://aviationwx.org` and `https://*.aviationwx.org` (API, webcam images)
- **style-src**: include `https://aviationwx.org` and `https://embed.aviationwx.org` (widget CSS)

**iframe embed:**
- **frame-src**: include `https://embed.aviationwx.org`

**Example CSP additions** (append to your existing policy):
```
script-src ... https://aviationwx.org https://embed.aviationwx.org;
connect-src ... https://aviationwx.org https://*.aviationwx.org;
style-src ... https://aviationwx.org https://embed.aviationwx.org;
frame-src ... https://embed.aviationwx.org;
```

Sites without CSP (e.g., most WordPress, Squarespace) work without changes. Add these only if the widget fails to load and your console shows CSP violations.

## Caching and Updates

The widget script uses short cache headers (`max-age=300`, `stale-while-revalidate=3600`) so updates propagate within ~5 minutes of deploy. For immediate cache busting, use the versioned URL from the [Embed Configurator](https://embed.aviationwx.org); it appends `?v=<timestamp>` based on file modification time, so new embeds fetch the latest script.

## Troubleshooting

### Widget not loading
- **HTTPS:** Ensure your site uses HTTPS (browsers block mixed content)
- **CSP:** If your site has a Content Security Policy, see [Content Security Policy (CSP)](#content-security-policy-csp) above
- **iframe:** Check that your platform allows iframe embeds
- **Airport ID:** Verify the airport ID is correct

### Webcam images not showing
- Webcam images are fetched from the main dashboard
- Check that the airport has webcams configured
- Verify the webcam index is valid for that airport
- If main image fails (stale/503), embed tries history API and shows dimmed overlay "Live image unavailable. Tap for time-lapse history." when frames exist; otherwise placeholder

### Widget appears too small/large
- **iframe:** Add `responsive=1` to the URL for auto-height; the iframe posts its height to the parent. Or set fixed `width` and `height` attributes.
- **Web component:** Uses responsive sizing by default; set `width` and `height` attributes for fixed dimensions.
- Each widget style has recommended dimensions shown in the configurator.

## Security Considerations

- Embeds are read-only and cannot modify any data
- All data comes from the public AviationWX API
- Webcam images are proxied through AviationWX servers
- No user data or tracking is included in embeds

## Support

For issues or questions:
- Open an issue on [GitHub](https://github.com/alexwitherspoon/aviationwx/issues)
- Email contact@aviationwx.org

