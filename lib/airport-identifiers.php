<?php
/**
 * Airport Identifier Utilities
 * 
 * Provides generalized functions for working with airport identifiers (ICAO, IATA, FAA).
 * Handles format validation, type detection, and ICAO code lookups from cached mapping files.
 */

/**
 * Detect the type of an airport identifier
 * 
 * Priority: IATA (3 letters) > ICAO (4 chars or 3 chars with numbers) > FAA (3-4 alphanumeric)
 * 
 * Note: 3-letter codes are ambiguous - they could be IATA or ICAO. This function
 * prioritizes IATA for 3-letter codes. For more accurate detection, use context
 * or check against known lists.
 * 
 * @param string $identifier The identifier to analyze
 * @return string|null One of: 'iata', 'icao', 'faa', or null if unknown format
 */
function detectIdentifierType(string $identifier): ?string {
    if (empty($identifier)) {
        return null;
    }
    
    $identifier = strtoupper(trim($identifier));
    
    // IATA: exactly 3 uppercase letters (highest priority for 3-letter codes)
    if (preg_match('/^[A-Z]{3}$/', $identifier) === 1) {
        return 'iata';
    }
    
    // ICAO: 4 characters OR 3 characters with numbers
    if (preg_match('/^[A-Z0-9]{4}$/', $identifier) === 1) {
        return 'icao';
    }
    if (preg_match('/^[A-Z0-9]{3}$/', $identifier) === 1 && preg_match('/[0-9]/', $identifier) === 1) {
        return 'icao';
    }
    
    // FAA: 3-4 alphanumeric characters (fallback for anything that matches the pattern)
    // FAA identifiers can start with numbers (e.g., "03S")
    if (preg_match('/^[A-Z0-9]{3,4}$/', $identifier) === 1) {
        return 'faa';
    }
    
    return null;
}

/**
 * Check if an identifier format is valid for a specific type
 * 
 * @param string $identifier The identifier to validate
 * @param string $type One of: 'icao', 'iata', 'faa'
 * @return bool True if valid format for the specified type
 */
function isValidIdentifierFormat(string $identifier, string $type): bool {
    if (empty($identifier)) {
        return false;
    }
    
    $identifier = strtoupper(trim($identifier));
    
    switch ($type) {
        case 'iata':
            return preg_match('/^[A-Z]{3}$/', $identifier) === 1;
        case 'icao':
            return preg_match('/^[A-Z0-9]{3,4}$/', $identifier) === 1;
        case 'faa':
            return preg_match('/^[A-Z0-9]{3,4}$/', $identifier) === 1;
        default:
            return false;
    }
}

/**
 * Get ICAO code from any identifier type (IATA, FAA, or ICAO itself)
 * 
 * Uses cached mapping files for lookups. If the identifier is already an ICAO code,
 * it returns it as-is (after validation).
 * 
 * @param string $identifier The identifier to look up (IATA, FAA, or ICAO)
 * @return string|null The corresponding ICAO code, or null if not found
 */
function getIcaoFromIdentifier(string $identifier): ?string {
    if (empty($identifier)) {
        return null;
    }
    
    $identifier = strtoupper(trim($identifier));
    $type = detectIdentifierType($identifier);
    
    if ($type === null) {
        return null;
    }
    
    // If it's already an ICAO code, validate and return it
    if ($type === 'icao') {
        if (isValidIdentifierFormat($identifier, 'icao')) {
            return $identifier;
        }
        return null;
    }
    
    // For IATA and FAA, look up in cached mapping files
    if ($type === 'iata') {
        return getIcaoFromIata($identifier);
    }
    
    if ($type === 'faa') {
        return getIcaoFromFaa($identifier);
    }
    
    return null;
}

/**
 * Get ICAO code from IATA code using cached mapping files
 * 
 * @param string $iataCode The IATA code to look up (e.g., "PDX")
 * @return string|null The corresponding ICAO code (e.g., "KPDX") or null if not found
 */
