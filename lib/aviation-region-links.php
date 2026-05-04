<?php
/**
 * Aviation link regions and built-in external link profiles.
 *
 * Effective ISO is computed in `lib/config.php`; this module maps alpha-2 to a link-region id and
 * resolves dashboard and Public API built-ins. The US profile omits SkyVector (AirNav agreement).
 * AirNav appears only in the `us` profile and in `unknown` override rows (type `airnav`); other
 * regions use regional authority URLs or SkyVector, not a duplicate AirNav row.
 * Adding a region or static URL implies updating profiles here, `aviationLinkRegionFromIso()`, tests,
 * and scheduled URL probes (which read profiles plus sample programmatic URLs).
 *
 * @package AviationWX
 */

/**
 * Region id when effective country cannot be mapped to a supported link bundle.
 */
const AVIATION_LINK_REGION_UNKNOWN = 'unknown';

/**
 * ISO 3166-1 alpha-2 codes mapped to the shared Europe link bundle (EU members, EEA, CH, and select microstates).
 *
 * @return array<string, true>
 */
function aviationLinkRegionEuropeIsoSet(): array
{
    static $set = null;
    if ($set !== null) {
        return $set;
    }
    $codes = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT',
        'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        'NO', 'IS', 'LI', 'CH', 'AD', 'MC', 'SM', 'VA',
    ];
    $set = [];
    foreach ($codes as $c) {
        $set[$c] = true;
    }
    return $set;
}

/**
 * Map effective ISO 3166-1 alpha-2 to a link-region id.
 *
 * @param string|null $iso Uppercase alpha-2 from getEffectiveIso3166Alpha2ForAirport(), or null
 * @return string Region id (e.g. us, ca, eu) or AVIATION_LINK_REGION_UNKNOWN
 */
function aviationLinkRegionFromIso(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return AVIATION_LINK_REGION_UNKNOWN;
    }
    $iso = strtoupper(trim($iso));
    $usFamily = ['US', 'AS', 'GU', 'MP', 'PR', 'VI', 'UM'];
    if (in_array($iso, $usFamily, true)) {
        return 'us';
    }
    if ($iso === 'CA') {
        return 'ca';
    }
    if ($iso === 'AU') {
        return 'au';
    }
    if ($iso === 'NZ') {
        return 'nz';
    }
    if ($iso === 'GB') {
        return 'gb';
    }
    if ($iso === 'MX') {
        return 'mx';
    }
    if ($iso === 'BR') {
        return 'br';
    }
    if ($iso === 'JP') {
        return 'jp';
    }
    if (isset(aviationLinkRegionEuropeIsoSet()[$iso])) {
        return 'eu';
    }
    return AVIATION_LINK_REGION_UNKNOWN;
}

/**
 * Built-in link profile map keyed by link-region id.
 *
 * Single source for `aviationRegionBuiltinLinkDefinitions()` and for enumerating static `https://`
 * URLs in `aviationRegionBuiltinHttpsUrlsForPeriodicHealthCheck()` so new profile URLs enter CI
 * probes without a second list.
 *
 * @return array<string, list<array{id: string, label: string, url?: string, type?: string}>>
 */
