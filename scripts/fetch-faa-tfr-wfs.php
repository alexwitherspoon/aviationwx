<?php
/**
 * National FAA TFR WFS ingest worker.
 *
 * Fetches public V_TFR_LOC GeoJSON, caches the raw payload, and merges
 * AirspaceRecord rows into map-airspace.json. On failure, marks WFS unhealthy
 * without clearing existing NMS (or prior) records.
 *
 * Usage: php scripts/fetch-faa-tfr-wfs.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/notam/map-aggregate-cache.php';
require_once __DIR__ . '/../lib/notam/airspace/UnifiedNotamFetcher.php';

use AviationWX\Notam\Airspace\Adapter\FaaTfrWfsAdapter;
use AviationWX\Notam\Airspace\UnifiedNotamFetcher;

$started = microtime(true);

$result = UnifiedNotamFetcher::fetchSource(FaaTfrWfsAdapter::SOURCE_TYPE, [
    'persist_raw' => true,
    'max_features' => 500,
]);

if (!$result['ok']) {
    $error = $result['error'] !== '' ? $result['error'] : 'WFS fetch failed';
    notamMapAirspaceAggregateMarkSourceStatus(FaaTfrWfsAdapter::SOURCE_TYPE, false, $error);
    aviationwx_log('warning', 'faa tfr wfs: fetch failed; retaining prior aggregate', [
        'error' => $error,
        'http_code' => $result['http_code'],
    ], 'app');
    fwrite(STDERR, "FAA TFR WFS fetch failed: {$error}\n");
    exit(1);
}

$records = $result['records'];
if (!notamMapAirspaceAggregateMergeWfsRecords($records)) {
    aviationwx_log('error', 'faa tfr wfs: merge write failed', [
        'record_count' => count($records),
    ], 'app');
    fwrite(STDERR, "FAA TFR WFS merge write failed\n");
    exit(1);
}

$elapsedMs = (int) round((microtime(true) - $started) * 1000);
aviationwx_log('info', 'faa tfr wfs: merged into airspace store', [
    'record_count' => count($records),
    'elapsed_ms' => $elapsedMs,
], 'app');

fwrite(STDOUT, 'FAA TFR WFS merged ' . count($records) . " records in {$elapsedMs}ms\n");
exit(0);
