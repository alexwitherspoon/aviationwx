# 15 - Weather sensor standards and maintenance

## Goal
Give airport sponsors and maintainers a fact-based picture of how official automated weather systems are specified, measured, and kept in service, how that differs from regulatory VFR minima, and what practical expectations make sense when you publish any field weather feed through AviationWX.

AviationWX can integrate many kinds of stations and APIs across the whole spectrum, from hobby and prosumer kits through light-industrial or bespoke field installs up to official surface weather products, including METAR-based sources, national automated surface observations where your airport has access (for example ASOS in the United States), and certified AWOS or other approved automated stations where installed and supported in your dashboard configuration.

This guide spends extra space on consumer-grade examples (Tempest, Davis Vantage Pro2, Ambient WS‑2902 class hardware) because they are common in volunteer airport projects and vendors publish plain-language limits pilots should know. That focus is not a product limit: whatever you connect, siting, exposure, calibration, and transparency still decide whether the numbers are safe to lean on as **supplemental** context.

### Why so much U.S. detail?
AviationWX's maintainers can cite FAA and NWS primary sources cleanly for AWOS/ASOS. Treat that material as a worked example of concerns every jurisdiction shares: traceable sensors, representative siting, quality control, maintenance records, and clear separation between approved aviation weather products and supplemental community feeds. Your country wires the same broad ideas into national law, ICAO Annex 3 implementation, and met service provider rules in different ways.

This guide is educational. It is not legal or flight instruction. AviationWX dashboards remain **supplemental** to official products, briefings, and on-board judgment (see the [Guides home README](README.md)).

---

## Terms that are easy to mix up

