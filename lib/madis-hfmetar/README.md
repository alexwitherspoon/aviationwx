# MADIS HFMETAR

MADIS HFMETAR is a NOAA public feed of Federal ASOS observations at 5-minute marks (12 per hour per station), delivered as hourly classic netCDF files. This package reads those files in pure PHP and supplies them to the unified weather fetcher as a co-located weather source.

Source (public anonymous, no API key): https://madis-data.ncep.noaa.gov/madisPublic1/data/LDAD/hfmetar/netCDF/

- `netcdf-classic.php` - minimal classic netCDF (CDF-1) reader
- `madis-hfmetar.php` - decodes a file into per-station observations in AviationWX standard units
- `bulk.php` - scheduler worker that downloads the current-hour file and slices it per configured station
- `tests/Fixtures/madis/hfmetar-sample.gz` - one completed hour (2026-11-20 23:00 UTC, 12,239 records, 1,026 stations), public domain NOAA data

Configuration and behavior are documented in `docs/CONFIGURATION.md` and `docs/DATA_FLOW.md`.
