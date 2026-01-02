<?php
/**
 * NOTAM AIXM XML Parser
 * 
 * Parses AIXM 5.1.1 XML NOTAMs and extracts structured data
 */

require_once __DIR__ . '/../logger.php';

/**
 * Parse AIXM 5.1.1 XML NOTAM string
 * 
 * Extracts key fields from NOTAM XML:
 * - NOTAM ID (series + number + year)
 * - Type (N=New, R=Replace, C=Cancel)
 * - Location identifiers
 * - Q-code/selection code
 * - Text content
 * - Start/end times (UTC)
 * - Airport name
 * - Classification
 * 
 * @param string $xmlString AIXM XML string
 * @return array|null Parsed NOTAM data or null on failure
 */
function parseNotamXml(string $xmlString): ?array {
    if (empty($xmlString)) {
        return null;
    }
    
    // Suppress XML errors and parse
    libxml_use_internal_errors(true);
    $xml = @simplexml_load_string($xmlString);
    
    if ($xml === false) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        aviationwx_log('warning', 'notam parser: failed to parse XML', [
            'errors' => array_map(function($e) { return $e->message; }, $errors)
        ], 'app');
        return null;
    }
    
    // Register namespaces
    $namespaces = $xml->getNamespaces(true);
    $ns = [
        'event' => $namespaces['event'] ?? 'http://www.aixm.aero/schema/5.1/event',
        'gml' => $namespaces['gml'] ?? 'http://www.opengis.net/gml/3.2',
        'fnse' => $namespaces['fnse'] ?? 'http://www.aixm.aero/schema/5.1/extensions/FAA/FNSE',
    ];
    
    // Extract NOTAM ID
    $series = (string)($xml->xpath('//event:series')[0] ?? '');
    $number = (string)($xml->xpath('//event:number')[0] ?? '');
    $year = (string)($xml->xpath('//event:year')[0] ?? '');
    $notamId = !empty($series) && !empty($number) && !empty($year) 
        ? $series . $number . '/' . $year 
        : '';
    
    // Extract type
    $type = (string)($xml->xpath('//event:type')[0] ?? 'N');
    
    // Extract location
    $icaoLocation = (string)($xml->xpath('//fnse:icaoLocation')[0] ?? '');
    $location = (string)($xml->xpath('//event:location')[0] ?? '');
    $finalLocation = !empty($icaoLocation) ? $icaoLocation : $location;
    
    // Extract Q-code
    $code = (string)($xml->xpath('//event:selectionCode')[0] ?? '');
    
    // Extract text
    $text = (string)($xml->xpath('//event:text')[0] ?? '');
    
    // Extract times
    $beginPosition = (string)($xml->xpath('//gml:beginPosition')[0] ?? '');
    $endPosition = (string)($xml->xpath('//gml:endPosition')[0] ?? '');
    
    // Handle permanent NOTAMs (endPosition may be empty or "PERM")
    $endTimeUtc = null;
    if (!empty($endPosition) && strtoupper($endPosition) !== 'PERM') {
        $endTimeUtc = $endPosition;
    }
    
    // Extract airport name
    $airportName = (string)($xml->xpath('//fnse:airportname')[0] ?? '');
    
    // Extract classification
    $classification = (string)($xml->xpath('//fnse:classification')[0] ?? '');
    
    return [
        'id' => $notamId,
        'type' => $type,
        'location' => $finalLocation,
        'code' => $code,
        'text' => $text,
        'start_time_utc' => $beginPosition,
        'end_time_utc' => $endTimeUtc,
        'airport_name' => $airportName,
        'classification' => $classification,
    ];
}

/**
 * Parse multiple AIXM XML NOTAM strings
 * 
 * @param array $xmlStrings Array of AIXM XML strings
 * @return array Array of parsed NOTAM data (null entries filtered out)
 */
function parseNotamXmlArray(array $xmlStrings): array {
    $parsed = [];
    foreach ($xmlStrings as $xmlString) {
        $notam = parseNotamXml($xmlString);
        if ($notam !== null) {
            $parsed[] = $notam;
        }
    }
    return $parsed;
}




