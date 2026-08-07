<?php
/**
 * NASR APT subscription discovery (cycle dates and download URLs).
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../worker-timeout.php';

/**
 * User-Agent for FAA/NFDC HTTP.
 *
 * Akamai often 403s "compatible; ...Bot" style UAs on www.faa.gov; a browser-like
 * UA with a trailing AviationWX product token is accepted.
 *
 * @return string
 */
function nasrHttpUserAgent(): string
{
    return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 '
        . 'AviationWX/1.0';
}

/**
 * Min interval between any FAA/NFDC NASR HTTP attempts.
 *
 * @return int Seconds
 */
function nasrHttpMinIntervalSeconds(): int
{
    if (isset($GLOBALS['nasrHttpMinIntervalSeconds'])
        && is_int($GLOBALS['nasrHttpMinIntervalSeconds'])
        && $GLOBALS['nasrHttpMinIntervalSeconds'] >= 0
    ) {
        return $GLOBALS['nasrHttpMinIntervalSeconds'];
    }

    return NASR_HTTP_MIN_INTERVAL_SECONDS;
}

/**
 * Whether the host-wide NASR HTTP throttle should enforce spacing.
 *
 * @return bool
 */
function nasrHttpThrottleShouldEnforce(): bool
{
    if (!empty($GLOBALS['nasrHttpThrottleTestForceEnforcement'])) {
        return true;
    }

    return !isTestMode() && !shouldMockExternalServices();
}

/**
 * Paths for NASR HTTP throttle state (overridable in tests).
 *
 * @return array{0: string, 1: string} [statePath, lockPath]
 */
function nasrHttpThrottlePaths(): array
{
    if (isset($GLOBALS['nasrHttpThrottleTestDir'])
        && is_string($GLOBALS['nasrHttpThrottleTestDir'])
        && $GLOBALS['nasrHttpThrottleTestDir'] !== ''
    ) {
        $dir = $GLOBALS['nasrHttpThrottleTestDir'];
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return [
            $dir . '/.http_last_request',
            $dir . '/.http_throttle.lock',
        ];
    }

    ensureCacheDir(CACHE_NASR_DIR);

    return [
        CACHE_NASR_DIR . '/.http_last_request',
        CACHE_NASR_DIR . '/.http_throttle.lock',
    ];
}

/**
 * Cross-process spacing for all FAA/NFDC NASR HTTP (index, probes, zip downloads).
 *
 * Serializes attempts across APT/FRQ workers via flock. If the lock cannot be
 * acquired, sleeps the full interval without recording state (fail closed on pacing).
 */
