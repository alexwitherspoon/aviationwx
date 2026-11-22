# MADIS HFMETAR source

MADIS HFMETAR is a NOAA data feed of Federal ASOS observations at 5-minute
marks (12 per hour per station), delivered as hourly classic netCDF files.
This package reads those files in pure PHP and wires the data into
AviationWX as a weather source, so airports with a co-located ASOS get
denser wind-rose petals, faster current wind, and 5-minute visibility and
ceiling instead of hourly METAR.

Public anonymous source (no API key):
https://madis-data.ncep.noaa.gov/madisPublic1/data/LDAD/hfmetar/netCDF/

## How it works

- `netcdf-classic.php` parses the CDF-1 header and record layout and reads
  per-record columns, including missing-value sentinels.
- `madis-hfmetar.php` decodes one station's observations into AviationWX
  standard units (celsius, knots, inHg, statute miles) and derives ceiling
  and cloud cover from the sky layer columns.
- `bulk.php` is the scheduler worker: it downloads the current UTC hour's
  netCDF file every 5 minutes and slices it into one JSON file per
  configured station under `cache/madis-hfmetar/stations/`.
- `lib/weather/adapter/madis-hfmetar-v1.php` implements the weather adapter
  contract. The unified fetcher resolves each station as mock, bulk slice,
  then HTTP, mirroring the METAR source.

## Operator policy

A `madis_hfmetar` source may only be configured when the ASOS is at the
airfield. Config validation requires `station_id` to equal the airport
`icao`, and airports without an `icao` cannot use it. See
`docs/CONFIGURATION.md` and `docs/DATA_FLOW.md`.

## How to run

```bash
# Inspect a station in the sample file
php scripts/madis-hfmetar-inspect.php tests/Fixtures/madis/hfmetar-sample.gz KSPB

# Header structure
php scripts/madis-hfmetar-inspect.php tests/Fixtures/madis/hfmetar-sample.gz --header

# Run one bulk refresh cycle (production mode)
php scripts/refresh-madis-hfmetar.php

# Tests
composer install
vendor/bin/phpunit tests/Unit/MadisHfmetarNetcdfTest.php tests/Unit/MadisHfmetarBulkTest.php
```

## Facts learned from the real feed

- The hourly file is rewritten roughly every 5-10 minutes as observations
  arrive; each 5-minute mark appears for ~1,000 stations within 15-25
  minutes of the mark. Median receive lag is ~2.4 minutes. Expect 10-25
  minutes of delivery latency, not true 5-minute freshness.
- Coverage is Federal ASOS only: 1,025 stations CONUS + Alaska + territories.
  AWOS stations are not in HFMETAR.
- Of the airports on the public airport list, 8 have a direct ICAO match in
  the HFMETAR feed (KBOI, KEUL, KHIO, KLLJ, KMYL, KPUW, KSPB, KTWF), and
  more sit close to a Federal ASOS.
- The realtime LDAD path retains ~5 days of hourly files, which is enough
  to warm the wind-rose history on a forward rolling basis.

## Constraints

- The netCDF reader is CDF-1 only. MADIS mesonet files are also CDF-1,
  but verify before pointing it at other datasets.
- Wind direction in HFMETAR is true north; the adapter passes it through
  and the existing magnetic conversion applies downstream.
