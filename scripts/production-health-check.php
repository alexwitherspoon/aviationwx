#!/usr/bin/env php
<?php
/**
 * Production external health checks: HTTPS probes against public AviationWX hosts.
 *
 * Read-only GET requests. Exit 0 when all checks pass, 1 on any failure.
 * Used by .github/workflows/production-health-check.yml (daily schedule).
 *
 * Environment (optional overrides):
 * - HEALTH_CHECK_MAIN                    default https://aviationwx.org
 * - HEALTH_CHECK_API                     default https://api.aviationwx.org
 * - HEALTH_CHECK_EMBED                   default https://embed.aviationwx.org
 * - HEALTH_CHECK_ICAO                    default kspb (lowercase; embed, dashboard, internal routes)
 * - HEALTH_CHECK_TIMEOUT                 default 20 (seconds)
 * - HEALTH_CHECK_MIN_INTERVAL_SECONDS    default 1.0 (minimum time between request starts; reduces burst traffic; non-numeric values fall back to 1.0; 0 disables throttling)
 * - HEALTH_CHECK_SAMPLE_AIRPORTS         default 3 (random listed airports for Public API weather + webcams only; non-numeric values fall back to 3; 0 clamps to 1)
 *
 * Reliable JSON/static probes validate response shape (see lib/production-health-check-evaluators.php).
 *
 * @package AviationWX
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/production-health-check-airports.php';
require_once __DIR__ . '/../lib/production-health-check-evaluators.php';

/**
 * Minimum spacing between outbound HTTP request starts (wall clock).
 *
 * @return float Seconds between request starts; non-numeric env values fall back to 1.0; 0 disables throttling (for local runs only)
 */
function productionHealthCheckMinIntervalSeconds(): float
{
    $raw = getenv('HEALTH_CHECK_MIN_INTERVAL_SECONDS');
    if ($raw === false || $raw === '') {
        return 1.0;
    }
    $t = trim((string) $raw);
    if (!is_numeric($t)) {
        return 1.0;
    }
    $v = (float) $t;
    if ($v <= 0) {
        return 0.0;
    }

    return $v;
}

/**
 * Read HEALTH_CHECK_SAMPLE_AIRPORTS: default 3, minimum 1 sample; invalid non-numeric uses default.
 *
 * @return int Number of random airports to probe for weather and webcams
 */
function productionHealthCheckReadSampleAirportCount(): int
{
    $raw = getenv('HEALTH_CHECK_SAMPLE_AIRPORTS');
    if ($raw === false || $raw === '') {
        return 3;
    }
    $t = trim((string) $raw);
    if (!is_numeric($t)) {
        return 3;
    }

    return max(1, (int) $t);
}

/**
 * Sleep until at least $interval seconds have passed since the previous request start marker.
 *
 * Chunked usleep (max 1s per iteration) avoids a single huge delay on platforms with usec limits.
 */
function productionHealthCheckThrottleBeforeRequest(): void
{
    static $lastRequestStart = null;
    $interval = productionHealthCheckMinIntervalSeconds();
    if ($interval <= 0) {
        return;
    }
    if ($lastRequestStart !== null) {
        $earliestNext = $lastRequestStart + $interval;
        $now = microtime(true);
        while ($now < $earliestNext) {
            $sleepUsec = (int) round(min($earliestNext - $now, 1.0) * 1_000_000);
            if ($sleepUsec < 1) {
                $sleepUsec = 1;
            }
            usleep($sleepUsec);
            $now = microtime(true);
        }
    }
    $lastRequestStart = microtime(true);
}

/**
 * Fetch GET /v1/airports once (throttled). Used for list validation and random sampling.
 *
 * @return array{code: int, json: array<string, mixed>|null, error: string}
 */
function productionHealthCheckFetchAirportsList(string $api, int $timeout): array
{
    $r = productionHealthCheckRequest(
        $api . '/v1/airports',
        productionHealthCheckFormatHeaders(['Accept' => 'application/json']),
        $timeout
    );
    $json = null;
    if ($r['body'] !== '') {
        $json = production_health_check_json_decode_assoc($r['body']);
    }

    return [
        'code' => $r['code'],
        'json' => $json,
        'error' => $r['error'],
    ];
}

