#!/usr/bin/env php
<?php
/**
 * CLI: HEAD-probe each HTTPS URL from {@see aviationRegionBuiltinHttpsUrlsForPeriodicHealthCheck()}.
 *
 * Exit 0 when every URL ends in HTTP 2xx/3xx after follow; exit 1 on any hard failure. Intended for
 * GitHub Actions (`weekly-link-check.yml`, `builtin-aviation-links.yml`) and optional `make check-builtin-aviation-links`.
 *
 * @package AviationWX
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/aviation-region-links.php';

/**
 * HEAD first; on 404, 405, 501, or transport failure (code 0), retry a tiny GET (`Range: bytes=0-0`).
 *
 * Some hosts answer HEAD differently than GET; some return synthetic 404 for uncommon user agents.
 *
 * @return array{ok: bool, code: int, error: string}
 */
function builtinAviationLinkProbe(string $url, int $timeoutSeconds = 20): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'error' => 'PHP curl extension not available'];
    }

    $run = static function (string $method) use ($url, $timeoutSeconds): array {
        $ch = curl_init($url);
        if ($ch === false) {
            return [0, 'curl_init failed'];
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            // Browser-like UA: several government sites return 404 to uncommon or bot-like strings; keep probes honest for pilot-facing links.
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 AviationWX-link-check/1.0',
        ];
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        } else {
            $opts[CURLOPT_HTTPGET] = true;
            $opts[CURLOPT_RANGE] = '0-0';
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);

        return [$code, $err];
    };

    [$code, $err] = $run('HEAD');
    if ($code >= 200 && $code < 400) {
        return ['ok' => true, 'code' => $code, 'error' => ''];
    }
    if (in_array($code, [405, 501, 404], true) || $code === 0) {
        [$codeGet, $errGet] = $run('GET');
        if ($codeGet >= 200 && $codeGet < 400) {
            return ['ok' => true, 'code' => $codeGet, 'error' => ''];
        }

        return ['ok' => false, 'code' => $codeGet, 'error' => $errGet !== '' ? $errGet : $err];
    }

    return ['ok' => false, 'code' => $code, 'error' => $err];
}

$urls = aviationRegionBuiltinHttpsUrlsForPeriodicHealthCheck();
$failures = [];
foreach ($urls as $url) {
    $r = builtinAviationLinkProbe($url);
    if (!$r['ok']) {
        $failures[] = $url . ' HTTP ' . $r['code'] . ($r['error'] !== '' ? ' (' . $r['error'] . ')' : '');
        fwrite(STDERR, "FAIL {$url} -> {$r['code']}\n");
    } else {
        fwrite(STDOUT, "OK {$url} -> {$r['code']}\n");
    }
}

if ($failures !== []) {
    fwrite(STDERR, "\n" . count($failures) . ' of ' . count($urls) . " URLs failed.\n");
    exit(1);
}

fwrite(STDOUT, 'All ' . count($urls) . " built-in HTTPS targets responded successfully.\n");
exit(0);
