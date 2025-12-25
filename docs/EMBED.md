# Embed Generator

The Embed Generator allows you to create embeddable weather widgets for any AviationWX airport. These widgets can be added to external websites like WordPress, Google Sites, Squarespace, or any HTML page.

## Quick Start

1. Visit [embed.aviationwx.org](https://embed.aviationwx.org)
2. Search and select an airport
3. Choose a widget style
4. Customize options (theme, cameras, units)
5. Copy the embed code
6. Paste into your website

## Widget Styles

### Mini Airport Card (300×275)
A compact card showing essential weather data including wind, temperature, altimeter, visibility, and density altitude. Includes a mini wind compass visualization. Best for sidebars or small spaces.

### Single Webcam (400×320)
Displays a single webcam feed with weather summary bar and wind compass. Useful for highlighting a specific runway view.

### Dual Camera (600×320)
Shows two webcams side by side with a weather bar including wind compass. Good for airports with two runways or different viewing angles.

### 4 Camera Grid (600×600)
Displays up to four webcams in a grid layout with comprehensive weather information and wind compass. Ideal for airports with multiple camera positions.

### Full Widget (800×700)
The most complete widget featuring a large webcam, wind compass visualization, and full weather metrics organized in columns (Wind, Temperature, Conditions, Altitude). Best for dedicated weather sections.

## Customization Options

### Theme
- **Light**: White background, optimized for light websites
- **Dark**: Dark background, optimized for dark websites

### Webcam Selection
For multi-camera widgets (Dual Camera, 4 Camera Grid), you can choose which camera appears in each slot. This allows you to prioritize specific runway views or angles.

### Units
Configure measurement units to match your preference:
- **Temperature**: °F or °C
- **Altitude/Distance**: ft or m
- **Wind Speed**: kt, mph, or km/h
- **Barometer**: inHg, hPa, or mmHg

### Link Behavior
Choose whether dashboard links open in a new tab or the same tab.

## Embed Types

### iframe Embed (Recommended)
```html
<iframe
  src="https://embed.aviationwx.org/kspb?style=card&theme=light&target=_blank"
  width="300"
  height="275"
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

### Static Badge
A server-rendered image that works in restricted environments including email signatures. Clicking the image links to the full dashboard.

### Web Component (Coming Soon)
A modern JavaScript-based widget for tighter integration with your website.

## URL Parameters

When embedding directly, you can customize the widget using URL parameters:

| Parameter | Values | Description |
|-----------|--------|-------------|
| `style` | `card`, `webcam`, `dual`, `multi`, `full` | Widget style |
| `theme` | `light`, `dark` | Color theme |
| `webcam` | `0`, `1`, `2`, ... | Camera index (for single webcam/full) |
| `cams` | `0,1,2,3` | Camera indices (for dual/multi) |
| `target` | `_blank`, `_self` | Link target |
| `temp` | `F`, `C` | Temperature unit |
| `dist` | `ft`, `m` | Distance unit |
| `wind` | `kt`, `mph`, `kmh` | Wind speed unit |
| `baro` | `inHg`, `hPa`, `mmHg` | Barometer unit |

Example URL:
```
https://embed.aviationwx.org/kspb?style=dual&theme=light&cams=0,1&target=_blank&temp=F&wind=kt
```

## Local Development

To test the embed generator locally:

```bash
# Start the local development server
make up

# Access the configurator
http://localhost:8080/?embed

# Access an embed directly
http://localhost:8080/?embed&airport=kspb&style=card&theme=light
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
- Adjust the `width` and `height` attributes on the iframe
- Each widget style has recommended dimensions shown in the configurator

## Security Considerations

- Embeds are read-only and cannot modify any data
- All data comes from the public AviationWX API
- Webcam images are proxied through AviationWX servers
- No user data or tracking is included in embeds

## Support

For issues or questions:
- Open an issue on [GitHub](https://github.com/alexwitherspoon/aviationwx/issues)
- Email contact@aviationwx.org

