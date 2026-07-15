<?php
/**
 * NASR APT subscription discovery (cycle dates and download URLs).
 */

require_once __DIR__ . '/../constants.php';

/**
 * HTTP status codes that warrant a retry against NFDC/FAA.
 *
 * @return list<int>
 */
function nasrRetryableHttpStatusCodes(): array
{
    return [408, 429, 500, 502, 503, 504];
}

/**
 * Execute one HTTP GET or ranged GET against FAA/NFDC.
 *
 * @param array{no_body?: bool, range_bytes?: string} $options
 * @return array{ok: bool, http_code: int, body: ?string, retryable: bool}
 */
function nasrHttpRequestOnce(string $url, array $options = []): array
{
    $noBody = !empty($options['no_body']);
    $rangeBytes = isset($options['range_bytes']) && is_string($options['range_bytes'])
        ? $options['range_bytes']
        : null;

    $headers = [
        'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
    ];
    if ($rangeBytes !== null && $rangeBytes !== '') {
        $headers[] = 'Range: bytes=' . $rangeBytes;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_ENCODING => '',
        CURLOPT_NOBODY => $noBody && $rangeBytes === null,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AviationWX/1.0; +https://aviationwx.org)',
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);

    $ok = in_array($httpCode, [200, 206], true)
        && ($noBody || $rangeBytes !== null || ($body !== false && $body !== null));

    $retryable = $curlErrno !== 0 || in_array($httpCode, nasrRetryableHttpStatusCodes(), true);

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'body' => ($body === false) ? null : $body,
        'retryable' => $retryable,
    ];
}

/**
 * HTTP GET/HEAD with retry/backoff for transient NFDC/FAA failures.
 *
 * @param array{no_body?: bool, max_attempts?: int, range_bytes?: string} $options
 * @return string|null Response body, or empty string for successful no-body/range checks
 */
function nasrHttpRequest(string $url, array $options = []): ?string
{
    $maxAttempts = (int) ($options['max_attempts'] ?? NASR_HTTP_MAX_ATTEMPTS);
    $delays = NASR_HTTP_RETRY_DELAYS_SECONDS;
    $noBody = !empty($options['no_body']);

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        if ($attempt > 0) {
            $delayIndex = min($attempt - 1, count($delays) - 1);
            sleep((int) $delays[$delayIndex]);
        }

        $result = nasrHttpRequestOnce($url, $options);
        if ($result['ok']) {
            return $noBody ? '' : $result['body'];
        }

        if (!$result['retryable']) {
            break;
        }
    }

    return null;
}

/**
 * HTTP GET with headers suitable for FAA and NFDC endpoints.
 */
function nasrHttpGet(string $url): ?string
{
    return nasrHttpRequest($url);
}

/**
 * Build NASR APT zip URL for an effective date (YYYY-MM-DD).
 *
 * FAA names files like 15_May_2025_APT_CSV.zip (not 2025-05-15_APT_CSV.zip).
 */
function buildNasrAptZipUrl(string $dateYmd): string
{
    $ts = strtotime($dateYmd . ' UTC');
    if ($ts === false) {
        return '';
    }

    $slug = gmdate('d_M_Y', $ts);

    return 'https://nfdc.faa.gov/webContent/28DaySub/extra/' . $slug . '_APT_CSV.zip';
}

/**
 * Discover NASR cycle effective dates published on the FAA subscription index.
 *
 * @return list<string> YYYY-MM-DD
 */
function discoverNasrCycleDatesFromFaaIndex(): array
{
    $html = nasrHttpGet(
        'https://www.faa.gov/air_traffic/flight_info/aeronav/aero_data/NASR_Subscription/'
    );
    if ($html === null || $html === '') {
        return [];
    }

    $dates = [];

    if (preg_match_all('#NASR_Subscription/(\d{4}-\d{2}-\d{2})#', $html, $pageLinks)) {
        foreach ($pageLinks[1] as $dateYmd) {
            $dates[] = $dateYmd;
        }
    }

    if (preg_match_all(
        '#28DaySubscription_Effective_(\d{4}-\d{2}-\d{2})\.zip#',
        $html,
        $archiveLinks
    )) {
        foreach ($archiveLinks[1] as $dateYmd) {
            $dates[] = $dateYmd;
        }
    }

    return array_values(array_unique($dates));
}

/**
 * Resolve APT CSV zip URL from a cycle detail page (authoritative link text).
 */
