<?php
/**
 * Weather Calculation Functions
 * 
 * Core weather calculation functions for aviation metrics.
 * These calculations are critical for flight safety decisions.
 */

/**
 * Calculate dewpoint from temperature and humidity using Magnus formula
 * 
 * Uses the Magnus-Tetens approximation to calculate dewpoint temperature in Celsius.
 * This is a widely accepted empirical formula in meteorology with good accuracy
 * for typical atmospheric conditions.
 * 
 * Formula:
 *   γ = ln(RH/100) + [(b × T) / (c + T)]
 *   Td = (c × γ) / (b - γ)
 * 
 * Constants (Alduchov and Eskridge, 1996):
 *   a = 6.1121 (mb, not used in dewpoint calculation but part of full Magnus formula)
 *   b = 17.368 (dimensionless)
 *   c = 238.88 (°C)
 * 
 * Valid range: -40°C to +50°C (typical atmospheric conditions)
 * Accuracy: ±0.4°C within valid range
 * 
 * Alternative constants exist for different temperature ranges:
 *   - b=17.27, c=237.7 (Buck, 1981) - commonly used for 0°C to 50°C
 *   - b=17.368, c=238.88 (Alduchov & Eskridge, 1996) - improved accuracy
 * 
 * Sources:
 *   - Alduchov, O. A., and Eskridge, R. E. (1996): "Improved Magnus Form
 *     Approximation of Saturation Vapor Pressure", Journal of Applied Meteorology, 35(4)
 *   - Lawrence, M. G. (2005): "The Relationship between Relative Humidity and the
 *     Dewpoint Temperature in Moist Air", Bulletin of the American Meteorological Society
 * 
 * @param float|null $tempC Temperature in Celsius
 * @param float|null $humidity Relative humidity percentage (0-100)
 * @return float|null Dewpoint in Celsius, or null if inputs are invalid
 */
function calculateDewpoint($tempC, $humidity) {
    if ($tempC === null || $humidity === null) return null;
    
    // Magnus formula constants (Alduchov and Eskridge, 1996)
    $a = 6.1121;
    $b = 17.368;
    $c = 238.88;
    
    $gamma = log($humidity / 100) + ($b * $tempC) / ($c + $tempC);
    $dewpoint = ($c * $gamma) / ($b - $gamma);
    
    return $dewpoint;
}

/**
 * Calculate humidity from temperature and dewpoint using Magnus formula
 * 
 * Uses the Magnus-Tetens approximation to calculate relative humidity from
 * temperature and dewpoint. This is the inverse of the dewpoint calculation.
 * 
 * Formula:
 *   e_sat = 6.112 × exp[(17.67 × T) / (T + 243.5)]
 *   e = 6.112 × exp[(17.67 × Td) / (Td + 243.5)]
 *   RH = (e / e_sat) × 100
 * 
 * Where:
 *   - e_sat = saturation vapor pressure at temperature T (mb)
 *   - e = actual vapor pressure at dewpoint Td (mb)
 *   - RH = relative humidity (%)
 * 
 * Constants used: 6.112 (mb), 17.67, 243.5°C (Buck, 1981)
 * Note: Uses slightly different constants than calculateDewpoint() for compatibility
 * with existing meteorological practice.
 * 
 * Valid range: -40°C to +50°C (typical atmospheric conditions)
 * 
 * Sources:
 *   - Buck, A. L. (1981): "New Equations for Computing Vapor Pressure and
 *     Enhancement Factor", Journal of Applied Meteorology, 20(12)
 *   - World Meteorological Organization (WMO) Guide to Instruments and
 *     Methods of Observation (CIMO Guide)
 * 
 * @param float|null $tempC Temperature in Celsius
 * @param float|null $dewpointC Dewpoint in Celsius
 * @return int|null Relative humidity percentage (0-100), or null if inputs are invalid
 */
function calculateHumidityFromDewpoint($tempC, $dewpointC) {
    if ($tempC === null || $dewpointC === null) return null;
    
    // Magnus formula (Buck, 1981)
    $esat = 6.112 * exp((17.67 * $tempC) / ($tempC + 243.5));
    $e = 6.112 * exp((17.67 * $dewpointC) / ($dewpointC + 243.5));
    $humidity = ($e / $esat) * 100;
    
    return round($humidity);
}

