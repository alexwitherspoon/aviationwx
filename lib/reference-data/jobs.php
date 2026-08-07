<?php
/**
 * Airport reference-data ProcessPool job definitions.
 *
 * Size-1 pools per job; scheduler owns lifecycle (timeout, drain, SIGTERM).
 */

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';

/** @var string Drain / registry class for live observation pools */
const SCHEDULER_POOL_CLASS_LIVE = 'live';

/** @var string Drain / registry class for airport reference catalogs */
const SCHEDULER_POOL_CLASS_REFERENCE = 'reference';

/**
 * Canonical reference-data jobs (pool name => script + timeout).
 *
 * @return array<string, array{script: string, timeout: int}>
 */
function referenceDataJobs(): array
{
    return [
        'ourairports_probe' => [
            'script' => 'probe-ourairports.php',
            'timeout' => (int) OURAIRPORTS_PROBE_WORKER_TIMEOUT,
        ],
        'ourairports_bulk' => [
            'script' => 'fetch-ourairports-bulk.php',
            'timeout' => (int) OURAIRPORTS_BULK_WORKER_TIMEOUT,
        ],
        'runways_merge' => [
            'script' => 'fetch-runways.php',
            'timeout' => (int) RUNWAYS_MERGE_WORKER_TIMEOUT,
        ],
        'nasr_apt' => [
            'script' => 'fetch-nasr-apt.php',
            'timeout' => (int) NASR_APT_WORKER_TIMEOUT,
        ],
        'nasr_frq' => [
            'script' => 'fetch-nasr-frq.php',
            'timeout' => (int) NASR_FRQ_WORKER_TIMEOUT,
        ],
        'country_resolution' => [
            'script' => 'refresh-airport-country-resolution.php',
            'timeout' => (int) COUNTRY_RESOLUTION_WORKER_TIMEOUT,
        ],
    ];
}

/**
 * @return list<string>
 */
function referenceDataJobNames(): array
{
    return array_keys(referenceDataJobs());
}
