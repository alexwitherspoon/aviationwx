<?php
/**
 * Shared display helpers for density altitude performance tiers.
 */

require_once __DIR__ . '/constants.php';

/**
 * Primary tooltip copy for density altitude performance tiers (pilot-facing uses AFM).
 */
function densityAltitudePerformanceTooltip(string $tier): string
{
    if ($tier === 'strong') {
        return 'Density altitude is dangerously high for average GA aircraft. '
            . 'Verify performance numbers before flight.';
    }
    if ($tier === 'caution') {
        return 'Density altitude is higher than normal. Verify performance numbers before flight.';
    }
    return '';
}

/**
 * Emoji indicator for tier (none returns empty string).
 */
function densityAltitudePerformanceEmoji(string $tier): string
{
    if ($tier === 'strong') {
        return '🚩';
    }
    if ($tier === 'caution') {
        return '⚠️';
    }
    return '';
}

/**
 * CSS class for strong-tier DA value styling.
 */
function densityAltitudePerformanceValueClass(string $tier): string
{
    return $tier === 'strong' ? 'density-altitude-strong' : '';
}

/**
 * Accessible label for density altitude cell.
 *
 * @param int|float|null $densityAltitudeFt
 */
function densityAltitudePerformanceAriaLabel($densityAltitudeFt, string $tier): string
{
    if (!is_numeric($densityAltitudeFt)) {
        return 'Density altitude unavailable';
    }
    $feet = (int) round((float) $densityAltitudeFt);
    $base = 'Density altitude ' . number_format($feet) . ' feet';
    if ($tier === 'strong') {
        return $base . '. Strong caution: dangerously high for average GA aircraft; verify performance numbers before flight.';
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
    if (!is_array($performance) || empty($performance['tier']) || $performance['tier'] === 'none') {
        return $formattedDistance;
    }

    $emoji = densityAltitudePerformanceEmoji((string) $performance['tier']);
    if ($emoji === '') {
        return $formattedDistance;
    }

    return trim($formattedDistance . ' ' . $emoji);
}
