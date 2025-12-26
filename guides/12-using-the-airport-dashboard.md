# 12 - Using the Airport Dashboard (Pilot's Quick Reference)

## What is this?
This guide helps you get the most out of an AviationWX airport dashboard. Think of it as the "controls overview" screen before the game starts - we'll show you where everything is, what it does, and let you learn by exploring.

**A working example:** https://kspb.aviationwx.org

### Our Philosophy

AviationWX dashboards focus on **essential, at-a-glance information** - the stuff you actually need when checking conditions before a flight. We keep it simple and fast.

For deeper research, every dashboard includes links to trusted external resources like **AirNav**, **SkyVector**, **AOPA**, and **FAA Weather Cams**. We're not trying to replace these great tools - we're giving you a quick visual check and then connecting you to the experts when you need more.

> ⚠️ **Important**: AviationWX is a **supplemental** information source. Always obtain official weather briefings and NOTAMs before flight.

---

## Dashboard at a Glance

```
+-----------------------------------------------------------------------------+
|  AIRPORT NAME (IDENTIFIER)                                                  |
|  City, State                                                                |
+-----------------------------------------------------------------------------+
|  [ Search by name, ICAO, IATA, or FAA code... ]     [ Nearby Airports ]     |
+-----------------------------------------------------------------------------+
                                     |
         +---------------------------+---------------------------+
         |                           |                           |
         v                           v                           v
+-------------------+     +-------------------+     +-------------------+
|      WEBCAMS      |     |  CURRENT WEATHER  |     |    RUNWAY WIND    |
|                   |     |                   |     |                   |
|   Tap any image   |     |   VFR / MVFR /    |     |    Wind rose +    |
|   for 24-hour     |     |   IFR / LIFR      |     |    crosswind      |
|   history         |     |                   |     |    components     |
+-------------------+     +-------------------+     +-------------------+
                                     |
                                     v
+-----------------------------------------------------------------------------+
|  AIRPORT INFO: Runways  *  Frequencies  *  Elevation  *  External Links    |
+-----------------------------------------------------------------------------+
```

---

## Section 1: Weather Display

### How Weather Data Works

AviationWX combines multiple data sources to show you the **freshest, most complete picture** of current conditions:

```
+-----------------------------------------------------------------------------+
|                          WEATHER DATA SOURCES                               |
|                                                                             |
|      +--------------+      +--------------+      +--------------+           |
|      |   On-Site    |      |   Official   |      |  Calculated  |           |
|      |    Sensor    |      |    METAR     |      |    Values    |           |
|      |              |      |              |      |              |           |
|      |   Tempest    |      |  ASOS/AWOS   |      |   Density    |           |
|      |   Davis      |      |   via FAA    |      |   Altitude   |           |
|      |   Ambient    |      |              |      |   Crosswind  |           |
|      +------+-------+      +------+-------+      +------+-------+           |
|             |                     |                     |                   |
|             +---------------------+---------------------+                   |
|                                   |                                         |
|                                   v                                         |
|                       +---------------------+                               |
|                       |     MERGED VIEW     |                               |
|                       |                     |                               |
|                       |   Latest reading    |                               |
|                       |   from best source  |                               |
|                       |   for each field    |                               |
|                       +---------------------+                               |
+-----------------------------------------------------------------------------+
```

**What this means for you:**
- Some airports have **on-site weather stations** (updated every 1-5 minutes)
- Some airports use **official METAR data** from nearby ASOS/AWOS
- Many airports show **both** - you get hyper-local conditions AND official aviation weather
- Values like density altitude and crosswind components are calculated automatically

### Flight Category Colors

The dashboard uses **standard aviation weather colors**:

```
+-----------------------------------------------------------------------------+
|  FLIGHT CATEGORY       |  VISIBILITY        |  CEILING                      |
+------------------------+--------------------+-------------------------------+
|  VFR   (Green)         |  > 5 SM            |  > 3,000 ft AGL               |
|  MVFR  (Blue)          |  3 - 5 SM          |  1,000 - 3,000 ft             |
|  IFR   (Red)           |  1 - 3 SM          |  500 - 1,000 ft               |
|  LIFR  (Magenta)       |  < 1 SM            |  < 500 ft                     |
+-----------------------------------------------------------------------------+
|  Ceiling and visibility are evaluated separately.                           |
|  The MORE restrictive condition determines the category.                    |
+-----------------------------------------------------------------------------+
```

