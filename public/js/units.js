/**
 * Unit Conversion Library - Safety Critical (JavaScript)
 * 
 * Centralized unit conversion functions for weather data.
 * All conversions use ICAO/WMO standard conversion factors.
 * 
 * CRITICAL: These conversion factors are safety-critical for aviation.
 * Incorrect conversions can cause dangerous altimeter/performance calculations.
 * All factors are verified against authoritative sources and TDD-tested.
 * 
 * CRITICAL: Conversion factors MUST match lib/units.php exactly!
 * Both implementations are tested with identical reference values.
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

(function(window) {
    'use strict';
    
    // ========================================================================
    // CONVERSION CONSTANTS - EXACT VALUES
    // These MUST match lib/units.php constants exactly!
    // ========================================================================
    
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
    
    // ========================================================================
    // PRESSURE CONVERSIONS (hPa ↔ inHg)
    // ========================================================================
    
    /**
     * Convert hectoPascals to inches of mercury
     * @param {number} hpa - Pressure in hectoPascals
     * @returns {number} Pressure in inches of mercury
     */
    function hpaToInhg(hpa) {
        return hpa / HPA_PER_INHG;
    }
    
    /**
     * Convert inches of mercury to hectoPascals
     * @param {number} inhg - Pressure in inches of mercury
     * @returns {number} Pressure in hectoPascals
     */
    function inhgToHpa(inhg) {
        return inhg * HPA_PER_INHG;
    }
    
    // ========================================================================
    // VISIBILITY CONVERSIONS (meters ↔ statute miles)
    // ========================================================================
    
    /**
     * Convert statute miles to meters
     * @param {number} miles - Visibility in statute miles
     * @returns {number} Visibility in meters
     */
    function statuteMilesToMeters(miles) {
        return miles * METERS_PER_STATUTE_MILE;
    }
    
    /**
     * Convert meters to statute miles
     * @param {number} meters - Visibility in meters
     * @returns {number} Visibility in statute miles
     */
    function metersToStatuteMiles(meters) {
        return meters / METERS_PER_STATUTE_MILE;
    }
    
    /**
     * Convert statute miles to kilometers
     * 1 statute mile = 1.609344 km (exact, derived from 1 SM = 1609.344 meters)
     * @param {number} miles - Visibility in statute miles
     * @returns {number} Visibility in kilometers
     */
    function statuteMilesToKilometers(miles) {
        return miles * METERS_PER_STATUTE_MILE / 1000;
    }
    
    /**
     * Convert kilometers to statute miles
     * @param {number} km - Visibility in kilometers
     * @returns {number} Visibility in statute miles
     */
    function kilometersToStatuteMiles(km) {
        return km * 1000 / METERS_PER_STATUTE_MILE;
    }
    
    // ========================================================================
    // PRECIPITATION CONVERSIONS (mm ↔ inches)
    // ========================================================================
    
    /**
     * Convert inches to millimeters
     * @param {number} inches - Precipitation in inches
     * @returns {number} Precipitation in millimeters
     */
    function inchesToMm(inches) {
        return inches * MM_PER_INCH;
    }
    
    /**
     * Convert millimeters to inches
     * @param {number} mm - Precipitation in millimeters
     * @returns {number} Precipitation in inches
     */
    function mmToInches(mm) {
        return mm / MM_PER_INCH;
    }
    
    // ========================================================================
    // TEMPERATURE CONVERSIONS (Celsius ↔ Fahrenheit)
    // ========================================================================
    
    /**
     * Convert Celsius to Fahrenheit
     * Formula: °F = (°C × 9/5) + 32
     * @param {number} celsius - Temperature in Celsius
     * @returns {number} Temperature in Fahrenheit
     */
    function celsiusToFahrenheit(celsius) {
        return (celsius * 9 / 5) + 32;
    }
    
    /**
     * Convert Fahrenheit to Celsius
     * Formula: °C = (°F - 32) × 5/9
     * @param {number} fahrenheit - Temperature in Fahrenheit
     * @returns {number} Temperature in Celsius
     */
    function fahrenheitToCelsius(fahrenheit) {
        return (fahrenheit - 32) * 5 / 9;
    }
    
    // ========================================================================
    // WIND SPEED CONVERSIONS (knots ↔ mph ↔ km/h)
    // ========================================================================
    
    /**
     * Convert knots to kilometers per hour
     * @param {number} knots - Wind speed in knots
     * @returns {number} Wind speed in km/h
     */
    function knotsToKmh(knots) {
        return knots * KMH_PER_KNOT;
    }
    
    /**
     * Convert kilometers per hour to knots
     * @param {number} kmh - Wind speed in km/h
     * @returns {number} Wind speed in knots
     */
    function kmhToKnots(kmh) {
        return kmh / KMH_PER_KNOT;
    }
    
    /**
     * Convert knots to miles per hour
     * @param {number} knots - Wind speed in knots
     * @returns {number} Wind speed in mph
     */
    function knotsToMph(knots) {
        return knots * MPH_PER_KNOT;
    }
    
    /**
     * Convert miles per hour to knots
     * @param {number} mph - Wind speed in mph
     * @returns {number} Wind speed in knots
     */
    function mphToKnots(mph) {
        return mph / MPH_PER_KNOT;
    }
    
    // ========================================================================
    // ALTITUDE CONVERSIONS (feet ↔ meters)
    // ========================================================================
    
    /**
     * Convert feet to meters
     * @param {number} feet - Altitude in feet
     * @returns {number} Altitude in meters
     */
    function feetToMeters(feet) {
        return feet * METERS_PER_FOOT;
    }
    
    /**
     * Convert meters to feet
     * @param {number} meters - Altitude in meters
     * @returns {number} Altitude in feet
     */
    function metersToFeet(meters) {
        return meters / METERS_PER_FOOT;
    }
    
    // ========================================================================
    // GENERIC CONVERSION FUNCTION
    // ========================================================================
    
    /**
     * Convert a value from one unit to another
     * @param {number} value - The value to convert
     * @param {string} fromUnit - Source unit (e.g., 'C', 'hPa', 'm')
     * @param {string} toUnit - Target unit (e.g., 'F', 'inHg', 'SM')
     * @returns {number} The converted value
     * @throws {Error} If conversion is not supported
     */
    function convert(value, fromUnit, toUnit) {
        // Same unit, no conversion needed
        if (fromUnit === toUnit) {
            return value;
        }
        
        const conversions = {
            // Temperature
            'C->F': celsiusToFahrenheit,
            'F->C': fahrenheitToCelsius,
            
            // Pressure
            'hPa->inHg': hpaToInhg,
            'inHg->hPa': inhgToHpa,
            
            // Visibility
            'm->SM': metersToStatuteMiles,
            'SM->m': statuteMilesToMeters,
            'SM->km': statuteMilesToKilometers,
            'km->SM': kilometersToStatuteMiles,

            // Precipitation
            'mm->in': mmToInches,
            'in->mm': inchesToMm,
            
            // Wind speed
            'kt->kmh': knotsToKmh,
            'kmh->kt': kmhToKnots,
            'kt->mph': knotsToMph,
            'mph->kt': mphToKnots,
            
            // Altitude
            'ft->m': feetToMeters,
            'm->ft': metersToFeet,
        };
        
        const key = fromUnit + '->' + toUnit;
        const converter = conversions[key];
        
        if (!converter) {
            throw new Error('Unsupported conversion: ' + fromUnit + ' to ' + toUnit);
        }
        
        return converter(value);
    }
    
    // ========================================================================
    // EXPORT TO GLOBAL NAMESPACE
    // ========================================================================
    
    window.AviationWX = window.AviationWX || {};
    window.AviationWX.units = {
        // Constants (exposed for verification)
        HPA_PER_INHG: HPA_PER_INHG,
        METERS_PER_STATUTE_MILE: METERS_PER_STATUTE_MILE,
        MM_PER_INCH: MM_PER_INCH,
        KMH_PER_KNOT: KMH_PER_KNOT,
        MPH_PER_KNOT: MPH_PER_KNOT,
        METERS_PER_FOOT: METERS_PER_FOOT,
        
        // Pressure
        hpaToInhg: hpaToInhg,
        inhgToHpa: inhgToHpa,
        
        // Visibility
        statuteMilesToMeters: statuteMilesToMeters,
        metersToStatuteMiles: metersToStatuteMiles,
        statuteMilesToKilometers: statuteMilesToKilometers,
        kilometersToStatuteMiles: kilometersToStatuteMiles,

        // Precipitation
        inchesToMm: inchesToMm,
        mmToInches: mmToInches,
        
        // Temperature
        celsiusToFahrenheit: celsiusToFahrenheit,
        fahrenheitToCelsius: fahrenheitToCelsius,
        
        // Wind
        knotsToKmh: knotsToKmh,
        kmhToKnots: kmhToKnots,
        knotsToMph: knotsToMph,
        mphToKnots: mphToKnots,
        
        // Altitude
        feetToMeters: feetToMeters,
        metersToFeet: metersToFeet,
        
        // Generic converter
        convert: convert,
    };
    
})(typeof window !== 'undefined' ? window : global);