function getIcaoFromIata(string $iataCode): ?string {
    if (empty($iataCode)) {
        return null;
    }
    
    $iataCode = strtoupper(trim($iataCode));
    
    // Check format first
    if (!isValidIdentifierFormat($iataCode, 'iata')) {
        return null;
    }
    
    // Check APCu cache for previous lookups
    $cacheKey = 'iata_to_icao_' . $iataCode;
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch($cacheKey);
        if ($cached !== false) {
            return $cached !== '' ? (string)$cached : null;
        }
    }
    
    // Check file-based cache for the mapping
    $mappingCacheFile = __DIR__ . '/../cache/iata_to_icao_mapping.json';
    $mappingCacheMaxAge = 7 * 24 * 3600; // 7 days
    
    if (file_exists($mappingCacheFile)) {
        $cacheAge = time() - filemtime($mappingCacheFile);
        if ($cacheAge < $mappingCacheMaxAge) {
            $cachedMapping = @json_decode(file_get_contents($mappingCacheFile), true);
            if (is_array($cachedMapping) && isset($cachedMapping[$iataCode])) {
                $icao = $cachedMapping[$iataCode];
                // Cache in APCu for faster access
                if (function_exists('apcu_store')) {
                    apcu_store($cacheKey, $icao !== null ? $icao : '', 2592000); // 30 day cache
                }
                return $icao !== null ? (string)$icao : null;
            }
        }
    }
    
    // Build mapping from OurAirports CSV
    return buildIataToIcaoMapping($iataCode, $cacheKey);
}

/**
 * Get ICAO code from FAA identifier using cached mapping files
 * 
 * @param string $faaCode The FAA identifier to look up (e.g., "PDX")
 * @return string|null The corresponding ICAO code (e.g., "KPDX") or null if not found
 */
function getIcaoFromFaa(string $faaCode): ?string {
    if (empty($faaCode)) {
        return null;
    }
    
    $faaCode = strtoupper(trim($faaCode));
    
    // Check format first
    if (!isValidIdentifierFormat($faaCode, 'faa')) {
        return null;
    }
    
    // Check APCu cache for previous lookups
    $cacheKey = 'faa_to_icao_' . $faaCode;
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch($cacheKey);
        if ($cached !== false) {
            return $cached !== '' ? (string)$cached : null;
        }
    }
    
    // Check file-based cache for the mapping
    $mappingCacheFile = __DIR__ . '/../cache/faa_to_icao_mapping.json';
    $mappingCacheMaxAge = 7 * 24 * 3600; // 7 days
    
    if (file_exists($mappingCacheFile)) {
        $cacheAge = time() - filemtime($mappingCacheFile);
        if ($cacheAge < $mappingCacheMaxAge) {
            $cachedMapping = @json_decode(file_get_contents($mappingCacheFile), true);
            if (is_array($cachedMapping) && isset($cachedMapping[$faaCode])) {
                $icao = $cachedMapping[$faaCode];
                // Cache in APCu for faster access
                if (function_exists('apcu_store')) {
                    apcu_store($cacheKey, $icao !== null ? $icao : '', 2592000); // 30 day cache
                }
                return $icao !== null ? (string)$icao : null;
            }
        }
    }
    
    // Build mapping from OurAirports CSV
    return buildFaaToIcaoMapping($faaCode, $cacheKey);
}

/**
 * Build IATA to ICAO mapping from OurAirports CSV and look up a specific code
 * 
 * @param string $iataCode The IATA code to look up
 * @param string $apcuCacheKey The APCu cache key to use
 * @return string|null The ICAO code or null if not found
 */
