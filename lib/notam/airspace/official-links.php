<?php
/**
 * Authority-aware official NOTAM search links for map popups.
 */

declare(strict_types=1);

/**
 * Build an official NOTAM details URL for a stored airspace record.
 *
 * @param array<string, mixed> $record AirspaceRecord
 * @return array{url: string, label: string}
 */
function notamAirspaceOfficialLinkForRecord(array $record): array
{
    $id = trim((string) ($record['notam_id'] ?? ''));
    $authority = strtolower(trim((string) ($record['authority'] ?? '')));
    $sources = $record['record_sources'] ?? [];
    if (!is_array($sources)) {
        $sources = [];
    }

    $custom = trim((string) ($record['official_search_url'] ?? ''));
    $custom = notamAirspaceSanitizeOfficialSearchUrl($custom);

    $knownFaaSource = in_array('nms', $sources, true)
        || in_array('faa_tfr_wfs', $sources, true)
        || in_array('nms_fdc_bulk', $sources, true);

    // Explicit FAA, known FAA sources, or legacy empty-authority rows without a custom URL.
    $isFaa = $authority === 'faa'
        || $knownFaaSource
        || ($authority === '' && $custom === '');

    if ($isFaa) {
        $url = 'https://notams.aim.faa.gov/notamSearch/search';
        if ($id !== '') {
            $url .= '?notamNumber=' . rawurlencode($id);
        }

        return [
            'url' => $url,
            'label' => 'Details on FAA Notam Search',
        ];
    }

    // Non-FAA authorities: adapters set authority and/or official_search_url.
    if ($custom !== '') {
        return [
            'url' => $custom,
            'label' => 'Details from issuing authority',
        ];
    }

    return [
        'url' => '',
        'label' => 'Verify with the issuing aviation authority',
    ];
}

/**
 * Allow only http(s) official-search URLs for map popup CTAs.
 */
function notamAirspaceSanitizeOfficialSearchUrl(string $url): string
{
    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }

    return $url;
}
