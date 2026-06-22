# Bundled World Magnetic Model (WMM) coefficients

`WMM.COF` is the NOAA/NCEI WMM2025 spherical harmonic coefficient file (public domain). It ships with the application for offline magnetic declination calculation.

- **Model:** WMM-2025 (epoch 2025.0, valid through 2030.0)
- **Manifest:** `manifest.json` records model metadata and SHA-256 for verification
- **Source:** [WMM coefficients](https://www.ncei.noaa.gov/products/world-magnetic-model/wmm-coefficients)

Update only when NOAA releases a new WMM model; refresh `manifest.json` and golden tests in the same change.
