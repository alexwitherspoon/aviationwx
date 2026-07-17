<?php
/**
 * Shared display helpers for density altitude performance tiers.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/units.php';

/**
 * Primary tooltip copy for density altitude performance tiers (pilot-facing uses AFM).
 *
 * @param array<string, mixed>|null $performance Optional API payload with fallback flag
 */
function densityAltitudePerformanceTooltip(string $tier, ?array $performance = null): string
{
    if (is_array($performance) && !empty($performance['fallback'])) {
        return DENSITY_ALTITUDE_PERFORMANCE_FALLBACK_TOOLTIP;
    }

    if ($tier === 'warning') {
        return 'Density altitude is dangerously high for average GA aircraft. '
            . 'Verify performance numbers before flight.'
            . densityAltitudePerformanceSelectionBasisNote($performance);
    }
    if ($tier === 'caution') {
        return 'Density altitude is higher than normal. Verify performance numbers before flight.'
            . densityAltitudePerformanceSelectionBasisNote($performance);
    }
    return '';
}

/**
 * Format the operational departure end for pilot-facing copy.
 *
 * @param array<string, mixed>|null $performance Optional API payload
 */
function densityAltitudePerformanceOperationalEndLabel(?array $performance): string
{
    if (!is_array($performance)) {
        return '';
    }

    $bestEnd = is_array($performance['best_end'] ?? null) ? $performance['best_end'] : null;
    if ($bestEnd === null) {
        return '';
    }

    $endId = isset($bestEnd['end_id'])
        ? trim((string) $bestEnd['end_id'])
        : '';
    if ($endId === '') {
        return '';
    }

    $rwyId = isset($bestEnd['rwy_id'])
        ? trim((string) $bestEnd['rwy_id'])
        : '';
    if ($rwyId !== '' && $rwyId !== 'config') {
        return 'RWY ' . $endId . ' (' . $rwyId . ')';
    }

    return 'RWY ' . $endId;
}

/**
 * Tooltip suffix naming the best departure end that drove the tier.
 *
 * @param array<string, mixed>|null $performance Optional API payload with selection_basis
 */
function densityAltitudePerformanceSelectionBasisNote(?array $performance): string
{
    if (!is_array($performance) || empty($performance['selection_basis'])) {
        return '';
    }

    $endLabel = densityAltitudePerformanceOperationalEndLabel($performance);
    if ($endLabel === '') {
        return ' Based on the best runway at this airport.';
    }

    return ' Based on ' . $endLabel . ', the best runway at this airport.';
}

/**
 * Emoji indicator for tier (normal returns empty string).
 */
function densityAltitudePerformanceEmoji(string $tier): string
{
    if ($tier === 'warning') {
        return '🚩';
    }
    if ($tier === 'caution') {
        return '⚠️';
    }
    return '';
}

/**
 * CSS class for non-normal DA value styling (caution and warning).
 */
function densityAltitudePerformanceValueClass(string $tier): string
{
    return ($tier === 'caution' || $tier === 'warning') ? 'density-altitude-warning' : '';
}

/**
 * Accessible label for density altitude cell.
 *
 * @param int|float|null $densityAltitudeFt
 * @param string $distUnit Embed distance unit (`ft` or `m`)
 * @param array<string, mixed>|null $performance Optional API payload with fallback flag
 */
function densityAltitudePerformanceAriaLabel(
    $densityAltitudeFt,
    string $tier,
    string $distUnit = 'ft',
    ?array $performance = null
): string
{
    if (!is_numeric($densityAltitudeFt)) {
        return 'Density altitude unavailable';
    }
    if ($distUnit === 'm') {
        $value = (int) round(feetToMeters((float) $densityAltitudeFt));
        $base = 'Density altitude ' . number_format($value) . ' meters';
    } else {
        $feet = (int) round((float) $densityAltitudeFt);
        $base = 'Density altitude ' . number_format($feet) . ' feet';
    }
    if (is_array($performance) && !empty($performance['fallback'])) {
        return $base . '. Runway data unavailable; indicator based on density altitude relative to field elevation only. '
            . 'Verify all performance calculations using your AFM.';
    }
    if ($tier === 'warning') {
        return $base . '. Warning: dangerously high for average GA aircraft; verify performance numbers before flight.'
            . densityAltitudePerformanceSelectionBasisNote($performance);
    }
    if ($tier === 'caution') {
        return $base . '. Caution: higher than normal; verify performance numbers before flight.'
            . densityAltitudePerformanceSelectionBasisNote($performance);
    }
    return $base;
}

/**
 * Format density altitude display value with optional performance emoji.
 *
 * @param int|float|null $densityAltitudeFt
 * @param string $formattedDistance Pre-formatted distance string (with unit if desired)
 * @param array<string, mixed>|null $performance Optional API payload
 */
function formatDensityAltitudePerformanceDisplay($densityAltitudeFt, string $formattedDistance, ?array $performance): string
{
    if ($formattedDistance === '--' || $formattedDistance === '---') {
        return $formattedDistance;
    }
    if (!is_array($performance) || empty($performance['tier']) || $performance['tier'] === 'normal') {
        return $formattedDistance;
    }

    $emoji = densityAltitudePerformanceEmoji((string) $performance['tier']);
    if ($emoji === '') {
        return $formattedDistance;
    }

    return trim($formattedDistance . ' ' . $emoji);
}