function discoverNasrAptZipUrlFromCyclePage(string $dateYmd): ?string
{
    $pageUrl = 'https://www.faa.gov/air_traffic/flight_info/aeronav/aero_data/NASR_Subscription/'
        . $dateYmd;
    $html = nasrHttpGet($pageUrl);
    if ($html === null || $html === '') {
        return null;
    }

    if (preg_match(
        '#https://nfdc\.faa\.gov/webContent/28DaySub/extra/[^"\']+_APT_CSV\.zip#',
        $html,
        $match
    )) {
        return $match[0];
    }

    return null;
}

/**
 * Select the active NASR cycle: largest effective date on or before today (UTC).
 *
 * @param list<string> $datesYmd
 */
function selectCurrentNasrCycleDate(array $datesYmd, ?int $referenceTimestamp = null): ?string
{
    $referenceTimestamp = $referenceTimestamp ?? time();
    $today = gmdate('Y-m-d', $referenceTimestamp);
    $current = null;

    foreach ($datesYmd as $dateYmd) {
        if (!is_string($dateYmd) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd) !== 1) {
            continue;
        }
        if ($dateYmd <= $today && ($current === null || $dateYmd > $current)) {
            $current = $dateYmd;
        }
    }

    return $current;
}

/**
 * Select the next NASR cycle after the current one.
 *
 * @param list<string> $datesYmd
 */
function selectNextNasrCycleDate(array $datesYmd, ?string $currentCycleDate): ?string
{
    if ($currentCycleDate === null || $currentCycleDate === '') {
        return null;
    }

    $next = null;
    foreach ($datesYmd as $dateYmd) {
        if (!is_string($dateYmd) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd) !== 1) {
            continue;
        }
        if ($dateYmd <= $currentCycleDate) {
            continue;
        }
        if ($next === null || $dateYmd < $next) {
            $next = $dateYmd;
        }
    }

    return $next;
}

/**
 * Rank cycle dates by calendar distance to a reference day (default today UTC).
 *
 * When two cycles are equally distant, prefer the earlier (current) cycle over preview.
 *
 * @param list<string> $datesYmd
 * @return list<string>
 */
function rankNasrCycleDatesByProximityToToday(array $datesYmd, ?int $referenceTimestamp = null): array
{
    $referenceTimestamp = $referenceTimestamp ?? time();
    $unique = array_values(array_unique(array_filter($datesYmd, static function ($date) {
        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
    })));

    usort($unique, static function (string $a, string $b) use ($referenceTimestamp): int {
        $ta = strtotime($a . ' UTC');
        $tb = strtotime($b . ' UTC');
        if ($ta === false || $tb === false) {
            return $a <=> $b;
        }

        $distanceA = abs($ta - $referenceTimestamp);
        $distanceB = abs($tb - $referenceTimestamp);
        if ($distanceA !== $distanceB) {
            return $distanceA <=> $distanceB;
        }

        return $ta <=> $tb;
    });

    return $unique;
}

/**
 * Check that a remote NASR zip exists (NFDC often rejects HEAD; use a 1-byte range GET).
 */
function nasrRemoteZipExists(string $url, bool $allowRetry = false): bool
{
    if ($url === '') {
        return false;
    }

    $options = ['range_bytes' => '0-0'];
    if (!$allowRetry) {
        $options['max_attempts'] = 1;
    }

    return nasrHttpRequest($url, $options) !== null;
}

/**
 * Candidate cycle dates for NFDC probing (28-day grid first, then narrow daily window).
 *
 * @return list<string> YYYY-MM-DD
 */
function generateNasrCycleProbeCandidates(
    ?string $anchorDateYmd = null,
    int $daysBefore = NASR_PROBE_DAYS_BEFORE,
    int $daysAfter = NASR_PROBE_DAYS_AFTER
): array {
    $anchorTs = $anchorDateYmd !== null ? strtotime($anchorDateYmd . ' UTC') : false;
    if ($anchorTs === false) {
        $anchorTs = time();
    }

    $dates = [];
    foreach ([-2, -1, 0, 1, 2] as $cycleMultiplier) {
        $dates[] = gmdate('Y-m-d', $anchorTs + ($cycleMultiplier * NASR_CYCLE_PERIOD_DAYS * 86400));
    }

    foreach (generateNasrNarrowProbeWindow($anchorDateYmd, $daysBefore, $daysAfter) as $dailyDate) {
        $dates[] = $dailyDate;
    }

    $seen = [];
    $ordered = [];
    foreach ($dates as $dateYmd) {
        if (isset($seen[$dateYmd])) {
            continue;
        }
        $seen[$dateYmd] = true;
        $ordered[] = $dateYmd;
    }

    return $ordered;
}