function nasrHttpThrottle(): void
{
    // Long NASR jobs: refresh silence clock on each paced FAA/NFDC attempt.
    updateWorkerHeartbeat();

    if (!nasrHttpThrottleShouldEnforce()) {
        return;
    }

    $interval = nasrHttpMinIntervalSeconds();
    if ($interval <= 0) {
        return;
    }

    [$statePath, $lockPath] = nasrHttpThrottlePaths();
    $fh = @fopen($lockPath, 'c+');
    if ($fh === false) {
        aviationwx_log('warning', 'nasr http throttle: lock open failed, sleeping full interval', [
            'lock_path' => $lockPath,
        ], 'app');
        nasrHttpThrottleSleep((float) $interval);
        return;
    }

    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        aviationwx_log('warning', 'nasr http throttle: flock failed, sleeping full interval', [
            'lock_path' => $lockPath,
        ], 'app');
        nasrHttpThrottleSleep((float) $interval);
        return;
    }

    try {
        $last = 0.0;
        if (is_readable($statePath)) {
            $raw = file_get_contents($statePath);
            if (is_string($raw) && is_numeric(trim($raw))) {
                $last = (float) trim($raw);
            }
        }

        $wait = $interval - (microtime(true) - $last);
        if ($wait > 0) {
            nasrHttpThrottleSleep($wait);
        }

        // Stamp under the lock so concurrent workers compute wait from the same clock.
        if (file_put_contents($statePath, (string) microtime(true)) === false) {
            aviationwx_log('warning', 'nasr http throttle: failed to persist last-request stamp', [
                'state_path' => $statePath,
            ], 'app');
        }
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * Sleep for NASR HTTP throttle pacing.
 *
 * @param float $seconds Seconds to wait (fractional allowed)
 */
function nasrHttpThrottleSleep(float $seconds): void
{
    if ($seconds <= 0) {
        return;
    }

    if (isset($GLOBALS['nasrHttpThrottleTestSleep'])
        && is_callable($GLOBALS['nasrHttpThrottleTestSleep'])
    ) {
        ($GLOBALS['nasrHttpThrottleTestSleep'])($seconds);
        return;
    }

    usleep((int) ceil($seconds * 1_000_000));
}

/**
 * Optional unit-test HTTP transport ($GLOBALS['nasrHttpTestTransport']).
 *
 * @return (callable(string, array): array{ok: bool, http_code: int, body: ?string, retryable: bool})|null
 */
function nasrHttpTestTransport(): ?callable
{
    if (!isset($GLOBALS['nasrHttpTestTransport']) || !is_callable($GLOBALS['nasrHttpTestTransport'])) {
        return null;
    }

    return $GLOBALS['nasrHttpTestTransport'];
}

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
    nasrHttpThrottle();

    $transport = nasrHttpTestTransport();
    if ($transport !== null) {
        return $transport($url, $options);
    }

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
        CURLOPT_USERAGENT => nasrHttpUserAgent(),
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);

    $ok = in_array($httpCode, [200, 206], true)
        && ($noBody || $rangeBytes !== null || ($body !== false && $body !== null));

    $retryable = $curlErrno !== 0 || in_array($httpCode, nasrRetryableHttpStatusCodes(), true);

    $result = [
        'ok' => $ok,
        'http_code' => $httpCode,
        'body' => ($body === false) ? null : $body,
        'retryable' => $retryable,
    ];
    curl_close($ch);

    return $result;
}

/**
 * HTTP GET/HEAD with retry/backoff for transient NFDC/FAA failures.
 *
 * @param array{no_body?: bool, max_attempts?: int, range_bytes?: string} $options
 * @return string|null Response body, empty string when no_body succeeds, or null on failure
 */
function nasrHttpRequest(string $url, array $options = []): ?string
{
    $maxAttempts = (int) ($options['max_attempts'] ?? NASR_HTTP_MAX_ATTEMPTS);
    $delays = NASR_HTTP_RETRY_DELAYS_SECONDS;
    $noBody = !empty($options['no_body']);
    $skipRetryDelay = nasrHttpTestTransport() !== null;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        if ($attempt > 0 && !$skipRetryDelay) {
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
 *
 * @return string|null
 */
function nasrHttpGet(string $url): ?string
{
    return nasrHttpRequest($url);
}

/**
 * Execute one HTTP GET and stream the response body to a file handle.
 *
 * @param resource $fileHandle Writable destination (e.g. fopen(..., 'wb'))
 * @return array{ok: bool, http_code: int, retryable: bool}
 */
function nasrHttpRequestOnceToFile(string $url, $fileHandle): array
{
    nasrHttpThrottle();

    $transport = nasrHttpTestTransport();
    if ($transport !== null) {
        $result = $transport($url, []);
        if ($result['ok'] && is_string($result['body'])) {
            if (fwrite($fileHandle, $result['body']) === false) {
                return [
                    'ok' => false,
                    'http_code' => (int) $result['http_code'],
                    'retryable' => true,
                ];
            }
        }

        return [
            'ok' => $result['ok'],
            'http_code' => $result['http_code'],
            'retryable' => $result['retryable'],
        ];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_FILE => $fileHandle,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => nasrHttpUserAgent(),
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);
    $execOk = curl_exec($ch) !== false;
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    $ok = in_array($httpCode, [200, 206], true) && $execOk;
    $retryable = $curlErrno !== 0 || in_array($httpCode, nasrRetryableHttpStatusCodes(), true);

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'retryable' => $retryable,
    ];
}

/**
 * Download a remote file with retry/backoff, streaming directly to disk.
 *
 * @return bool True when the file was downloaded successfully
 */
function nasrHttpDownloadToFile(string $url, string $destPath): bool
{
    $maxAttempts = NASR_HTTP_MAX_ATTEMPTS;
    $delays = NASR_HTTP_RETRY_DELAYS_SECONDS;
    $skipRetryDelay = nasrHttpTestTransport() !== null;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        if ($attempt > 0) {
            if (!$skipRetryDelay) {
                $delayIndex = min($attempt - 1, count($delays) - 1);
                sleep((int) $delays[$delayIndex]);
            }
            @unlink($destPath);
        }

        $fp = @fopen($destPath, 'wb');
        if ($fp === false) {
            return false;
        }

        $result = nasrHttpRequestOnceToFile($url, $fp);
        fclose($fp);

        if ($result['ok']) {
            return true;
        }

        @unlink($destPath);
        if (!$result['retryable']) {
            break;
        }
    }

    return false;
}