function aviationRegionBuiltinProfilesMap(): array
{
    static $profiles = null;
    if ($profiles !== null) {
        return $profiles;
    }
    $profiles = [
        'us' => [
            ['id' => 'airnav', 'label' => 'AirNav', 'type' => 'airnav'],
            ['id' => 'faa_weather', 'label' => 'FAA Weather', 'type' => 'faa_weather_cams'],
            ['id' => 'foreflight', 'label' => 'ForeFlight', 'type' => 'foreflight'],
        ],
        'ca' => [
            ['id' => 'regional_weather', 'label' => 'NAV Canada Weather', 'url' => 'https://plan.navcanada.ca/wxrecall/'],
            ['id' => 'skyvector', 'label' => 'SkyVector', 'type' => 'skyvector'],
            ['id' => 'foreflight', 'label' => 'ForeFlight', 'type' => 'foreflight'],
        ],
        'au' => [
            ['id' => 'regional_weather', 'label' => 'Airservices Weather Cams', 'url' => 'https://weathercams.airservicesaustralia.com/'],
            ['id' => 'skyvector', 'label' => 'SkyVector', 'type' => 'skyvector'],
            ['id' => 'foreflight', 'label' => 'ForeFlight', 'type' => 'foreflight'],
        ],
        'nz' => [
            ['id' => 'regional_weather', 'label' => 'NZ CAA Meteorology', 'url' => 'https://www.aviation.govt.nz/airspace-and-aerodromes/meteorology/'],
            ['id' => 'preflight', 'label' => 'PreFlight (MetService)', 'url' => 'https://gopreflight.co.nz/'],
            ['id' => 'skyvector', 'label' => 'SkyVector', 'type' => 'skyvector'],
            ['id' => 'foreflight', 'label' => 'ForeFlight', 'type' => 'foreflight'],
        ],
        'gb' => [
            ['id' => 'regional_weather', 'label' => 'Met Office Aviation', 'url' => 'https://www.metoffice.gov.uk/aviation'],
            ['id' => 'skyvector', 'label' => 'SkyVector', 'type' => 'skyvector'],
            ['id' => 'foreflight', 'label' => 'ForeFlight', 'type' => 'foreflight'],
        ],
        'eu' => [
            ['id' => 'regional_weather', 'label' => 'EUROCONTROL Network Manager', 'url' => 'https://www.eurocontrol.int/network-manager'],
            ['id' => 'meteoblue', 'label' => 'meteoblue', 'type' => 'meteoblue_current'],
            ['id' => 'skyvector', 'label' => 'SkyVector', 'type' => 'skyvector'],
            ['id' => 'foreflight', 'label' => 'ForeFlight', 'type' => 'foreflight'],
        ],
        'mx' => [
            ['id' => 'regional_weather', 'label' => 'SENEAM', 'url' => 'https://www.gob.mx/seneam'],
            ['id' => 'skyvector', 'label' => 'SkyVector', 'type' => 'skyvector'],
            ['id' => 'foreflight', 'label' => 'ForeFlight', 'type' => 'foreflight'],
        ],
        'br' => [
            ['id' => 'regional_weather', 'label' => 'REDEMET', 'url' => 'https://www.redemet.aer.mil.br/'],
            ['id' => 'skyvector', 'label' => 'SkyVector', 'type' => 'skyvector'],
            ['id' => 'foreflight', 'label' => 'ForeFlight', 'type' => 'foreflight'],
        ],
        'jp' => [
            ['id' => 'regional_weather', 'label' => 'JMA (English)', 'url' => 'https://www.jma.go.jp/jma/indexe.html'],
            ['id' => 'skyvector', 'label' => 'SkyVector', 'type' => 'skyvector'],
            ['id' => 'foreflight', 'label' => 'ForeFlight', 'type' => 'foreflight'],
        ],
        // Unknown: rows are id+label only so overrides still run; auto-built URLs stay off until ISO maps to a profile.
        AVIATION_LINK_REGION_UNKNOWN => [
            ['id' => 'airnav', 'label' => 'AirNav'],
            ['id' => 'faa_weather', 'label' => 'FAA Weather'],
            ['id' => 'regional_weather', 'label' => 'Regional weather'],
            ['id' => 'foreflight', 'label' => 'ForeFlight'],
        ],
    ];

    return $profiles;
}

/**
 * Built-in link definitions for a region (order preserved). Each item: id, label, and either
 * static url or type for programmatic URLs. The unknown region lists override slots only (no
 * type/url); built-in URLs are never auto-generated there.
 *
 * @param string $regionId Region id from aviationLinkRegionFromIso()
 * @return list<array{id: string, label: string, url?: string, type?: string}>
 */
function aviationRegionBuiltinLinkDefinitions(string $regionId): array
{
    return aviationRegionBuiltinProfilesMap()[$regionId] ?? [];
}

/**
 * HTTPS URLs for scheduled reachability checks (CI and `scripts/check-builtin-aviation-links.php`).
 *
 * Includes every static `https://` profile `url`, one sample {@see aviationRegionMeteoblueCurrentUrl()}
 * path, and sample AirNav, SkyVector, and FAA Weather Cams URLs from {@see aviationRegionResolveBuiltinLinkUrl()}.
 * ForeFlight app URIs are skipped (not HTTP).
 *
 * @return list<string>
 */
