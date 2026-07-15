<?php
/**
 * Shared display helpers for density altitude performance tiers.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/units.php';

/**
 * Primary tooltip copy for density altitude performance tiers (pilot-facing uses AFM).
 */
function densityAltitudePerformanceTooltip(string $tier): string
{
    if ($tier === 'warning') {
        return 'Density altitude is dangerously high for average GA aircraft. '
            . 'Verify performance numbers before flight.';
    }
    if ($tier === 'caution') {
        return 'Density altitude is higher than normal. Verify performance numbers before flight.';
    }
    return '';
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
 * CSS class for warning-tier DA value styling.
 */
function densityAltitudePerformanceValueClass(string $tier): string
{
    return $tier === 'warning' ? 'density-altitude-warning' : '';
}

/**
 * Accessible label for density altitude cell.
 *
 * @param int|float|null $densityAltitudeFt
 * @param string $distUnit Embed distance unit (`f` or `m`)
 */
function densityAltitudePerformanceAriaLabel($densityAltitudeFt, string $tier, string $distUnit = 'f'): string
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
    if ($tier === 'warning') {
        return $base . '. Warning: dangerously high for average GA aircraft; verify performance numbers before flight.';
    }
    if ($tier === 'caution') {
        return $base . '. Caution: higher than normal; verify performance numbers before flight.';
    }
    return $base;
}

/**
 * Format density altitude display value with optional performance emoji.
 *
 * @param int|float|null $densityAltitudeFt
 * @param string $formattedDistance Pre-formatted distance string (with unit if desired)
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
