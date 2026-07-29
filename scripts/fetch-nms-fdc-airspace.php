<?php
/**
 * National NMS FDC + AIRSPACE bulk ingest worker.
 *
 * Fills US map coverage gaps that per-airport NMS side-channel fetches miss.
 * On failure, marks nms_fdc_bulk unhealthy without clearing the unified store.
 *
 * Usage: php scripts/fetch-nms-fdc-airspace.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/notam/map-aggregate-cache.php';
require_once __DIR__ . '/../lib/notam/airspace/UnifiedNotamFetcher.php';

use AviationWX\Notam\Airspace\Adapter\NmsFdcAirspaceAdapter;
use AviationWX\Notam\Airspace\UnifiedNotamFetcher;

$started = microtime(true);

$result = UnifiedNotamFetcher::fetchSource(NmsFdcAirspaceAdapter::SOURCE_TYPE, []);

if (!$result['ok']) {
    $error = $result['error'] !== '' ? $result['error'] : 'FDC bulk fetch failed';
    notamMapAirspaceAggregateMarkSourceStatus(NmsFdcAirspaceAdapter::SOURCE_TYPE, false, $error);
    aviationwx_log('warning', 'nms fdc airspace: fetch failed; retaining prior aggregate', [
        'error' => $error,
        'http_code' => $result['http_code'],
    ], 'app');
    fwrite(STDERR, "NMS FDC airspace fetch failed: {$error}\n");
    exit(1);
}

$records = $result['records'];
if (!notamMapAirspaceAggregateMergeRecords($records, NmsFdcAirspaceAdapter::SOURCE_TYPE)) {
    aviationwx_log('error', 'nms fdc airspace: merge write failed', [
        'record_count' => count($records),
    ], 'app');
    fwrite(STDERR, "NMS FDC airspace merge write failed\n");
    exit(1);
}

$elapsedMs = (int) round((microtime(true) - $started) * 1000);
aviationwx_log('info', 'nms fdc airspace: merged into airspace store', [
    'record_count' => count($records),
    'elapsed_ms' => $elapsedMs,
], 'app');

fwrite(STDOUT, 'NMS FDC airspace merged ' . count($records) . " drawable records in {$elapsedMs}ms\n");
exit(0);
