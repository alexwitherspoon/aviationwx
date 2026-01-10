# Single Airport Mode - Design Document

## Problem Statement

When self-hosting AviationWX for a **single airport**, many UI elements designed for the multi-airport platform (aviationwx.org) are unnecessary and create clutter:

- Main site navigation bar (`lib/navigation.php`)
- Airport search functionality (both main nav and dashboard)
- Nearby airports dropdown
- Hamburger menu on airport dashboard
- Multi-airport map page
- Links to other platform features (Guides, Embed Generator, etc.)

## Goal

Automatically detect single-airport installations and simplify the UI to show only relevant features.

## Detection Strategy

**Trigger:** When `airports.json` contains **exactly 1 enabled airport**

```php
function isSingleAirportMode(): bool {
    static $cachedResult = null;
    
    if ($cachedResult !== null) {
        return $cachedResult;
    }
    
    $config = loadConfig();
    $enabledAirports = getEnabledAirports($config);
    
    $cachedResult = (count($enabledAirports) === 1);
    return $cachedResult;
}

function getSingleAirportId(): ?string {
    if (!isSingleAirportMode()) {
        return null;
    }
    
    $config = loadConfig();
    $enabledAirports = getEnabledAirports($config);
    
    return array_key_first($enabledAirports);
}
```

## UI Changes by Component

**Summary Table:**

| Component | Multi-Airport (2+) | Single-Airport (1) |
|-----------|-------------------|-------------------|
| Main nav bar | Full navigation with search | Hidden entirely |
| Airport dashboard search | Yes | Hidden (no other airports) |
| Nearby airports dropdown | Yes | Hidden (no nearby in config) |
| Dashboard hamburger menu | Links: Home, Airports, Embed, API, Status | Links: Home (→dashboard), Embed, API, Status |
| Homepage `/` | Platform overview | Redirect to airport dashboard |
| Guides `/guides` | Full guides | Redirect to airport dashboard |
| Map page `/airports` | Interactive map | Redirect to airport dashboard |
| Status page `/status` | System health | System health (kept) |
| API page `/api` | API docs | API docs (kept, essential for federation) |
| Embed page `/embed` | Embed configurator | Embed configurator (kept) |

### 1. Main Site Navigation (`lib/navigation.php`)

**Multi-Airport Mode (2+ airports):**
- Full navigation bar with logo, search, links
- Airport search dropdown
- Developer menu (Embed, API, GitHub)
- Contact, Status links
- Mobile hamburger menu

**Single-Airport Mode:**
- **Hide entirely** - Cleaner, self-hosted users likely know about API already
- Show API/Status/GitHub links in footer only

### 2. Airport Dashboard Page (`pages/airport.php`)

**Multi-Airport Mode:**
- Airport search bar (top right)
- Nearby airports button/dropdown
- Hamburger menu (Home, Airports map, Embed, Status)
- Full navigation

**Single-Airport Mode:**
- **Remove:** Airport search bar (no other airports to search)
- **Remove:** Nearby airports dropdown (no nearby airports in config)
- **Keep:** Hamburger menu BUT hide "Browse all airports" link
- **Hamburger menu items:** Home → dashboard, Embed, API, Status
- **Keep:** All weather/webcam functionality
- **Keep:** Dark mode toggle
- **Keep:** Settings/preferences

### 3. Airports Map Page (`pages/airports.php`)

**Multi-Airport Mode:**
- Interactive map with all airports
- Flight category legend
- Weather radar/clouds overlay
- Zoom controls

**Single-Airport Mode:**
- **Redirect to single airport dashboard** (`/{airport_id}`)
- No map page needed for single airport

### 4. Homepage (`pages/homepage.php`)

**Multi-Airport Mode:**
- Platform introduction
- Links to all sections

**Single-Airport Mode:**
- **Redirect to single airport dashboard** (`/{airport_id}`)
- Direct users immediately to weather content

### 5. Guides Page (`pages/guides.php`)

**Multi-Airport Mode:**
- Full installation and configuration guides
- Platform documentation

**Single-Airport Mode:**
- **Redirect to single airport dashboard** (`/{airport_id}`)
- Guides are for platform operators, not end users

### 6. Status Page (`health/status.php`)

**Both Modes:**
- **Keep accessible** - Local installs need system health monitoring
- Shows: Uptime, metrics, cache status, etc.
- Accessible from hamburger menu

### 7. Footer Links

**Multi-Airport Mode:**
- Links to: Home, Airports, Guides, Embed, API, Status, GitHub, Contact

**Single-Airport Mode:**
- Links to: API docs, Status, GitHub (platform), GitHub (bridge tool)
- Simplified attribution

### 8. API Documentation (`api/docs/index.php`)

**Both Modes:**
- **Keep fully functional** - API is essential for federation (see below)
- Full API docs with examples
- Single-airport mode: Examples auto-populated with single airport ID

### 9. Embed Configurator (`pages/embed-configurator.php`)

**Both Modes:**
- **Keep fully functional** - Users may want to embed their airport elsewhere
- Single-airport mode: No airport selector needed (auto-use single airport)

