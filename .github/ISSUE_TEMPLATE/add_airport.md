---
name: Add Airport Request
about: Request to add a new airport to AviationWX.org
title: '[AIRPORT] '
labels: airport-request
assignees: ''
---

## Airport Information

**Airport Name**: <!-- e.g., Scappoose Airport -->

**ICAO Code**: <!-- e.g., KSPB -->

**Requested Airport ID** (for subdomain): <!-- e.g., kspb -->

**Location**:
- City, State: <!-- e.g., Scappoose, Oregon -->
- Latitude: <!-- e.g., 45.7710278 -->
- Longitude: <!-- e.g., -122.8618333 -->
- Elevation (feet): <!-- e.g., 58 -->
- Timezone: <!-- e.g., America/Los_Angeles (see CONFIGURATION.md for timezone options) -->

## Weather Source

**Weather Source Type**: <!-- Choose one: Tempest, Ambient, or METAR -->

<!-- If using Tempest: -->
- Station ID: <!-- (leave blank if you'll provide in private message) -->
- API Key: <!-- ⚠️ **DO NOT POST YOUR API KEY HERE** - You can provide it privately or we can set it up later -->

<!-- If using Ambient: -->
- API Key: <!-- ⚠️ **DO NOT POST YOUR API KEY HERE** -->
- Application Key: <!-- ⚠️ **DO NOT POST YOUR API KEY HERE** -->

<!-- If using METAR only: -->
- METAR Station Code: <!-- e.g., KSPB (usually same as ICAO) -->

## Runway Information

<!-- List all runways -->
<!-- Example format: -->
- **Runway Name**: 15/33
  - Heading 1: 152
  - Heading 2: 332

<!-- For parallel runways, include L/C/R designations: -->
- **Runway Name**: 28L/10R
  - Heading 1: 280
  - Heading 2: 100
- **Runway Name**: 28R/10L
  - Heading 1: 280
  - Heading 2: 100

<!-- Add more runways as needed -->

## Frequencies (Optional)

<!-- Fill in available frequencies -->
- CTAF: <!-- e.g., 122.8 -->
- ASOS/ATIS: <!-- e.g., 135.875 -->
- Approach: <!-- e.g., 124.35 -->
- Departure: <!-- e.g., 133.0 -->
- Clearance: <!-- e.g., 121.65 -->

## Services (Optional)

<!-- Check all that apply -->
- [ ] Fuel available
- [ ] Repairs available
- [ ] 100LL available
- [ ] Jet A available

## Webcams (Optional)

<!-- For each webcam, provide: -->
<!-- ⚠️ **IMPORTANT**: If webcams require authentication, DO NOT post usernames/passwords here. Provide them privately or we can set them up later. -->

**Webcam 1:**
- Name: <!-- e.g., North Runway Camera -->
- URL: <!-- e.g., https://example.com/video.mjpg or rtsp://camera.example.com:554/stream -->
- Type: <!-- MJPEG, RTSP, RTSPS, or Static Image -->
- Position: <!-- e.g., north, south, east, west -->
- Partner Name: <!-- Organization name if applicable -->
- Partner Link: <!-- Website URL if applicable -->
- Refresh Interval (seconds): <!-- e.g., 60 (optional, defaults to 60) -->

<!-- Add more webcams as needed (up to 6 supported) -->

## Additional Links

**AirNav URL**: <!-- e.g., https://www.airnav.com/airport/KSPB -->

**Nearby METAR Stations** (optional): <!-- e.g., KVUO, KHIO (for fallback weather data) -->

## Additional Notes

<!-- Any other relevant information about this airport -->

## Credentials & Sensitive Information

<!-- ⚠️ **SECURITY NOTE**: -->
<!-- - DO NOT post API keys, passwords, or credentials in this issue -->
<!-- - You can share sensitive credentials privately via direct message or encrypted communication -->
<!-- - We can also set up credentials later after the airport structure is added -->

---

**Ready to Add?** Once this issue is approved, we'll add the airport configuration to `airports.json` and set up the necessary DNS/domain configuration.