### AWOS (Automated Weather Observing System)
In the U.S., non-Federal AWOS programs are described in FAA Advisory Circular AC 150/5220-16E (*Automated Weather Observing Systems (AWOS) for Non-Federal Applications*). Systems that follow that path can become an **FAA-approved** source of aviation weather information when manufactured, installed, commissioned, and maintained under the program. The AC defines AWOS as an air navigation facility that automatically measures parameters, prepares an observation, and disseminates it (for example by voice on VHF, telephone, or other approved paths). See the AC purpose and definition sections in the [PDF on the FAA document library](https://www.faa.gov/documentLibrary/media/Advisory_Circular/AC_150_5220-16E_w-chg1.pdf).

### ASOS (Automated Surface Observing System)
ASOS is the primary U.S. automated surface observing network operated for public and aviation needs; development was a joint FAA / NWS / DoD effort, and the FAA provides a national station map and links into observation products on its [ASOS/AWOS surface weather page](https://www.faa.gov/air_traffic/weather/asos). Detailed sensor engineering descriptions are published by the National Weather Service on its [ASOS documentation site](https://www.weather.gov/asos/TechnicalOverview.html).

### ICAO (international context)
ICAO Annex 3 sets international standards and recommended practices for meteorological service for air navigation, including observation and reporting requirements. National programs (including the U.S.) implement those SARPs through their own regulations, orders, and equipment programs. The current Annex 3 is sold as a publication on the [ICAO Annex 3 store listing](https://store.icao.int/en/annexes/annex-3) (editions and store URLs change over time).

---

## What a U.S. non-Federal AWOS program covers (high level)

Non-Federal AWOS guidance in AC 150/5220-16E spans the full lifecycle: design, manufacture, procurement, installation, activation, use, and maintenance, including site criteria, commissioning, annual inspection, and ongoing validation so the system continues as an approved source. The AC explicitly states it is **not a regulation by itself**, but it is an acceptable means of compliance for certain grant- and PFC-funded projects and for parties seeking an FAA-approved AWOS. Maintenance concepts include operating within approved tolerances, retaining technical performance records, and removing or NOTAMing sensors that are out of tolerance. For Chapter 1 and maintenance chapters, use the AC PDF linked under AWOS (Automated Weather Observing System) in Terms.

### FAA siting and agency maintenance orders (where to look)
- JO 6560.20C (*Siting Criteria for Automated Weather Observing Systems (AWOS)*): index [Order JO 6560.20C](https://www.faa.gov/regulations_policies/orders_notices/index.cfm/go/document.current/documentNumber/6560.20). Implements NOAA FCM-S4-1994 for baseline siting, plus additional aviation-specific siting for FAA applications. Full order text may be restricted to the FAA network; sponsors in formal AWOS programs should obtain current directives through FAA channels.
- JO 6560.13F (*Maintenance of Aviation Meteorological Systems*): index [Order JO 6560.13F](https://www.faa.gov/regulations_policies/orders_notices/index.cfm/go/document.information/documentID/1040651). Agency maintenance policy at a high level. Embedded PDFs may be restricted to the FAA network.

---

## What U.S. ASOS measures and how (public NWS references)

The NWS ASOS documentation explains sensor suite design, sampling, and reporting; the subsections below are illustrative only. Always defer to current NWS pages and manuals for authoritative numbers.

### Pressure
ASOS uses redundant digital barometers (for example three independent transducers at towered locations and two elsewhere), with algorithm checks before a value is released. Published characteristics on the NWS site include range 16.9–31.5 inHg, accuracy ±0.02 inHg, and fine measurement and reporting resolution. See [Barometric Pressure Sensor](https://www.weather.gov/asos/BarometricPressureSensor.html).

### Wind
The ASOS ultrasonic ice-free wind sensor uses electro-optical timing methods; winds 2 knots or less are reported as calm. Wind direction in METAR is reported to 10° increments, with described 2-minute averages built from shorter samples. See [Wind Sensor](https://www.weather.gov/asos/WindSensor.html) (includes a performance table on that page).

### Temperature and dew point
Ambient temperature uses a platinum RTD in aspirated airflow. Dew point uses a chilled mirror hygrometer with automated optical detection and periodic self-checks (including mirror cleaning / recalibration logic described on the page). NWS states the hygrometer meets NWS specifications expressed as RMSE and maximum error versus dew-point depression. See [Ambient/Dew Point Temperature Sensor](https://www.weather.gov/asos/AmbientDewPointTemperature.html).

### Broader ASOS accuracy context
An NWS climate-focused overview notes that, for primary climate sensors, temperature spans roughly -80 °F to +130 °F with accuracy better than ±2 °F, and the rain gauge resolves to 0.01 inch, alongside continuous software quality checks. See [ASOS & Climate Observations (PDF)](https://www.weather.gov/media/owlie/2018-ASOS.pdf).

---

## "VFR sensor tolerances" - what is actually regulated?

14 CFR § 91.155 defines basic VFR weather minimums in the United States using flight visibility, distance from clouds, and related rules for takeoff and landing in certain surface areas. It does **not** assign "tolerance bands" to personal weather stations. Authoritative text is in the CFR; eCFR hosts a current copy: [14 CFR 91.155](https://www.ecfr.gov/current/title-14/chapter-I/subchapter-F/part-91/subpart-B/section-91.155). Elsewhere, the same pilot judgment problem exists, but the numbers and definitions live in your state's rules of the air and related publications, not in the U.S. CFR.

### Practical meaning for pilots
- VFR compliance is judged against visibility and cloud relationships in flight (and reported ground visibility where the rules require it for takeoff/landing in certain areas), **not** against an **unofficial** field feed on a community website, regardless of hardware tier.
- A field station can still be valuable for local trends and microscale cues when sited and maintained well, but it must stay in the **supplemental** role relative to METAR/ATIS, TAFs, PIREPs, and what you can see from the cockpit.

---

## Industrial advisory aviation (Dyacon MS-100 series)

[Dyacon](https://dyacon.com/) manufactures MS-100 series stations (MS-120 through MS-135) for commercial, industrial, and **advisory aviation** use. The [MS-130](https://dyacon.com/Ms-130/) is a common small-airport configuration: solar-powered, cellular upload to [DyaconLive](https://dyacon.com/dyaconlive/), optional SMS METAR-style text reports, and Modbus for local systems. Dyacon describes these as advisory stations - a practical middle ground between consumer hardware and FAA-certified AWOS, not a substitute for type-certified automated observing programs.

Strengths: industrial build, documented [airport siting guidance](https://dyacon.com/wp-content/uploads/57-6040-DOC-Quick-start%20Guide-MS-100.pdf), autonomous remote operation, DyaconLive Aviation Mode for local flight planning (altimeter, density altitude, estimated cloud base in the portal).

Limitations: **not** FAA-certified AWOS/ASOS; standard package measures wind, temperature, humidity, and barometric pressure (rain gauge optional). DyaconLive portal and SMS outputs may include **estimated** cloud base in METAR-style text, but there are no ceiling or visibility **instruments**. AviationWX's DyaconLive integration reads API sensor fields only - treat ceiling/visibility on the dashboard as coming from METAR or another source, not from Dyacon. Update cadence is about 10 minutes on clock-aligned buckets, slower than ASOS engineering streams.

Calibration and care: follow Dyacon maintenance features in DyaconLive+ when enabled; inspect mount, cables, and rain funnel on the same quarterly rhythm as other field stations; compare wind and pressure trends to nearby official METAR during benign weather. Product hub: [Dyacon aviation weather stations](https://dyacon.com/aviation-weather-station/).

---

## Consumer-grade examples (common, and especially limitation-heavy)

Official AWOS/ASOS programs combine type-certified sensors, controlled siting, redundancy, and scheduled maintenance. Any field sensor you attach to AviationWX still inherits the same physics and exposure problems: wind shadow, radiation heating, wetting losses in precipitation, drift, and cabling faults. Higher-grade gear may add better shields, faster sampling, serviceable modules, or documented calibration procedures, but it is **never** a substitute for good siting, periodic checks, and honest labeling when a channel is weak. The Dyacon, Tempest, Davis, and Ambient examples below cover common supported paths; we spell out vendor limits most often where pilots can mistake a slick dashboard for AWOS-class data.

Consumer and compact integrated stations usually optimize for cost, ease of install, and home use, with accuracy statements that assume controlled or ideal conditions and good siting. Expect wider real-world error than a national automated station when mounts are improvised.

Cross-check your exact model and firmware revision in the vendor manual (or OEM spec sheet for industrial sensors) before quoting numbers in airport documents.

### WeatherFlow / Tempest
Strengths: integrated design, no moving parts in the wind path, continuous wind sampling, haptic rain with fast rain onset cues, optional lightning, straightforward APIs for sharing.

Limitations (manufacturer): haptic rain does **not** report snow, sleet, graupel, hail, fog, dew, or extremely light rain/mist the same way a weighing or tipping gauge might. Vibration can create false rain; wind accuracy can degrade in cold extremes and with flow obstructions in the sonic path; very high wind may have limited sampling integrity. See the [Tempest spec sheet PDF](https://tempest.earth/wp-content/uploads/2016/05/Tempest_Spec-Sheet_220301-web-view.pdf) and WeatherFlow's notes on calibration, rain, and siting linked from that document.

Published accuracy (Tempest device, spec sheet): air temperature ±0.2 °C, relative humidity ±2 %, station pressure ±1 mbar, wind speed the greater of ±0.5 mph or ±2 %, wind direction ±5°, with rain-rate / accumulation accuracy stated at ±10 % under the sheet's test notes. The sheet states that accuracy is referenced to professional co-located instrumentation under controlled conditions designed to mimic WMO-style exposure, and that local siting dominates field error.

Calibration and care (manufacturer-led): factory calibration plus automated field calibration where applicable; manual recalibration is described as rare. Follow WeatherFlow's current calibration and maintenance articles (for example [Technical Specifications](https://help.tempest.earth/hc/en-us/articles/208644807-Technical-Specifications)) and keep hub firmware current.

### Davis Vantage Pro2 (and Plus options)
Strengths: modular ISS, long service history in aviation-adjacent communities, optional fan-aspirated radiation shields that materially reduce solar-induced temperature bias compared with passive shields, well-documented consumables (supercap, battery), optional sonic anemometer upgrade path on newer bundles.

Limitations: mechanical anemometer wear, cable and connector integrity, tipping-bucket errors in wind-driven rain and snow, temperature errors if the shield is undersized for the site's sun/wind environment.

Calibration and care (operator tasks): use the console / WeatherLink workflow to set barometric offset against a trusted local altimeter setting reference; verify ISS level and wind vane indexing per Davis install manuals; clean the rain funnel regularly; inspect wind bearings and cups; consider annual checks if the station supports safety-adjacent decisions. Start from Davis's own [Vantage Pro2 product hub](https://www.davisinstruments.com/pages/vantage-pro2) and their support knowledge base for your exact ISS and console generation.

### Ambient WS‑2902 class (integrated array)
Strengths: low cost, fast path to 'something live at the field', solar-powered array, common AmbientWeather.net connectivity.

Limitations: compact all-in-one arrays are easy to mount in low or turbulent wind positions; update cadence to cloud services is often tens of seconds, which is slower than ASOS's continuous one-minute engineering stream and different from METAR wind averaging; ultrasonic or cup wind sensors in this tier can show larger wind errors in gusty, obstructed, or roof-edge flows. See Ambient's product and FAQ material (for example [WS‑2902 product page](https://ambientweather.com/amws2902.html)) and the model operator manual (linked from that page as WS‑2902D USER MANUAL).

Calibration and care: follow Ambient's guidance for leveling the wind vane / UV fixture, keeping the solar panel clear, and power-cycling / reconnecting when network stacks drift; use the console or vendor app offsets only when the vendor documents a traceable adjustment.

---

## Maintenance mindset for field stations

Use the same disciplines approved automated programs emphasize, adapted to your team's capacity. The list applies to every integrated sensor class, not only consumer kits:

- Siting first: most "sensor error" is exposure error. Revisit [02 - Location & Siting](02-location-and-siting.md), [03 - Mounting Options](03-mounting-options.md), and [09 - Weather Station Configuration](09-weather-station-configuration.md).
- Integrity checks: after storms, confirm mount torque, cable strain relief, bird nests, and rain funnel debris.
- Reference comparisons: when a nearby METAR exists, periodically compare wind direction, sea-level pressure trend, and temperature during benign weather. Large fixed offsets usually mean siting, shielding, or a failed sensor, not "random noise."
- Change control: log firmware updates, hardware swaps, and moves; they change baselines for anyone interpreting history.
- Transparency: if a sensor is suspect, say so locally and fix it; AviationWX already treats staleness explicitly in software, but humans should not over-trust a known-bad leg.

---

## Minimum quality bar AviationWX asks of participating stations

AviationWX does not certify your station as an AWOS or duplicate FAA type certification. We do ask sponsors to meet a minimum stewardship bar so dashboards stay honest:

1. Representative placement following Guides 02, 03, and 09, with wind exposed as well as the site allows; document known limitations (for example "wind reads low behind hangar row").
2. Stable connectivity and power so data is not chronically stale; designate a named maintainer pilots can reach.
3. Post-install validation for at least 24–72 hours, including a sanity check against a trusted reference (nearby ASOS/METAR or calibrated portable) when available.
4. Periodic checks on a quarterly cadence at minimum (sooner after major weather): physical inspection, cleaning, and a quick reference comparison during steady weather.
5. Prompt repair or takedown when a sensor channel is clearly wrong; do not leave misleading wind or pressure online if you can avoid it.
6. Clear expectations in local pilot communication: the feed is **supplemental**, not a substitute for official weather sources or legal VFR minima in your jurisdiction (in the U.S., that includes § 91.155-style visibility and cloud clearance judgment).

Meeting this bar does **not** imply FAA, ICAO, or NWS endorsement. It does align whatever sensors you publish with the **trustworthy enough** standard described in Guide 09, with extra scrutiny warranted for compact consumer arrays where limits are tightest.

---

## Outside the United States: where to look next

Use this list as starting points, not an exhaustive map of every civil aviation authority. In every region, three layers usually appear:

1. ICAO SARPs (especially Annex 3 for meteorological service) as the international baseline your state implements.
2. National regulations for met service providers, aerodrome operators, and rules of the air (VFR minima live here).
3. WMO technical guidance on instruments, siting, calibration, and quality management, which national programs and manufacturers still lean on even when your station is not "official."

| Region / organization | What it is | Starting link |
| --- | --- | --- |
| ICAO | Annex 3 (MET service), international codes and quality expectations | [ICAO Annex 3 store listing](https://store.icao.int/en/annexes/annex-3) |
| WMO | *Guide to Instruments and Methods of Observation* (WMO-No. 8): measurement, AWS chapters, calibration concepts; complements Annex 3 with engineering detail | [WMO-No. 8 hub](https://community.wmo.int/guide-instruments-and-methods-of-observation-wmo-no-8) |
| European Union | Commission Implementing Regulation (EU) 2017/373 (ATM/ANS common requirements, including meteorological services) and EASA Part-MET acceptable means of compliance and guidance | [EUR-Lex 2017/373](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32017R0373); [EASA Part-MET overview](https://www.easa.europa.eu/en/acceptable-means-compliance-and-guidance-material-group/part-met); [AMC & GM to Part-MET (download index)](https://www.easa.europa.eu/en/downloads/136122/en) |
| United Kingdom | CAA CAP 746 - requirements and procedures for meteorological observations at aerodromes (AUTO METAR topics appear in recent editions) | [CAP 746 publication page](https://www.caa.co.uk/cap746) |
| Canada | MANOBS (Manual of Surface Weather Observation Standards) aligned with WMO/ICAO; aviation regulatory references appear in the CARs for who may provide services | [MANOBS (Canada.ca)](https://www.canada.ca/en/environment-climate-change/services/weather-manuals-documentation/manobs-surface-observations.html); [Canadian Aviation Regulations (SOR/96-433)](https://laws-lois.justice.gc.ca/eng/regulations/sor-96-433/index.html) |

If your country is not listed, a practical search pattern is: `"(your CAA name)" meteorological observation aerodrome` or `"Annex 3" (your language) aviation meteorology implementing"`, plus your national hydromet service manual for surface observation standards.

---

## Further reading (official and vendor)

| Topic | Link |
| --- | --- |
| Non-Federal AWOS AC | [AC 150/5220-16E PDF](https://www.faa.gov/documentLibrary/media/Advisory_Circular/AC_150_5220-16E_w-chg1.pdf) |
| FAA ASOS/AWOS station map | [FAA ASOS page](https://www.faa.gov/air_traffic/weather/asos) |
| NWS ASOS technical library | [ASOS sensor suite design](https://www.weather.gov/asos/TechnicalOverview.html) |
| Basic VFR weather minimums | [14 CFR 91.155 (eCFR)](https://www.ecfr.gov/current/title-14/chapter-I/subchapter-F/part-91/subpart-B/section-91.155) |
| ICAO Annex 3 publication | [ICAO Annex 3 store listing](https://store.icao.int/en/annexes/annex-3) |
| Tempest engineering specs | [Tempest spec sheet PDF](https://tempest.earth/wp-content/uploads/2016/05/Tempest_Spec-Sheet_220301-web-view.pdf) |
| Dyacon advisory aviation stations | [Dyacon aviation weather stations](https://dyacon.com/aviation-weather-station/) |
| WMO instruments guide (global) | [WMO-No. 8 hub](https://community.wmo.int/guide-instruments-and-methods-of-observation-wmo-no-8) |

For EASA, UK CAA, and Canada starting points, see the international table above.

If you find a broken link or a revised edition, open an issue or PR so this guide stays current.
