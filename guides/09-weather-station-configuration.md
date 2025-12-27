# 09 - Weather Station Configuration (Tempest / Davis / Ambient)

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

### Practical configuration defaults
- Start with a **moderate update cadence** (the platform can handle faster, but stable is better than fancy)
- If you see frequent dropouts, fix connectivity first before chasing settings

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

### Practical notes
- Davis installs tend to reward careful mounting and cable hygiene
- If you have an airport or community already familiar with Davis, lean into that

---

## Ambient WS‑2902 setup (budget-friendly option)

### Why Ambient can be useful
- a common "prove value" station
- often the fastest path to getting basic local conditions online

### Install checklist (Ambient)
- ☐ Prioritize wind exposure and representative placement (same rules apply)
- ☐ Confirm the station can reliably publish readings through your chosen path
- ☐ Validate readings over 24-72 hours

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

## Document what you installed (this makes validation easy later)
- Station type/model:
- Mount type (pole/building/other):
- Approx height above ground:
- Nearby obstructions (describe):
- Connectivity method:
- Who maintains it:
- Install date:

## Next
Next guide will cover **how to submit a new airport feed to AviationWX for review and inclusion**, including the validation process for image quality, data sanity, and operational expectations.

