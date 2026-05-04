# Bundled geography data

## Admin-0 countries (110m)

`ne_110m_admin_0_countries.geojson` is [Natural Earth](https://www.naturalearthdata.com/) Admin 0 -- Countries (110m), distributed under the [Natural Earth terms of use](https://www.naturalearthdata.com/about/terms-of-use/). It is used offline to derive geometry-only ISO 3166-1 alpha-2 hints for airports (see `scripts/refresh-airport-country-resolution.php`).

Update this file only when intentionally refreshing boundaries (infrequent); bump `COUNTRY_RESOLUTION_BOUNDARY_DATASET_ID` in `lib/constants.php` if the dataset identity changes.
