<?php
/**
 * NOTAM schedule helpers (FAA-style EFFECTIVE windows vs GML envelope times).
 *
 * NMS often exposes one gml:beginPosition / gml:endPosition pair for the whole NOTAM
 * while the NOTAM body lists disjunct EFFECTIVE ... UTC UNTIL ... slices (see TFRs).
 */

require_once __DIR__ . '/../logger.php';

/**
 * Convert a FAA 10-digit UTC group (YYMMDDHHMM) to a Unix timestamp (UTC).
 *
 * @param string $digits Exactly 10 digits (YY MM DD HH MM in UTC)
 * @return int|null Unix timestamp or null if invalid
 */
function faaTenDigitUtcGroupToTimestamp(string $digits): ?int {
    if (strlen($digits) !== 10 || !ctype_digit($digits)) {
        return null;
    }
    $yy = (int)substr($digits, 0, 2);
    $year = 2000 + $yy;
    $month = (int)substr($digits, 2, 2);
    $day = (int)substr($digits, 4, 2);
    $hour = (int)substr($digits, 6, 2);
    $minute = (int)substr($digits, 8, 2);
    if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $minute > 59) {
        return null;
    }
    $ts = gmmktime($hour, $minute, 0, $month, $day, $year);
    if ($ts === false) {
        return null;
    }
    return $ts;
}

/**
 * Format a Unix timestamp as ISO 8601 UTC with Z suffix.
 *
 * @param int $timestamp Unix timestamp (UTC)
 * @return string|null ISO string or null on failure
 */
function notamTimestampToIsoUtc(int $timestamp): ?string {
    $formatted = gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    return $formatted !== false ? $formatted : null;
}

/**
 * Parse FAA-style EFFECTIVE pairs from NOTAM prose (10-digit UTC groups).
 *
 * Matches patterns such as: "2605151800 UTC UNTIL 2605152200 UTC" (real Hillsboro TFR text).
 *
 * @param string $text NOTAM body (case-insensitive match)
 * @return array<int, array{start_time_utc: string, end_time_utc: string}> Sorted, merged overlaps
 */
function parseFaaEffectiveUtcSegmentsFromText(string $text): array {
    if ($text === '') {
        return [];
    }
    if (!preg_match_all('/(\d{10})\s*UTC\s*UNTIL\s*(\d{10})\s*UTC/i', $text, $matches, PREG_SET_ORDER)) {
        return [];
    }
    $raw = [];
    foreach ($matches as $row) {
        $startTs = faaTenDigitUtcGroupToTimestamp($row[1]);
        $endTs = faaTenDigitUtcGroupToTimestamp($row[2]);
        if ($startTs === null || $endTs === null || $endTs < $startTs) {
            continue;
        }
        $startIso = notamTimestampToIsoUtc($startTs);
        $endIso = notamTimestampToIsoUtc($endTs);
        if ($startIso === null || $endIso === null) {
            continue;
        }
        $raw[] = ['start_time_utc' => $startIso, 'end_time_utc' => $endIso];
    }
    return notamNormalizeEffectiveSegments($raw);
}

/**
 * Sort and merge overlapping or touching effective segments.
 *
 * @param array<int, array{start_time_utc: string, end_time_utc: string}> $segments Segments
 * @return array<int, array{start_time_utc: string, end_time_utc: string}> Normalized segments
 */
