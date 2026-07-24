<?php

declare(strict_types=1);

/**
 * Airspace TFR category classification and headlines (banner and map).
 */

require_once __DIR__ . '/filter.php';

/**
 * Classify airspace TFR category from NOTAM prose (fail open to general).
 *
 * @param string $text NOTAM body
 * @return string Category slug (fire, vip_disaster, space_launch, general, ...)
 */
function notamClassifyAirspaceTfrCategory(string $text): string
{
    if ($text === '') {
        return 'general';
    }
    $upper = strtoupper($text);

    if (preg_match('/\b91\.137\s*\(\s*A\s*\)\s*\(\s*2\s*\)/', $upper) === 1
        || str_contains($upper, 'FIRE FIGHTING')
        || str_contains($upper, 'FIRE FIGHT')
    ) {
        return 'fire';
    }
    if (preg_match('/\b91\.137\s*\(\s*A\s*\)\s*\(\s*1\s*\)/', $upper) === 1) {
        return 'hazard_surface';
    }
    if (preg_match('/\b91\.137\s*\(\s*A\s*\)\s*\(\s*3\s*\)/', $upper) === 1
        || preg_match('/\bVIP\b/', $upper) === 1
        || str_contains($upper, 'DISASTER')
    ) {
        return 'vip_disaster';
    }
    if (preg_match('/\b(ROCKET|SPACE\s+LAUNCH|LAUNCH\s+OPS)\b/', $upper) === 1
        || str_contains($upper, 'GROUND BASED ROCKET')
    ) {
        return 'space_launch';
    }
    if (preg_match('/\b(UAS|DRONE)\b/', $upper) === 1) {
        return 'uas';
    }
    if (preg_match('/\b(SPORTING|STADIUM)\b/', $upper) === 1) {
        return 'sporting';
    }

    return 'general';
}

/**
 * Airspace TFR headline with category and geometry hints when parseable.
 *
 * Shared by dashboard banners and the airports map TFR popup title.
 *
 * @param string $category From {@see notamClassifyAirspaceTfrCategory()}
 * @param string $text NOTAM body
 */
function notamBuildAirspaceTfrHeadline(string $category, string $text): string
{
    $upper = strtoupper($text);
    $daily = preg_match('/\bDLY\b/', $upper) === 1;

    $label = match ($category) {
        'fire' => $daily ? 'Daily fire TFR' : 'Fire TFR',
        'hazard_surface' => 'Hazard TFR',
        'vip_disaster' => 'VIP TFR',
        'space_launch' => 'Rocket test TFR',
        'uas' => 'UAS TFR',
        'sporting' => 'Sporting event TFR',
        default => 'TFR',
    };

    $parts = [$label];
    $radius = parseTfrRadiusNm($text);
    if ($radius !== null) {
        $nm = rtrim(rtrim(number_format($radius, 1, '.', ''), '0'), '.');
        $parts[] = $nm . ' NM radius';
    } elseif (count(parseTfrPolygonVertices($text)) >= 3) {
        $parts[] = 'polygon area';
    }

    $vertical = parseTfrVerticalLimitsSummary($text);
    if ($vertical !== null && $vertical !== '') {
        $parts[] = $vertical;
    }

    return implode(' - ', $parts);
}

/**
 * Classify and build the airspace TFR headline from NOTAM prose.
 *
 * @param string $text NOTAM body
 */
function notamBuildAirspaceTfrHeadlineFromText(string $text): string
{
    return notamBuildAirspaceTfrHeadline(notamClassifyAirspaceTfrCategory($text), $text);
}
