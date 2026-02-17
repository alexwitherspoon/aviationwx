# 09 - Weather Station Configuration

## Goal
Set up a weather station so it provides **useful, trustworthy local observations** that can complement official sources.
This guide focuses on practical configuration and sanity checks, not vendor marketing.

This guide assumes you have already decided:
- where the station will be mounted (Guides 02-03)
- how it will be powered and connected (Guides 05-06)

## What "good weather data" means (plain language)
A good install produces readings that are:
- **representative** of conditions at the airport (not a sheltered corner)
- **consistent** over time (no wild spikes from poor placement)
- **interpretable** by pilots (wind/temperature/pressure trends make sense)

You don't need perfection. You need "trustworthy enough" to support better decisions.

## The most important rule: wind exposure
**Wind sampling matters.** Whenever possible, mount the wind sensor with **unobstructed exposure**, ideally **at the top of an approved pole, building, or structure**.

Avoid:
- sheltered courtyards and behind-hangar corners
- rooftop turbulence zones and heat sources
- places where trees, buildings, or terrain block a large portion of the wind

If you can't get good wind exposure, it is usually better to:
- change location, or
- treat the wind reading as "low confidence" and emphasize other fields (temperature/pressure/rain), or
- use an official airport wind source if one exists nearby.

## Quick pick-your-path
- If you're installing **Tempest** → go to **Tempest setup**
- If you're installing **Davis Vantage Pro2** → go to **Davis setup**
- If you're installing **Ambient WS‑2902** → go to **Ambient setup**
- If you're integrating an existing station → go to **Existing station integration checklist**

---

## Universal setup checklist (do this for any station)
- ☐ Confirm written permission and approved location (Guide 01)
- ☐ Mount for representative readings (Guide 02)
- ☐ Confirm wind sensor has unobstructed exposure (top of approved structure when possible)
- ☐ Confirm the station is physically secure and serviceable
- ☐ Configure connectivity so it auto-recovers after power outages
- ☐ Run sanity checks after install (see below)
- ☐ Document ownership, maintenance, and how to verify health (Guide 11)

---

## Tempest setup (recommended default)

### Why Tempest works well for this project
- strong "community deployment" fit: relatively simple install and good usefulness for pilots
- good value per dollar

### Install checklist (Tempest)
- ☐ Mount with good wind exposure (top of approved pole/building/structure when possible)
- ☐ Avoid heat-soaked surfaces and sheltered corners
- ☐ Confirm the hub / gateway location has stable internet (LAN or reliable Wi‑Fi/LTE)
- ☐ Confirm the station is reporting and updates look consistent over 24-72 hours
- ☐ Record basic metadata (location description, mount height, nearby obstructions)

### What you need to do
- **Set up your Tempest station** following WeatherFlow's instructions
- **Provide AviationWX with API access** (see "Connecting Your Station to AviationWX" below)
- **That's it** - AviationWX handles polling automatically (every 60 seconds by default)

You don't need to configure update cadence or push settings. We pull data from your station's API centrally.

---

## Davis Vantage Pro2 setup (professional-grade alternative)

### Why Davis is a great fit
- durable, long-lived hardware when installed well
- widely used and well understood

### Install checklist (Davis)
- ☐ Mount for unobstructed wind exposure (top of approved structure preferred)
- ☐ Confirm the full sensor suite is installed correctly and level as required
- ☐ Confirm data is accessible through your chosen connectivity path
- ☐ Validate "looks reasonable" readings for 24-72 hours

### What you need to do
- **Set up your Davis station** with WeatherLink connectivity
- **Provide AviationWX with API access** (see "Connecting Your Station to AviationWX" below)
- **That's it** - AviationWX handles polling automatically

Davis installs tend to reward careful mounting and cable hygiene. If your airport or community is already familiar with Davis, lean into that expertise.

---

## Ambient WS‑2902 setup (budget-friendly option)

### Why Ambient can be useful
- a common "prove value" station
- often the fastest path to getting basic local conditions online

### Install checklist (Ambient)
- ☐ Prioritize wind exposure and representative placement (same rules apply)
- ☐ Confirm the station can reliably publish readings to Ambient Weather Network
- ☐ Validate readings over 24-72 hours