function notamNormalizeEffectiveSegments(array $segments): array {
    if ($segments === []) {
        return [];
    }
    $parsed = [];
    foreach ($segments as $seg) {
        $s = strtotime($seg['start_time_utc'] ?? '');
        $e = strtotime($seg['end_time_utc'] ?? '');
        if ($s === false || $e === false || $s <= 0 || $e <= 0 || $e < $s) {
            continue;
        }
        $parsed[] = ['s' => $s, 'e' => $e];
    }
    if ($parsed === []) {
        return [];
    }
    usort($parsed, static function (array $a, array $b): int {
        return $a['s'] <=> $b['s'];
    });
    $merged = [];
    $cur = $parsed[0];
    for ($i = 1, $n = count($parsed); $i < $n; $i++) {
        $nxt = $parsed[$i];
        if ($nxt['s'] <= $cur['e'] + 1) {
            $cur['e'] = max($cur['e'], $nxt['e']);
        } else {
            $merged[] = $cur;
            $cur = $nxt;
        }
    }
    $merged[] = $cur;
    $out = [];
    foreach ($merged as $m) {
        $sIso = notamTimestampToIsoUtc($m['s']);
        $eIso = notamTimestampToIsoUtc($m['e']);
        if ($sIso !== null && $eIso !== null) {
            $out[] = ['start_time_utc' => $sIso, 'end_time_utc' => $eIso];
        }
    }
    return $out;
}

/**
 * Add effective_segments and schedule_source from NOTAM text and GML envelope fields.
 *
 * @param array<string, mixed> $notam Parsed NOTAM row from {@see parseNotamXml()}; mutates effective_segments and schedule_source
 * @return array<string, mixed> Same array reference for chaining
 */
function enrichParsedNotamWithSchedule(array &$notam): array {
    $text = (string)($notam['text'] ?? '');
    $segments = parseFaaEffectiveUtcSegmentsFromText($text);
    if ($segments !== []) {
        $notam['effective_segments'] = $segments;
        $notam['schedule_source'] = 'text_effective';
        return $notam;
    }
    $start = trim((string)($notam['start_time_utc'] ?? ''));
    $endRaw = $notam['end_time_utc'] ?? null;
    $end = is_string($endRaw) ? trim($endRaw) : '';
    if ($start !== '' && $end !== '' && strtoupper($end) !== 'PERM') {
        $sTs = strtotime($start);
        $eTs = strtotime($end);
        if ($sTs !== false && $eTs !== false && $sTs > 0 && $eTs > 0 && $eTs >= $sTs) {
            $sIso = notamTimestampToIsoUtc($sTs);
            $eIso = notamTimestampToIsoUtc($eTs);
            if ($sIso !== null && $eIso !== null) {
                $notam['effective_segments'] = [['start_time_utc' => $sIso, 'end_time_utc' => $eIso]];
                $notam['schedule_source'] = 'envelope';
                return $notam;
            }
        }
    }
    $notam['effective_segments'] = [];
    $notam['schedule_source'] = 'none';
    return $notam;
}

/**
 * Ensure effective_segments exist (for older cache files predating schedule enrichment).
 *
 * @param array<string, mixed> $notam Parsed NOTAM row (mutated in place)
 * @return void
 */
function notamEnsureEffectiveSegments(array &$notam): void {
    if (isset($notam['effective_segments']) && is_array($notam['effective_segments'])) {
        return;
    }
    enrichParsedNotamWithSchedule($notam);
}

/**
 * Merge two parsed NOTAM rows with the same id (richer text wins; envelope widened).
 *
 * @param array<string, mixed> $primary Primary row (typically longer text); keys id, text, start_time_utc, end_time_utc
 * @param array<string, mixed> $secondary Secondary row with same shape
 * @return array<string, mixed> Merged NOTAM
 */
