#!/usr/bin/env php
<?php
/**
 * Repair map-airspace.json when workers wrote logic-vN but deploy SHA is available.
 *
 * Invoked from docker-entrypoint.sh after persisting cache/.deploy-git-sha.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/notam/map-aggregate-cache.php';

if (!notamMapAirspaceAggregateRepairStaleLogicBuildToken()) {
    exit(0);
}

fwrite(
    STDOUT,
    'Repaired map-airspace build token to ' . notamTfrMapLayerCurrentBuildToken() . PHP_EOL
);