### What you need to do
- **Set up your Ambient station** and confirm it's reporting to ambientweather.net
- **Provide AviationWX with API access** (see "Connecting Your Station to AviationWX" below)
- **That's it** - AviationWX handles polling automatically

---

## Existing station integration checklist
If an airport already has a station (official or unofficial), the best move may be to integrate rather than replace.

- ☐ Identify the data source and who owns it
- ☐ Confirm access is permitted and stable
- ☐ Confirm the station is sited appropriately (or note limitations)
- ☐ Confirm the update cadence is reasonable
- ☐ Confirm how outages and reboots are handled
- ☐ Document who to call when it breaks

---

## Sanity checks (simple, pilot-friendly)
Do these checks after install and then occasionally during maintenance.

### Wind
- Does wind direction make sense relative to known conditions?
- Is wind speed "plausible" (not constantly zero unless it's truly calm)?
- Do gusts and lulls look realistic, not like random spikes?

### Temperature
- Does temperature roughly match nearby official sources (if any), adjusted for microclimate?
- Is it obviously heat-soaked (too warm on sunny days compared to expectations)?

### Pressure
- Is the trend plausible over the day?
- Does it roughly match nearby official sources (if any)?

### Rain (if present)
- Does precipitation correlate with what you can see / local reports?
- Is it falsely triggered by sprinklers, roof runoff, or splashing?

If any of these are wildly off, fix siting first. Sensor "calibration" rarely beats good placement.

---

## Common failure modes (and what to do)
- **Wind reads low or wrong:** likely sheltered placement or obstruction. Move it higher / more exposed if possible.
- **Temperature reads too high:** likely heat soak. Move away from roofs/walls/metal surfaces.
- **Dropouts after outages:** improve connectivity and auto-reconnect; consider a UPS (Guide 05).
- **Rain false positives:** review placement and shielding; avoid drip lines and runoff.

---

## Troubleshooting data issues on AviationWX

AviationWX performs quality checks on incoming weather data. If we detect problems - missing fields, implausible values, or stale data - we **fail closed** and stop displaying that data rather than show pilots something potentially misleading.

If your station's data isn't appearing correctly on AviationWX:

### Step 1: Check the source directly
First, verify your station is working by checking its native app or website:
- **Tempest**: Check [tempestwx.com](https://tempestwx.com) or the Tempest app
- **Ambient**: Check [ambientweather.net](https://ambientweather.net)
- **Davis WeatherLink**: Check [weatherlink.com](https://www.weatherlink.com)
- **PWSWeather**: Check [pwsweather.com](https://www.pwsweather.com)

If you can see valid, current data there, the station itself is working.

### Step 2: Common issues to check
- **Station offline**: Is the station connected to the internet? Check hub/gateway connectivity.
- **API access revoked**: Did you regenerate API keys? You'll need to provide the new ones.
- **Station moved to a new account**: Account changes may invalidate API credentials.

### Step 3: Contact AviationWX
If your station shows valid data in its native app but AviationWX isn't displaying it correctly, reach out to us. Include:
- Your airport identifier
- What you're seeing (missing data, stale readings, etc.)
- Confirmation that the station's native app shows current data

We can check our logs to see what's happening on our end.

---

## Document what you installed (this makes validation easy later)
- Station type/model:
- Mount type (pole/building/other):
- Approx height above ground:
- Nearby obstructions (describe):
- Connectivity method:
- Who maintains it:
- Install date:

---

## Connecting Your Station to AviationWX

Once your station is installed and producing good data, you need to connect it to AviationWX. This section explains **exactly what information we need** from each weather source type.

### Your Data Helps More Than Just Pilots

When you connect your station to AviationWX, your data doesn't just help pilots at your airport - **we publish quality-checked weather observations to NOAA** to support broader forecasting efforts. Your local weather station becomes part of the national observation network, contributing to better weather predictions for everyone.

### Supported Weather Sources

AviationWX supports six weather station platforms plus METAR-only configuration:

| Source | Best For | Update Speed | Cost |
|--------|----------|--------------|------|
| **Tempest** | New installs, community deployments | ~1 minute | Free API |
| **Ambient Weather** | Budget stations, existing installs | ~1 minute | Free API |
| **Davis WeatherLink** | Professional/long-term installs | 15 min (Basic/free), 5 min (Pro), ~1 min (Pro+) | Free API (Basic); paid for 5 min / 1 min |
| **PWSWeather** | Stations already uploading to PWSWeather.com | ~5 minutes | Free API via AerisWeather |
| **SynopticData** | Backup source, aggregated networks | 5-10 minutes | Free tier available |
| **AWOSnet** | Airports with AWOSnet-hosted AWOS | ~10 minutes | No API key needed |
| **Nav Canada Weather** | Canadian airports (CYAV, CYVR, etc.) | ~5 minutes | No API key needed |
| **METAR Only** | Official airport weather | Hourly | No API key needed |

Davis WeatherLink intervals depend on subscription; see the [Configuration Guide](../docs/CONFIGURATION.md) (Weather Sources) or [WeatherLink v2 Data Permissions](https://weatherlink.github.io/v2-api/data-permissions).

---

## Tempest (WeatherFlow) - What We Need

Tempest is our **recommended default** for new community installs.

### Required Information

| Field | Description | Where to Find It |
|-------|-------------|------------------|
| `station_id` | Your station's numeric ID | Tempest app or web dashboard |
| `api_key` | Personal API token | WeatherFlow developer portal |

### How to Get Your API Token

1. **Create a WeatherFlow account** (if you don't have one) at [tempestwx.com](https://tempestwx.com)
2. **Go to the developer portal**: [tempestwx.com/settings/tokens](https://tempestwx.com/settings/tokens)
3. **Create a new token** - give it a descriptive name like "AviationWX Integration"
4. **Copy the token** - this is your `api_key`

### How to Find Your Station ID

1. Open the Tempest app or go to [tempestwx.com](https://tempestwx.com)
2. Select your station
3. The station ID is in the URL: `tempestwx.com/station/XXXXX` - the number is your `station_id`

### Configuration Example

```json
"weather_source": {
  "type": "tempest",
  "station_id": "149918",
  "api_key": "your-api-token-here"
}
```

### API Documentation

- [WeatherFlow Tempest API](https://weatherflow.github.io/Tempest/api/)

---

## Ambient Weather - What We Need

Good for existing Ambient Weather stations or budget-conscious installs.

### Required Information

| Field | Description | Where to Find It |
|-------|-------------|------------------|
| `api_key` | Your API key | Ambient Weather dashboard |
| `application_key` | Application-specific key | Ambient Weather dashboard |
| `mac_address` | Device MAC address | Which station to pull weather from |

### How to Get Your API Keys

1. **Log into your Ambient Weather account** at [ambientweather.net](https://ambientweather.net)
2. **Go to My Devices**: Click your profile → "My Devices"
3. **Access API Keys**: Click the settings icon next to your device, then "API Keys"
4. **Create keys**: 
   - Click "Create API Key" - this is your `api_key`
   - Click "Create Application Key" - this is your `application_key`

> **Note**: Ambient requires both keys for API access. The `api_key` is tied to your account, and the `application_key` is for rate limiting.

### How to Find Your MAC Address

The MAC address identifies which weather station to pull data from. To find it:

1. **Log into your Ambient Weather account** at [ambientweather.net](https://ambientweather.net)
2. **Go to My Devices**: Click your profile → "My Devices"
3. **Find the MAC address**: It's displayed below your device name (format: `AA:BB:CC:DD:EE:FF`)

Alternatively, the MAC address is printed on a label on your weather station's base unit or console.

### Configuration Example

```json
"weather_source": {
  "type": "ambient",
  "api_key": "your-api-key-here",
  "application_key": "your-application-key-here",
  "mac_address": "AA:BB:CC:DD:EE:FF"
}
```

### API Documentation

- [Ambient Weather API](https://ambientweather.docs.apiary.io/)

---

## Davis WeatherLink - What We Need

Professional-grade option for long-term, high-reliability installs. Davis offers two API versions depending on your hardware.

### Which API Version Do I Need?

| Device Type | API Version | Type Value |
|-------------|-------------|------------|
| **WeatherLink Live** | v2 API | `weatherlink_v2` |
| **WeatherLink Console** | v2 API | `weatherlink_v2` |
| **EnviroMonitor** | v2 API | `weatherlink_v2` |
| **Vantage Connect** | v1 API | `weatherlink_v1` |
| **WeatherLinkIP** | v1 API | `weatherlink_v1` |
| **WeatherLink USB/Serial** | v1 API | `weatherlink_v1` |

> **Note:** If you're unsure which version you need, try v2 first. Most modern Davis setups use the v2 API.

---

### WeatherLink v2 API (Newer Devices)

For WeatherLink Live, WeatherLink Console, and EnviroMonitor systems.

#### What You'll Need to Provide

| Information | Description | How to Get It |
|-------------|-------------|---------------|
| **API Key** | Your v2 API key | WeatherLink account page |
| **API Secret** | Your v2 API secret | WeatherLink account page |
| **Station ID** | Numeric station identifier | We'll look this up for you! |

#### Step-by-Step: Getting Your v2 API Credentials

**Step 1: Log into WeatherLink**

1. Go to [weatherlink.com](https://www.weatherlink.com) and log in
2. Click your **username** in the top-right corner
3. Select **"Account"** from the dropdown menu

**Step 2: Generate Your API Key and Secret**

1. On your Account page, look for the **"API v2 Key"** section
2. If you don't have a key yet, click **"Generate v2 Key"**
3. Copy both values:
   - **API Key** - a long string of letters and numbers
   - **API Secret** - another long string (keep this private!)

> ⚠️ **Important:** The API Secret is only shown once when generated. Save it somewhere safe!

**Step 3: Finding Your Station ID**

The Station ID is a numeric value that identifies your specific station. Unlike the API credentials, the Station ID is not displayed in the WeatherLink web interface.

**Don't worry! We can look it up for you.** Just provide your API Key and API Secret when you submit your station, and we'll automatically discover your Station ID.

Alternatively, if you have multiple stations and need to identify a specific one:
1. Use the [WeatherLink API v2 Playground](https://api.weatherlink.com/v2/stations) (requires your API credentials)
2. Or ask our team and we'll help you find the correct Station ID

#### Example Configuration (v2)

Once you have all the information, your configuration will look like this:

```json
"weather_source": {
  "type": "weatherlink_v2",
  "station_id": "123456",
  "api_key": "abc123def456...",
  "api_secret": "xyz789..."
}
```

---

### WeatherLink v1 API (Legacy Devices)

For older devices: Vantage Connect, WeatherLinkIP, and WeatherLink USB/Serial data loggers.

#### What You'll Need to Provide

| Information | Description | How to Get It |
|-------------|-------------|---------------|
| **Device ID (DID)** | 12-16 character code | Printed on your device |
| **API Token** | Authentication token | WeatherLink account page |

#### Step-by-Step: Getting Your v1 Credentials

**Step 1: Find Your Device ID (DID)**

The Device ID is printed on a physical label on your hardware:

| Device | Where to Look |
|--------|---------------|
| **WeatherLinkIP** | Label on the side of the data logger module |
| **Vantage Connect** | Label inside the plastic cover, or on the manual's front cover |
| **USB/Serial Logger** | Label on the data logger hardware |

The DID looks like: `001D0A12345678` (12-16 alphanumeric characters)

> 💡 **Tip:** Take a photo of the label with your phone so you have it handy!

**Step 2: Get Your API Token**

1. Go to [weatherlink.com](https://www.weatherlink.com) and log in
2. Click your **username** in the top-right corner
3. Select **"Account"** from the dropdown menu
4. Look for the **"API Token"** section
5. Copy your API Token

#### Example Configuration (v1)

```json
"weather_source": {
  "type": "weatherlink_v1",
  "device_id": "001D0A12345678",
  "api_token": "ABCDEF123456..."
}
```

---

### API Documentation

- [WeatherLink v2 API Documentation](https://weatherlink.github.io/v2-api/)
- [WeatherLink v1 API Documentation (PDF)](https://www.weatherlink.com/static/docs/APIdocumentation.pdf)

---

## PWSWeather (via AerisWeather) - What We Need

For stations that upload to PWSWeather.com. Data is accessed through the AerisWeather API.

### Required Information

| Field | Description | Where to Find It |
|-------|-------------|------------------|
| `station_id` | Your PWSWeather station ID | PWSWeather.com dashboard |
| `client_id` | AerisWeather client ID | AerisWeather developer account |
| `client_secret` | AerisWeather client secret | AerisWeather developer account |

### How to Get Your Credentials

**Step 1: Find your PWSWeather Station ID**
1. Log into [PWSWeather.com](https://www.pwsweather.com)
2. Your station ID is visible on your dashboard (e.g., `KMAHANOV10`)

**Step 2: Get AerisWeather API Access**

PWSWeather station owners get **free access** to the AerisWeather API for their station data:

1. **Register at Xweather** (formerly AerisWeather): [xweather.com/signup](https://www.xweather.com/signup)
   - Select the free tier for PWS access
2. **Create an application** in your dashboard
3. **Copy credentials**:
   - `client_id` (also called App ID)
   - `client_secret` (also called App Secret)

### Configuration Example

```json
"weather_source": {
  "type": "pwsweather",
  "station_id": "KMAHANOV10",
  "client_id": "your-aeris-client-id",
  "client_secret": "your-aeris-client-secret"
}
```

### API Documentation

- [Xweather API Documentation](https://www.xweather.com/docs/weather-api/endpoints/observations)
- [PWSWeather.com](https://www.pwsweather.com)

---

## SynopticData - What We Need

Best used as a backup source or for accessing aggregated weather networks (170,000+ stations worldwide).

### Required Information

| What We Need | Description |
|--------------|-------------|
| `station_id` | SynopticData station ID (STID) |
| **Permission** | Your authorization for AviationWX to access your station's data |

> **Note**: AviationWX maintains a central API key with SynopticData. You don't need to create your own API token - just provide the station ID and permission.

### How to Find Your Station ID

1. **Use the Station Selector**: [viewer.synopticdata.com](https://viewer.synopticdata.com)
2. **Search by location** or browse the map
3. **Find your station** and note the **STID** (Station ID)

If you're not sure whether your station is in SynopticData's network, search by location - they aggregate data from many regional and national networks.

### What to Send Us

- Your station's **STID** (e.g., `AT297`)
- **Confirmation** that you own or are authorized to share data from this station
- Your **contact information** for coordination

We'll handle the API integration on our end.

### API Documentation

- [SynopticData Weather API](https://docs.synopticdata.com/services/weather-api)
- [Station Viewer](https://viewer.synopticdata.com)

---

## METAR Only - What We Need

If an airport has official METAR reporting, you can use that as the primary weather source. **No API key required.**

### Required Information

| Field | Description |
|-------|-------------|
| `metar_station` | ICAO airport code (e.g., `KSPB`) |

### Configuration Example

Simple (METAR as only weather source):

```json
"metar_station": "KSPB"
```

Or explicitly declare it as primary:

```json
"weather_source": { "type": "metar" },
"metar_station": "KSPB"
```

### METAR Limitations

- Updates hourly (not real-time like personal weather stations)
- Only available at airports with official weather reporting
- Good backup when local stations fail

### Using METAR as Backup

You can combine a primary station with METAR fallback:

```json
"weather_source": {
  "type": "tempest",
  "station_id": "149918",
  "api_key": "your-key"
},
"metar_station": "KSPB",
"nearby_metar_stations": ["KVUO", "KHIO"]
```

The system will try the primary first, then fall back to METAR stations in order.

---

## AWOSnet - What We Need

If an airport has an AWOS station hosted on [awosnet.com](https://awosnet.com), you can use that as the weather source. **No API key required.**

### Required Information

| Field | Description |
|-------|-------------|
| `station_id` | AWOSnet station identifier (e.g., `ks40`) |

### How to Find Your Station ID

The station ID is the subdomain used on awosnet.com. For example, if your airport's AWOS page is `http://ks40.awosnet.com`, the station ID is `ks40`. Use lowercase.

1. Visit [awosnet.com](https://awosnet.com) or search for your airport's AWOSnet page
2. The URL format is `http://{station_id}.awosnet.com`
3. The station ID is typically the FAA or ICAO identifier in lowercase (e.g., `ks40`, `7s5`)

### Configuration Example

```json
"weather_source": {
  "type": "awosnet",
  "station_id": "ks40"
}
```

### AWOSnet Limitations

- Updates approximately every 10 minutes (not real-time like personal weather stations)
- Only available at airports with AWOSnet-hosted AWOS
- Good option when METAR is hourly but you want more frequent updates

### Reference

- [AWOSnet](https://awosnet.com)

---

## Nav Canada Weather (Canadian Airports)

For Canadian airports, AviationWX can use Nav Canada weather data via the SWOB-ML feed. **No API key required.** This covers many Canadian airports that don't have METAR on aviationweather.gov, including Nav Canada AWOS and manned stations.

### Required Information

| Field | Description |
|-------|-------------|
| `station_id` | 4-letter ICAO code (e.g., `CYAV`, `CYVR`) |
| `type` | `swob_auto` for automated stations, `swob_man` for manned stations |

### Which Type to Use?

- **swob_auto** – Automated stations (e.g., CYAV Winnipeg/St. Andrews, CBBC Bella Bella)
- **swob_man** – Manned stations (e.g., CYVR Vancouver, CYYZ Toronto, CYOW Ottawa)

Most airports use one or the other. Check the [SWOB station list](https://dd.meteo.gc.ca/today/observations/doc/swob-xml_station_list.csv) or try the AUTO endpoint first; if it returns 404, try MAN.

### Configuration Example

```json
"weather_sources": [
  {
    "type": "swob_auto",
    "station_id": "CYAV"
  }
]
```

Or for a manned station:

```json
"weather_sources": [
  {
    "type": "swob_man",
    "station_id": "CYVR"
  }
]
```

### SWOB Limitations

- Only available for Canadian airports in the SWOB-ML feed
- Updates approximately every 5 minutes
- Good option when aviationweather.gov METAR doesn't cover your Canadian airport

### Reference

- [Nav Canada](https://www.navcanada.ca/)
- [SWOB-ML Documentation](https://eccc-msc.github.io/open-data/msc-data/obs_station/readme_obs_insitu_swobdatamart_en/)

---

## Backup Weather Sources

For reliability, you can configure a backup weather source that activates when the primary fails:

```json
"weather_source": {
  "type": "tempest",
  "station_id": "149918",
  "api_key": "your-primary-key"
},
"weather_source_backup": {
  "type": "ambient",
  "api_key": "backup-key",
  "application_key": "backup-app-key"
}
```

The backup activates automatically when the primary source exceeds 5× its normal refresh interval.

---

## Submitting Your Station Information

Once you have gathered all the required information, send it to the AviationWX team via:

1. **Email**: [Contact info in Guide 12]
2. **Include**:
   - Airport identifier (ICAO, FAA, or IATA code)
   - Weather source type (Tempest, Ambient, etc.)
   - All required credentials for your source type
   - Station location description and mount details
   - Your contact information for maintenance coordination

We'll validate the connection, verify data quality, and add your airport to the platform.

---

## Quick Reference: What Each Source Needs

| Source | Required Fields |
|--------|-----------------|
| **Tempest** | `station_id`, `api_key` |
| **Ambient** | `api_key`, `application_key`,  `mac_address` |
| **Davis WeatherLink** | `station_id`, `api_key`, `api_secret` |
| **PWSWeather** | `station_id`, `client_id`, `client_secret` |
| **SynopticData** | `station_id` + permission (we have a central API key) |
| **AWOSnet** | `station_id` (e.g., `ks40`) |
| **Nav Canada Weather** | `station_id` (4-letter ICAO, e.g., `CYAV`), `type` (`swob_auto` or `swob_man`) |
| **METAR Only** | `metar_station` (ICAO code) |

---

## Have a Different Weather Station?

Don't see your weather station platform listed above? **We want to hear from you.**

AviationWX is actively expanding support for additional weather station types. If you have a station that uses a different platform or API, please reach out and let us know:

- **What station hardware** you're using (brand, model)
- **What platform or service** the data goes to (e.g., Weather Underground, WeatherCloud, Open Weather Map, etc.)
- **Whether an API is available** to access the data programmatically
- **Your airport location** and use case

We're especially interested in:
- Stations already deployed at airports that pilots rely on
- Platforms with public APIs that could benefit multiple airports
- Regional or specialized weather networks common in aviation

Even if we can't add support immediately, knowing what the community uses helps us prioritize future development.

**Contact us**: Open an issue on GitHub or reach out via the contact information in Guide 12.

---

## Next
Next guide covers **how to submit a new airport feed to AviationWX for review and inclusion**, including the validation process for image quality, data sanity, and operational expectations.