### Weather Section Layout

```
+-----------------------------------------------------------------------------+
|  Current Weather                         [Theme] [12hr] [F] [ft] [inHg]     |
+-----------------------------------------------------------------------------+
|  Updated: 2 minutes ago                                                     |
|                                                   ^ Unit toggles            |
|  +-----------+   +-----------+   +-----------+      (click to change)       |
|  | Condition |   |   Wind    |   |   Temp    |                              |
|  |           |   |           |   |           |                              |
|  |    VFR    |   |  270 at   |   |   68 F    |                              |
|  |           |   |  12 kts   |   |           |                              |
|  +-----------+   +-----------+   +-----------+                              |
|                                                                             |
|  +-----------+   +-----------+   +-----------+   +-----------+              |
|  | Humidity  |   | Pressure  |   |Visibility |   | Ceiling   |              |
|  |    65%    |   |29.92 inHg |   |  10+ SM   |   | Unlimited |              |
|  +-----------+   +-----------+   +-----------+   +-----------+              |
+-----------------------------------------------------------------------------+
```

**Hover over any toggle button** to see what it does.

---

## Section 2: Runway Wind Display

The wind rose shows wind relative to the runway(s):

```
+-----------------------------------------------------------------------------+
|  Runway Wind                                                        [kts]   |
+-----------------------------------------------------------------------------+
|                                                                             |
|                               N                                             |
|                               |                                             |
|                          \    |    /                                        |
|                           \   |   /                                         |
|                       W ----- o ----- E       <-- Wind direction arrow      |
|                           /   |   \               points FROM wind source   |
|                          /    |    \                                        |
|                               |                                             |
|                               S                                             |
|                                                                             |
|                 +-----------------------------------+                       |
|                 |  Runway 15/33                     |                       |
|                 |  Headwind:  8 kts  (Rwy 33)       |                       |
|                 |  Crosswind: 5 kts  (from left)    |                       |
|                 +-----------------------------------+                       |
+-----------------------------------------------------------------------------+
```

**What you see:**
- **Wind arrow** pointing FROM the wind direction
- **Runway alignment** overlaid for reference
- **Component breakdown** - headwind/tailwind and crosswind for each runway end

---

## Section 3: Webcams & 24-Hour History

### Webcam Grid

```
+-----------------------------------------------------------------------------+
|  WEBCAMS                                                                    |
+-----------------------------------------------------------------------------+
|                                                                             |
|  +---------------------------+       +---------------------------+          |
|  |                           |       |                           |          |
|  |                           |       |                           |          |
|  |       [Live Image]        |       |       [Live Image]        |          |
|  |                           |       |                           |          |
|  |     <-- TAP FOR HISTORY   |       |     <-- TAP FOR HISTORY   |          |
|  |                           |       |                           |          |
|  +---------------------------+       +---------------------------+          |
|  | North Runway    * 2m ago  |       | South Approach   * 5m ago |          |
|  +---------------------------+       +---------------------------+          |
|                                                                             |
|                 Hover: "Tap to view 24-hour history"                        |
+-----------------------------------------------------------------------------+
```

