<?php
/**
 * Address Formatting Utilities
 * 
 * Functions for formatting addresses in envelope-style format.
 * Handles addresses sourced from Google Maps and other common formats.
 */

/**
 * Format address string in envelope-style format
 * 
 * Parses address strings (typically from Google Maps) and formats them
 * in a standard envelope format with proper line breaks. Handles various
 * formats including:
 * - "City, State"
 * - "Street Address, City, State"
 * - "City, State ZIP"
 * - "Street Address, City, State ZIP"
 * - "Street Address, City, State, Country"
 * 
 * @param string $address Raw address string to format
 * @return string HTML-formatted address with <br> tags for line breaks
 */
function formatAddressEnvelope(string $address): string
{
    if (empty(trim($address))) {
        return '';
    }
    
    $address = trim($address);
    
    // Parse address components
    $components = parseAddressComponents($address);
    
    // Build formatted address lines
    $lines = [];
    
    // Street address (if present)
    if (!empty($components['street'])) {
        $lines[] = htmlspecialchars($components['street'], ENT_QUOTES, 'UTF-8');
    }
    
    // City, State ZIP line
    $cityStateZip = [];
    if (!empty($components['city'])) {
        $cityStateZip[] = htmlspecialchars($components['city'], ENT_QUOTES, 'UTF-8');
    }
    if (!empty($components['state'])) {
        $cityStateZip[] = htmlspecialchars($components['state'], ENT_QUOTES, 'UTF-8');
    }
    if (!empty($components['zip'])) {
        $cityStateZip[] = htmlspecialchars($components['zip'], ENT_QUOTES, 'UTF-8');
    }
    
    if (!empty($cityStateZip)) {
        $lines[] = implode(', ', $cityStateZip);
    }
    
    // Country (if present and not USA/US)
    if (!empty($components['country']) && 
        !in_array(strtoupper($components['country']), ['USA', 'US', 'UNITED STATES', 'UNITED STATES OF AMERICA'])) {
        $lines[] = htmlspecialchars($components['country'], ENT_QUOTES, 'UTF-8');
    }
    
    // If parsing failed, return original address (sanitized)
    if (empty($lines)) {
        return htmlspecialchars($address, ENT_QUOTES, 'UTF-8');
    }
    
    return implode('<br>', $lines);
}

/**
 * Parse address string into components
 * 
 * Extracts street, city, state, ZIP, and country from address strings.
 * Handles various formats commonly used by Google Maps.
 * 
 * @param string $address Raw address string
 * @return array {
 *   'street' => string|null,    // Street address
 *   'city' => string|null,      // City name
 *   'state' => string|null,     // State abbreviation or name
 *   'zip' => string|null,       // ZIP/postal code
 *   'country' => string|null    // Country name
 * }
 */
function parseAddressComponents(string $address): array
{
    $result = [
        'street' => null,
        'city' => null,
        'state' => null,
        'zip' => null,
        'country' => null,
    ];
    
    $address = trim($address);
    
    // Split by commas
    $parts = array_map('trim', explode(',', $address));
    $partCount = count($parts);
    
    if ($partCount === 0) {
        return $result;
    }
    
    // Handle simple "City, State" format
    if ($partCount === 2) {
        $result['city'] = $parts[0];
        // Check if second part contains ZIP
        $stateZip = parseStateZip($parts[1]);
        $result['state'] = $stateZip['state'];
        $result['zip'] = $stateZip['zip'];
        return $result;
    }
    
    // Handle "Street, City, State" or "Street, City, State ZIP"
    if ($partCount === 3) {
        $result['street'] = $parts[0];
        $result['city'] = $parts[1];
        $stateZip = parseStateZip($parts[2]);
        $result['state'] = $stateZip['state'];
        $result['zip'] = $stateZip['zip'];
        return $result;
    }
    
    // Handle "Street, City, State, Country" or "Street, City, State ZIP, Country"
    if ($partCount === 4) {
        $result['street'] = $parts[0];
        $result['city'] = $parts[1];
        $stateZip = parseStateZip($parts[2]);
        $result['state'] = $stateZip['state'];
        $result['zip'] = $stateZip['zip'];
        $result['country'] = $parts[3];
        return $result;
    }
    
    // Handle longer formats - try to intelligently parse
    if ($partCount > 4) {
        // Last part is likely country
        $result['country'] = $parts[$partCount - 1];
        
        // Second-to-last is likely state (possibly with ZIP)
        $stateZip = parseStateZip($parts[$partCount - 2]);
        $result['state'] = $stateZip['state'];
        $result['zip'] = $stateZip['zip'];
        
        // Third-to-last is likely city
        if ($partCount >= 3) {
            $result['city'] = $parts[$partCount - 3];
        }
        
        // Everything before city is street address
        if ($partCount > 3) {
            $streetParts = array_slice($parts, 0, $partCount - 3);
            $result['street'] = implode(', ', $streetParts);
        }
        
        return $result;
    }
    
    // Fallback: if we can't parse, treat entire string as city
    if (empty($result['city']) && empty($result['street'])) {
        $result['city'] = $address;
    }
    
    return $result;
}

/**
 * Parse state and ZIP code from a string
 * 
 * Extracts state abbreviation/name and ZIP code from strings like:
 * - "OR 97201"
 * - "Oregon 97201"
 * - "97201"
 * - "OR"
 * 
 * @param string $stateZip String containing state and/or ZIP
 * @return array {
 *   'state' => string|null,
 *   'zip' => string|null
 * }
 */
function parseStateZip(string $stateZip): array
{
    $result = ['state' => null, 'zip' => null];
    
    $stateZip = trim($stateZip);
    
    // Match ZIP code pattern (5 digits, or 5+4 format)
    if (preg_match('/\b(\d{5}(?:-\d{4})?)\b/', $stateZip, $zipMatches)) {
        $result['zip'] = $zipMatches[1];
        // Remove ZIP from string to get state
        $stateZip = trim(preg_replace('/\b\d{5}(?:-\d{4})?\b/', '', $stateZip));
    }
    
    // Remaining string is state
    if (!empty(trim($stateZip))) {
        $result['state'] = trim($stateZip);
    }
    
    return $result;
}
