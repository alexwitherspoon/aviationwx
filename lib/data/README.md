# Static data files

- **`iso3166-alpha2-codes.txt`** - One ISO 3166-1 alpha-2 code per line, derived from [lukes/ISO-3166-Countries-with-Regional-Codes](https://github.com/lukes/ISO-3166-Countries-with-Regional-Codes) (MIT). Used to validate optional `iso_country` in `airports.json` and related fields.
- **`iso3166-country-names.csv`** - `alpha-2,name` pairs for ISO 3166-1, derived from the same source (MIT). Used by `countryNameFromIso3166Alpha2()` for the Public API `country_name` field.
