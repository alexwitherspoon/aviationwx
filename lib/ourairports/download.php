<?php

/**
 * OurAirports full GET downloads to raw CSV cache files.
 */

require_once __DIR__ . '/http.php';
require_once __DIR__ . '/meta.php';
require_once __DIR__ . '/urls.php';

/**
 * Download one OurAirports CSV when policy requires a fetch.
 *
 * @return array{
 *   file_key: string,
 *   downloaded: bool,
 *   ok: bool,
 *   http_code: ?int
 * }
 */
function ourAirportsDownloadFile(string $fileKey): array
{
    if (!ourAirportsIsValidFileKey($fileKey)) {
        return [
            'file_key' => $fileKey,
            'downloaded' => false,
            'ok' => false,
            'http_code' => null,
        ];
    }

    $response = ourAirportsHttpGet(ourAirportsCsvUrl($fileKey));
    $now = time();

    if (!$response['ok'] || $response['body'] === null) {
        return [
            'file_key' => $fileKey,
            'downloaded' => false,
            'ok' => false,
            'http_code' => $response['http_code'],
        ];
    }

    ensureCacheDir(CACHE_OURAIRPORTS_DIR);
    $path = ourAirportsCsvPath($fileKey);
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $response['body'], LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        return [
            'file_key' => $fileKey,
            'downloaded' => false,
            'ok' => false,
            'http_code' => $response['http_code'],
        ];
    }

    ourAirportsUpdateFileMeta($fileKey, [
        'last_fetch_at' => $now,
        'last_probe_result' => 'unchanged',
        'etag' => $response['etag'],
        'upstream_etag' => $response['etag'],
        'last_modified' => $response['last_modified'],
    ]);

    return [
        'file_key' => $fileKey,
        'downloaded' => true,
        'ok' => true,
        'http_code' => $response['http_code'],
    ];
}
