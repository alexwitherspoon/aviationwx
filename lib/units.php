<?php
/**
 * Unit Conversion Library - Safety Critical
 * 
 * Centralized unit conversion functions for weather data.
 * All conversions use ICAO/WMO standard conversion factors.
 * 
 * CRITICAL: These conversion factors are safety-critical for aviation.
 * Incorrect conversions can cause dangerous altimeter/performance calculations.
 * All factors are verified against authoritative sources and TDD-tested.
 * 
 * Internal standard units (ICAO):
 * - Temperature: Celsius (°C)
 * - Pressure: hectoPascals (hPa)
 * - Visibility: meters (m)
 * - Precipitation: millimeters (mm)
 * - Wind speed: knots (kt)
 * - Altitude: feet (ft)
 * 
 * Sources:
 * - BIPM SI Brochure (exact metric definitions)
 * - ICAO Doc 8400 (Abbreviations and Codes)
 * - US Code Title 15 Section 205 (legal definitions)
 * - International Yard and Pound Agreement (1959)
 * - NOAA/NWS conversion tables
 */

// ============================================================================
// CONVERSION CONSTANTS - EXACT VALUES
// ============================================================================

/**
 * Pressure conversion factor
 * 1 inHg = 33.8639 hPa (ICAO standard)
 */
const HPA_PER_INHG = 33.8639;

/**
 * Visibility conversion factor
 * 1 statute mile = 1609.344 meters (exact, US Code Title 15 §205)
 */
const METERS_PER_STATUTE_MILE = 1609.344;

/**
 * Precipitation conversion factor
 * 1 inch = 25.4 mm (exact, International Yard and Pound Agreement 1959)
 */
const MM_PER_INCH = 25.4;

/**
 * Wind speed conversion factors
 * 1 kt = 1.852 km/h (exact, derived from nautical mile = 1852 meters)
 * 1 kt = 1.15078 mph (NOAA standard)
 */
const KMH_PER_KNOT = 1.852;
const MPH_PER_KNOT = 1.15078;

/**
 * Altitude conversion factor
 * 1 foot = 0.3048 meters (exact, International Yard and Pound Agreement 1959)
 */
const METERS_PER_FOOT = 0.3048;


// ============================================================================
// PRESSURE CONVERSIONS (hPa ↔ inHg)
// ============================================================================

/**
 * Convert hectoPascals to inches of mercury
 * 
 * @param float $hpa Pressure in hectoPascals
 * @return float Pressure in inches of mercury
 */
function hpaToInhg(float $hpa): float
{
    return $hpa / HPA_PER_INHG;
}

/**
 * Convert inches of mercury to hectoPascals
 * 
 * @param float $inhg Pressure in inches of mercury
 * @return float Pressure in hectoPascals
 */
function inhgToHpa(float $inhg): float
{
    return $inhg * HPA_PER_INHG;
}


// ============================================================================
// VISIBILITY CONVERSIONS (meters ↔ statute miles)
// ============================================================================

/**
 * Convert statute miles to meters
 * 
 * @param float $miles Visibility in statute miles
 * @return float Visibility in meters
 */
function statuteMilesToMeters(float $miles): float
{
    return $miles * METERS_PER_STATUTE_MILE;
}

/**
 * Convert meters to statute miles
 * 
 * @param float $meters Visibility in meters
 * @return float Visibility in statute miles
 */
function metersToStatuteMiles(float $meters): float
{
    return $meters / METERS_PER_STATUTE_MILE;
}

/**
 * Convert statute miles to kilometers
 * 
 * 1 statute mile = 1.609344 km (exact, derived from 1 SM = 1609.344 meters)
 * 
 * @param float $miles Visibility in statute miles
 * @return float Visibility in kilometers
 */
function statuteMilesToKilometers(float $miles): float
{
    return $miles * METERS_PER_STATUTE_MILE / 1000;
}

/**
 * Convert kilometers to statute miles
 * 
 * @param float $km Visibility in kilometers
 * @return float Visibility in statute miles
 */
function kilometersToStatuteMiles(float $km): float
{
    return $km * 1000 / METERS_PER_STATUTE_MILE;
}


// ============================================================================
// PRECIPITATION CONVERSIONS (mm ↔ inches)
// ============================================================================

/**
 * Convert inches to millimeters
 * 
 * @param float $inches Precipitation in inches
 * @return float Precipitation in millimeters
 */
function inchesToMm(float $inches): float
{
    return $inches * MM_PER_INCH;
}

