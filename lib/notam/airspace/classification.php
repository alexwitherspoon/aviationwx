<?php
/**
 * Restriction kind classification from provider hints (not US-keyword only).
 */

declare(strict_types=1);

/**
 * Map provider metadata into a canonical restriction_kind.
 *
 * @param array<string, mixed> $hints Keys may include legal, title, q_code, restriction_kind, text
 */
function notamAirspaceRestrictionKindFromHints(array $hints): string
{
    $explicit = strtolower(trim((string) ($hints['restriction_kind'] ?? '')));
    $allowed = ['tfr', 'security', 'fis_b', 'airshow', 'runway_closure', 'other'];
    if (in_array($explicit, $allowed, true)) {
        return $explicit;
    }

    $legal = strtoupper(trim((string) ($hints['legal'] ?? $hints['LEGAL'] ?? '')));
    $title = strtoupper(trim((string) ($hints['title'] ?? $hints['TITLE'] ?? '')));
    $text = strtoupper(trim((string) ($hints['text'] ?? '')));
    $blob = $legal . ' ' . $title . ' ' . $text;

    if ($legal === 'FIS-B' || str_contains($blob, 'FIS-B')) {
        return 'fis_b';
    }
    if ($legal === 'SECURITY' || $legal === 'VIP' || str_contains($blob, 'SECURITY RESTRICTION')) {
        return 'security';
    }
    if ($legal === 'AIR SHOWS/SPORTS' || str_contains($legal, 'AIR SHOW') || str_contains($blob, 'AIRSHOW')) {
        return 'airshow';
    }
    if (str_contains($blob, 'RUNWAY CLOSED') || str_contains($blob, 'RWY CLSD')) {
        return 'runway_closure';
    }
    if (str_contains($blob, 'TEMPORARY FLIGHT RESTRICTION') || str_contains($blob, ' TFR') || $legal === 'HAZARDS') {
        return 'tfr';
    }

    return 'other';
}