### 10. Error Pages

**404 Errors:**
- Multi-airport: Suggest searching for airports
- Single-airport: Link back to dashboard only

## Implementation Plan

### Phase 1: Core Detection Function
- [ ] Add `isSingleAirportMode()` to `lib/config.php`
- [ ] Add `getSingleAirportId()` to `lib/config.php`
- [ ] Add unit tests for detection logic

### Phase 2: Routing Changes
- [ ] `index.php`: Redirect `/` to `/{airport_id}` in single-airport mode
- [ ] `index.php`: Redirect `/airports` to `/{airport_id}` in single-airport mode
- [ ] Handle `/embed`, `/api` normally (still useful)

### Phase 3: Dashboard Simplification
- [ ] `pages/airport.php`: Hide airport search in single-airport mode
- [ ] `pages/airport.php`: Hide nearby airports dropdown
- [ ] `pages/airport.php`: Hide hamburger menu
- [ ] Update CSS to handle missing elements gracefully

### Phase 4: Navigation Simplification
- [ ] `lib/navigation.php`: Return early (no output) in single-airport mode
- [ ] Update pages that include navigation to handle empty output
- [ ] Ensure footer links still work

### Phase 5: Testing & Documentation
- [ ] Test with 1-airport config
- [ ] Test with 2-airport config (should show full UI)
- [ ] Test enabling/disabling airports (mode switches correctly)
- [ ] Update `docs/LOCAL_SETUP.md` with single-airport mode info
- [ ] Update `README.md` to highlight single-airport simplicity

## Edge Cases & Considerations

### Cache Invalidation
- `isSingleAirportMode()` uses static caching (per-request)
- Config changes require server restart (already documented)
- No additional cache invalidation needed

### Disabled Airports
- Only count **enabled** airports via `getEnabledAirports()`
- If user temporarily disables an airport (1 enabled remaining), UI simplifies
- Re-enabling brings back full UI

### API & Embed Still Work
- API endpoints work normally (important for integrations)
- Embed configurator still accessible (user might embed elsewhere)
- Just hide navigation links to these features

### SEO & Meta Tags
- Single-airport installations probably don't care about SEO
- Keep existing meta tags, just simplify navigation

### Mobile Considerations
- Most benefit on mobile (less navigation clutter)
- Dark mode, settings still fully functional
- Cleaner experience for pilots at the airport

## Configuration Override (Optional Enhancement)

Allow explicit mode override in `airports.json`:

```json
{
  "config": {
    "ui_mode": "auto",  // "auto", "multi_airport", "single_airport"
    ...
  }
}
```

**Use case:** User has 1 airport but wants to keep multi-airport UI for testing/future expansion.

## Benefits

### For Self-Hosted Users:
- ✅ Cleaner, focused UI
- ✅ No unnecessary navigation elements
- ✅ Faster to understand for first-time visitors
- ✅ Works out-of-the-box based on config

### For AviationWX.org:
- ✅ No impact (always has 2+ airports)
- ✅ Better developer experience (can test single-airport mode locally)
- ✅ Shows commitment to self-hosted community

### For Development:
- ✅ Automatic detection (no manual configuration)
- ✅ Easy to test (just modify airports.json)
- ✅ Backward compatible (existing multi-airport setups unchanged)

## Example User Flow

**Self-Hosted Installation (1 Airport):**

1. User sets up config with single airport (e.g., `kspb`)
2. User visits their domain: `https://weather.myairport.com`
3. **Automatically redirected** to `https://weather.myairport.com/kspb`
4. Dashboard shows:
   - Airport name & info
   - Live webcams
   - Weather data
   - No navigation clutter
   - Dark mode toggle
   - Settings panel
5. Direct, focused experience for pilots

**Multi-Airport Installation (2+ Airports):**

1. User sets up config with multiple airports
2. User visits domain: Full platform experience
3. Map page, search, navigation all work normally
4. No changes to existing behavior

## Files to Modify

### Core Logic:
- `lib/config.php` - Add detection functions

### Routing:
- `index.php` - Add redirects for single-airport mode

### UI Components:
- `lib/navigation.php` - Conditional rendering
- `pages/airport.php` - Hide search/nearby/hamburger
- `pages/homepage.php` - Redirect or simplify
- `pages/airports.php` - Redirect or simplify

### Styles:
- `public/css/navigation.css` - Handle missing nav gracefully
- Dashboard CSS - Adjust spacing when elements hidden

### Tests:
- `tests/Unit/ConfigTest.php` - Test detection functions
- `tests/Integration/SingleAirportModeTest.php` - New integration tests

### Documentation:
- `docs/LOCAL_SETUP.md` - Document single-airport mode
- `docs/CONFIGURATION.md` - Document behavior
- `README.md` - Highlight in features section

## Federation: Single-Airport Installs as Data Sources

### Vision

Allow single-airport installations to become part of the AviationWX.org network by exposing their data via the public API. This creates a **federated architecture** where:

1. Airport operators self-host their own installation
2. They maintain full control over their data and infrastructure
3. AviationWX.org can optionally fetch from their public API
4. Creates a decentralized network of aviation weather data

