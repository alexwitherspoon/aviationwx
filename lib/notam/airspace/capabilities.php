<?php
/**
 * AirspaceRecord capability gates (data sufficiency, not display permission).
 */

declare(strict_types=1);

require_once __DIR__ . '/../filter.php';
require_once __DIR__ . '/../schedule.php';
require_once __DIR__ . '/../closure-parse.php';

/**
 * Whether a parsed NMS row has enough schedule metadata for banner revalidation.
 *
 * @param array<string, mixed> $notam Parsed NOTAM row
 */
function notamAirspaceRecordBannerCapable(array $notam): bool
{
    if (!isTfr($notam)) {
        return false;
    }

    if (trim((string) ($notam['id'] ?? '')) === '') {
        return false;
    }

    if (trim((string) ($notam['text'] ?? '')) === '') {
        return false;
    }

    $status = trim((string) ($notam['status'] ?? ''));
    if ($status !== '' && $status !== 'unknown') {
        return true;
    }

    $start = trim((string) ($notam['start_time_utc'] ?? ''));
    if ($start !== '') {
        return true;
    }

    // Copy before ensure so banner capability checks do not mutate fetch rows.
    $probe = $notam;
    notamEnsureEffectiveSegments($probe);
    $segments = $probe['effective_segments'] ?? null;

    return is_array($segments) && $segments !== [];
}

/**
 * Whether a parsed NMS row has closure-parse inputs (data sufficiency only).
 *
 * @param array<string, mixed> $notam Parsed NOTAM row
 */
function notamAirspaceRecordRunwayClosureCapable(array $notam): bool
{
    if (isNotamCancellation($notam)) {
        return false;
    }

    if (!notamRestrictionScopeIsRunwayOrAerodrome($notam)) {
        return false;
    }

    $text = notamNormalizeProse((string) ($notam['text'] ?? ''));

    return notamProseHasClosureKeyword($text);
}
