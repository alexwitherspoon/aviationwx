# NASR APT CSV test fixtures

Offline slices of FAA NASR `APT_BASE.csv`, `APT_RWY.csv`, and `APT_RWY_END.csv` for unit tests.

Rows may span more than one subscription `EFF_DATE` (for example 2025/05/15 and 2026/07/09). The parser keys airports by `ARPT_ID`; cycle date is not used for performance calculations in tests.

Add or update rows when locking a new density altitude performance reference airport in `tests/Fixtures/density-altitude-performance-scenarios.json`.
