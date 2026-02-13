#!/usr/bin/env php
<?php
/**
 * Unified Webcam Worker
 * 
 * CLI entry point for the unified webcam processing worker.
 * Handles both push and pull cameras through a single interface.
 * 
 * Usage:
 *   Worker mode (called by ProcessPool):
 *     php unified-webcam-worker.php --worker <airportId> <camIndex>
 * 
 *   Manual single camera processing:
 *     php unified-webcam-worker.php --single <airportId> <camIndex>
 * 
 *   Process all cameras once:
 *     php unified-webcam-worker.php --all
 * 
 * Exit Codes:
 *   0 = Success (image processed)
 *   1 = Failure (actual error)
 *   2 = Skip (circuit breaker, fresh cache, no work) - not a failure
 *   124 = Timeout (self-termination via SIGALRM)
 * 
 * @package AviationWX
 */

// Only run in CLI mode
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from command line');
}

// Load dependencies
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/sentry.php'; // Initialize Sentry early
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/worker-timeout.php';
require_once __DIR__ . '/../lib/webcam-worker.php';
require_once __DIR__ . '/../lib/variant-health.php';
require_once __DIR__ . '/../lib/metrics.php';

// Verify exiftool is available (required for EXIF handling)
require_once __DIR__ . '/../lib/exif-utils.php';
try {
    requireExiftool();
} catch (RuntimeException $e) {
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
    exit(1);
}

/**
 * Parse command line arguments
 * 
 * @param array $argv Command line arguments
 * @return array{mode: string, airportId: string|null, camIndex: int|null}
 */
function parseArgs(array $argv): array
{
    $mode = 'help';
    $airportId = null;
    $camIndex = null;

    // Skip script name
    array_shift($argv);

    if (empty($argv)) {
        // No arguments provided - this is an error, not a help request
        return ['mode' => 'error', 'airportId' => null, 'camIndex' => null];
    }

    $flag = array_shift($argv);

    switch ($flag) {
        case '--worker':
        case '-w':
            // Worker mode: --worker <airportId> <camIndex>
            if (count($argv) >= 2) {
                $airportId = $argv[0];
                $camIndex = (int)$argv[1];
                $mode = 'worker';
            }
            break;

        case '--single':
        case '-s':
            // Single camera mode: --single <airportId> <camIndex>
            if (count($argv) >= 2) {
                $airportId = $argv[0];
                $camIndex = (int)$argv[1];
                $mode = 'single';
            }
            break;

        case '--all':
        case '-a':
            $mode = 'all';
            break;

        case '--help':
        case '-h':
            $mode = 'help';
            break;

        default:
            // Legacy format: <airportId> <camIndex> (no flag)
            // Support for backwards compatibility with ProcessPool
            if (count($argv) >= 1) {
                $airportId = $flag;
                $camIndex = (int)$argv[0];
                $mode = 'worker';
            }
            break;
    }

    return [
        'mode' => $mode,
        'airportId' => $airportId,
        'camIndex' => $camIndex
    ];
}

/**
 * Print usage help
 */
function printHelp(): void
{
    echo <<<HELP
Unified Webcam Worker - AviationWX

Usage:
  php unified-webcam-worker.php --worker <airportId> <camIndex>
      Process a single camera (worker mode for ProcessPool)

  php unified-webcam-worker.php --single <airportId> <camIndex>
      Process a single camera with verbose output

  php unified-webcam-worker.php --all
      Process all configured cameras once

  php unified-webcam-worker.php --help
      Show this help message

Exit Codes:
  0  - Success (image processed)
  1  - Failure (actual error)
  2  - Skip (circuit breaker, fresh cache, no work)
  124 - Timeout (self-termination)

Examples:
  php unified-webcam-worker.php --worker kspb 0
  php unified-webcam-worker.php --single kczk 1
  php unified-webcam-worker.php --all

HELP;
}

/**
 * Determine worker timeout based on pending file count for push cameras
 * 
 * Extends timeout when many files are pending to allow batch processing.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @return int|null Timeout in seconds, or null for default
 */