/**
 * Convert millimeters to inches
 * 
 * @param float $mm Precipitation in millimeters
 * @return float Precipitation in inches
 */
function mmToInches(float $mm): float
{
    return $mm / MM_PER_INCH;
}


// ============================================================================
// TEMPERATURE CONVERSIONS (Celsius ↔ Fahrenheit)
// ============================================================================

/**
 * Convert Celsius to Fahrenheit
 * Formula: °F = (°C × 9/5) + 32
 * 
 * @param float $celsius Temperature in Celsius
 * @return float Temperature in Fahrenheit
 */
function celsiusToFahrenheit(float $celsius): float
{
    return ($celsius * 9 / 5) + 32;
}

/**
 * Convert Fahrenheit to Celsius
 * Formula: °C = (°F - 32) × 5/9
 * 
 * @param float $fahrenheit Temperature in Fahrenheit
 * @return float Temperature in Celsius
 */
function fahrenheitToCelsius(float $fahrenheit): float
{
    return ($fahrenheit - 32) * 5 / 9;
}


// ============================================================================
// WIND SPEED CONVERSIONS (knots ↔ mph ↔ km/h)
// ============================================================================

/**
 * Convert knots to kilometers per hour
 * 
 * @param float $knots Wind speed in knots
 * @return float Wind speed in km/h
 */
function knotsToKmh(float $knots): float
{
    return $knots * KMH_PER_KNOT;
}

/**
 * Convert kilometers per hour to knots
 * 
 * @param float $kmh Wind speed in km/h
 * @return float Wind speed in knots
 */
function kmhToKnots(float $kmh): float
{
    return $kmh / KMH_PER_KNOT;
}

/**
 * Convert knots to miles per hour
 * 
 * @param float $knots Wind speed in knots
 * @return float Wind speed in mph
 */
function knotsToMph(float $knots): float
{
    return $knots * MPH_PER_KNOT;
}

/**
 * Convert miles per hour to knots
 * 
 * @param float $mph Wind speed in mph
 * @return float Wind speed in knots
 */
function mphToKnots(float $mph): float
{
    return $mph / MPH_PER_KNOT;
}


// ============================================================================
// ALTITUDE CONVERSIONS (feet ↔ meters)
// ============================================================================

/**
 * Convert feet to meters
 * 
 * @param float $feet Altitude in feet
 * @return float Altitude in meters
 */
function feetToMeters(float $feet): float
{
    return $feet * METERS_PER_FOOT;
}

/**
 * Convert meters to feet
 * 
 * @param float $meters Altitude in meters
 * @return float Altitude in feet
 */
function metersToFeet(float $meters): float
{
    return $meters / METERS_PER_FOOT;
}


// ============================================================================
// GENERIC CONVERSION FUNCTION
// ============================================================================

/**
 * Convert a value from one unit to another
 * 
 * @param float $value The value to convert
 * @param string $fromUnit Source unit (e.g., 'C', 'hPa', 'm')
 * @param string $toUnit Target unit (e.g., 'F', 'inHg', 'SM')
 * @return float The converted value
 * @throws \InvalidArgumentException If conversion is not supported
 */
function convert(float $value, string $fromUnit, string $toUnit): float
{
    // Same unit, no conversion needed
    if ($fromUnit === $toUnit) {
        return $value;
    }
    
    $key = $fromUnit . '->' . $toUnit;
    
    return match ($key) {
        // Temperature
        'C->F' => celsiusToFahrenheit($value),
        'F->C' => fahrenheitToCelsius($value),
        
        // Pressure
        'hPa->inHg' => hpaToInhg($value),
        'inHg->hPa' => inhgToHpa($value),
        
        // Visibility
        'm->SM' => metersToStatuteMiles($value),
        'SM->m' => statuteMilesToMeters($value),
        'SM->km' => statuteMilesToKilometers($value),
        'km->SM' => kilometersToStatuteMiles($value),

        // Precipitation
        'mm->in' => mmToInches($value),
        'in->mm' => inchesToMm($value),
        
        // Wind speed
        'kt->kmh' => knotsToKmh($value),
        'kmh->kt' => kmhToKnots($value),
        'kt->mph' => knotsToMph($value),
        'mph->kt' => mphToKnots($value),
        
        // Altitude
        'ft->m' => feetToMeters($value),
        'm->ft' => metersToFeet($value),
        
        default => throw new \InvalidArgumentException(
            "Unsupported conversion: $fromUnit to $toUnit"
        ),
    };
}