function mergeParsedNotamDuplicates(array $primary, array $secondary): array {
    $out = $primary;
    $t1 = (string)($primary['text'] ?? '');
    $t2 = (string)($secondary['text'] ?? '');
    if (strlen($t2) > strlen($t1)) {
        $out['text'] = $t2;
    }
    $pickEarlier = static function (string $a, string $b): string {
        $ta = strtotime($a);
        $tb = strtotime($b);
        if ($ta === false || $ta <= 0) {
            return $b;
        }
        if ($tb === false || $tb <= 0) {
            return $a;
        }
        return $ta <= $tb ? $a : $b;
    };
    $pickLater = static function (string $a, string $b): string {
        $ta = strtotime($a);
        $tb = strtotime($b);
        if ($ta === false || $ta <= 0) {
            return $b;
        }
        if ($tb === false || $tb <= 0) {
            return $a;
        }
        return $ta >= $tb ? $a : $b;
    };
    $s1 = trim((string)($primary['start_time_utc'] ?? ''));
    $s2 = trim((string)($secondary['start_time_utc'] ?? ''));
    if ($s1 !== '' && $s2 !== '') {
        $out['start_time_utc'] = $pickEarlier($s1, $s2);
    } elseif ($s2 !== '') {
        $out['start_time_utc'] = $s2;
    }
    $e1 = isset($primary['end_time_utc']) && is_string($primary['end_time_utc'])
        ? trim($primary['end_time_utc']) : '';
    $e2 = isset($secondary['end_time_utc']) && is_string($secondary['end_time_utc'])
        ? trim($secondary['end_time_utc']) : '';
    if ($e1 !== '' && strtoupper($e1) !== 'PERM' && $e2 !== '' && strtoupper($e2) !== 'PERM') {
        $out['end_time_utc'] = $pickLater($e1, $e2);
    } elseif ($e2 !== '' && strtoupper($e2) !== 'PERM') {
        $out['end_time_utc'] = $e2;
    } elseif ($e1 !== '' && strtoupper($e1) !== 'PERM') {
        $out['end_time_utc'] = $e1;
    }
    unset($out['effective_segments'], $out['schedule_source']);
    enrichParsedNotamWithSchedule($out);
    return $out;
}

/**
 * Earliest restriction start as Unix time (UTC), from segments or envelope start.
 *
 * @param array<string, mixed> $notam Parsed NOTAM (mutated by {@see notamEnsureEffectiveSegments()})
 * @return int|null Unix timestamp or null
 */
function notamFirstRestrictionStartUnix(array &$notam): ?int {
    notamEnsureEffectiveSegments($notam);
    $segments = $notam['effective_segments'] ?? [];
    $min = PHP_INT_MAX;
    foreach ($segments as $seg) {
        $t = strtotime($seg['start_time_utc'] ?? '');
        if ($t !== false && $t > 0) {
            $min = min($min, $t);
        }
    }
    if ($min !== PHP_INT_MAX) {
        return $min;
    }
    $s = strtotime(trim((string)($notam['start_time_utc'] ?? '')));
    if ($s === false || $s <= 0) {
        return null;
    }
    return $s;
}

/**
 * End time (UTC ISO) of the restriction window containing $nowUnix, if any.
 *
 * @param array<string, mixed> $notam Parsed NOTAM
 * @param int $nowUnix Current time
 * @return string|null ISO8601 Z or null
 */
function notamCurrentRestrictionEndUtc(array &$notam, int $nowUnix): ?string {
    notamEnsureEffectiveSegments($notam);
    foreach ($notam['effective_segments'] ?? [] as $seg) {
        $s = strtotime($seg['start_time_utc'] ?? '');
        $e = strtotime($seg['end_time_utc'] ?? '');
        if ($s === false || $e === false || $s <= 0 || $e <= 0) {
            continue;
        }
        if ($nowUnix >= $s && $nowUnix <= $e) {
            return $seg['end_time_utc'];
        }
    }
    return null;
}

/**
 * Start time (UTC ISO) of the next restriction window strictly after $nowUnix.
 *
 * @param array<string, mixed> $notam Parsed NOTAM
 * @param int $nowUnix Current time
 * @return string|null ISO8601 Z or null
 */
function notamNextRestrictionStartUtc(array &$notam, int $nowUnix): ?string {
    notamEnsureEffectiveSegments($notam);
    $bestTs = PHP_INT_MAX;
    $bestIso = null;
    foreach ($notam['effective_segments'] ?? [] as $seg) {
        $s = strtotime($seg['start_time_utc'] ?? '');
        if ($s === false || $s <= 0 || $s <= $nowUnix) {
            continue;
        }
        if ($s < $bestTs) {
            $bestTs = $s;
            $bestIso = $seg['start_time_utc'];
        }
    }
    return $bestIso;
}