function determineWorkerTimeout(string $airportId, int $camIndex): ?int
{
    try {
        $config = loadConfig(false);
        if ($config === null || !isset($config['airports'][$airportId])) {
            return null;
        }

        $camConfig = $config['airports'][$airportId]['webcams'][$camIndex] ?? [];
        
        // Only extend timeout for push cameras
        $isPush = (isset($camConfig['type']) && $camConfig['type'] === 'push')
            || isset($camConfig['push_config']);

        if (!$isPush) {
            return null;
        }

        $username = $camConfig['push_config']['username'] ?? null;
        if (!$username) {
            return null;
        }

        $uploadDir = getWebcamUploadDir($airportId, $username);
        if (!is_dir($uploadDir)) {
            return null;
        }

        // Quick count of image files
        $count = 0;
        $files = glob($uploadDir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE);
        if ($files !== false) {
            $count = count($files);
        }

        if ($count >= PUSH_EXTENDED_TIMEOUT_THRESHOLD) {
            aviationwx_log('debug', 'Extended timeout for push camera backlog', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'pending_files' => $count,
                'timeout' => PUSH_EXTENDED_TIMEOUT_SECONDS
            ], 'app');
            return PUSH_EXTENDED_TIMEOUT_SECONDS;
        }

    } catch (Exception $e) {
        // Ignore errors, use default timeout
    }

    return null;
}

/**
 * Run worker mode (called by ProcessPool)
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @return int Exit code
 */
function runWorkerMode(string $airportId, int $camIndex): int
{
    // Set Sentry service context for this worker
    sentrySetServiceContext('worker-webcam', [
        'airport_id' => $airportId,
        'camera_index' => $camIndex,
    ]);
    
    // Start performance tracing
    $transaction = sentryStartTransaction('worker.webcam', "fetch_webcam_{$airportId}_{$camIndex}", [
        'airport_id' => $airportId,
        'camera_index' => (string)$camIndex,
    ]);
    
    // Determine appropriate timeout (extended for push camera backlogs)
    $timeout = determineWorkerTimeout($airportId, $camIndex);
    initWorkerTimeout($timeout, "webcam_{$airportId}_{$camIndex}");

    // Validate airport ID
    if (!WebcamWorkerFactory::validateAirportId($airportId)) {
        aviationwx_log('error', 'unified-webcam-worker: invalid airport ID', [
            'airport' => $airportId
        ], 'app');
        sentryFinishTransaction($transaction);
        return WorkerResult::FAILURE;
    }

    try {
        $worker = WebcamWorkerFactory::create($airportId, $camIndex);
        $result = $worker->run();

        // Flush counters before exit - worker runs in isolated process
        variant_health_flush();
        metrics_flush();

        sentryFinishTransaction($transaction);
        return $result->exitCode;

    } catch (InvalidArgumentException $e) {
        aviationwx_log('error', 'unified-webcam-worker: configuration error', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'error' => $e->getMessage()
        ], 'app');
        sentryFinishTransaction($transaction);
        return WorkerResult::FAILURE;

    } catch (Exception $e) {
        aviationwx_log('error', 'unified-webcam-worker: unexpected error', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 'app');
        sentryFinishTransaction($transaction);
        return WorkerResult::FAILURE;
    }
}

/**
 * Run single camera mode with verbose output
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @return int Exit code
 */
function runSingleMode(string $airportId, int $camIndex): int
{
    echo "Processing camera: {$airportId}/{$camIndex}\n";
    echo str_repeat('-', 40) . "\n";

    $startTime = microtime(true);

    try {
        $worker = WebcamWorkerFactory::create($airportId, $camIndex);
        echo "Source type: " . $worker->getSourceType() . "\n";
        echo "Push camera: " . ($worker->isPushCamera() ? 'yes' : 'no') . "\n";
        echo "\n";

        $result = $worker->run();

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);

        if ($result->isSuccess()) {
            echo "✓ SUCCESS\n";
            echo "  Timestamp: " . ($result->metadata['timestamp'] ?? 'unknown') . "\n";
            echo "  Variants: " . ($result->metadata['variants'] ?? 0) . "\n";
            echo "  Formats: " . implode(', ', $result->metadata['formats'] ?? []) . "\n";
        } elseif ($result->isSkip()) {
            echo "⊘ SKIPPED: " . ($result->reason ?? 'unknown') . "\n";
        } else {
            echo "✗ FAILED: " . ($result->reason ?? 'unknown') . "\n";
        }

        echo "  Duration: {$elapsed}ms\n";
        echo "\n";

        // Flush counters before exit
        variant_health_flush();
        metrics_flush();

        return $result->exitCode;

    } catch (Exception $e) {
        echo "✗ ERROR: " . $e->getMessage() . "\n";
        return WorkerResult::FAILURE;
    }
}

