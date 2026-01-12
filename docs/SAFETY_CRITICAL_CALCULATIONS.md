# Safety-Critical Weather Calculations

This document provides a comprehensive reference for all safety-critical weather calculations in AviationWX. These calculations directly affect flight safety decisions and must be verified against official FAA and ICAO sources.

**Last Updated**: 2026-01-12  
**Verification Status**: ✅ All formulas verified against FAA sources

---

## Table of Contents

1. [Density Altitude](#density-altitude)
2. [Pressure Altitude](#pressure-altitude)
3. [Flight Category](#flight-category)
4. [Dewpoint Calculations](#dewpoint-calculations)
5. [Temperature Conversions](#temperature-conversions)
6. [Wind Calculations](#wind-calculations)

---

## Density Altitude

**⚠️ SAFETY CRITICAL**: This calculation directly affects takeoff/landing performance decisions. An underestimated density altitude can lead to runway overruns or inability to climb.

### Formula

Per **FAA Pilot's Handbook of Aeronautical Knowledge (FAA-H-8083-25)**:

```
Step 1: Calculate Pressure Altitude
PA = Station Elevation + [(29.92 - Altimeter Setting) × 1000]

Step 2: Calculate ISA Temperature at Pressure Altitude
ISA Temp (°F) = 59 - [3.57 × (PA / 1000)]

Step 3: Convert Actual Temperature to Fahrenheit
Actual Temp (°F) = (tempC × 9/5) + 32

Step 4: Calculate Density Altitude
Density Altitude = PA + [120 × (Actual Temp - ISA Temp)]
```

### Critical Implementation Detail

**ISA temperature MUST be calculated at pressure altitude, not station elevation.**

This is especially critical for:
- High altitude airports (e.g., Denver International, 5,434 ft)
- Low pressure days (where PA significantly differs from elevation)
- Hot conditions (where errors compound)

### Example Calculation

**Denver International (KDEN) on hot summer day:**
- Elevation: 5,434 ft
- Temperature: 35°C (95°F)
- Altimeter: 24.50 inHg

**Step 1**: PA = 5,434 + (29.92 - 24.50) × 1000 = **10,854 ft**  
**Step 2**: ISA = 59 - (3.57 × 10.854) = **20.25°F**  
**Step 3**: Actual = (35 × 9/5) + 32 = **95°F**  
**Step 4**: DA = 10,854 + [120 × (95 - 20.25)] = **19,824 ft**

Result: Density altitude is **3.6× field elevation** - aircraft performance severely degraded!

### Standard Atmosphere Reference

Per **ICAO Standard Atmosphere (Doc 7488)**:

- **Sea level**: 15°C (59°F), 29.92 inHg (1013.25 hPa)
- **Temperature lapse rate**: 2°C per 1,000 ft (6.5°C/km) or 3.57°F per 1,000 ft
- **Valid range**: Up to tropopause at 36,089 ft (11 km)
- **120 coefficient**: Represents feet of density altitude change per degree Fahrenheit deviation from ISA

### Implementation

- **File**: `lib/weather/calculator.php`
- **Function**: `calculateDensityAltitude($weather, $airport)`
- **Tests**: `tests/Unit/WeatherCalculationsTest.php` (10 test cases with known good values)

### Official Sources

1. FAA Pilot's Handbook of Aeronautical Knowledge (FAA-H-8083-25)
2. FAA Aviation Weather Handbook (FAA-H-8083-28)
3. ICAO Standard Atmosphere (Doc 7488)

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
- **Tests**: `tests/Unit/WeatherCalculationsTest.php`

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
- **Tests**: `tests/Unit/WeatherCalculationsTest.php` (12 test cases including boundary conditions)

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
- **Tests**: `tests/Unit/WeatherCalculationsTest.php`

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

---

## Wind Calculations

### Gust Factor (Gust Spread)

**Formula**:
```
Gust Factor = Peak Gust - Steady Wind Speed
```

**Purpose**: Allows pilots to apply the "add half the gust factor to your approach speed" rule for crosswind/gusty conditions.

**Units**: Displayed in user-selected unit (knots, mph, or km/h)

**Validation**: 
- Gust factor must be ≥ 0 (gusts cannot be less than steady wind)
- Range: 0 to 50 knots (reasonable spread for typical conditions)

### Peak Gust

**Definition**: Live peak gust value measured through sampling within the last 10-20 minute period (varies by weather source).

**Note**: Provided directly by weather sources - no calculation needed.

**Validation**:
- Must be ≥ wind_speed (gusts cannot be less than steady wind)
- Range: 0 to 242 knots (earth wind max + 10% margin)

---

## Validation Strategy

All safety-critical calculations include:

1. **Comprehensive unit tests** with known good values
2. **Boundary condition tests** to catch edge cases
3. **Real-world scenario tests** (e.g., Denver hot summer day)
4. **Comparison tests** against previous formulas to quantify error corrections
5. **Null handling** for missing data (fail-safe behavior)

### Test Coverage

- Density Altitude: 10 test cases including sea level, high elevation, hot/cold days, and formula comparison
- Pressure Altitude: 4 test cases covering standard, high, and low pressure
- Flight Category: 12 test cases including all categories and boundary conditions
- Dewpoint: 3 test cases including round-trip verification

### Running Tests

```bash
# Full CI test suite (required before commit/push)
make test-ci

# Unit tests only (faster iteration)
make test-unit

# Specific test file
vendor/bin/phpunit tests/Unit/WeatherCalculationsTest.php --testdox
```

---

## Change History

### 2026-01-12: Density Altitude Formula Correction

**Critical Safety Fix**: Corrected density altitude formula to use pressure altitude for ISA temperature calculation instead of station elevation.

**Impact**: 
- Old formula underestimated density altitude, especially at:
  - High altitude airports
  - Low pressure days
  - Hot conditions
- Example error: ~1,428 ft underestimation at 5,000 ft elevation with low pressure
- This could have led to runway overruns or inability to climb

**Changes**:
1. Updated ISA temperature calculation to use pressure altitude
2. Changed lapse rate constant from 0.003566 to 3.57 (divided by 1000 in formula)
3. Added comprehensive documentation with FAA references
4. Added 10 test cases with known good values
5. Updated DATA_FLOW.md with correct formulas and sources

### 2026-01-12: Flight Category Threshold Correction

**Safety Fix**: Corrected visibility threshold for IFR category.

**Issue**: Code was classifying 3 SM visibility as IFR, but FAA defines IFR as "1 to **less than** 3 SM".

**Impact**: 
- 3 SM exactly was incorrectly shown as IFR instead of MVFR
- Could lead to overly conservative flight decisions

**Changes**:
1. Updated visibility categorization: `>= 1 && < 3` for IFR (was `>= 1 && <= 3`)
2. Updated visibility categorization: `>= 3 && <= 5` for MVFR (was `> 3 && <= 5`)
3. Added boundary condition tests
4. Updated documentation with FAA sources

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