function aviationRegionBuiltinHttpsUrlsForPeriodicHealthCheck(): array
{
    $seen = [];
    $out = [];
    $add = static function (string $u) use (&$seen, &$out): void {
        if ($u === '' || !str_starts_with($u, 'https://')) {
            return;
        }
        if (isset($seen[$u])) {
            return;
        }
        $seen[$u] = true;
        $out[] = $u;
    };

    foreach (aviationRegionBuiltinProfilesMap() as $defs) {
        foreach ($defs as $def) {
            $u = $def['url'] ?? '';
            if (is_string($u)) {
                $add($u);
            }
        }
    }

    // Arbitrary coordinates: only validates the meteoblue segment format used by the EU profile type.
    $add(aviationRegionMeteoblueCurrentUrl(48.3538, 11.7861));

    $airnav = aviationRegionResolveBuiltinLinkUrl(['id' => 'x', 'label' => 'y', 'type' => 'airnav'], [], 'KSEA');
    if (is_string($airnav)) {
        $add($airnav);
    }
    $skyvector = aviationRegionResolveBuiltinLinkUrl(['id' => 'x', 'label' => 'y', 'type' => 'skyvector'], [], 'CYVR');
    if (is_string($skyvector)) {
        $add($skyvector);
    }
    $faaAirport = ['lat' => 45.77, 'lon' => -122.86];
    $faa = aviationRegionResolveBuiltinLinkUrl(['id' => 'x', 'label' => 'y', 'type' => 'faa_weather_cams'], $faaAirport, 'KSPB');
    if (is_string($faa)) {
        $add($faa);
    }

    return $out;
}

/**
 * Build meteoblue "today" page URL from WGS84 coordinates (path segment after `/en/weather/today/`).
 *
 * If meteoblue changes this path, update EU profile expectations and `aviationRegionBuiltinHttpsUrlsForPeriodicHealthCheck()`.
 *
 * @param float $lat WGS84 latitude
 * @param float $lon WGS84 longitude
 * @return string HTTPS URL on meteoblue.com
 */
function aviationRegionMeteoblueCurrentUrl(float $lat, float $lon): string
{
    $latAbs = abs($lat);
    $latH = $lat >= 0.0 ? 'N' : 'S';
    $lonAbs = abs($lon);
    $lonH = $lon >= 0.0 ? 'E' : 'W';

    return 'https://www.meteoblue.com/en/weather/today/' . $latAbs . $latH . $lonAbs . $lonH;
}

/**
 * Resolve one built-in link URL from a definition row, or null if requirements are not met.
 *
 * @param array{id: string, label: string, url?: string, type?: string} $def
 * @param array<string, mixed> $airport
 * @return string|null Resolved target: https URL, static `url` field, `foreflightmobile:` URI for ForeFlight, or null when inputs are insufficient
 */
function aviationRegionResolveBuiltinLinkUrl(array $def, array $airport, ?string $linkIdentifier): ?string
{
    if (isset($def['url']) && is_string($def['url']) && $def['url'] !== '') {
        return $def['url'];
    }
    $type = $def['type'] ?? '';
    if ($type === 'airnav') {
        if ($linkIdentifier === null) {
            return null;
        }

        return 'https://www.airnav.com/airport/' . $linkIdentifier;
    }
    if ($type === 'faa_weather_cams') {
        if ($linkIdentifier === null || !isset($airport['lat'], $airport['lon'])) {
            return null;
        }
        $buffer = 2.0;
        $minLon = (float) $airport['lon'] - $buffer;
        $minLat = (float) $airport['lat'] - $buffer;
        $maxLon = (float) $airport['lon'] + $buffer;
        $maxLat = (float) $airport['lat'] + $buffer;
        $faaId = preg_replace('/^K/', '', $linkIdentifier);

        return sprintf(
            'https://weathercams.faa.gov/map/%.5f,%.5f,%.5f,%.5f/airport/%s/',
            $minLon,
            $minLat,
            $maxLon,
            $maxLat,
            $faaId
        );
    }
    if ($type === 'skyvector') {
        if ($linkIdentifier === null) {
            return null;
        }

        return 'https://skyvector.com/airport/' . $linkIdentifier;
    }
    if ($type === 'foreflight') {
        if ($linkIdentifier === null) {
            return null;
        }

        return 'foreflightmobile://maps/search?q=' . rawurlencode($linkIdentifier);
    }
    if ($type === 'meteoblue_current') {
        if (!isset($airport['lat'], $airport['lon']) || !is_numeric($airport['lat']) || !is_numeric($airport['lon'])) {
            return null;
        }

        return aviationRegionMeteoblueCurrentUrl((float) $airport['lat'], (float) $airport['lon']);
    }

    return null;
}

