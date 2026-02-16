# Safety-Critical Weather Calculations

This document provides a comprehensive reference for all safety-critical weather calculations in AviationWX. These calculations directly affect flight safety decisions and must be verified against official FAA and ICAO sources.

**Last Updated**: 2026-02-14  
**Verification Status**: ✅ All formulas verified against FAA sources

---

## Table of Contents

1. [Density Altitude](#density-altitude)
2. [Pressure Altitude](#pressure-altitude)
3. [Flight Category](#flight-category)
4. [Dewpoint Calculations](#dewpoint-calculations)
5. [Temperature Conversions](#temperature-conversions)
6. [Wind Calculations](#wind-calculations)
7. [METAR Visibility Parsing](#metar-visibility-parsing)
8. [Local vs Neighboring METAR Aggregation](#local-vs-neighboring-metar-aggregation)

---

## Density Altitude

**⚠️ SAFETY CRITICAL**: This calculation directly affects takeoff/landing performance decisions. An underestimated density altitude can lead to runway overruns or inability to climb.

### Formula

Per **FAA Pilot's Handbook of Aeronautical Knowledge (FAA-H-8083-25C)**:

```
Step 1: Calculate Pressure Altitude
PA = Station Elevation + [(29.92 - Altimeter Setting) × 1000]

Step 2: Calculate ISA Temperature at Pressure Altitude (in Celsius)
ISA Temp (°C) = 15 - [2 × (PA / 1000)]

Step 3: Calculate Density Altitude
Density Altitude = PA + [120 × (Actual Temp °C - ISA Temp °C)]
```

**CRITICAL**: The 120 coefficient is for **Celsius**, not Fahrenheit. Using Fahrenheit would overestimate DA by ~80%.

### Critical Implementation Details

1. **ISA temperature MUST be calculated at pressure altitude, not station elevation.**
   - Especially critical for high-altitude airports
   - Critical on low-pressure days (where PA differs significantly from elevation)
   - Errors compound in hot conditions

2. **Use the environmental lapse rate (2°C/1000ft), not adiabatic lapse rates**
   - Environmental lapse rate: Static atmosphere temperature profile (correct for DA)
   - Dry adiabatic (3°C/1000ft): Rising air parcels (NOT used for DA)
   - Moist adiabatic (1.5°C/1000ft): Saturated rising parcels in clouds (NOT used for DA)

3. **Use simplified lapse rate (2.0°C), not exact ICAO (1.98°C)**
   - Difference causes only 12-36 ft error (negligible)
   - Far smaller than sensor uncertainty (±60-240 ft)
   - Matches FAA standard practice

### Example Calculation

**Denver International (KDEN) on hot summer day:**
- Elevation: 5,434 ft
- Temperature: 35°C
- Altimeter: 24.50 inHg

**Step 1**: PA = 5,434 + (29.92 - 24.50) × 1000 = **10,854 ft**  
**Step 2**: ISA = 15 - (2 × 10.854) = **-6.7°C**  
**Step 3**: DA = 10,854 + [120 × (35 - (-6.7))] = **15,858 ft**

Result: Density altitude is **2.9× field elevation** - aircraft performance severely degraded!

### Standard Atmosphere Reference

Per **ICAO Standard Atmosphere (Doc 7488)**:

- **Sea level**: 15°C (59°F), 29.92 inHg (1013.25 hPa)
- **Environmental lapse rate**: 2°C per 1,000 ft (exact: 1.98°C from 6.5°C/km)
- **Valid range**: Up to tropopause at 36,089 ft (11 km)
- **120 coefficient**: Feet of density altitude change per degree **Celsius** deviation from ISA

### Implementation

- **File**: `lib/weather/calculator.php`
- **Function**: `calculateDensityAltitude($weather, $airport)`
- **Reference Tests**: `tests/Unit/SafetyCriticalReferenceTest.php`
  - 8 density altitude tests with known-good values
  - E6B manual examples, FAA chart values, real-world cross-checks
  - Static hardcoded test data (no external dependencies)
- **Implementation Tests**: `tests/Unit/WeatherCalculationsTest.php`
  - 11 tests covering standard conditions, extremes, real-world scenarios
  - Includes boundary tests and formula validation tests

### Test-Driven Development (TDD) Approach

All safety-critical calculations use TDD methodology:

1. **Reference Tests First**: Create test cases using known-good values from authoritative sources (E6B manuals, FAA handbooks, NOAA calculators)
2. **Verify Against Standards**: Confirm implementation matches FAA/ICAO specifications
3. **Implementation**: Code formula per official specifications
4. **Validate**: All reference tests pass within expected tolerance
5. **Continuous Validation**: Tests run on every commit via CI

**Test Philosophy**:
- Static, hardcoded reference values (no calculations in tests)
- Multiple authoritative sources (E6B, FAA charts, NOAA calculators)
- Cover full range of conditions (sea level, high altitude, hot, cold, standard)
- Tests must pass before any deployment

### Official Sources

1. **FAA-H-8083-25C**: Pilot's Handbook of Aeronautical Knowledge (primary source)
2. **FAA-H-8083-28**: Aviation Weather Handbook
3. **FAA-H-8083-15B**: Instrument Flying Handbook
4. **ICAO Doc 7488**: Standard Atmosphere
5. **E6B Flight Computer**: Manual examples and documented calculations

---

## Pressure Altitude

**⚠️ SAFETY CRITICAL**: Used as input for density altitude calculation. Errors propagate to density altitude.

### Formula

Per **FAA Pilot's Handbook of Aeronautical Knowledge (FAA-H-8083-25)**:

```
Pressure Altitude = Station Elevation + [(29.92 - Altimeter Setting) × 1000]
```

### Purpose

- Represents the altitude in standard atmosphere (29.92 inHg at sea level)
- It's the altitude indicated when altimeter is set to 29.92 inHg
- Used for performance calculations and as input to density altitude

### Standard Pressure Reference

- **Sea level standard**: 29.92 inHg (1013.25 hPa)
- **Pressure lapse rate**: ~1 inHg per 1,000 feet

### Examples

| Altimeter (inHg) | Field Elevation (ft) | Pressure Altitude (ft) | Performance Impact |
|------------------|---------------------|----------------------|-------------------|
| 29.92 (standard) | 1,000 | 1,000 | Standard |
| 29.42 (low) | 1,000 | 1,500 | Reduced (worse) |
| 30.42 (high) | 1,000 | 500 | Improved (better) |

### Implementation

- **File**: `lib/weather/calculator.php`
- **Function**: `calculatePressureAltitude($weather, $airport)`
- **Reference Tests**: `tests/Unit/SafetyCriticalReferenceTest.php`
  - 3 pressure altitude tests with known-good values
  - Covers sea level standard, high/low pressure scenarios
- **Implementation Tests**: `tests/Unit/WeatherCalculationsTest.php`

### Official Sources

1. FAA Pilot's Handbook of Aeronautical Knowledge (FAA-H-8083-25)
2. FAA Instrument Flying Handbook (FAA-H-8083-15B)

---

## Flight Category

**⚠️ SAFETY CRITICAL**: Incorrect categorization could lead pilots to attempt VFR flight in marginal or IFR conditions, potentially causing controlled flight into terrain (CFIT) or loss of control.

### Official Definitions

Per **FAA Aeronautical Information Manual (AIM) Chapter 7, Section 7-1-6** and **National Weather Service Directive**:

| Category | Ceiling (AGL) | Visibility (SM) | Logic | Color |
|----------|--------------|----------------|-------|-------|
| **VFR** | > 3,000 ft | > 5 SM | **BOTH** required | Green |
| **MVFR** | 1,000 - 3,000 ft | 3 - 5 SM | **Either** qualifies | Blue |
| **IFR** | 500 - < 1,000 ft | 1 - **< 3** SM | **Either** qualifies | Red |
| **LIFR** | < 500 ft | < 1 SM | **Either** qualifies | Magenta |

### Critical Threshold Detail

**3 statute miles exactly is MVFR, not IFR**

The FAA defines IFR as "1 to **less than** 3 statute miles". This means:
- 2.9 SM = IFR ✅
- 3.0 SM = MVFR ✅ (not IFR)
- 3.1 SM = MVFR ✅

### Decision Logic

1. Categorize ceiling and visibility independently
2. For **VFR**: Both must be VFR (AND logic)
3. For **all other categories**: Use worst case (most restrictive)
4. Restrictiveness order: LIFR > IFR > MVFR > VFR

### Special Cases

- **Unlimited ceiling** (null/no clouds): Treated as VFR for ceiling
- **Unlimited visibility** (>10 SM): Treated as VFR for visibility
- **Missing ceiling + VFR visibility**: Assumes unlimited ceiling → VFR
- **Missing visibility + VFR ceiling**: Conservative → MVFR (cannot confirm VFR)

### Important Disclaimer

These categories are for **planning and situational awareness only**. They do NOT represent the minimum weather requirements for VFR flight under **14 CFR § 91.155**, which vary by:
- Airspace class (Class A/B/C/D/E/G)
- Time of day (day vs night)
- Altitude above ground level

### Implementation

- **File**: `lib/weather/calculator.php`
- **Function**: `calculateFlightCategory($weather)`
- **Reference Tests**: `tests/Unit/SafetyCriticalReferenceTest.php`
  - 9 flight category tests with boundary conditions
  - Covers all 4 categories, boundary values (3.0 SM exactly), edge cases
  - Tests AND/OR logic for VFR vs other categories
- **Implementation Tests**: `tests/Unit/WeatherCalculationsTest.php`
  - 12 test cases including all categories and special cases

### Official Sources

1. FAA Aeronautical Information Manual (AIM) Chapter 7, Section 7-1-6
2. FAA Aviation Weather Handbook (FAA-H-8083-28A), Chapter 13
3. National Weather Service Directive on Aviation Weather Services
4. 14 CFR § 91.155 (VFR weather minimums - separate from categories)

---

## Dewpoint Calculations

### From Temperature and Humidity (Magnus-Tetens Approximation)

**Purpose**: Calculate dewpoint when only temperature and humidity are available.

**Formula** (Alduchov and Eskridge, 1996):

```
γ = ln(RH/100) + [(b × T) / (c + T)]
Td = (c × γ) / (b - γ)
```

**Constants**:
- a = 6.1121 mb (saturation vapor pressure at 0°C, not used in dewpoint calc)
- b = 17.368 (dimensionless)
- c = 238.88°C

**Valid Range**: -40°C to +50°C  
**Accuracy**: ±0.4°C within valid range

**Alternative Constants**: b=17.27, c=237.7 (Buck, 1981) - commonly used for 0°C to 50°C

### From Dewpoint to Humidity (Reverse Magnus)

**Purpose**: Calculate humidity when only temperature and dewpoint are available.

**Formula** (Buck, 1981):

```
e_sat = 6.112 × exp[(17.67 × T) / (T + 243.5)]
e = 6.112 × exp[(17.67 × Td) / (Td + 243.5)]
RH = (e / e_sat) × 100
```

Where:
- e_sat = saturation vapor pressure at temperature T (mb)
- e = actual vapor pressure at dewpoint Td (mb)

**Constants**: 6.112 mb, 17.67, 243.5°C

### Implementation

- **File**: `lib/weather/calculator.php`
- **Functions**: `calculateDewpoint($tempC, $humidity)`, `calculateHumidityFromDewpoint($tempC, $dewpointC)`
- **Reference Tests**: `tests/Unit/SafetyCriticalReferenceTest.php`
  - 3 dewpoint tests covering saturated, typical, and dry conditions
  - Tests Magnus formula accuracy across humidity ranges
- **Implementation Tests**: `tests/Unit/WeatherCalculationsTest.php`

### Official Sources

1. Alduchov, O. A., and Eskridge, R. E. (1996): "Improved Magnus Form Approximation of Saturation Vapor Pressure", Journal of Applied Meteorology, 35(4)
2. Buck, A. L. (1981): "New Equations for Computing Vapor Pressure and Enhancement Factor", Journal of Applied Meteorology, 20(12)
3. Lawrence, M. G. (2005): "The Relationship between Relative Humidity and the Dewpoint Temperature in Moist Air", Bulletin of the American Meteorological Society
4. World Meteorological Organization (WMO) Guide to Instruments and Methods of Observation (CIMO Guide)

---

## Temperature Conversions

### Celsius to Fahrenheit

**Formula**:
```
°F = (°C × 9/5) + 32
```

**Alternative form**:
```
°F = (°C × 1.8) + 32
```

**Applied to**: temperature, dewpoint  
**Stored as**: `temperature_f`, `dewpoint_f`

**Note**: Performed server-side when storing weather data.

**Key Values**:
- 0°C = 32°F (freezing point)
- 15°C = 59°F (ISA sea level)
- 100°C = 212°F (boiling point)
- -40°C = -40°F (same in both scales)

### Fahrenheit to Celsius

**Formula**:
```
°C = (°F - 32) × 5/9
```

**Alternative form**:
```
°C = (°F - 32) / 1.8
```

**Used by**: API adapters (Ambient Weather, WeatherLink) to convert data to standard format

### Implementation

- **File**: `lib/weather/UnifiedFetcher.php` (lines 345-350)
- **Reference Tests**: `tests/Unit/SafetyCriticalReferenceTest.php`
  - 6 temperature conversion tests
  - Covers freezing, standard, hot, cold, and boiling points
  - Validates both C→F and F→C conversions

---

## Wind Calculations

### Gust Factor (Gust Spread)

**Formula**:
```
Gust Factor = Peak Gust - Steady Wind Speed
```

**Constraint**: Gust factor must be ≥ 0 (gusts cannot be less than steady wind)

**Purpose**: Allows pilots to apply the "add half the gust factor to your approach speed" rule for crosswind/gusty conditions.

**Units**: Displayed in user-selected unit (knots, mph, or km/h)

**Validation**: 
- Gust factor must be ≥ 0 (gusts cannot be less than steady wind)
- Range: 0 to 50 knots (reasonable gust spread range), or null if no gust data
- If gust < wind (invalid data), gust factor is clamped to 0

**Examples**:
- Wind 10 kts, gusting 15 kts → Gust Factor = 5 kts
- Wind 15 kts, gusting 30 kts → Gust Factor = 15 kts
- Wind 10 kts, no gusts → Gust Factor = 0 kts
- Calm (0 kts) → Gust Factor = 0 kts

### Peak Gust

**Definition**: Live peak gust value measured through sampling within the last 10-20 minute period (varies by weather source).

**Note**: Provided directly by weather sources - no calculation needed.

**Validation**:
- Must be ≥ wind_speed (gusts cannot be less than steady wind)
- Range: 0 to 242 knots (earth wind max + 10% margin)

### Implementation

- **File**: `lib/weather/data/WindGroup.php` (lines 145-152)
- **Function**: `getGustFactor()`
- **Reference Tests**: `tests/Unit/SafetyCriticalReferenceTest.php`
  - 5 gust factor tests
  - Covers normal, strong, no gusts, calm, and negative protection
  - Validates clamping to 0 for invalid data

---

## METAR Visibility Parsing

**⚠️ SAFETY CRITICAL**: Incorrect visibility parsing can mislead pilots. The METAR P prefix indicates "greater than" semantics and must be preserved for display.

### P Prefix (Greater Than)

Per **ICAO METAR format, visibility group**:
- `P6SM` = visibility greater than 6 statute miles (reportable value exceeds 6)
- `6SM` = visibility exactly 6 statute miles
- `P10SM` = greater than 10 SM (common for unlimited conditions)

**Display**: `P6SM` should display as "6+ SM" to distinguish from exact "6 SM".

**Flight category**: Uses the numeric value for thresholds (6.0 SM = VFR). The `visibility_greater_than` flag affects display only, not flight category calculation.

### Implementation

- **File**: `lib/weather/adapter/metar-v1.php`
- **Function**: `parseRawMETARToWeatherArray()`
- **Parsing order**: Check `P(\d+)SM` before `(\d+)SM` so the prefix is not lost
- **Tests**: `tests/Unit/RawMetarParsingTest.php` (P6SM, P10SM, 6SM, regex order)
- **Display**: `formatEmbedVisibility()` in `lib/embed-templates/shared.php` appends "+" when `visibility_greater_than` is true

### Official Sources

- ICAO WMO Manual on Codes (WMO-306)

---

## Local vs Neighboring METAR Aggregation

**⚠️ SAFETY CRITICAL**: Wind and temperature at an airport can differ significantly from nearby airports. Using neighboring METAR data for local measurements could mislead pilots about actual conditions at the field.

### Rule

For LOCAL_FIELDS (wind_speed, wind_direction, gust_speed, temperature, dewpoint, humidity, pressure, precip_accum), **local sources always override neighboring METAR** when both have valid data, regardless of observation freshness.

- **Local source**: On-site sensors (Tempest, Ambient, etc.) or METAR from the same station as the airport (e.g., KSPB METAR for KSPB airport)
- **Neighboring METAR**: METAR from a different station (e.g., KVUO when displaying KSPB)
- **Fill-in allowed**: Neighboring METAR may fill in missing fields (visibility, ceiling, cloud_cover) when local has no data

### Implementation

- **File**: `lib/weather/WeatherAggregator.php`
- **Policy**: `AggregationPolicy::LOCAL_FIELDS`
- **Detection**: `WeatherSnapshot.metarStationId` vs `localAirportIcao` (from airport config)
- **Tests**: `tests/Unit/WeatherAggregatorTest.php` (testLocalWindOverridesNeighboringMetar_EvenWhenMetarFresher, etc.)

### Rationale

Wind and temperature vary with local terrain, elevation, and microclimate. A METAR from an airport 10 nm away may report different conditions. Pilots need accurate local data for takeoff/landing decisions.

---

## Unit Conversions

**⚠️ SAFETY CRITICAL**: Incorrect unit conversions can cause dangerous misinterpretation of weather data. A pressure value in the wrong unit could result in dangerous altimeter settings. All conversion factors are verified against authoritative sources and tested with TDD methodology.

### Conversion Libraries

**PHP Library**: `lib/units.php`  
**JavaScript Library**: `public/js/units.js`

Both libraries use identical conversion factors to ensure consistency between server-side calculations and client-side display.

### Conversion Constants (Exact Values)

| Conversion | Factor | Source |
|------------|--------|--------|
| 1 inHg → hPa | 33.8639 | ICAO Standard |
| 1 statute mile → meters | 1609.344 (exact) | US Code Title 15 §205 |
| 1 inch → mm | 25.4 (exact) | International Yard and Pound Agreement 1959 |
| 1 knot → km/h | 1.852 (exact) | Nautical mile = 1852 meters |
| 1 knot → mph | 1.15078 | NOAA standard |
| 1 foot → meters | 0.3048 (exact) | International Yard and Pound Agreement 1959 |

### Internal Standard Units

AviationWX stores weather data internally using these standard units:

| Field | Internal Unit | Notes |
|-------|--------------|-------|
| Temperature | Celsius (°C) | ICAO standard |
| Dewpoint | Celsius (°C) | ICAO standard |
| Pressure | inHg | US aviation standard |
| Visibility | Statute miles (SM) | FAA standard for US aviation |
| Precipitation | Inches (in) | US standard |
| Wind Speed | Knots (kt) | ICAO standard |
| Altitude/Ceiling | Feet (ft) | ICAO standard |
| Humidity | Percent (%) | Universal |

### WeatherReading Unit Tracking

Each `WeatherReading` object carries its unit explicitly for safety:

```php
// Factory methods ensure correct unit assignment
$temp = WeatherReading::celsius($value, $source, $obsTime);
$pressure = WeatherReading::inHg($value, $source, $obsTime);
$visibility = WeatherReading::statuteMiles($value, $source, $obsTime);

// Convert between units safely
$tempFahrenheit = $temp->convertTo('F');
$pressureHpa = $pressure->convertTo('hPa');
```

### Pressure Conversions

**Formula**:
```
hPa = inHg × 33.8639
inHg = hPa / 33.8639
```

**Key Values**:
- 29.92 inHg = 1013.25 hPa (ISA sea level standard)
- 30.00 inHg = 1015.92 hPa
- 28.00 inHg = 948.19 hPa (low pressure system)

**Critical**: Used in altimeter setting calculations. Incorrect conversion can cause dangerous altitude errors.

### Visibility Conversions

**Formula**:
```
meters = SM × 1609.344
SM = meters / 1609.344
```

**Key Values**:
- 10 SM = 16,093.44 meters (unrestricted)
- 3 SM = 4,828.03 meters (MVFR threshold)
- 1 SM = 1,609.344 meters (IFR threshold)

### Wind Speed Conversions

**Formulas**:
```
km/h = knots × 1.852
mph = knots × 1.15078
knots = km/h / 1.852
knots = mph / 1.15078
```

**Key Values**:
- 10 kt = 18.52 km/h = 11.51 mph
- 25 kt = 46.30 km/h = 28.77 mph
- 50 kt = 92.60 km/h = 57.54 mph

### Altitude Conversions

**Formula**:
```
meters = feet × 0.3048
feet = meters / 0.3048
```

**Key Values**:
- 1,000 ft = 304.8 m
- 3,000 ft = 914.4 m (typical pattern altitude)
- 10,000 ft = 3,048 m (Class B floor)
- 18,000 ft = 5,486.4 m (Class A floor / FL180)

### Precipitation Conversions

**Formula**:
```
mm = inches × 25.4
inches = mm / 25.4
```

**Key Values**:
- 0.01 in = 0.254 mm (trace)
- 1.00 in = 25.4 mm
- 2.00 in = 50.8 mm (heavy rain)

### Implementation

- **PHP Library**: `lib/units.php`
- **JavaScript Library**: `public/js/units.js`
- **Reference Tests (PHP)**: `tests/Unit/SafetyCriticalReferenceTest.php`
  - 41 unit conversion tests with authoritative values
  - Covers pressure, visibility (meters and kilometers), precipitation, temperature, wind, altitude
  - Round-trip conversion tests verify accuracy
- **Reference Tests (JS)**: `tests/js/unit-conversion.test.js`
  - 41 identical tests using same reference values as PHP
  - Ensures PHP and JS implementations produce identical results

### TDD Approach for Unit Conversions

1. **Reference tests created first** with exact values from authoritative sources
2. **Conversion factors verified** against ICAO, FAA, BIPM SI Brochure
3. **Round-trip tests** ensure conversions preserve precision
4. **Cross-language consistency** - PHP and JS use identical factors

### Official Sources

1. **BIPM SI Brochure** - Exact metric definitions
2. **ICAO Doc 8400** - Aviation abbreviations and codes
3. **US Code Title 15 Section 205** - Legal definitions for inch, foot, mile
4. **International Yard and Pound Agreement (1959)** - Exact imperial-metric conversions
5. **NOAA/NWS Conversion Tables** - Standard meteorological conversions

---

## Validation Strategy

All safety-critical calculations follow Test-Driven Development (TDD) methodology:

### Reference Test Suite (`tests/Unit/SafetyCriticalReferenceTest.php`)

**Purpose**: Validate against authoritative known-good values from external sources.

**Characteristics**:
- **Static test data**: All test values hardcoded (no calculations in tests)
- **Authoritative sources**: E6B flight computer manuals, FAA handbooks, NOAA calculators
- **Multiple sources**: Cross-validation using different authoritative references
- **Full coverage**: Sea level, high altitude, hot, cold, standard conditions
- **Boundary tests**: Test exact threshold values (e.g., 3.0 SM visibility)

**Test Counts**:
- Density Altitude: 8 tests
- Pressure Altitude: 3 tests
- Flight Category: 9 tests (including boundary conditions)
- Dewpoint: 3 tests
- Temperature Conversions: 6 tests (C→F and F→C)
- Wind Calculations: 5 tests (gust factor validation)
- Unit Conversions: 41 tests (pressure, visibility including km, precipitation, wind, altitude)

**Total**: 75 static reference tests with known-good values

### Implementation Test Suite (`tests/Unit/WeatherCalculationsTest.php`)

**Purpose**: Test implementation behavior, edge cases, and error handling.

**Characteristics**:
- Real-world scenarios (Denver hot day, etc.)
- Null/missing data handling
- Formula validation tests
- Integration with weather data structures

**Test Counts**:
- Density Altitude: 11 tests
- Pressure Altitude: 4 tests
- Flight Category: 12 tests
- Dewpoint: 3 tests
- Error handling: Multiple tests for null/missing data

### TDD Workflow

1. **Write reference tests first** with known-good values from authoritative sources
2. **Run tests** - should fail with incorrect implementation
3. **Fix implementation** to match FAA/ICAO specifications
4. **Run tests again** - should now pass within acceptable tolerance
5. **Add implementation tests** for edge cases and error handling
6. **Continuous validation** - tests run on every commit via CI

### Running Tests

```bash
# Reference tests only (fastest validation)
vendor/bin/phpunit tests/Unit/SafetyCriticalReferenceTest.php --testdox

# Implementation tests
vendor/bin/phpunit tests/Unit/WeatherCalculationsTest.php --testdox

# Full CI test suite (required before commit/push)
make test-ci

# Unit tests only (faster iteration)
make test-unit
```

---

## Change History

### 2026-01-13: Density Altitude Calculation Standardization

**Implementation Update**: Standardized density altitude calculation to use the 120 coefficient with Celsius temperatures per FAA-H-8083-25C specification.

**Formula**:
```
DA = PA + [120 × (OAT_°C - ISA_°C)]
```

Where ISA_°C = 15 - [2 × (PA / 1000)]

**Implementation Characteristics**:
- Uses Celsius throughout (per FAA specification: "add 120 feet for every degree Celsius above the ISA")
- Calculates ISA temperature at pressure altitude (not station elevation)
- Uses environmental lapse rate (2°C/1000ft)
- All calculations validated against E6B flight computer examples and FAA reference charts

**Comparison with Industry Services**:
| Service | Formula Coefficient | Typical Difference |
|---------|-------------------|-------------------|
| AviationWX | 120°C (FAA standard) | Reference |
| FlightAware | ~116°C (proprietary) | ~54 ft |
| Eye-n-Sky | ~115°C (proprietary) | ~81 ft |

All implementations produce similar results within acceptable margins for flight planning.

**Test Coverage**:
- 8 density altitude reference tests with authoritative values
- 11 implementation tests covering real-world scenarios
- All tests pass within specified tolerances

**References**:
- FAA Pilot's Handbook of Aeronautical Knowledge (FAA-H-8083-25C)
- E6B Flight Computer manual examples
- FAA Density Altitude charts

### 2026-01-12: Flight Category Threshold Standardization

**Implementation Update**: Standardized visibility threshold for IFR category per FAA AIM 7-1-6.

**Specification**: IFR visibility is defined as "1 to **less than** 3 SM", meaning:
- Visibility < 3 SM = IFR
- Visibility = 3.0 SM exactly = MVFR
- Visibility > 3 SM = MVFR (if ceiling permits)

**Implementation**:
- IFR visibility: `>= 1 && < 3` SM
- MVFR visibility: `>= 3 && <= 5` SM

**Test Coverage**: 9 flight category tests including boundary condition (3.0 SM = MVFR)

**References**:
- FAA Aeronautical Information Manual (AIM) Chapter 7, Section 7-1-6
- National Weather Service Aviation Weather Services Directive

---

## Maintenance Guidelines

When modifying safety-critical calculations:

1. **Research First**: Verify formula against official FAA/ICAO sources
2. **Document Sources**: Include specific handbook references and page numbers if available
3. **Add Tests**: Include test cases with known good values
4. **Boundary Tests**: Test edge cases and thresholds
5. **Real-World Tests**: Include practical scenarios pilots encounter
6. **Update Docs**: Update both inline comments and DATA_FLOW.md
7. **Review Impact**: Consider how changes affect existing data and displays
8. **Full CI**: Always run `make test-ci` before commit/push

---

## Contact

For questions about safety-critical calculations, consult:
- FAA Pilot's Handbook of Aeronautical Knowledge (FAA-H-8083-25)
- FAA Aviation Weather Handbook (FAA-H-8083-28)
- FAA Aeronautical Information Manual (AIM)
- ICAO Standard Atmosphere (Doc 7488)

**Note**: When in doubt about aviation formulas, always defer to official FAA sources. Safety is paramount.
