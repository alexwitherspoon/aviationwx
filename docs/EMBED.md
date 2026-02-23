# Embed Generator

The Embed Generator allows you to create embeddable weather widgets for any AviationWX airport. These widgets can be added to external websites like WordPress, Google Sites, Squarespace, or any HTML page.

## Quick Start

### Option 1: Web Component (Recommended for Modern Sites)
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

## Widget Styles

### Weather Card (400×435)
A compact card showing essential weather data including wind, temperature, altimeter, visibility, and density altitude. Includes a mini wind compass visualization. Best for sidebars or small spaces.

**Style Code:** `card`

### Webcam Only Single (450×380)
Displays a single webcam feed with footer. Webcams and footer only—no weather metrics.

**Style Code:** `webcam-only`

### Webcam Only Dual (600×250)
Shows two webcams side by side with footer. Webcams and footer only—no weather metrics.

**Style Code:** `dual-only`

### Webcam Only Quad (600×400)
Displays up to four webcams in a compact grid with footer. Webcams and footer only—no weather metrics.

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
| `responsive` | `1` | Iframe height auto-adjusts to content (postMessage to parent) |
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

**CORS:** Permissive (`*`) for third-party embedding.

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

The embed subdomain requires modified security headers to allow iframe embedding on third-party sites. Key differences from the main site:

- No `X-Frame-Options` header (allows embedding anywhere)
- Modified CSP `frame-ancestors *` (allows embedding from any origin)
- Allows connections to `*.aviationwx.org` for webcam images

## Troubleshooting

### Widget not loading in iframe
- Ensure your site uses HTTPS (some browsers block mixed content)
- Check that your platform allows iframe embeds
- Verify the airport ID is correct

### Webcam images not showing
- Webcam images are fetched from the main dashboard
- Check that the airport has webcams configured
- Verify the webcam index is valid for that airport

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

