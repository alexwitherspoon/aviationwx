# NASR APT/FRQ CSV test fixtures

Offline slices of FAA NASR CSV extracts for unit tests.

## APT (runway performance)

`APT_BASE.csv`, `APT_RWY.csv`, and `APT_RWY_END.csv` support density altitude performance and NASR APT cache tests.

## FRQ (airport frequencies)

`FRQ.csv` contains pilot-facing frequency rows for fixture airports such as `69V` (duplicate CTAF/UNICOM) and `HIO` (tower/ground/ATIS).

Rows may span more than one subscription `EFF_DATE`. The parser keys airports by `SERVICED_FACILITY` (ARPT_ID).

Add or update rows when locking a new density altitude performance reference airport in `tests/Fixtures/density-altitude-performance-scenarios.json`.