/**
 * Override URL for a built-in link id from airport config, if set.
 *
 * @param array<string, mixed> $airport
 * @param string $linkId Built-in row id (airnav, faa_weather, foreflight, regional_weather)
 * @return string|null Override URL or null
 */
function aviationRegionBuiltinLinkOverrideUrl(array $airport, string $linkId): ?string
{
    if ($linkId === 'airnav' && !empty($airport['airnav_url']) && is_string($airport['airnav_url'])) {
        return $airport['airnav_url'];
    }
    if ($linkId === 'faa_weather' && !empty($airport['faa_weather_url']) && is_string($airport['faa_weather_url'])) {
        return $airport['faa_weather_url'];
    }
    if ($linkId === 'foreflight' && !empty($airport['foreflight_url']) && is_string($airport['foreflight_url'])) {
        return $airport['foreflight_url'];
    }
    if ($linkId === 'regional_weather' && !empty($airport['regional_weather_url']) && is_string($airport['regional_weather_url'])) {
        return $airport['regional_weather_url'];
    }

    return null;
}

/**
 * Label for the regional built-in row when the operator sets `regional_weather_url` only.
 *
 * @param array<string, mixed> $airport Airport configuration
 * @return string Display label (default `Weather Cams` when `regional_weather_label` is absent or blank)
 */
function aviationRegionRegionalWeatherOverrideLabel(array $airport): string
{
    $label = $airport['regional_weather_label'] ?? null;

    return (is_string($label) && trim($label) !== '') ? $label : 'Weather Cams';
}

/**
 * Built-in external links for dashboard and Public API (not operator custom links).
 *
 * @param array<string, mixed> $airport Airport configuration
 * @param string $regionId From aviationLinkRegionFromIso()
 * @param string|null $linkIdentifier From getBestIdentifierForLinks()
 * @return list<array{label: string, url: string}>
 */
function aviationRegionResolveBuiltinExternalLinks(array $airport, string $regionId, ?string $linkIdentifier): array
{
    $autoAllowed = ($regionId !== AVIATION_LINK_REGION_UNKNOWN);
    $out = [];
    foreach (aviationRegionBuiltinLinkDefinitions($regionId) as $def) {
        $id = $def['id'] ?? '';
        if (!is_string($id) || $id === '') {
            continue;
        }
        $override = aviationRegionBuiltinLinkOverrideUrl($airport, $id);
        if ($override !== null) {
            $label = (string) ($def['label'] ?? '');
            if ($id === 'regional_weather') {
                $label = aviationRegionRegionalWeatherOverrideLabel($airport);
            }
            $out[] = ['label' => $label, 'url' => $override];
            continue;
        }
        if (!$autoAllowed) {
            continue;
        }
        $url = aviationRegionResolveBuiltinLinkUrl($def, $airport, $linkIdentifier);
        if ($url === null) {
            continue;
        }
        $out[] = ['label' => (string) ($def['label'] ?? ''), 'url' => $url];
    }

    return $out;
}

/**
 * First static `regional_weather` URL from the link-region profile (dashboard helper).
 *
 * @param string $regionId Link-region id from {@see aviationLinkRegionFromIso()}
 * @return array{url: string, label: string}|null Null when the profile has no static `regional_weather` row with a `url`
 */
function aviationRegionBuiltInRegionalWeatherSlot(string $regionId): ?array
{
    foreach (aviationRegionBuiltinLinkDefinitions($regionId) as $def) {
        if (($def['id'] ?? '') !== 'regional_weather') {
            continue;
        }
        $url = $def['url'] ?? null;
        if (is_string($url) && $url !== '') {
            return ['url' => $url, 'label' => (string) ($def['label'] ?? '')];
        }
    }

    return null;
}