/**
 * Calculate pressure altitude using FAA-approved formula
 * 
 * Calculates pressure altitude in feet based on station elevation and altimeter setting.
 * Pressure altitude is the altitude in the standard atmosphere corresponding to a particular
 * pressure value. It's the altitude indicated when the altimeter is set to 29.92 inHg.
 * 
 * Formula (per FAA handbooks):
 *   Pressure Altitude = Station Elevation + [(29.92 - Altimeter Setting) × 1000]
 * 
 * The formula derives from the standard atmosphere pressure lapse rate of approximately
 * 1 inch of mercury per 1,000 feet of altitude change.
 * 
 * Examples:
 *   - Altimeter = 29.92 inHg (standard) → PA = Field Elevation
 *   - Altimeter < 29.92 inHg (low pressure) → PA > Field Elevation (worse performance)
 *   - Altimeter > 29.92 inHg (high pressure) → PA < Field Elevation (better performance)
 * 
 * SAFETY CRITICAL: Used as input for density altitude calculation, which affects
 * aircraft performance decisions (takeoff/landing distance, climb rate).
 * 
 * Sources:
 *   - FAA Pilot's Handbook of Aeronautical Knowledge (FAA-H-8083-25)
 *   - FAA Instrument Flying Handbook (FAA-H-8083-15B)
 * 
 * @param array $weather Weather data array (must contain 'pressure' key in inHg)
 * @param array $airport Airport configuration array (must contain 'elevation_ft')
 * @return int|null Pressure altitude in feet, or null if required data is missing
 */
function calculatePressureAltitude($weather, $airport) {
    if (!isset($weather['pressure'])) {
        return null;
    }
    
    $stationElevation = $airport['elevation_ft'];
    $pressureInHg = $weather['pressure'];
    
    // PA = Station Elevation + (29.92 - Altimeter) × 1000
    $pressureAlt = $stationElevation + (29.92 - $pressureInHg) * 1000;
    
    return round($pressureAlt);
}

/**
 * Calculate density altitude using FAA-approved formula
 * 
 * SAFETY CRITICAL: Directly affects takeoff/landing performance decisions.
 * 
 * Formula: DA = PA + [120 × (OAT - ISA_Temp)]
 * Where ISA_Temp = 15°C - [2°C × (PA / 1000)]
 * 
 * CRITICAL: 120 coefficient is for CELSIUS (per FAA-H-8083-25C).
 * Using Fahrenheit would overestimate DA by ~80% (common implementation error).
 * 
 * @param array $weather Weather data (must contain 'temperature' in Celsius and 'pressure' in inHg)
 * @param array $airport Airport config (must contain 'elevation_ft')
 * @return int|null Density altitude in feet, or null if required data missing
 */
function calculateDensityAltitude($weather, $airport) {
    if (!isset($weather['temperature']) || !isset($weather['pressure'])) {
        return null;
    }
    
    $stationElevation = $airport['elevation_ft'];
    $tempC = $weather['temperature'];
    $pressureInHg = $weather['pressure'];
    
    // Calculate PA using station elevation, NOT just elevation
    // (critical for hot/low-pressure days where PA differs significantly)
    $pressureAlt = $stationElevation + (29.92 - $pressureInHg) * 1000;
    
    // ISA uses environmental lapse rate (2°C/1000ft), not adiabatic (~3°C/1000ft)
    $isaTemperatureC = 15 - (2.0 * ($pressureAlt / 1000));
    
    $densityAlt = $pressureAlt + (120 * ($tempC - $isaTemperatureC));
    
    return (int)round($densityAlt);
}

/**
 * Calculate flight category (VFR, MVFR, IFR, LIFR) based on ceiling and visibility
 * 
 * Uses standard FAA aviation weather category definitions for situational awareness.
 * These categories indicate general ceiling and visibility conditions and help pilots
 * assess whether VFR or IFR flight is appropriate.
 * 
 * IMPORTANT: These categories are for planning and situational awareness only.
 * They do NOT represent the minimum weather requirements for VFR flight under
 * 14 CFR § 91.155, which vary by airspace class and time of day.
 * 
 * FAA Flight Category Definitions (Worst-Case Rule):
 * 
 * VFR (Visual Flight Rules) - Green:
 *   - Ceiling: Greater than 3,000 feet AGL
 *   - Visibility: Greater than 5 statute miles
 *   - Rule: BOTH conditions must be met
 * 
 * MVFR (Marginal VFR) - Blue:
 *   - Ceiling: 1,000 to 3,000 feet AGL
 *   - Visibility: 3 to 5 statute miles
 *   - Rule: Either condition qualifies
 * 
 * IFR (Instrument Flight Rules) - Red:
 *   - Ceiling: 500 to less than 1,000 feet AGL
 *   - Visibility: 1 to less than 3 statute miles
 *   - Rule: Either condition qualifies
 * 
 * LIFR (Low IFR) - Magenta:
 *   - Ceiling: Less than 500 feet AGL
 *   - Visibility: Less than 1 statute mile
 *   - Rule: Either condition qualifies
 * 
 * Decision Logic:
 *   1. Categorize ceiling and visibility independently
 *   2. For VFR: BOTH must be VFR (AND logic)
 *   3. For all other categories: Use WORST case (most restrictive)
 *   4. Category order (most to least restrictive): LIFR > IFR > MVFR > VFR
 * 
 * Special Cases:
 *   - Unlimited ceiling (null/no clouds): Treated as VFR for ceiling
 *   - Unlimited visibility (>10 SM or sentinel value): Treated as VFR for visibility
 *   - Missing ceiling + VFR visibility: Assumes unlimited ceiling → VFR
 *   - Missing visibility + VFR ceiling: Conservative → MVFR (cannot confirm VFR)
 * 
 * SAFETY CRITICAL: Incorrect categorization could lead pilots to attempt VFR flight
 * in marginal or IFR conditions, potentially leading to controlled flight into terrain
 * (CFIT) or loss of control accidents.
 * 
 * Sources:
 *   - FAA Aeronautical Information Manual (AIM) Chapter 7, Section 7-1-6
 *   - FAA Aviation Weather Handbook (FAA-H-8083-28A), Chapter 13
 *   - 14 CFR § 91.155 (Basic VFR weather minimums - separate from categories)
 * 
 * @param array $weather Weather data array (should contain 'ceiling' in feet and 'visibility' in SM)
 * @return string|null Flight category ('VFR', 'MVFR', 'IFR', 'LIFR'), or null if insufficient data
 */
