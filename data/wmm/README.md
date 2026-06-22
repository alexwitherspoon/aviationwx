# Bundled World Magnetic Model (WMM) coefficients

`WMM.COF` is the NOAA/NCEI WMM2025 spherical harmonic coefficient file (public domain). It ships with the application for offline magnetic declination calculation.

- **Model:** WMM-2025 (epoch 2025.0, valid through 2030.0)
- **Manifest:** `manifest.json` records model metadata and SHA-256 for verification
- **Source:** [WMM coefficients](https://www.ncei.noaa.gov/products/world-magnetic-model/wmm-coefficients)

## Maintainer updates

When NOAA publishes a new WMM model (or the weekly verify CI job reports drift):

```bash
php scripts/update-wmm-coefficients.php   # refresh WMM.COF, manifest.json, golden fixtures
make test-unit                            # full unit test suite (includes WmmCalculatorTest)
make verify-wmm-coefficients            # networked NOAA verify (same as weekly CI)
```

Use `--dry-run` on the update script to preview metadata without writing files. Commit all three paths above in one reviewed PR - production never downloads coefficients at runtime.

## Weekly CI

`.github/workflows/weekly-wmm-coefficients.yml` runs `scripts/verify-wmm-coefficients.php` every Wednesday. It discovers the current WMM*COF.zip from NOAA's coefficients page and compares header fields and SHA-256 to `manifest.json`. Failures mean NOAA has published new coefficients that are not yet vendored in the repo.