**Each webcam shows:**
- Current image (updated every 1-15 minutes depending on configuration)
- Camera name and last update time
- Warning icon if image is stale (hasn't updated in a while)

### History Player

Tap (or click) any webcam image to open the **24-hour history player**:

```
+-----------------------------------------------------------------------------+
|  <- Back                                North Runway                        |
+-----------------------------------------------------------------------------+
|                                                                             |
|                                                                             |
|                                                                             |
|                         [ Historical Image ]                                |
|                                                                             |
|                        Tap image to show/hide                               |
|                             controls                                        |
|                                                                             |
|                                                                             |
+-----------------------------------------------------------------------------+
|                       2:45:30 PM (3 hours ago)                              |
+-----------------------------------------------------------------------------+
|                                                                             |
|       o===================[]=================================o              |
|     6:00 AM               ^ drag to scrub                  Now              |
|                                                                             |
+-----------------------------------------------------------------------------+
|                                                                             |
|              [<<]    [PLAY]    [>>]     |     [Loop]    [Hide]              |
|              prev     play    next      |    autoplay   controls            |
|                                         |                                   |
+-----------------------------------------------------------------------------+
```

### Player Controls Reference

| Control | Action | Keyboard Shortcut |
|---------|--------|-------------------|
| << Previous | Step back one frame | `←` Left Arrow |
| PLAY/Pause | Start/stop playback | `Space` |
| >> Next | Step forward one frame | `→` Right Arrow |
| Timeline | Drag to scrub through time | - |
| Loop | Loop continuously | - |
| Hide | Full-screen view (controls auto-hide) | `C` |
| <- Back | Close player | `Escape` |
| - | Jump to oldest frame | `Home` |
| - | Jump to newest frame | `End` |

### Touch Gestures (Mobile)

```
+-----------------------------------------------------------------------------+
|                                                                             |
|                              SWIPE LEFT/RIGHT                               |
|                           <-- -- -- -- -- -- -->                            |
|                              Navigate frames                                |
|                                                                             |
|          +-----------------------------------------------+                  |
|          |                                               |                  |
|    S     |                                               |     S            |
|    W     |                                               |     W            |
|    I     |              [ Player Image ]                 |     I            |
|    P     |                                               |     P            |
|    E     |                                               |     E            |
|          |                                               |                  |
|    U     |           TAP to toggle controls              |     U            |
|    P     |                                               |     P            |
|          |                                               |                  |
|          +-----------------------------------------------+                  |
|                                                                             |
|                              SWIPE DOWN                                     |
|                                  |                                          |
|                                  v                                          |
|                             Close player                                    |
|                                                                             |
+-----------------------------------------------------------------------------+
```

---

## Section 4: Unit & Display Toggles

Click any toggle button to cycle through options. Your preferences are saved automatically.

```
+-----------------------------------------------------------------------------+
|  TOGGLE BUTTONS (in weather section header)                                 |
|                                                                             |
|      +--------+    +--------+    +--------+    +--------+    +--------+     |
|      | Theme  |    |  12hr  |    |   F    |    |   ft   |    |  inHg  |     |
|      +--------+    +--------+    +--------+    +--------+    +--------+     |
|          |             |             |             |             |          |
|          v             v             v             v             v          |
|        Theme         Time         Temp        Distance       Pressure       |
|        mode         format        unit          unit           unit         |
+-----------------------------------------------------------------------------+
```

### Theme Modes

| Icon | Mode | Description |
|------|------|-------------|
| Day | Day | Light background, standard colors |
| Dark | Dark | Dark background, reduced eye strain |
| Night | Night | Red-on-black, preserves night vision for cockpit use |
| Auto | Auto | Follows your device's light/dark preference |

> 💡 **Night mode auto-activates** on mobile devices after sunset at the airport's location. You can override this manually if needed.

### Unit Options

| Toggle | Options |
|--------|---------|
| Time | 12-hour / 24-hour (Zulu) |
| Temperature | °F / °C |
| Distance/Altitude | feet / meters |
| Visibility | statute miles / kilometers |
| Wind Speed | knots / mph / km/h |
| Pressure | inHg / hPa / mmHg |

---

## Section 5: Navigation

### Searching for Airports

```
+-----------------------------------------------------------------------------+
|  [ Search by name, ICAO, IATA, or FAA code...                          ]    |
+-----------------------------------------------------------------------------+
                                     |
                         Start typing to search
                                     |
                                     v
+-----------------------------------------------------------------------------+
|  Scappoose Industrial Airpark               KSPB                   12.3 mi  |
+-----------------------------------------------------------------------------+
|  Pearson Field                              KVUO                    8.1 mi  |
+-----------------------------------------------------------------------------+
|  Portland-Hillsboro Airport                 KHIO                   18.4 mi  |
+-----------------------------------------------------------------------------+
```

**Accepts:**
- Airport name (partial matches work)
- ICAO code (e.g., KSPB)
- IATA code (e.g., PDX)
- FAA code (e.g., SPB)

### Nearby Airports

Click **"Nearby Airports"** to see other airports within 200 miles, sorted by distance:

```
+-----------------------------------------------------------------------------+
|  [ Nearby Airports v ]                                                      |
+-----------------------------------------------------------------------------+
                                     |
                                     v
+-----------------------------------------------------------------------------+
|  Pearson Field                              KVUO                    8.1 mi  |
|  Scappoose Industrial                       KSPB                   12.3 mi  |
|  Portland-Hillsboro                         KHIO                   18.4 mi  |
|  Aurora State                               KUAO                   24.7 mi  |
|  Mulino State                               4S9                    31.2 mi  |
+-----------------------------------------------------------------------------+
```

### External Resource Links

Each airport dashboard includes quick links to external resources:

```
+-----------------------------------------------------------------------------+
|                                                                             |
|       [ AirNav ]      [ SkyVector ]      [ AOPA ]      [ FAA Weather ]      |
|                                                                             |
|           |                 |                |                |             |
|           v                 v                v                v             |
|      Airport info      VFR charts      Pilot info      FAA weather          |
|      & frequencies     & planning      & directory     cams (if avail)      |
|                                                                             |
+-----------------------------------------------------------------------------+
```

On mobile devices, you'll also see a **ForeFlight** link that opens the airport directly in the ForeFlight app.

---

## Section 6: Airport Information

### Basic Info

```
+-----------------------------------------------------------------------------+
|  Airport Information                                                        |
+-----------------------------------------------------------------------------+
|                                                                             |
|    Elevation            Runways              Fuel                           |
|    +-----------+       +-----------+       +-----------+                    |
|    |   58 ft   |       |   15/33   |       |   100LL   |                    |
|    |    MSL    |       |  5100x75  |       |   MoGas   |                    |
|    +-----------+       +-----------+       +-----------+                    |
|                                                                             |
|    Frequencies                                                              |
|    +-----------+       +-----------+       +-----------+                    |
|    |   CTAF    |       |   AWOS    |       | Approach  |                    |
|    |   122.9   |       |  118.375  |       |  124.35   |                    |
|    +-----------+       +-----------+       +-----------+                    |
+-----------------------------------------------------------------------------+
```

### Address & Map Link

Click the airport address to open in your device's map application for navigation.

---

## Section 7: Understanding Data Freshness

### Timestamps

Every data source shows when it was last updated:

```
+-----------------------------------------------------------------------------+
|                                                                             |
|    "2 minutes ago"           <-- Recent, reliable                           |
|                                                                             |
|    "1 hour 23 minutes ago"   <-- Getting stale, check conditions            |
|                                                                             |
|    "! 6 hours ago"           <-- Stale warning, data may be outdated        |
|                                                                             |
+-----------------------------------------------------------------------------+
```

### Outage Banners

If data sources experience issues, you'll see a banner at the top of the page:

```
+-----------------------------------------------------------------------------+
|  ! WEATHER DATA OUTAGE -- Last successful update: 45 min ago                |
|    Some readings may be outdated. Check official sources.                   |
+-----------------------------------------------------------------------------+
```

### NOTAM Banners

Active NOTAMs for the airport appear at the top:

```
+-----------------------------------------------------------------------------+
|  ! ACTIVE NOTAM -- Runway 15/33 closed for maintenance                      |
|    Effective: Dec 20 0800Z - Dec 20 1700Z                                   |
+-----------------------------------------------------------------------------+
```

---

## Section 8: Embedding Dashboards

Want to display an AviationWX dashboard on your own website, FBO lobby screen, or flight school? Use the **Embed Generator**.

```
+-----------------------------------------------------------------------------+
|  Want to add this dashboard to your website?          [ Create Embed -> ]   |
+-----------------------------------------------------------------------------+
                                     |
                     Click to open the Embed Configurator
                                     |
                                     v
+-----------------------------------------------------------------------------+
|  EMBED CONFIGURATOR                                                         |
+-----------------------------------------------------------------------------+
|                                                                             |
|    Airport:  [ KSPB v ]                                                     |
|                                                                             |
|    Options:                                                                 |
|    [x] Show webcams            [x] Show weather                             |
|    [x] Show wind rose          [ ] Dark theme                               |
|    [ ] Hide controls           [ ] Autoplay webcam                          |
|                                                                             |
|    Preview:                                                                 |
|    +-------------------------------------------------------------------+    |
|    |                                                                   |    |
|    |                       [ Live Preview ]                            |    |
|    |                                                                   |    |
|    +-------------------------------------------------------------------+    |
|                                                                             |
|    Embed Code:                                                              |
|    +-------------------------------------------------------------------+    |
|    | <iframe src="https://embed.aviationwx.org/..."                    |    |
|    |         width="100%" height="600"></iframe>                       |    |
|    +-------------------------------------------------------------------+    |
|                                                       [ Copy Code ]         |
+-----------------------------------------------------------------------------+
```

**Common use cases:**
- FBO/airport lobby displays
- Flight school websites
- Pilot lounge monitors
- Personal aviation blogs
- Club/organization websites

Visit **https://embed.aviationwx.org** to create your embed.

---

## Section 9: Tips & Tricks

### Treat It Like an Amazing PIREP

The real power of AviationWX is **visual verification**. Use the dashboard like you'd use a freshly received pilot report:

| What to Check | What You're Looking For |
|---------------|------------------------|
| **Webcam images** | Actual sky conditions - does it match the forecast? Can you see the horizon? Any fog, haze, or precipitation visible? |
| **24-hour history** | Weather trends - is it improving or deteriorating? When did that fog roll in? How quickly did conditions change? |
| **On-site wind** | Real-time local wind vs. official report - is there a difference? Gusty conditions the METAR might miss? |
| **Crosswind display** | Runway decision - which runway has the best wind component right now? |
| **Multiple cameras** | Full picture - check different angles to see if conditions vary across the field |
| **Timestamps** | Data freshness - is this a current observation or stale data? |

**Pro tip:** Scrub through the webcam history before departure to understand how conditions have evolved. A clear field now that was IFR an hour ago tells a different story than one that's been clear all day.

### For Quick Checks
- Bookmark your home airport's dashboard
- Use the search to quickly jump between airports
- On mobile, add the page to your home screen for app-like access

### Sharing
- Every dashboard has a shareable URL: `https://[airport-id].aviationwx.org`
- Webcam player URLs are shareable too - great for showing conditions to others

---

## Quick Reference Card

```
+-----------------------------------------------------------------------------+
|  AVIATIONWX DASHBOARD -- QUICK REFERENCE                                    |
+-----------------------------------------------------------------------------+
|                                                                             |
|  WEBCAM PLAYER KEYS                   FLIGHT CATEGORIES                     |
|  -----------------------              ---------------------                 |
|  Space      Play/Pause                VFR   > 5 SM, > 3000 ft               |
|  <- ->      Prev/Next frame           MVFR  3-5 SM, 1000-3000 ft            |
|  Home/End   First/Last frame          IFR   1-3 SM, 500-1000 ft             |
|  Escape     Close player              LIFR  < 1 SM, < 500 ft                |
|  C          Toggle controls                                                 |
|                                                                             |
|  TOUCH GESTURES (mobile)              THEME MODES                           |
|  -----------------------              ---------------------                 |
|  Tap image    Show/hide UI            Day    Light mode                     |
|  Swipe L/R    Navigate frames         Dark   Dark mode                      |
|  Swipe Down   Close player            Night  Red (night vision)             |
|                                       Auto   Follow device setting          |
|                                                                             |
|  SEARCH FORMATS                       DATA SOURCES                          |
|  -----------------------              ---------------------                 |
|  Airport name (partial OK)            On-site sensors (1-5 min updates)     |
|  ICAO code (e.g., KSPB)               Official METAR (hourly+)              |
|  IATA code (e.g., PDX)                Combined for best picture             |
|  FAA code (e.g., SPB)                                                       |
|                                                                             |
+-----------------------------------------------------------------------------+
```

---

## Need More Help?

- **Technical documentation:** [docs/](https://github.com/alexwitherspoon/aviationwx/tree/main/docs)
- **Report an issue:** [GitHub Issues](https://github.com/alexwitherspoon/aviationwx/issues)
- **Contact:** `contact@aviationwx.org`