function calculateFlightCategory($weather) {
    require_once __DIR__ . '/../constants.php';
    require_once __DIR__ . '/utils.php';
    
    $ceiling = $weather['ceiling'] ?? null;
    $visibility = $weather['visibility'] ?? null;
    
    // Check for unlimited sentinel values before categorization
    $isUnlimitedVisibility = isUnlimitedVisibility($visibility);
    $isUnlimitedCeiling = isUnlimitedCeiling($ceiling);
    
    if (!$isUnlimitedVisibility && $visibility === null && !$isUnlimitedCeiling && $ceiling === null) {
        return null;
    }
    
    // Categorize visibility and ceiling independently (worst-case rule applies)
    $visibilityCategory = null;
    $ceilingCategory = null;
    
    // Categorize visibility
    if ($isUnlimitedVisibility) {
        $visibilityCategory = 'VFR';
    } elseif ($visibility !== null) {
        if ($visibility < 1) {
            $visibilityCategory = 'LIFR';
        } elseif ($visibility >= 1 && $visibility < 3) {
            $visibilityCategory = 'IFR';  // FAA: 1 to less than 3 SM
        } elseif ($visibility >= 3 && $visibility <= 5) {
            $visibilityCategory = 'MVFR';  // FAA: 3 to 5 SM (inclusive)
        } else {
            $visibilityCategory = 'VFR';
        }
    }
    
    // Categorize ceiling
    if ($isUnlimitedCeiling) {
        $ceilingCategory = 'VFR';
    } elseif ($ceiling !== null) {
        if ($ceiling < 500) {
            $ceilingCategory = 'LIFR';
        } elseif ($ceiling >= 500 && $ceiling < 1000) {
            $ceilingCategory = 'IFR';
        } elseif ($ceiling >= 1000 && $ceiling < 3000) {
            $ceilingCategory = 'MVFR';
        } else {
            $ceilingCategory = 'VFR';
        }
    }
    
    // Apply worst-case rule: LIFR > IFR > MVFR > VFR
    if ($visibilityCategory !== null && $ceilingCategory !== null) {
        // VFR requires BOTH conditions to be VFR
        if ($visibilityCategory === 'VFR' && $ceilingCategory === 'VFR') {
            return 'VFR';
        }
        
        // Use most restrictive category
        $categoryOrder = ['LIFR' => 0, 'IFR' => 1, 'MVFR' => 2, 'VFR' => 3];
        $visibilityOrder = $categoryOrder[$visibilityCategory];
        $ceilingOrder = $categoryOrder[$ceilingCategory];
        
        return ($visibilityOrder < $ceilingOrder) ? $visibilityCategory : $ceilingCategory;
    }
    
    // Handle single known value
    if ($visibilityCategory !== null && $ceiling === null) {
        if ($visibilityCategory !== 'VFR') {
            return $visibilityCategory;
        }
        // VFR visibility + unlimited ceiling = VFR
        return 'VFR';
    }
    
    if ($ceilingCategory !== null && $visibility === null) {
        if ($ceilingCategory !== 'VFR') {
            return $ceilingCategory;
        }
        // VFR ceiling but unknown visibility = conservative MVFR
        return 'MVFR';
    }
    
    return null;
}

/**
 * Calculate and set flight category and CSS class
 * 
 * Helper function to calculate flight category and set both the category
 * and the corresponding CSS class in a weather data array.
 * 
 * @param array &$data Weather data array (modified in place)
 * @return void
 */
function calculateAndSetFlightCategory(&$data) {
    $data['flight_category'] = calculateFlightCategory($data);
    if ($data['flight_category'] === null) {
        $data['flight_category_class'] = '';
    } else {
        $data['flight_category_class'] = 'status-' . strtolower($data['flight_category']);
    }
}
