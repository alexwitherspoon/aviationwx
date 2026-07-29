<?php

declare(strict_types=1);

/**
 * Airspace TFR category classification and headlines (banner and map).
 *
 * Headlines are a research signal, not an authoritative product. Prefer
 * specific CFR / purpose cues over boilerplate exception lists.
 */

require_once __DIR__ . '/filter.php';

/**
 * Classify airspace TFR category from NOTAM prose (fail open to general).
 *
 * Order matters:
 * - Wildfire / hazard CFR clauses first.
 * - Presidential / VIP (91.141, VIP) before generic SSI security, because
 *   presidential rings often cite both 91.141 and 40103/99.7.
 * - SSI / national defense security before VIP-disaster "DISASTER" leftovers.
 * - Do not treat one-word FIREFIGHTING in SSI UAS exceptions as a fire TFR.
 *
 * @param string $text NOTAM body
 * @return string Category slug
 */
function notamClassifyAirspaceTfrCategory(string $text): string
{
    if ($text === '') {
        return 'general';
    }
    $upper = strtoupper($text);

    // Wildfire: 91.137(a)(2) or spaced "FIRE FIGHT..." (not SSI "FIREFIGHTING").
    if (preg_match('/\b91\.137\s*\(\s*A\s*\)\s*\(\s*2\s*\)/', $upper) === 1
        || str_contains($upper, 'FIRE FIGHTING')
        || str_contains($upper, 'FIRE FIGHT')
    ) {
        return 'fire';
    }
    if (preg_match('/\b91\.137\s*\(\s*A\s*\)\s*\(\s*1\s*\)/', $upper) === 1) {
        return 'hazard_surface';
    }

    // Presidential / VIP movement before generic SSI security.
    if (preg_match('/\b91\.141\b/', $upper) === 1
        || preg_match('/\bVIP\b/', $upper) === 1
        || preg_match('/\b91\.137\s*\(\s*A\s*\)\s*\(\s*3\s*\)/', $upper) === 1
        || preg_match('/\bDISASTER\s+RELIEF\b/', $upper) === 1
        || preg_match('/\bNATURAL\s+DISASTER\b/', $upper) === 1
    ) {
        return 'vip_disaster';
    }

    // Standing security / national defense / special security instructions (14 CFR 99.7).
    if (str_contains($upper, 'NTL DEFENSE AIRSPACE')
        || str_contains($upper, 'NATIONAL DEFENSE AIRSPACE')
        || str_contains($upper, 'SPECIAL SECURITY INSTRUCTIONS')
        || preg_match('/\b14\s*CFR\s*99\.7\b/', $upper) === 1
        || preg_match('/\b49\s*USC\s*40103\b/', $upper) === 1
        || preg_match('/\bSSI\s+AIRSPACE\b/', $upper) === 1
    ) {
        return 'security';
    }

    // Rocket / space launch or static engine tests.
    if (preg_match('/\b(ROCKET|SPACE\s+LAUNCH|LAUNCH\s+OPS|LAUNCH\s+ACT|GROUND\s+BASED\s+ROCKET)\b/', $upper) === 1
        || str_contains($upper, 'GROUND BASED ROCKET')
    ) {
        return 'space_launch';
    }

    // Stadium / large-gathering UAS prohibitions (49 USC 44812) before generic UAS.
    if (str_contains($upper, '44812')
        || str_contains($upper, 'LARGE PUBLIC GATHERINGS')
    ) {
        return 'uas_gathering';
    }

    if (preg_match('/\b(UAS|DRONE)\b/', $upper) === 1) {
        return 'uas';
    }

    // 91.145 boilerplate cites both aerial demos and sporting events; prefer airshow
    // when demonstration / airshow language is present.
    if (preg_match('/\b(AERIAL\s+DEMONSTRATIONS?|AIR\s*SHOWS?|AIRSHOW)\b/', $upper) === 1) {
        return 'airshow';
    }
    if (preg_match('/\b(SPORTING|STADIUM)\b/', $upper) === 1
        || preg_match('/\b91\.145\b/', $upper) === 1
    ) {
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
        'security' => 'Security TFR',
        'vip_disaster' => notamTfrCategoryVipDisasterLabel($upper),
        'space_launch' => notamTfrCategorySpaceLaunchLabel($upper),
        'uas_gathering' => 'Event UAS TFR',
        'uas' => 'UAS TFR',
        'airshow' => 'Airshow TFR',
        'sporting' => 'Sporting event TFR',
        default => notamTfrCategoryGeneralLabel($upper),
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
 * VIP / disaster-relief headline label from already-uppercased prose.
 */
function notamTfrCategoryVipDisasterLabel(string $upper): string
{
    if (preg_match('/\b91\.141\b/', $upper) === 1 || preg_match('/\bVIP\b/', $upper) === 1) {
        return 'VIP TFR';
    }
    if (preg_match('/\bDISASTER\s+RELIEF\b/', $upper) === 1
        || preg_match('/\bNATURAL\s+DISASTER\b/', $upper) === 1
    ) {
        return 'Disaster relief TFR';
    }

    return 'VIP TFR';
}

/**
 * Space / rocket headline label from already-uppercased prose.
 */
function notamTfrCategorySpaceLaunchLabel(string $upper): string
{
    if (preg_match('/\b(STATIC|ENGINE\s+TEST|GROUND\s+BASED\s+ROCKET)\b/', $upper) === 1) {
        return 'Rocket test TFR';
    }
    if (preg_match('/\b(SPACE\s+LAUNCH|ROCKET\s+LAUNCH|LAUNCH\s+OPS|LAUNCH\s+ACT)\b/', $upper) === 1) {
        return 'Space launch TFR';
    }

    return 'Space launch TFR';
}

/**
 * Fail-open label: only say TFR when the prose is actually a TFR.
 */
function notamTfrCategoryGeneralLabel(string $upper): string
{
    if (str_contains($upper, 'TEMPORARY FLIGHT RESTRICTION')
        || preg_match('/\bTFR\b/', $upper) === 1
    ) {
        return 'TFR';
    }

    return 'Airspace notice';
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