### Architecture

**Single-Airport Installation:**
```
Local Server (weather.myairport.com)
├── Own cameras/weather stations
├── Local data processing
├── Public API enabled
└── Exposes: /api/v1/weather/{airport_id}
```

**AviationWX.org Platform:**
```
Main Platform (aviationwx.org)
├── Fetches from federated sources
├── New weather source adapter: "aviationwx_api"
├── Treats federated installs like any other data source
└── Falls back to other sources if federated source fails
```

### Implementation: New Weather Source Adapter

Add `lib/weather/adapter/AviationWXAPIAdapter.php`:

```php
/**
 * Fetches weather data from another AviationWX instance's public API
 * Enables federated architecture for self-hosted installations
 */
class AviationWXAPIAdapter implements WeatherAdapter {
    public function fetchWeather(string $airportId, array $sourceConfig): ?array {
        $baseUrl = $sourceConfig['base_url']; // e.g., "https://weather.myairport.com"
        $apiKey = $sourceConfig['api_key'] ?? null; // Optional, for partner API keys
        
        // Fetch from federated instance's public API
        $url = rtrim($baseUrl, '/') . "/api/v1/weather/{$airportId}";
        
        // Make request with optional API key
        $response = makeHttpRequest($url, $apiKey);
        
        // Transform API response to internal format
        return $this->transformResponse($response);
    }
}
```

### Configuration Example

**On AviationWX.org (main platform):**
```json
{
  "airports": {
    "k0s9": {
      "name": "Jefferson County International",
      "weather_source": {
        "type": "aviationwx_api",
        "base_url": "https://weather.k0s9.org",
        "api_key": "ak_live_federated_k0s9_12345"
      },
      "webcams": [
        {
          "name": "Federated Camera 1",
          "type": "aviationwx_api",
          "base_url": "https://weather.k0s9.org",
          "api_key": "ak_live_federated_k0s9_12345",
          "camera_index": 0
        }
      ]
    }
  }
}
```

**On Local Install (weather.k0s9.org):**
```json
{
  "config": {
    "public_api": {
      "enabled": true,
      "partner_keys": {
        "ak_live_federated_k0s9_12345": {
          "name": "AviationWX.org Main Platform",
          "contact": "federated@aviationwx.org",
          "enabled": true,
          "tier": "partner"
        }
      }
    }
  },
  "airports": {
    "k0s9": {
      "name": "Jefferson County International",
      "weather_source": {
        "type": "tempest",
        "station_id": "12345",
        "api_key": "local_tempest_key"
      },
      "webcams": [
        {
          "name": "Runway 26",
          "url": "rtsp://camera.local/stream",
          "type": "rtsp"
        }
      ]
    }
  }
}
```

### Benefits of Federation

**For Airport Operators:**
- ✅ Full control over infrastructure
- ✅ No vendor lock-in
- ✅ Data stays local (processed on-site)
- ✅ Can join/leave network anytime
- ✅ Optional: Share data with main platform

**For AviationWX.org:**
- ✅ Expand coverage without infrastructure costs
- ✅ Distributed, resilient architecture
- ✅ Airport operators maintain their own systems
- ✅ Falls back gracefully if federated source offline

**For Pilots/Users:**
- ✅ More airports available
- ✅ Consistent interface (all via aviationwx.org)
- ✅ Can also access individual airport sites directly

### Security Considerations

1. **API Authentication:**
   - Federated sources use partner API keys
   - Rate limiting per key
   - Keys can be revoked

2. **Data Validation:**
   - Adapter validates all responses
   - Sanity checks on values
   - Staleness detection

3. **Fallback Strategy:**
   - If federated source fails, mark as stale
   - Optional: Configure backup sources
   - Never silently fail (safety-critical)

### Future Enhancements

1. **Auto-discovery:**
   - DNS TXT records for federation metadata
   - `_aviationwx.k0s9.org TXT "api=https://weather.k0s9.org"`

2. **Health Checks:**
   - Periodic pings to federated sources
   - Auto-disable if consistently down

3. **Bidirectional Federation:**
   - Large installs could federate with each other
   - Create mesh network of weather data

4. **Contributor Dashboard:**
   - Show federated contributors on main site
   - Credit airport operators

### Implementation Timeline

**Phase 1 (Current):** Single-airport mode UI simplification
**Phase 2 (Future):** AviationWX API adapter for federation
**Phase 3 (Future):** Auto-discovery and mesh networking

This design ensures single-airport installations are **first-class citizens** while enabling future federation.

## Migration Path

Existing installations (multi-airport) are **not affected**:
- AviationWX.org has 10+ airports → Always multi-airport mode
- Full UI remains unchanged
- No breaking changes

New single-airport installations automatically get simplified UI.

## Timeline Estimate

- **Phase 1 (Detection):** 1-2 hours
- **Phase 2 (Routing):** 1-2 hours  
- **Phase 3 (Dashboard):** 2-3 hours
- **Phase 4 (Navigation):** 1-2 hours
- **Phase 5 (Testing/Docs):** 2-3 hours

**Total:** 7-12 hours of development + testing
