# MADIS HFMETAR classic netCDF experiment

Prototype for reading NOAA MADIS HFMETAR data in pure PHP, following the
aviationwx weather adapter pattern. HFMETAR carries Federal ASOS
observations at 5-minute marks (12 per hour per station), which is the
dense wind feed that makes the compass wind-rose petals work for airports
that otherwise only have hourly METAR.

Public anonymous source (no API key):
https://madis-data.ncep.noaa.gov/madisPublic1/data/LDAD/hfmetar/netCDF/

## What is here

| File | Purpose |
|------|---------|
| `lib/madis-hfmetar/netcdf-classic.php` | Minimal classic netCDF (CDF-1) reader in PHP. Header parser, record layout, per-column reads, missing-value handling. Rejects CDF-2/CDF-5 and corrupt files loudly. |
| `lib/madis-hfmetar/madis-hfmetar.php` | Turns a netCDF HFMETAR file into per-station observations in AviationWX standard units (celsius, knots, inHg, statute miles). |
| `lib/weather/adapter/madis-hfmetar-v1.php` | Weather adapter mock implementing the same contract as the METAR adapter. `buildUrl()` returns the current UTC hour's bulk file; `parseResponse()` decodes and builds a `WeatherSnapshot` for the configured station. |
| `scripts/madis-hfmetar-inspect.php` | CLI to inspect a file: `php scripts/madis-hfmetar-inspect.php <file.gz> KSPB` or `--header` for structure. |
| `tests/Unit/MadisHfmetarNetcdfTest.php` | PHPUnit tests against a real one-hour sample. |
| `tests/Fixtures/madis/hfmetar-sample.gz` | One completed hour (2026-11-20 23:00 UTC, 12,239 records, 1,026 stations). Public domain NOAA data. |

## How to run

```bash
# Inspect a station in the sample file
php scripts/madis-hfmetar-inspect.php tests/Fixtures/madis/hfmetar-sample.gz KSPB

# Header structure
php scripts/madis-hfmetar-inspect.php tests/Fixtures/madis/hfmetar-sample.gz --header

# Tests
composer install
vendor/bin/phpunit tests/Unit/MadisHfmetarNetcdfTest.php
```

## Facts learned from the real feed

- The hourly file is rewritten roughly every 5-10 minutes as observations
  arrive; each 5-minute mark appears for ~1,000 stations within 15-25
  minutes of the mark. Median receive lag is ~2.4 minutes.
- Coverage is Federal ASOS only: 1,025 stations CONUS + Alaska + territories.
  AWOS stations are not in HFMETAR.
- Of the 54 airports on the aviationwx.org public list, 8 have a direct
  ICAO match (KBOI, KEUL, KHIO, KLLJ, KMYL, KPUW, KSPB, KTWF) and 21 sit
  within 25 km of a Federal ASOS.
- The realtime LDAD path retains ~5 days of hourly files, which is enough
  to warm the wind-rose history on a forward rolling basis.

## Open items before this becomes a real source

- `buildUrl()` returns the whole-hour bulk file; a production integration
  should share one download across airports (same pattern as the METAR
  bulk refresh) rather than fetching per airport.
- The netCDF reader is CDF-1 only. MADIS mesonet files are also CDF-1,
  but verify before pointing it at other datasets.
- Wind direction in HFMETAR is true north; the adapter passes it through
  and the existing magnetic conversion applies downstream.
