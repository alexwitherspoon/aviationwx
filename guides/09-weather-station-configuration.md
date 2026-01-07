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

AviationWX supports five weather station platforms plus METAR-only configuration:

| Source | Best For | Update Speed | Cost |
|--------|----------|--------------|------|
| **Tempest** | New installs, community deployments | ~1 minute | Free API |
| **Ambient Weather** | Budget stations, existing installs | ~1 minute | Free API |
| **Davis WeatherLink** | Professional/long-term installs | ~1 minute | Free API |
| **PWSWeather** | Stations already uploading to PWSWeather.com | ~5 minutes | Free API via AerisWeather |
| **SynopticData** | Backup source, aggregated networks | 5-10 minutes | Free tier available |
| **METAR Only** | Official airport weather | Hourly | No API key needed |

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
| `mac_address` (optional) | Device MAC address | Use if you have multiple stations |

### How to Get Your API Keys

1. **Log into your Ambient Weather account** at [ambientweather.net](https://ambientweather.net)
2. **Go to My Devices**: Click your profile → "My Devices"
3. **Access API Keys**: Click the settings icon next to your device, then "API Keys"
4. **Create keys**: 
   - Click "Create API Key" - this is your `api_key`
   - Click "Create Application Key" - this is your `application_key`

> **Note**: Ambient requires both keys for API access. The `api_key` is tied to your account, and the `application_key` is for rate limiting.

### Configuration Example

```json
"weather_source": {
  "type": "ambient",
  "api_key": "your-api-key-here",
  "application_key": "your-application-key-here"
}
```

With specific device (if you have multiple):

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

Professional-grade option for long-term, high-reliability installs.

### Required Information

| Field | Description | Where to Find It |
|-------|-------------|------------------|
| `station_id` | Your WeatherLink station ID | WeatherLink developer portal |
| `api_key` | v2 API key | WeatherLink developer portal |
| `api_secret` | v2 API secret | WeatherLink developer portal |

### How to Get Your API Credentials

1. **Log into WeatherLink**: [weatherlink.com](https://www.weatherlink.com)
2. **Go to the developer portal**: [weatherlink.com/api-explorer](https://www.weatherlink.com/api-explorer)
3. **Generate API v2 credentials**:
   - Click "Generate v2 API Key"
   - Copy both the `API Key` and `API Secret`
4. **Find your Station ID**:
   - In the API Explorer, find your station in the station list
   - Note the station ID number

### Configuration Example

```json
"weather_source": {
  "type": "weatherlink",
  "station_id": "123456",
  "api_key": "your-api-key-here",
  "api_secret": "your-api-secret-here"
}
```

### API Documentation

- [WeatherLink v2 API](https://weatherlink.github.io/v2-api/)

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
| **Ambient** | `api_key`, `application_key`, (optional: `mac_address`) |
| **Davis WeatherLink** | `station_id`, `api_key`, `api_secret` |
| **PWSWeather** | `station_id`, `client_id`, `client_secret` |
| **SynopticData** | `station_id` + permission (we have a central API key) |
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
