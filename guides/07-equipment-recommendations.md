# 07 - Equipment Recommendations

## Goal
Choose equipment that:
- produces **useful, trustworthy observations**,
- is **simple to install and maintain**,
- fits the airport’s **budget and connectivity**, and
- can be supported by a volunteer or airport team over time.

This guide is intentionally practical. It does not try to cover every possible vendor or model.

## The “must have” capabilities (don’t buy gear without these)
### Cameras (choose at least one supported upload method)
AviationWX works best with **still images** (not continuous video). For cameras, you want at least one of:
- **FTP / FTPs / SFTP uploads** (the camera can push a JPEG on a schedule)
- **RTSP** video stream (we can extract still images)
- **JPEG snapshot URL** (a URL that returns a current still image)

**Important:** many cheaper cameras-especially many **battery-powered** models-do not support these features (or hide them behind paid cloud plans). Those are usually a poor fit for AviationWX.

### Weather stations (reliable local data)
A weather station should be able to provide the basics reliably:
- wind speed + direction
- temperature
- pressure
- precipitation (optional but very useful)
- optional: humidity, lightning, solar, etc.

**Wind sampling matters:** whenever possible, mount the wind sensor with **unobstructed exposure**, ideally **at the top of an approved pole, building, or structure**.

## Recommended weather stations (in order)
These are the recommendations we’ve tested and used as practical defaults.

### 1) Tempest Weather System (recommended default)
**Why it’s the default**
- good data quality for the cost
- relatively easy to install
- works well for “community scale” airport deployments

**Good for**
- most small airports
- volunteer-led installs
- places where you want good coverage without complex infrastructure

### 2) Davis Vantage Pro2 (professional-grade alternative)
**Why choose it**
- widely used and respected hardware
- strong long-term durability when installed and maintained well

**Good for**
- airports that want a more “traditional” pro-grade station
- locations with an existing Davis community or support network

### 3) Ambient Weather WS-2902 (budget-friendly option)
**Why choose it**
- often the lowest cost path to “good enough” local conditions
- good for pilots and community groups to prove value

**Good for**
- pilot projects / trials
- airports with very tight budgets

## Recommended cameras (tested)
### Reolink (recommended for cost + robustness)
We recommend Reolink models that support scheduled uploads (or RTSP) without depending on cloud features.

**Tested models**
- **Reolink RLC-810WA**
- **Reolink RLC-510A**

**Why these work well**
- reliable still image quality
- supports practical integration methods (e.g., FTP/FTPs or RTSP depending on configuration)
- good value per dollar

### UniFi Protect cameras (great when the airport already uses UniFi)
If an airport, FBO, or hangar already uses UniFi/Protect, these can be a strong choice because the ecosystem is well-managed and maintainable.

**Why they can be a good fit**
- strong manageability and reliability when a UniFi environment already exists
- easy ongoing operations for a local IT-minded maintainer

## How often should it update?
Typical configurations:
- **every 1-15 minutes** (common default range)
- faster updates can help in rapidly changing conditions, but cost more in bandwidth/power and can increase operational complexity

AviationWX favors fewer **high-quality** still images over many low-quality frames.

## A practical “equipment recipe” (common pattern)
A simple, high-impact setup often looks like:
- **1-4 cameras** (still images every 1-15 minutes)
- **1 weather station**
- optional: a small network device (switch/router/LTE modem), depending on your internet choice

## How AviationWX uses weather data (plain language)
AviationWX can display conditions from:
- **official airport weather sources** when available (e.g., **ASOS/AWOS**), and/or
- **local on-site sensors** installed by the airport/community (recommended when official sensors aren’t available nearby)

When multiple sources exist, the goal is to present the most useful “right now” view while being clear about where the data came from.

## Integration notes (keep it simple)
- For cameras, scheduled image upload (FTP/FTPs/SFTP) is often the simplest “set it and forget it” approach - and often yields **higher still-image quality** than sampling a video stream.
- RTSP and snapshot URLs can work well too, especially when the hardware supports them cleanly.
- Prefer equipment that does not require a paid cloud plan to access the data you need.

## Buying guidance (avoid common mistakes)
- Don’t buy “consumer cloud-only” cameras for this project.
- Prefer models that can operate reliably in cold/wet conditions.
- Prioritize **maintainability**: a slightly more expensive camera that works for years is usually cheaper than a “cheap” camera that fails often.
- If the airport already has standard equipment or vendors, AviationWX is designed to integrate-don’t force a rewrite of existing infrastructure.

## Decision (write it down)
- Weather station choice:
- Camera choice(s):
- Update frequency (minutes):
- Upload method (FTP/FTPs/SFTP, RTSP, snapshot URL):
- Who will maintain it and where spare parts live:

## Next
Move to **Camera Configuration** (Guide 08), **Weather Station Configuration** (Guide 09), or loop back and confirm your choices:
- permission (Guide 01)
- siting (Guide 02)
- mounting (Guide 03)
- power (Guide 05)
- internet (Guide 06)