/**
 * HTTP GET with optional request headers; follows redirects.
 *
 * With redirects, the response body is only the final hop; header lines are collected in
 * order and reset on each HTTP status line so the final response supplies CORS and content headers.
 *
 * @param string $url Request URL
 * @param array<int, string> $requestHeaders Raw header lines (e.g. "Origin: https://...")
 * @param int $timeoutSeconds Connect and total operation timeout
 * @return array{
 *     code: int,
 *     headers: array<string, string>,
 *     body: string,
 *     error: string,
 *     final_url: string
 * }
 */
function productionHealthCheckRequest(string $url, array $requestHeaders, int $timeoutSeconds): array
{
    if (!function_exists('curl_init')) {
        return [
            'code' => 0,
            'headers' => [],
            'body' => '',
            'error' => 'PHP curl extension not available',
            'final_url' => $url,
        ];
    }
    productionHealthCheckThrottleBeforeRequest();
    $ch = curl_init($url);
    if ($ch === false) {
        return [
            'code' => 0,
            'headers' => [],
            'body' => '',
            'error' => 'curl_init failed',
            'final_url' => $url,
        ];
    }
    // CURLOPT_HEADER false: final body only; HEADERFUNCTION collects lines; reset on new status
    // so multi-hop redirects do not mix header sets or append prior bodies to the payload.
    $headerLines = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'AviationWX-production-health-check/1.0',
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADERFUNCTION => static function ($ch, $header) use (&$headerLines) {
            $len = strlen($header);
            $trim = trim($header);
            if ($trim !== '' && preg_match('/^HTTP\\/\\d/', $trim) === 1) {
                $headerLines = [];
            }
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $headerLines[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return $len;
        },
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $curlErr = curl_error($ch);

    if ($body === false) {
        return [
            'code' => 0,
            'headers' => $headerLines,
            'body' => '',
            'error' => $curlErr !== '' ? $curlErr : 'curl_exec failed',
            'final_url' => $finalUrl,
        ];
    }

    return [
        'code' => $code,
        'headers' => $headerLines,
        'body' => (string) $body,
        'error' => $curlErr,
        'final_url' => $finalUrl,
    ];
}

/**
 * Format associative headers as cURL request header lines.
 *
 * @param array<string, string> $headers Header name (no colon) to value
 * @return array<int, string> Lines for CURLOPT_HTTPHEADER
 */
function productionHealthCheckFormatHeaders(array $headers): array
{
    $out = [];
    foreach ($headers as $k => $v) {
        $out[] = $k . ': ' . $v;
    }

    return $out;
}

/**
 * Run a single named check and record wall-clock duration.
 *
 * Elapsed time includes any {@see productionHealthCheckThrottleBeforeRequest} sleep and the HTTP
 * round trip for this callback (not comparable to server-side latency alone).
 *
 * Callback exceptions are caught and reported as a failed check row.
 * @param string $name Stable check identifier (used in logs and GitHub summary)
 * @param callable(): array{ok: bool, detail?: string} $fn Check body
 * @return array{name: string, ok: bool, detail: string}
 */
function runNamedCheck(string $name, callable $fn): array
{
    $start = microtime(true);
    try {
        /** @var array{ok: bool, detail?: string} $r */
        $r = $fn();
        $elapsed = round(microtime(true) - $start, 3);
        $msg = $r['detail'] ?? ($r['ok'] ? 'OK' : 'failed');

        return [
            'name' => $name,
            'ok' => (bool) $r['ok'],
            'detail' => $name . ': ' . $msg . " ({$elapsed}s)",
        ];
    } catch (Throwable $e) {
        $elapsed = round(microtime(true) - $start, 3);

        return [
            'name' => $name,
            'ok' => false,
            'detail' => $name . ': ' . $e->getMessage() . " ({$elapsed}s)",
        ];
    }
}

$main = rtrim((string) (getenv('HEALTH_CHECK_MAIN') ?: 'https://aviationwx.org'), '/');
$api = rtrim((string) (getenv('HEALTH_CHECK_API') ?: 'https://api.aviationwx.org'), '/');
$embed = rtrim((string) (getenv('HEALTH_CHECK_EMBED') ?: 'https://embed.aviationwx.org'), '/');
$icao = strtolower(trim((string) (getenv('HEALTH_CHECK_ICAO') ?: 'kspb')));
$timeout = max(5, (int) (getenv('HEALTH_CHECK_TIMEOUT') ?: '20'));
$googleEmbedOrigin = 'https://1912747447-atari-embeds.googleusercontent.com';

$sampleCount = productionHealthCheckReadSampleAirportCount();
$airportsList = productionHealthCheckFetchAirportsList($api, $timeout);
$sampleAirports = production_health_check_pick_sample_airports($airportsList['json'], $icao, $sampleCount);

$sampleChecks = [];
foreach ($sampleAirports as $sampleIndex => $sampleId) {
    $checkSuffix = preg_replace('/[^a-z0-9-]/', '_', $sampleId);
    if ($checkSuffix === '') {
        $checkSuffix = 'airport';
    }
    $slotKey = (string) $sampleIndex . '_' . $checkSuffix;
    $sampleChecks['api_v1_weather_' . $slotKey] = static function () use ($api, $sampleId, $timeout) {
        $r = productionHealthCheckRequest(
            $api . '/v1/airports/' . rawurlencode($sampleId) . '/weather',
            productionHealthCheckFormatHeaders(['Accept' => 'application/json']),
            $timeout
        );
        if ($r['code'] === 503) {
            return ['ok' => true, 'detail' => 'HTTP 503 (upstream)'];
        }
        if ($r['code'] === 429) {
            return ['ok' => true, 'detail' => 'HTTP 429 (rate limit)'];
        }
        if ($r['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        $json = production_health_check_json_decode_assoc($r['body']);
        if ($json === null) {
            return ['ok' => false, 'detail' => 'invalid JSON body'];
        }
        $ev = production_health_check_evaluate_api_v1_weather_json($json);
        if (!$ev['ok']) {
            return ['ok' => false, 'detail' => $ev['detail']];
        }

        return ['ok' => true, 'detail' => 'HTTP 200, ' . $ev['detail']];
    };
    $sampleChecks['api_v1_webcams_' . $slotKey] = static function () use ($api, $sampleId, $timeout) {
        $r = productionHealthCheckRequest(
            $api . '/v1/airports/' . rawurlencode($sampleId) . '/webcams',
            productionHealthCheckFormatHeaders(['Accept' => 'application/json']),
            $timeout
        );
        if ($r['code'] === 429) {
            return ['ok' => true, 'detail' => 'HTTP 429 (rate limit)'];
        }
        if ($r['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        $json = production_health_check_json_decode_assoc($r['body']);
        if ($json === null) {
            return ['ok' => false, 'detail' => 'invalid JSON body'];
        }
        $ev = production_health_check_evaluate_api_v1_webcams_json($json);
        if (!$ev['ok']) {
            return ['ok' => false, 'detail' => $ev['detail']];
        }

        return ['ok' => true, 'detail' => 'HTTP 200, ' . $ev['detail']];
    };
}

$checksHead = [
    'embed_widget_js' => static function () use ($embed, $timeout) {
        $r = productionHealthCheckRequest($embed . '/widget.js', [], $timeout);
        if ($r['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code'] . ' ' . $r['error']];
        }
        $ct = $r['headers']['content-type'] ?? '';
        if (stripos($ct, 'javascript') === false && stripos($ct, 'application/javascript') === false && stripos($ct, 'text/javascript') === false) {
            return ['ok' => false, 'detail' => 'unexpected content-type: ' . $ct];
        }
        if (strlen($r['body']) < 200) {
            return ['ok' => false, 'detail' => 'body too small'];
        }
        $body = $r['body'];
        // Public embed contract: custom element tag and registration (not comment text or branding).
        if (stripos($body, 'aviation-wx') === false) {
            return ['ok' => false, 'detail' => 'missing custom element tag aviation-wx'];
        }
        if (stripos($body, 'customElements.define') === false) {
            return ['ok' => false, 'detail' => 'missing customElements.define'];
        }
        return ['ok' => true, 'detail' => 'HTTP 200'];
    },
    'embed_css' => static function () use ($embed, $timeout) {
        $r = productionHealthCheckRequest($embed . '/public/css/embed-widgets.css', [], $timeout);
        if ($r['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        $ct = $r['headers']['content-type'] ?? '';
        if (stripos($ct, 'text/css') === false) {
            return ['ok' => false, 'detail' => 'unexpected content-type: ' . $ct];
        }
        if (strlen($r['body']) < 100) {
            return ['ok' => false, 'detail' => 'CSS body too small'];
        }
        $body = $r['body'];
        // Theme tokens shipped with embed widgets (stable contract; survives :root layout edits).
        if (stripos($body, '--bg-color') === false || stripos($body, '--card-bg') === false) {
            return ['ok' => false, 'detail' => 'missing expected theme CSS variables'];
        }
        return ['ok' => true, 'detail' => 'HTTP 200 + text/css'];
    },
    'embed_api_cors' => static function () use ($embed, $icao, $timeout, $googleEmbedOrigin) {
        $embedHost = parse_url($embed, PHP_URL_HOST);
        if (!is_string($embedHost) || $embedHost === '') {
            return ['ok' => false, 'detail' => 'invalid HEALTH_CHECK_EMBED host'];
        }
        $url = $embed . '/api/v1/airports/' . rawurlencode($icao) . '/embed';
        $r = productionHealthCheckRequest(
            $url,
            productionHealthCheckFormatHeaders([
                'Origin' => $googleEmbedOrigin,
                'Accept' => 'application/json',
            ]),
            $timeout
        );
        $finalHost = parse_url($r['final_url'], PHP_URL_HOST);
        if (!is_string($finalHost) || strcasecmp($finalHost, $embedHost) !== 0) {
            return ['ok' => false, 'detail' => 'expected final URL on embed host ' . $embedHost . ', got ' . $r['final_url']];
        }
        if ($r['code'] === 429) {
            return ['ok' => true, 'detail' => 'HTTP 429 (rate limit)'];
        }
        if ($r['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code'] . ' ' . $r['error']];
        }
        $acao = $r['headers']['access-control-allow-origin'] ?? '';
        if ($acao === '') {
            return ['ok' => false, 'detail' => 'missing Access-Control-Allow-Origin'];
        }
        $json = production_health_check_json_decode_assoc($r['body']);
        if ($json === null) {
            return ['ok' => false, 'detail' => 'invalid JSON body'];
        }
        $ev = production_health_check_evaluate_api_v1_embed_json($json);
        if (!$ev['ok']) {
            return ['ok' => false, 'detail' => $ev['detail']];
        }
        return ['ok' => true, 'detail' => '200 + CORS + embed JSON'];
    },
    'api_v1_embed' => static function () use ($api, $icao, $timeout) {
        $url = $api . '/v1/airports/' . rawurlencode($icao) . '/embed';
        $r = productionHealthCheckRequest($url, productionHealthCheckFormatHeaders(['Accept' => 'application/json']), $timeout);
        if ($r['code'] === 429) {
            return ['ok' => true, 'detail' => 'HTTP 429 (rate limit)'];
        }
        if ($r['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        $json = production_health_check_json_decode_assoc($r['body']);
        if ($json === null) {
            return ['ok' => false, 'detail' => 'invalid JSON body'];
        }
        $ev = production_health_check_evaluate_api_v1_embed_json($json);
        if (!$ev['ok']) {
            return ['ok' => false, 'detail' => $ev['detail']];
        }
        return ['ok' => true, 'detail' => 'HTTP 200, ' . $ev['detail']];
    },
    'api_v1_version' => static function () use ($api, $timeout) {
        $r = productionHealthCheckRequest($api . '/v1/version.php', [], $timeout);
        if ($r['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        $json = production_health_check_json_decode_assoc($r['body']);
        if ($json === null) {
            return ['ok' => false, 'detail' => 'invalid JSON body'];
        }
        $ev = production_health_check_evaluate_api_v1_version_json($json);
        if (!$ev['ok']) {
            return ['ok' => false, 'detail' => $ev['detail']];
        }
        return ['ok' => true, 'detail' => 'HTTP 200, ' . $ev['detail']];
    },
    'api_v1_status' => static function () use ($api, $timeout) {
        $r = productionHealthCheckRequest($api . '/v1/status', productionHealthCheckFormatHeaders(['Accept' => 'application/json']), $timeout);
        if ($r['code'] === 429) {
            return ['ok' => true, 'detail' => 'HTTP 429 (rate limit)'];
        }
        if ($r['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        $json = production_health_check_json_decode_assoc($r['body']);
        if ($json === null) {
            return ['ok' => false, 'detail' => 'invalid JSON body'];
        }
        $ev = production_health_check_evaluate_api_v1_status_json($json);
        if (!$ev['ok']) {
            return ['ok' => false, 'detail' => $ev['detail']];
        }
        return ['ok' => true, 'detail' => 'HTTP 200, ' . $ev['detail']];
    },
    'api_v1_operations' => static function () use ($api, $timeout) {
        $r = productionHealthCheckRequest($api . '/v1/operations', productionHealthCheckFormatHeaders(['Accept' => 'application/json']), $timeout);
        if ($r['code'] === 429) {
            return ['ok' => true, 'detail' => 'HTTP 429 (rate limit)'];
        }
        if ($r['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        $json = production_health_check_json_decode_assoc($r['body']);
        if ($json === null) {
            return ['ok' => false, 'detail' => 'invalid JSON body'];
        }
        $ev = production_health_check_evaluate_api_v1_operations_json($json);
        if (!$ev['ok']) {
            return ['ok' => false, 'detail' => $ev['detail']];
        }
        return ['ok' => true, 'detail' => 'HTTP 200, ' . $ev['detail']];
    },
    'api_v1_airports' => static function () use ($airportsList) {
        if ($airportsList['code'] === 429) {
            return ['ok' => true, 'detail' => 'HTTP 429 (rate limit)'];
        }
        if ($airportsList['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $airportsList['code'] . ' ' . $airportsList['error']];
        }
        $j = $airportsList['json'];
        if (!is_array($j) || ($j['success'] ?? null) !== true || !isset($j['airports']) || !is_array($j['airports'])) {
            return ['ok' => false, 'detail' => 'invalid airports JSON'];
        }

        return ['ok' => true, 'detail' => 'HTTP 200, ' . count($j['airports']) . ' airports (list used for random samples)'];
    },
];

$checksTail = [
    'main_home' => static function () use ($main, $timeout) {
        $r = productionHealthCheckRequest($main . '/', [], $timeout);
        if ($r['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        return ['ok' => true, 'detail' => 'HTTP 200'];
    },
    'dashboard_subdomain' => static function () use ($icao, $timeout) {
        $url = 'https://' . rawurlencode($icao) . '.aviationwx.org/';
        $r = productionHealthCheckRequest($url, [], $timeout);
        if (!in_array($r['code'], [200, 404], true)) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        return ['ok' => true, 'detail' => 'HTTP ' . $r['code']];
    },
    'internal_weather_json' => static function () use ($main, $icao, $timeout) {
        $r = productionHealthCheckRequest(
            $main . '/api/weather.php?airport=' . rawurlencode($icao),
            productionHealthCheckFormatHeaders(['Accept' => 'application/json']),
            $timeout
        );
        if (in_array($r['code'], [503, 429], true)) {
            return ['ok' => true, 'detail' => 'HTTP ' . $r['code']];
        }
        if ($r['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        return ['ok' => true, 'detail' => 'HTTP 200'];
    },
    'internal_webcam_image' => static function () use ($main, $icao, $timeout) {
        $r = productionHealthCheckRequest($main . '/api/webcam.php?airport=' . rawurlencode($icao) . '&index=0', [], $timeout);
        if (!in_array($r['code'], [200, 404], true)) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        if ($r['code'] === 200) {
            $ct = $r['headers']['content-type'] ?? '';
            if (stripos($ct, 'image/') !== 0) {
                return ['ok' => false, 'detail' => 'expected image/*, got ' . $ct];
            }
        }
        return ['ok' => true, 'detail' => 'HTTP ' . $r['code']];
    },
    'internal_partner_logo_error_shape' => static function () use ($main, $timeout) {
        $r = productionHealthCheckRequest($main . '/api/partner-logo.php', [], $timeout);
        if ($r['code'] === 500) {
            return ['ok' => false, 'detail' => 'HTTP 500'];
        }
        if ($r['code'] !== 400) {
            return ['ok' => false, 'detail' => 'expected HTTP 400, got ' . $r['code']];
        }
        return ['ok' => true, 'detail' => 'HTTP 400'];
    },
    'internal_notam_json' => static function () use ($main, $icao, $timeout) {
        $r = productionHealthCheckRequest(
            $main . '/api/notam.php?airport=' . rawurlencode($icao),
            productionHealthCheckFormatHeaders(['Accept' => 'application/json']),
            $timeout
        );
        if ($r['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        return ['ok' => true, 'detail' => 'HTTP 200'];
    },
    'outage_status_json' => static function () use ($main, $icao, $timeout) {
        $r = productionHealthCheckRequest(
            $main . '/api/outage-status.php?airport=' . rawurlencode($icao),
            productionHealthCheckFormatHeaders(['Accept' => 'application/json']),
            $timeout
        );
        if ($r['code'] === 429) {
            return ['ok' => true, 'detail' => 'HTTP 429 (rate limit)'];
        }
        if ($r['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        $json = production_health_check_json_decode_assoc($r['body']);
        if ($json === null) {
            return ['ok' => false, 'detail' => 'invalid JSON body'];
        }
        $ev = production_health_check_evaluate_outage_status_json($json);
        if (!$ev['ok']) {
            return ['ok' => false, 'detail' => $ev['detail']];
        }
        return ['ok' => true, 'detail' => 'HTTP 200, ' . $ev['detail']];
    },
    'health_live' => static function () use ($main, $timeout) {
        $r = productionHealthCheckRequest($main . '/health/health.php', [], $timeout);
        if ($r['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        $json = production_health_check_json_decode_assoc($r['body']);
        if ($json === null) {
            return ['ok' => false, 'detail' => 'invalid JSON body'];
        }
        $ev = production_health_check_evaluate_health_live_json($json);
        if (!$ev['ok']) {
            return ['ok' => false, 'detail' => $ev['detail']];
        }
        return ['ok' => true, 'detail' => 'HTTP 200, ' . $ev['detail']];
    },
    'health_ready' => static function () use ($main, $timeout) {
        $r = productionHealthCheckRequest($main . '/health/ready.php', [], $timeout);
        if (!in_array($r['code'], [200, 503], true)) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        $json = production_health_check_json_decode_assoc($r['body']);
        if ($json === null) {
            return ['ok' => false, 'detail' => 'invalid JSON body'];
        }
        $ev = production_health_check_evaluate_health_ready_json($json);
        if (!$ev['ok']) {
            return ['ok' => false, 'detail' => $ev['detail']];
        }
        return ['ok' => true, 'detail' => 'HTTP ' . $r['code'] . ', ' . $ev['detail']];
    },
    'openapi_json' => static function () use ($api, $timeout) {
        $r = productionHealthCheckRequest($api . '/openapi.json', productionHealthCheckFormatHeaders(['Accept' => 'application/json']), $timeout);
        if ($r['code'] !== 200) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        $json = production_health_check_json_decode_assoc($r['body']);
        if ($json === null) {
            return ['ok' => false, 'detail' => 'invalid JSON body'];
        }
        $ev = production_health_check_evaluate_openapi_json($json);
        if (!$ev['ok']) {
            return ['ok' => false, 'detail' => $ev['detail']];
        }
        return ['ok' => true, 'detail' => 'HTTP 200, ' . $ev['detail']];
    },
    'map_tiles_proxy' => static function () use ($main, $timeout) {
        $url = $main . '/api/map-tiles.php?layer=clouds_new&z=1&x=0&y=0';
        $r = productionHealthCheckRequest($url, [], $timeout);
        if ($r['code'] === 429) {
            return ['ok' => true, 'detail' => 'HTTP 429'];
        }
        if ($r['code'] >= 500) {
            return ['ok' => false, 'detail' => 'HTTP ' . $r['code']];
        }
        return ['ok' => true, 'detail' => 'HTTP ' . $r['code']];
    },
];

$checks = array_merge($checksHead, $sampleChecks, $checksTail);

fwrite(
    STDOUT,
    '# Airport list: 1 request; sample IDs for Public API weather/webcams: '
        . implode(', ', $sampleAirports)
        . '; min interval '
        . (string) productionHealthCheckMinIntervalSeconds()
        . "s between request starts\n"
);

$failed = 0;
$rows = [];
foreach ($checks as $name => $fn) {
    $row = runNamedCheck($name, $fn);
    $rows[] = $row;
    fwrite(STDOUT, ($row['ok'] ? '[OK] ' : '[FAIL] ') . $row['detail'] . PHP_EOL);
    if (!$row['ok']) {
        $failed++;
    }
}

$summaryPath = getenv('GITHUB_STEP_SUMMARY');
if ($summaryPath !== false && $summaryPath !== '') {
    $dir = dirname($summaryPath);
    if (is_dir($dir) && is_writable($dir)) {
        $md = "\n## Production health check\n\n| Check | Result |\n|-------|--------|\n";
        foreach ($rows as $row) {
            $md .= '| `' . $row['name'] . '` | ' . ($row['ok'] ? 'pass' : '**fail**') . " |\n";
        }
        @file_put_contents($summaryPath, $md, FILE_APPEND);
    }
}

exit($failed > 0 ? 1 : 0);
