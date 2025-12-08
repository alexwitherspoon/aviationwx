<?php
/**
 * Weather Calculation Functions
 * 
 * Core weather calculation functions for aviation metrics.
 * These calculations are critical for flight safety decisions.
 */

/**
 * Calculate dewpoint from temperature and humidity
 * 
 * Uses Magnus formula to calculate dewpoint temperature in Celsius.
 * 
 * @param float|null $tempC Temperature in Celsius
 * @param float|null $humidity Relative humidity percentage (0-100)
 * @return float|null Dewpoint in Celsius, or null if inputs are invalid
 */
function calculateDewpoint($tempC, $humidity) {
    if ($tempC === null || $humidity === null) return null;
    
    $a = 6.1121;
    $b = 17.368;
    $c = 238.88;
    
    $gamma = log($humidity / 100) + ($b * $tempC) / ($c + $tempC);
    $dewpoint = ($c * $gamma) / ($b - $gamma);
    
    return $dewpoint;
}

/**
 * Calculate humidity from temperature and dewpoint
 * 
 * Uses Magnus formula to calculate relative humidity from temperature and dewpoint.
 * 
 * @param float|null $tempC Temperature in Celsius
 * @param float|null $dewpointC Dewpoint in Celsius
 * @return int|null Relative humidity percentage (0-100), or null if inputs are invalid
 */
function calculateHumidityFromDewpoint($tempC, $dewpointC) {
    if ($tempC === null || $dewpointC === null) return null;
    
    // Magnus formula
    $esat = 6.112 * exp((17.67 * $tempC) / ($tempC + 243.5));
    $e = 6.112 * exp((17.67 * $dewpointC) / ($dewpointC + 243.5));
    
    $humidity = ($e / $esat) * 100;
    
    return round($humidity);
}

/**
 * Calculate pressure altitude
 * 
 * Calculates pressure altitude in feet based on station elevation and altimeter setting.
 * Formula: Pressure Altitude = Station Elevation + (29.92 - Altimeter) × 1000
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
    
    // Calculate pressure altitude
    $pressureAlt = $stationElevation + (29.92 - $pressureInHg) * 1000;
    
    return round($pressureAlt);
}

/**
 * Calculate density altitude
 * 
 * Calculates density altitude in feet based on station elevation, temperature, and pressure.
 * Density altitude accounts for both pressure and temperature effects on aircraft performance.
 * 
 * @param array $weather Weather data array (must contain 'temperature' in Celsius and 'pressure' in inHg)
 * @param array $airport Airport configuration array (must contain 'elevation_ft')
 * @return int|null Density altitude in feet, or null if required data is missing
 */
function calculateDensityAltitude($weather, $airport) {
    if (!isset($weather['temperature']) || !isset($weather['pressure'])) {
        return null;
    }
    
    $stationElevation = $airport['elevation_ft'];
    $tempC = $weather['temperature'];
    $pressureInHg = $weather['pressure'];
    
    // Convert to feet
    $pressureAlt = $stationElevation + (29.92 - $pressureInHg) * 1000;
    
    // Calculate density altitude (simplified)
    $stdTempF = 59 - (0.003566 * $stationElevation);
    $actualTempF = ($tempC * 9/5) + 32;
    $densityAlt = $stationElevation + (120 * ($actualTempF - $stdTempF));
    
    return (int)round($densityAlt);
}

/**
 * Calculate flight category (VFR, MVFR, IFR, LIFR) based on ceiling and visibility
 * 
 * Uses standard FAA aviation weather category definitions (worst-case rule):
 * - LIFR (Magenta): Visibility < 1 mile OR Ceiling < 500 feet
 * - IFR (Red): Visibility 1 to <= 3 miles OR Ceiling 500 to < 1,000 feet
 * - MVFR (Blue): Visibility 3 to 5 miles OR Ceiling 1,000 to < 3,000 feet
 * - VFR (Green): Visibility > 3 miles AND Ceiling >= 1,000 feet (BOTH must be true)
 * 
 * For categories other than VFR, the WORST of the two conditions determines the category.
 * VFR requires BOTH conditions to meet minimums per FAA standards.
 * 
 * @param array $weather Weather data array (should contain 'ceiling' in feet and 'visibility' in SM)
 * @return string|null Flight category ('VFR', 'MVFR', 'IFR', 'LIFR'), or null if insufficient data
 */
function calculateFlightCategory($weather) {
    $ceiling = $weather['ceiling'] ?? null;
    $visibility = $weather['visibility'] ?? null;
    
    // Cannot determine category without any data
    if ($visibility === null && $ceiling === null) {
        return null;
    }
    
    // Determine category for visibility and ceiling separately (worst-case rule)
    $visibilityCategory = null;
    $ceilingCategory = null;
    
    // Categorize visibility
    if ($visibility !== null) {
        if ($visibility < 1) {
            $visibilityCategory = 'LIFR';
        } elseif ($visibility >= 1 && $visibility <= 3) {
            $visibilityCategory = 'IFR';
        } elseif ($visibility > 3 && $visibility <= 5) {
            $visibilityCategory = 'MVFR';
        } else {
            $visibilityCategory = 'VFR';  // > 5 SM
        }
    }
    
    // Categorize ceiling
    if ($ceiling !== null) {
        if ($ceiling < 500) {
            $ceilingCategory = 'LIFR';
        } elseif ($ceiling >= 500 && $ceiling < 1000) {
            $ceilingCategory = 'IFR';
        } elseif ($ceiling >= 1000 && $ceiling < 3000) {
            $ceilingCategory = 'MVFR';
        } else {
            $ceilingCategory = 'VFR';  // >= 3000 ft
        }
    }
    
    // If both are categorized, use worst-case (most restrictive) category
    // Order of restrictiveness: LIFR > IFR > MVFR > VFR
    if ($visibilityCategory !== null && $ceilingCategory !== null) {
        // VFR requires BOTH conditions to be VFR (or better)
        // If either is not VFR, use the worst of the two
        if ($visibilityCategory === 'VFR' && $ceilingCategory === 'VFR') {
            return 'VFR';
        }
        
        // Otherwise, use worst-case category
        $categoryOrder = ['LIFR' => 0, 'IFR' => 1, 'MVFR' => 2, 'VFR' => 3];
        $visibilityOrder = $categoryOrder[$visibilityCategory];
        $ceilingOrder = $categoryOrder[$ceilingCategory];
        
        return ($visibilityOrder < $ceilingOrder) ? $visibilityCategory : $ceilingCategory;
    }
    
    // If only one is known, check if VFR is still possible
    // VFR requires visibility >= 3 SM AND ceiling >= 1,000 ft
    if ($visibilityCategory !== null && $ceiling === null) {
        // If visibility is not VFR, use that category
        if ($visibilityCategory !== 'VFR') {
            return $visibilityCategory;
        }
        // If visibility is VFR and ceiling is null (unlimited/no clouds), ceiling is effectively VFR
        // Unlimited ceiling means no restriction - this is VFR conditions
        return 'VFR';
    }
    
    if ($ceilingCategory !== null && $visibility === null) {
        // If ceiling is not VFR, use that category
        if ($ceilingCategory !== 'VFR') {
            return $ceilingCategory;
        }
        // If ceiling is VFR but visibility unknown, cannot confirm VFR
        // Return MVFR as conservative estimate (visibility could be 3-5 SM)
        return 'MVFR';
    }
    
    // Should not reach here, but fallback
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