/**
 * Narrow calendar window for NFDC probing when the FAA index is unreachable.
 *
 * @return list<string> YYYY-MM-DD
 */
function generateNasrNarrowProbeWindow(
    ?string $anchorDateYmd = null,
    int $daysBefore = NASR_PROBE_DAYS_BEFORE,
    int $daysAfter = NASR_PROBE_DAYS_AFTER
): array {
    $anchorTs = $anchorDateYmd !== null ? strtotime($anchorDateYmd . ' UTC') : false;
    if ($anchorTs === false) {
        $anchorTs = time();
    }

    $dates = [];
    for ($offset = -$daysBefore; $offset <= $daysAfter; $offset++) {
        $dates[] = gmdate('Y-m-d', $anchorTs + ($offset * 86400));
    }

    return $dates;
}

/**
 * Whether tracked cycle metadata should be rediscovered from FAA/NFDC.
 *
 * @param array<string, mixed>|null $meta NASR meta from cache
 */
function nasrCycleRediscoveryNeeded(?array $meta, ?int $referenceTimestamp = null): bool
{
    $referenceTimestamp = $referenceTimestamp ?? time();
    $today = gmdate('Y-m-d', $referenceTimestamp);

    if ($meta === null || empty($meta['tracked_current_cycle_date'])) {
        return true;
    }

    $nextCycle = $meta['tracked_next_cycle_date'] ?? null;
    if (is_string($nextCycle) && $nextCycle !== '' && $today >= $nextCycle) {
        return true;
    }

    $currentCycle = $meta['tracked_current_cycle_date'] ?? null;
    if (is_string($currentCycle) && $currentCycle !== '') {
        $estimatedNext = nasrEstimateNextCycleDate($currentCycle);
        if ($estimatedNext !== null && $today >= $estimatedNext) {
            return true;
        }
    }

    return false;
}

/**
 * Estimate the next NASR cycle from a current cycle effective date.
 */
function nasrEstimateNextCycleDate(?string $currentCycleDate): ?string
{
    if ($currentCycleDate === null || $currentCycleDate === '') {
        return null;
    }

    $currentTs = strtotime($currentCycleDate . ' UTC');
    if ($currentTs === false) {
        return null;
    }

    return gmdate('Y-m-d', $currentTs + (NASR_CYCLE_PERIOD_DAYS * 86400));
}

/**
 * Probe NFDC for valid APT zip cycle dates near an anchor day.
 *
 * @return list<string> YYYY-MM-DD dates with a reachable APT zip
 */
function probeNasrCycleDatesNearAnchor(?string $anchorDateYmd, ?int $referenceTimestamp = null): array
{
    $referenceTimestamp = $referenceTimestamp ?? time();
    $probeDates = generateNasrCycleProbeCandidates($anchorDateYmd);
    $found = [];

    foreach ($probeDates as $dateYmd) {
        $url = buildNasrAptZipUrl($dateYmd);
        if ($url !== '' && nasrRemoteZipExists($url)) {
            $found[] = $dateYmd;
        }

        $current = selectCurrentNasrCycleDate($found, $referenceTimestamp);
        $next = selectNextNasrCycleDate($found, $current);
        if ($current !== null && $next !== null) {
            break;
        }
    }

    return array_values(array_unique($found));
}

/**
 * Discover current and next NASR cycle dates to track.
 *
 * @param array<string, mixed>|null $cachedMeta Existing NASR meta (tracked cycles)
 * @return array{
 *   current_cycle_date: ?string,
 *   next_cycle_date: ?string,
 *   source: string,
 *   known_cycle_dates: list<string>
 * }
 */