/**
 * Run all cameras mode
 * 
 * @return int Exit code (0 if any succeeded, 1 if all failed)
 */
function runAllMode(): int
{
    $config = loadConfig(false);
    if ($config === null) {
        fwrite(STDERR, "Error: Could not load configuration\n");
        return WorkerResult::FAILURE;
    }

    $airports = $config['airports'] ?? [];
    if (empty($airports)) {
        fwrite(STDERR, "Error: No airports configured\n");
        return WorkerResult::FAILURE;
    }

    $startTime = microtime(true);
    $totalCameras = 0;
    $processed = 0;
    $skipped = 0;
    $failed = 0;

    echo "Processing all webcams...\n";
    echo str_repeat('=', 50) . "\n\n";

    foreach ($airports as $airportId => $airport) {
        // Skip disabled airports
        if (!isAirportEnabled($airport)) {
            continue;
        }

        $webcams = $airport['webcams'] ?? [];
        if (empty($webcams)) {
            continue;
        }

        $airportName = $airport['name'] ?? $airportId;
        echo "Airport: {$airportName} ({$airportId})\n";
        echo str_repeat('-', 40) . "\n";

        foreach ($webcams as $camIndex => $cam) {
            $totalCameras++;
            $camName = $cam['name'] ?? "Camera {$camIndex}";
            echo "  [{$camIndex}] {$camName}: ";

            try {
                $worker = WebcamWorkerFactory::create($airportId, $camIndex);
                $result = $worker->run();

                if ($result->isSuccess()) {
                    echo "✓ SUCCESS\n";
                    $processed++;
                } elseif ($result->isSkip()) {
                    echo "⊘ SKIP ({$result->reason})\n";
                    $skipped++;
                } else {
                    echo "✗ FAIL ({$result->reason})\n";
                    $failed++;
                }

            } catch (Exception $e) {
                echo "✗ ERROR ({$e->getMessage()})\n";
                $failed++;
            }
        }

        echo "\n";
    }

    // Flush counters before exit
    variant_health_flush();
    metrics_flush();

    $elapsed = round((microtime(true) - $startTime) * 1000, 2);

    echo str_repeat('=', 50) . "\n";
    echo "Summary:\n";
    echo "  Total cameras: {$totalCameras}\n";
    echo "  Processed: {$processed}\n";
    echo "  Skipped: {$skipped}\n";
    echo "  Failed: {$failed}\n";
    echo "  Duration: {$elapsed}ms\n";

    // Return success if any succeeded or all skipped (no failures)
    return ($failed === $totalCameras) ? WorkerResult::FAILURE : WorkerResult::SUCCESS;
}

// =============================================================================
// MAIN ENTRY POINT
// =============================================================================

$args = parseArgs($argv);

switch ($args['mode']) {
    case 'worker':
        exit(runWorkerMode($args['airportId'], $args['camIndex']));

    case 'single':
        exit(runSingleMode($args['airportId'], $args['camIndex']));

    case 'all':
        exit(runAllMode());

    case 'help':
        // Explicit --help flag requested
        printHelp();
        exit(0);

    default:
        // No valid mode or missing arguments - exit with error
        fwrite(STDERR, "Error: This script must be run in worker mode via ProcessPool or with explicit flags.\n");
        fwrite(STDERR, "Run with --help for usage information.\n");
        exit(1);
}
