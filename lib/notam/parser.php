<?php
/**
 * NOTAM AIXM XML Parser
 *
 * Parses AIXM 5.1.1 XML NOTAMs and extracts structured data
 */

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/schedule.php';

/**
 * Build a pilot-facing NOTAM id from AIXM fields (ICAO or DOM/local formats).
 *
 * @param string $series ICAO series letter when present
 * @param string $number NOTAM number or DOM nn/nnn group
 * @param string $year Four-digit year
 * @param string $simpleText LOCAL_FORMAT simpleText when present
 * @return string Public id (e.g. A1234/2026 or 06/001/2026) or empty when unknown
 */
function notamResolvePublicIdFromAixmFields(
    string $series,
    string $number,
    string $year,
    string $simpleText,
): string {
    $series = trim($series);
    $number = trim($number);
    $year = trim($year);
    $simpleText = trim($simpleText);

    if ($series !== '' && $number !== '' && $year !== '') {
        return $series . $number . '/' . $year;
    }

    if ($simpleText !== '' && preg_match('/!\S+\s+(\d{2}\/\d{3})\b/', $simpleText, $matches) === 1) {
        $domNumber = $matches[1];
        if ($year !== '') {
            return $domNumber . '/' . $year;
        }

        return $domNumber;
    }

    if ($number !== '' && $year !== '') {
        return $number . '/' . $year;
    }

    return '';
}

/**
 * Parse AIXM 5.1.1 XML NOTAM string
 *
 * Extracts key fields from NOTAM XML:
 * - NOTAM ID (series + number + year, or DOM simpleText fallback)
 * - Type (N=New, R=Replace, C=Cancel)
 * - Location identifiers
 * - Q-code/selection code
 * - FAA scenario and runway-event hints (DOM runway closures)
 * - Text content
 * - Start/end times (UTC)
 * - Airport name
 * - Classification
 * - effective_segments and schedule_source from {@see enrichParsedNotamWithSchedule()} (FAA EFFECTIVE windows)
 *
 * @param string $xmlString AIXM XML string
 * @return array<string, mixed>|null Parsed NOTAM data or null on failure
 */
function parseNotamXml(string $xmlString): ?array {
    if (empty($xmlString)) {
        return null;
    }

    libxml_use_internal_errors(true);
    $xml = @simplexml_load_string($xmlString);

    if ($xml === false) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        aviationwx_log('warning', 'notam parser: failed to parse XML', [
            'errors' => array_map(static function ($e) {
                return $e->message;
            }, $errors),
        ], 'app');
        return null;
    }

    $namespaces = $xml->getNamespaces(true);
    $eventNs = $namespaces['event'] ?? 'http://www.aixm.aero/schema/5.1/event';
    $aixmNs = $namespaces['aixm'] ?? 'http://www.aixm.aero/schema/5.1';
    $xml->registerXPathNamespace('event', $eventNs);
    $xml->registerXPathNamespace('aixm', $aixmNs);
    $xml->registerXPathNamespace('gml', $namespaces['gml'] ?? 'http://www.opengis.net/gml/3.2');
    $xml->registerXPathNamespace('fnse', $namespaces['fnse'] ?? 'http://www.aixm.aero/schema/5.1/extensions/FAA/FNSE');

    $series = (string) ($xml->xpath('//event:series')[0] ?? '');
    $number = (string) ($xml->xpath('//event:number')[0] ?? '');
    $year = (string) ($xml->xpath('//event:year')[0] ?? '');
    $simpleText = (string) ($xml->xpath('//event:simpleText')[0] ?? '');
    $notamId = notamResolvePublicIdFromAixmFields($series, $number, $year, $simpleText);

    $type = (string) ($xml->xpath('//event:type')[0] ?? 'N');

    $icaoLocation = (string) ($xml->xpath('//fnse:icaoLocation')[0] ?? '');
    $location = (string) ($xml->xpath('//event:location')[0] ?? '');
    $finalLocation = !empty($icaoLocation) ? $icaoLocation : $location;

    $code = (string) ($xml->xpath('//event:selectionCode')[0] ?? '');
    $text = (string) ($xml->xpath('//event:text')[0] ?? '');

    $beginPosition = (string) ($xml->xpath('//gml:beginPosition')[0] ?? '');
    $endPosition = (string) ($xml->xpath('//gml:endPosition')[0] ?? '');

    $endTimeUtc = null;
    if (!empty($endPosition) && strtoupper($endPosition) !== 'PERM') {
        $endTimeUtc = $endPosition;
    }

    $airportName = (string) ($xml->xpath('//fnse:airportname')[0] ?? '');
    $classification = (string) ($xml->xpath('//fnse:classification')[0] ?? '');
    $scenario = (string) ($xml->xpath('//event:scenario')[0] ?? '');
    $aixmRunwayNodes = $xml->xpath('//aixm:Runway');

    $result = [
        'id' => $notamId,
        'type' => $type,
        'location' => $finalLocation,
        'code' => $code,
        'text' => $text,
        'start_time_utc' => $beginPosition,
        'end_time_utc' => $endTimeUtc,
        'airport_name' => $airportName,
        'classification' => $classification,
        'scenario' => $scenario,
        'aixm_runway_event' => is_array($aixmRunwayNodes) && $aixmRunwayNodes !== [],
    ];
    enrichParsedNotamWithSchedule($result);
    return $result;
}

/**
 * Parse multiple AIXM XML NOTAM strings
 *
 * @param array<int, string> $xmlStrings Array of AIXM XML strings
 * @return array<int, array<string, mixed>> Parsed NOTAM rows (null entries filtered out)
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