function discoverNasrTrackedCycles(?array $cachedMeta = null, ?int $referenceTimestamp = null): array
{
    $referenceTimestamp = $referenceTimestamp ?? time();
    $rediscover = nasrCycleRediscoveryNeeded($cachedMeta, $referenceTimestamp);

    if (!$rediscover && is_array($cachedMeta)) {
        $current = $cachedMeta['tracked_current_cycle_date'] ?? null;
        $next = $cachedMeta['tracked_next_cycle_date'] ?? null;
        if (is_string($current) && $current !== '') {
            $url = buildNasrAptZipUrl($current);
            if ($url !== '' && nasrRemoteZipExists($url)) {
                $known = [];
                if (is_string($next) && $next !== '') {
                    $known[] = $next;
                }
                $known[] = $current;

                return [
                    'current_cycle_date' => $current,
                    'next_cycle_date' => is_string($next) && $next !== '' ? $next : null,
                    'source' => 'cached_meta',
                    'known_cycle_dates' => array_values(array_unique($known)),
                ];
            }
        }
    }

    $datesFromIndex = discoverNasrCycleDatesFromFaaIndex();
    if ($datesFromIndex !== []) {
        $current = selectCurrentNasrCycleDate($datesFromIndex, $referenceTimestamp);
        $next = selectNextNasrCycleDate($datesFromIndex, $current);

        return [
            'current_cycle_date' => $current,
            'next_cycle_date' => $next,
            'source' => 'faa_index',
            'known_cycle_dates' => $datesFromIndex,
        ];
    }

    $anchor = null;
    if (is_array($cachedMeta)) {
        $anchor = $cachedMeta['tracked_next_cycle_date'] ?? null;
        if (!is_string($anchor) || $anchor === '') {
            $currentCached = $cachedMeta['tracked_current_cycle_date'] ?? null;
            if (is_string($currentCached) && $currentCached !== '') {
                $anchorTs = strtotime($currentCached . ' UTC');
                if ($anchorTs !== false) {
                    $anchor = gmdate(
                        'Y-m-d',
                        $anchorTs + (NASR_CYCLE_PERIOD_DAYS * 86400)
                    );
                }
            }
        }
    }

    $probed = probeNasrCycleDatesNearAnchor(is_string($anchor) ? $anchor : null, $referenceTimestamp);
    $current = selectCurrentNasrCycleDate($probed, $referenceTimestamp);
    $next = selectNextNasrCycleDate($probed, $current);

    if ($current !== null && $next === null) {
        $currentTs = strtotime($current . ' UTC');
        if ($currentTs !== false) {
            for ($mult = 1; $mult <= 2; $mult++) {
                $candidate = gmdate(
                    'Y-m-d',
                    $currentTs + ($mult * NASR_CYCLE_PERIOD_DAYS * 86400)
                );
                $candidateUrl = buildNasrAptZipUrl($candidate);
                if ($candidateUrl !== '' && nasrRemoteZipExists($candidateUrl)) {
                    $probed[] = $candidate;
                    $next = selectNextNasrCycleDate($probed, $current);
                    if ($next !== null) {
                        break;
                    }
                }
            }
        }
    }

    return [
        'current_cycle_date' => $current,
        'next_cycle_date' => $next,
        'source' => 'nfdc_probe',
        'known_cycle_dates' => $probed,
    ];
}

/**
 * Resolve APT CSV zip URL for one cycle date.
 */
function resolveNasrAptZipUrlForCycle(string $dateYmd): string
{
    $fromPage = discoverNasrAptZipUrlFromCyclePage($dateYmd);
    if ($fromPage !== null && $fromPage !== '') {
        return $fromPage;
    }

    return buildNasrAptZipUrl($dateYmd);
}

/**
 * Build download plan for the active NASR cycle (current, not preview).
 *
 * @param array<string, mixed>|null $cachedMeta Existing NASR meta
 * @return list<array{effective_date: string, source_url: string, discovery_source: string}>
 */
function buildNasrAptDownloadPlans(?int $referenceTimestamp = null, ?array $cachedMeta = null): array
{
    $referenceTimestamp = $referenceTimestamp ?? time();
    $tracked = discoverNasrTrackedCycles($cachedMeta, $referenceTimestamp);
    $current = $tracked['current_cycle_date'] ?? null;

    if ($current === null || $current === '') {
        return [];
    }

    $useFaaLinks = $tracked['source'] === 'faa_index';
    $url = $useFaaLinks
        ? resolveNasrAptZipUrlForCycle($current)
        : buildNasrAptZipUrl($current);

    if ($url === '' || !nasrRemoteZipExists($url, true)) {
        return [];
    }

    return [
        [
            'effective_date' => $current,
            'source_url' => $url,
            'discovery_source' => $tracked['source'],
            'tracked_next_cycle_date' => $tracked['next_cycle_date'] ?? null,
            'known_cycle_dates' => $tracked['known_cycle_dates'] ?? [],
        ],
    ];
}