/**
 * Build NASR APT zip URL for an effective date (YYYY-MM-DD).
 *
 * FAA names files like 15_May_2025_APT_CSV.zip (not 2025-05-15_APT_CSV.zip).
 */
function buildNasrAptZipUrl(string $dateYmd): string
{
    return buildNasrSubscriptionZipUrl($dateYmd, 'APT');
}

/**
 * Build NASR FRQ zip URL for an effective date (YYYY-MM-DD).
 */
function buildNasrFrqZipUrl(string $dateYmd): string
{
    return buildNasrSubscriptionZipUrl($dateYmd, 'FRQ');
}

/**
 * Build NASR subscription CSV zip URL for a cycle date and data group.
 */
function buildNasrSubscriptionZipUrl(string $dateYmd, string $group): string
{
    $ts = strtotime($dateYmd . ' UTC');
    if ($ts === false) {
        return '';
    }

    $slug = gmdate('d_M_Y', $ts);
    $group = strtoupper(trim($group));

    return 'https://nfdc.faa.gov/webContent/28DaySub/extra/' . $slug . '_' . $group . '_CSV.zip';
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
 * Candidate cycle dates on the NASR/AIRAC 28-day lattice near today.
 *
 * Fixed epoch (any known historical effective date) yields a handful of real
 * AIRAC zip URLs instead of probing every calendar day.
 *
 * @return list<string> YYYY-MM-DD
 */
function generateNasrAiracAlignedProbeCandidates(
    ?int $referenceTimestamp = null,
    int $cyclesBefore = NASR_AIRAC_PROBE_CYCLES_BEFORE,
    int $cyclesAfter = NASR_AIRAC_PROBE_CYCLES_AFTER
): array {
    $referenceTimestamp = $referenceTimestamp ?? time();
    $epochTs = strtotime(NASR_AIRAC_EPOCH_DATE . ' UTC');
    if ($epochTs === false) {
        return [];
    }

    $daysSinceEpoch = intdiv($referenceTimestamp - $epochTs, 86400);
    $cyclesSince = $daysSinceEpoch >= 0
        ? intdiv($daysSinceEpoch, NASR_CYCLE_PERIOD_DAYS)
        : 0;

    $dates = [];
    for ($k = -$cyclesBefore; $k <= $cyclesAfter; $k++) {
        $cycleIndex = $cyclesSince + $k;
        if ($cycleIndex < 0) {
            continue;
        }
        $dates[] = gmdate(
            'Y-m-d',
            $epochTs + ($cycleIndex * NASR_CYCLE_PERIOD_DAYS * 86400)
        );
    }

    return array_values(array_unique($dates));
}

/**
 * Last-resort candidate dates for NFDC probing (28-day grid + daily window).
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
 * Dense calendar window for last-resort NFDC probing.
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
 * Probe NFDC for reachable APT zip cycle dates from an explicit candidate list.
 *
 * Stops early once current and next cycles are both found.
 *
 * @param list<string> $probeDates YYYY-MM-DD
 * @param bool $allowRetry Retry transient NFDC failures (use for small AIRAC sets only)
 * @return list<string> YYYY-MM-DD dates with a reachable APT zip
 */
function probeNasrCycleDates(
    array $probeDates,
    ?int $referenceTimestamp = null,
    bool $allowRetry = false
): array {
    $referenceTimestamp = $referenceTimestamp ?? time();
    $found = [];

    foreach ($probeDates as $dateYmd) {
        if (!is_string($dateYmd) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd) !== 1) {
            continue;
        }
        $url = buildNasrAptZipUrl($dateYmd);
        if ($url !== '' && nasrRemoteZipExists($url, $allowRetry)) {
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
 * Last-resort NFDC probe: dense calendar window near an anchor day.
 *
 * @return list<string> YYYY-MM-DD dates with a reachable APT zip
 */
function probeNasrCycleDatesNearAnchor(?string $anchorDateYmd, ?int $referenceTimestamp = null): array
{
    return probeNasrCycleDates(
        generateNasrCycleProbeCandidates($anchorDateYmd),
        $referenceTimestamp,
        false
    );
}

/**
 * Finish current/next selection after an NFDC probe, including +28d next-cycle chase.
 *
 * @param list<string> $probed
 * @return array{
 *   current_cycle_date: ?string,
 *   next_cycle_date: ?string,
 *   known_cycle_dates: list<string>
 * }
 */
function nasrFinalizeProbedCycles(array $probed, ?int $referenceTimestamp = null): array
{
    $referenceTimestamp = $referenceTimestamp ?? time();
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
                // Small chase set: retry transient NFDC errors so next_cycle is not dropped.
                if ($candidateUrl !== '' && nasrRemoteZipExists($candidateUrl, true)) {
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
        'known_cycle_dates' => array_values(array_unique($probed)),
    ];
}

/**
 * Discover current and next NASR cycle dates to track.
 *
 * Order: cached meta → FAA index → AIRAC-aligned NFDC probes → dense daily NFDC probes.
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

    $airacProbed = probeNasrCycleDates(
        generateNasrAiracAlignedProbeCandidates($referenceTimestamp),
        $referenceTimestamp,
        true
    );
    $airacFinal = nasrFinalizeProbedCycles($airacProbed, $referenceTimestamp);
    if ($airacFinal['current_cycle_date'] !== null) {
        return [
            'current_cycle_date' => $airacFinal['current_cycle_date'],
            'next_cycle_date' => $airacFinal['next_cycle_date'],
            'source' => 'nfdc_airac',
            'known_cycle_dates' => $airacFinal['known_cycle_dates'],
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

    $dailyFinal = nasrFinalizeProbedCycles(
        probeNasrCycleDatesNearAnchor(is_string($anchor) ? $anchor : null, $referenceTimestamp),
        $referenceTimestamp
    );

    return [
        'current_cycle_date' => $dailyFinal['current_cycle_date'],
        'next_cycle_date' => $dailyFinal['next_cycle_date'],
        'source' => 'nfdc_probe',
        'known_cycle_dates' => $dailyFinal['known_cycle_dates'],
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

    if ($url === '') {
        return [];
    }

    // cached_meta / NFDC probe paths already Range-checked the zip; re-checking
    // would burn another minute under the host-wide throttle.
    $zipVerifiedDuringDiscovery = in_array(
        $tracked['source'],
        ['cached_meta', 'nfdc_airac', 'nfdc_probe'],
        true
    );
    if (!$zipVerifiedDuringDiscovery && !nasrRemoteZipExists($url, true)) {
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
