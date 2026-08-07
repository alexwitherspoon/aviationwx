<?php
/**
 * Dependency-aware enqueue gates for airport reference ProcessPools.
 *
 * Policy lives here; scripts keep file locks as crash/dedup insurance.
 */

declare(strict_types=1);

require_once __DIR__ . '/../nasr/workers.php';
require_once __DIR__ . '/../ourairports/refresh.php';
require_once __DIR__ . '/../airport-country-resolution-merge.php';
require_once __DIR__ . '/jobs.php';

/**
 * Whether a size-1 reference pool currently has an active worker.
 *
 * @param array<string, object|null> $pools Map of job name => ProcessPool
 */
function referenceDataPoolIsActive(array $pools, string $jobName): bool
{
    $pool = $pools[$jobName] ?? null;
    if ($pool === null || !method_exists($pool, 'getActiveCount')) {
        return false;
    }
    $count = $pool->getActiveCount();

    return (is_int($count) || is_float($count)) && (int) $count > 0;
}

/**
 * Whether the scheduler should enqueue a reference job on this tick.
 *
 * @param array<string, object|null> $pools
 * @param array<string, mixed> $state Scheduler tick state (last-attempt timestamps, config, etc.)
 */
function referenceDataShouldEnqueue(string $jobName, int $now, array $pools, array $state): bool
{
    switch ($jobName) {
        case 'ourairports_probe':
            if (referenceDataPoolIsActive($pools, 'ourairports_probe')
                || referenceDataPoolIsActive($pools, 'ourairports_bulk')
            ) {
                return false;
            }
            $last = (int) ($state['last_ourairports_probe'] ?? 0);
            if (($now - $last) < OURAIRPORTS_PROBE_INTERVAL) {
                return false;
            }

            return ourAirportsProbeWorkerShouldRun();

        case 'ourairports_bulk':
            if (referenceDataPoolIsActive($pools, 'ourairports_bulk')
                || referenceDataPoolIsActive($pools, 'ourairports_probe')
                || referenceDataPoolIsActive($pools, 'runways_merge')
            ) {
                return false;
            }
            $last = (int) ($state['last_ourairports_bulk'] ?? 0);
            if (($now - $last) < OURAIRPORTS_BULK_FETCH_CHECK_INTERVAL) {
                return false;
            }

            return ourAirportsBulkWorkerShouldRun();

        case 'runways_merge':
            if (referenceDataPoolIsActive($pools, 'runways_merge')
                || referenceDataPoolIsActive($pools, 'ourairports_bulk')
            ) {
                return false;
            }
            $startupDone = !empty($state['runways_startup_done']);
            if (!$startupDone) {
                return runwaysMergeWorkerShouldRun();
            }
            $last = (int) ($state['last_runways'] ?? 0);
            if (($now - $last) < OURAIRPORTS_BULK_FETCH_CHECK_INTERVAL) {
                return false;
            }

            return runwaysMergeWorkerShouldRun();

        case 'nasr_apt':
            if (referenceDataPoolIsActive($pools, 'nasr_apt')
                || referenceDataPoolIsActive($pools, 'nasr_frq')
            ) {
                return false;
            }

            return nasrAptSchedulerShouldEnqueue($now, (int) ($state['last_nasr_apt'] ?? 0));

        case 'nasr_frq':
            // APT cache must exist; never overlap APT (pool or file lock).
            if (!nasrAptCacheDataPresent()
                || referenceDataPoolIsActive($pools, 'nasr_apt')
                || referenceDataPoolIsActive($pools, 'nasr_frq')
                || nasrAptFetchInProgress()
            ) {
                return false;
            }

            return nasrFrqSchedulerShouldEnqueue($now, (int) ($state['last_nasr_frq'] ?? 0));

        case 'country_resolution':
            if (referenceDataPoolIsActive($pools, 'country_resolution')) {
                return false;
            }
            $startupEval = !empty($state['country_startup_eval']);
            $lastCheck = (int) ($state['last_country_check'] ?? 0);
            $due = !$startupEval
                || (($now - $lastCheck) >= COUNTRY_RESOLUTION_SCHEDULER_CHECK_INTERVAL);
            if (!$due) {
                return false;
            }
            $cfgPath = $state['config_path'] ?? null;
            $configSha = $state['config_sha'] ?? null;
            if (!is_string($cfgPath) || $cfgPath === '' || !is_readable($cfgPath)
                || !is_string($configSha) || $configSha === ''
            ) {
                return false;
            }

            return countryResolutionAggregateShouldRefresh($cfgPath, $configSha);

        default:
            return false;
    }
}

/**
 * Enqueue due reference jobs onto size-1 ProcessPools.
 *
 * @param array<string, object|null> $pools
 * @param array<string, mixed> $state Mutated: last-attempt and startup flags
 * @return list<string> Job names started this tick
 */
function referenceDataEnqueueDueJobs(int $now, array $pools, array &$state): array
{
    $started = [];

    foreach (referenceDataJobNames() as $jobName) {
        if (!referenceDataShouldEnqueue($jobName, $now, $pools, $state)) {
            // Startup runways still advances the startup flag when skipped.
            if ($jobName === 'runways_merge' && empty($state['runways_startup_done'])) {
                $state['runways_startup_done'] = true;
                $state['last_runways'] = $now;
            }
            if ($jobName === 'country_resolution') {
                $startupEval = !empty($state['country_startup_eval']);
                $lastCheck = (int) ($state['last_country_check'] ?? 0);
                $due = !$startupEval
                    || (($now - $lastCheck) >= COUNTRY_RESOLUTION_SCHEDULER_CHECK_INTERVAL);
                if ($due) {
                    $state['last_country_check'] = $now;
                    $state['country_startup_eval'] = true;
                }
            }
            continue;
        }

        $pool = $pools[$jobName] ?? null;
        if ($pool === null || !method_exists($pool, 'addJob')) {
            continue;
        }

        if (!$pool->addJob([])) {
            continue;
        }

        $started[] = $jobName;
        switch ($jobName) {
            case 'ourairports_probe':
                $state['last_ourairports_probe'] = $now;
                break;
            case 'ourairports_bulk':
                $state['last_ourairports_bulk'] = $now;
                break;
            case 'runways_merge':
                $state['runways_startup_done'] = true;
                $state['last_runways'] = $now;
                break;
            case 'nasr_apt':
                $state['last_nasr_apt'] = $now;
                break;
            case 'nasr_frq':
                $state['last_nasr_frq'] = $now;
                break;
            case 'country_resolution':
                $state['last_country_check'] = $now;
                $state['country_startup_eval'] = true;
                break;
        }
    }

    return $started;
}