function buildIataToIcaoMapping(string $iataCode, string $apcuCacheKey): ?string {
    try {
        $csvUrl = 'https://davidmegginson.github.io/ourairports-data/airports.csv';
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'method' => 'GET',
                'header' => 'User-Agent: AviationWX/1.0',
                'ignore_errors' => true
            ]
        ]);
        
        $csvContent = @file_get_contents($csvUrl, false, $context);
        if ($csvContent === false) {
            return null;
        }
        
        // Parse CSV and build IATA -> ICAO mapping
        // CSV columns: id,ident,type,name,latitude_deg,longitude_deg,elevation_ft,continent,iso_country,iso_region,municipality,scheduled_service,icao_code,iata_code,gps_code,local_code,...
        $iataToIcao = [];
        $lines = explode("\n", $csvContent);
        $headerSkipped = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // Skip header line
            if (!$headerSkipped) {
                $headerSkipped = true;
                continue;
            }
            
            // Parse CSV line (handle quoted fields properly)
            $fields = str_getcsv($line, ',', '"', null);
            if (count($fields) < 15) {
                continue; // Skip malformed lines
            }
            
            $icao = isset($fields[12]) ? trim($fields[12]) : '';
            $iata = isset($fields[13]) ? trim($fields[13]) : '';
            
            // Only include entries with both IATA and ICAO codes
            if (!empty($iata) && !empty($icao)) {
                $iataToIcao[$iata] = $icao;
            }
        }
        
        // Save mapping to cache
        $mappingCacheFile = __DIR__ . '/../cache/iata_to_icao_mapping.json';
        $cacheDir = dirname($mappingCacheFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        @file_put_contents($mappingCacheFile, json_encode($iataToIcao, JSON_PRETTY_PRINT));
        
        // Look up the requested IATA code
        $result = isset($iataToIcao[$iataCode]) ? $iataToIcao[$iataCode] : null;
        
        // Cache in APCu for faster access
        if (function_exists('apcu_store')) {
            apcu_store($apcuCacheKey, $result !== null ? $result : '', 2592000); // 30 day cache
        }
        
        return $result !== null ? (string)$result : null;
        
    } catch (Exception $e) {
        if (function_exists('aviationwx_log')) {
            aviationwx_log('error', 'error building IATA to ICAO mapping', ['error' => $e->getMessage()], 'app');
        }
        return null;
    }
}

/**
 * Build FAA to ICAO mapping from OurAirports CSV and look up a specific code
 * 
 * @param string $faaCode The FAA code to look up
 * @param string $apcuCacheKey The APCu cache key to use
 * @return string|null The ICAO code or null if not found
 */
function buildFaaToIcaoMapping(string $faaCode, string $apcuCacheKey): ?string {
    try {
        $csvUrl = 'https://davidmegginson.github.io/ourairports-data/airports.csv';
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'method' => 'GET',
                'header' => 'User-Agent: AviationWX/1.0',
                'ignore_errors' => true
            ]
        ]);
        
        $csvContent = @file_get_contents($csvUrl, false, $context);
        if ($csvContent === false) {
            return null;
        }
        
        // Parse CSV and build FAA -> ICAO mapping
        // CSV columns: id,ident,type,name,latitude_deg,longitude_deg,elevation_ft,continent,iso_country,iso_region,municipality,scheduled_service,icao_code,iata_code,gps_code,local_code,...
        $faaToIcao = [];
        $lines = explode("\n", $csvContent);
        $headerSkipped = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            // Skip header line
            if (!$headerSkipped) {
                $headerSkipped = true;
                continue;
            }
            
            // Parse CSV line (handle quoted fields properly)
            $fields = str_getcsv($line, ',', '"', null);
            if (count($fields) < 15) {
                continue; // Skip malformed lines
            }
            
            $icao = isset($fields[12]) ? trim($fields[12]) : '';
            $gpsCode = isset($fields[14]) ? trim($fields[14]) : ''; // GPS code is often the FAA identifier
            
            // Use GPS code as FAA identifier (for US airports, GPS code = FAA code)
            // Only include entries with both FAA (GPS) and ICAO codes
            if (!empty($gpsCode) && !empty($icao)) {
                $faaToIcao[$gpsCode] = $icao;
            }
        }
        
        // Save mapping to cache
        $mappingCacheFile = __DIR__ . '/../cache/faa_to_icao_mapping.json';
        $cacheDir = dirname($mappingCacheFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        @file_put_contents($mappingCacheFile, json_encode($faaToIcao, JSON_PRETTY_PRINT));
        
        // Look up the requested FAA code
        $result = isset($faaToIcao[$faaCode]) ? $faaToIcao[$faaCode] : null;
        
        // Cache in APCu for faster access
        if (function_exists('apcu_store')) {
            apcu_store($apcuCacheKey, $result !== null ? $result : '', 2592000); // 30 day cache
        }
        
        return $result !== null ? (string)$result : null;
        
    } catch (Exception $e) {
        if (function_exists('aviationwx_log')) {
            aviationwx_log('error', 'error building FAA to ICAO mapping', ['error' => $e->getMessage()], 'app');
        }
        return null;
    }
}

