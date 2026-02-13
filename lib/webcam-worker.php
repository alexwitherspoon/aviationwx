<?php
/**
 * Unified Webcam Worker
 * 
 * Single unified worker class for all webcam types (push and pull).
 * Orchestrates acquisition strategy and processing pipeline.
 * 
 * Design:
 * - Strategy pattern for acquisition (pull vs push sources)
 * - Shared processing pipeline with consistent validation chain
 * - Hybrid lock strategy (ProcessPool primary, file locks for crash resilience)
 * 
 * @package AviationWX
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/webcam-acquisition.php';
require_once __DIR__ . '/webcam-pipeline.php';
require_once __DIR__ . '/exif-utils.php';

/**
 * Worker result status codes
 */
class WorkerResult
{
    /** Successfully processed an image */
    public const SUCCESS = 0;
    
    /** Processing failed (actual error) */
    public const FAILURE = 1;
    
    /** Skipped (circuit breaker, fresh cache, no work) - not a failure */
    public const SKIP = 2;
    
    /** @var int Exit code */
    public int $exitCode;
    
    /** @var string|null Error/skip reason */
    public ?string $reason;
    
    /** @var array Additional metadata */
    public array $metadata;

    private function __construct(int $exitCode, ?string $reason = null, array $metadata = [])
    {
        $this->exitCode = $exitCode;
        $this->reason = $reason;
        $this->metadata = $metadata;
    }

    public static function success(array $metadata = []): self
    {
        return new self(self::SUCCESS, null, $metadata);
    }

    public static function failure(string $reason, array $metadata = []): self
    {
        return new self(self::FAILURE, $reason, $metadata);
    }

    public static function skip(string $reason, array $metadata = []): self
    {
        return new self(self::SKIP, $reason, $metadata);
    }

    public function isSuccess(): bool
    {
        return $this->exitCode === self::SUCCESS;
    }

    public function isSkip(): bool
    {
        return $this->exitCode === self::SKIP;
    }
}

/**
 * Unified webcam worker
 * 
 * Handles both push and pull cameras through strategy pattern.
 * Uses hybrid locking (ProcessPool + file locks for crash safety).
 */
class WebcamWorker
{
    private string $airportId;
    private int $camIndex;
    private array $camConfig;
    private array $airportConfig;
    private AcquisitionStrategy $strategy;
    private ProcessingPipeline $pipeline;
    
    /** @var resource|null Lock file handle */
    private $lockFile = null;
    
    /** @var string|null Lock file path */
    private ?string $lockPath = null;

    public function __construct(string $airportId, int $camIndex)
    {
        $this->airportId = $airportId;
        $this->camIndex = $camIndex;
        
        // Load configuration
        $config = loadConfig(false);
        if ($config === null || !isset($config['airports'][$airportId])) {
            throw new InvalidArgumentException("Airport not found: {$airportId}");
        }
        
        $this->airportConfig = $config['airports'][$airportId];
        
        if (!isset($this->airportConfig['webcams'][$camIndex])) {
            throw new InvalidArgumentException("Camera not found: {$airportId}/{$camIndex}");
        }
        
        $this->camConfig = $this->airportConfig['webcams'][$camIndex];
        
        // Create acquisition strategy
        $this->strategy = AcquisitionStrategyFactory::create(
            $airportId,
            $camIndex,
            $this->camConfig,
            $this->airportConfig
        );
        
        // Create processing pipeline
        $this->pipeline = ProcessingPipelineFactory::create(
            $airportId,
            $camIndex,
            $this->camConfig,
            $this->airportConfig
        );
    }

    /**
     * Run the worker
     * 
     * Acquires image(s), processes through pipeline, returns result.
     * Push cameras process multiple files per run for efficient backlog handling.
     * 
     * @return WorkerResult Processing result with exit code
     */
    public function run(): WorkerResult
    {
        $startTime = microtime(true);
        
        // Register shutdown handler for lock cleanup
        register_shutdown_function([$this, 'releaseLock']);
        
        try {
            // Acquire lock (hybrid strategy)
            if (!$this->acquireLock()) {
                return WorkerResult::skip('lock_unavailable', [
                    'airport' => $this->airportId,
                    'cam' => $this->camIndex
                ]);
            }

            // Push cameras use batch processing for efficient backlog handling
            if ($this->isPushCamera()) {
                return $this->runPushCamera($startTime);
            }

            // Pull cameras: single acquisition per run
            return $this->runPullCamera($startTime);

        } catch (Exception $e) {
            aviationwx_log('error', 'Webcam worker exception', [
                'airport' => $this->airportId,
                'cam' => $this->camIndex,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'app');

            return WorkerResult::failure('exception', [
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Run pull camera processing (single image per run)
     * 
     * @param float $startTime Processing start time
     * @return WorkerResult
     */
    private function runPullCamera(float $startTime): WorkerResult
    {
        // Check if should skip (circuit breaker, not due, etc.)
        $skipCheck = $this->strategy->shouldSkip();
        if ($skipCheck['skip']) {
            aviationwx_log('info', 'Webcam worker skipped', [
                'airport' => $this->airportId,
                'cam' => $this->camIndex,
                'reason' => $skipCheck['reason'],
                'details' => $skipCheck['details'] ?? []
            ], 'app');
            
            return WorkerResult::skip($skipCheck['reason'], $skipCheck['details'] ?? []);
        }

        // Acquire image
        $acquisitionResult = $this->strategy->acquire();
        
        if (!$acquisitionResult->success) {
            if ($acquisitionResult->isSkip()) {
                aviationwx_log('info', 'Webcam acquisition skipped', [
                    'airport' => $this->airportId,
                    'cam' => $this->camIndex,
                    'reason' => $acquisitionResult->getSkipReason(),
                    'source' => $acquisitionResult->sourceType
                ], 'app');
                
                return WorkerResult::skip(
                    $acquisitionResult->getSkipReason() ?? 'unknown',
                    ['source' => $acquisitionResult->sourceType]
                );
            }
            
            // Determine log level based on failure reason
            $logLevel = 'error';
            $reason = $acquisitionResult->errorReason;
            
            // Expected/configuration issues - log as info
            $expectedReasons = [
                'no_username_configured',  // Push camera not set up
                'ffmpeg_not_available',    // RTSP not supported on this system
                'missing_base_url'         // Federation not configured
            ];
            
            // Transient network/camera issues - log as warning
            $transientReasons = [
                'http_error',
                'fetch_failed',
                'rtsp_failed',
                'timeout',
                'error_frame',           // Camera showing error screen
                'invalid_format',        // Temporary camera issue
                'no_jpeg_frame'          // MJPEG stream issue
            ];
            
            if (in_array($reason, $expectedReasons, true)) {
                $logLevel = 'info';
            } elseif (in_array($reason, $transientReasons, true)) {
                $logLevel = 'warning';
            }
            
            aviationwx_log($logLevel, 'Webcam acquisition failed', [
                'airport' => $this->airportId,
                'cam' => $this->camIndex,
                'reason' => $reason,
                'source' => $acquisitionResult->sourceType,
                'metadata' => $acquisitionResult->metadata
            ], 'app');
            
            return WorkerResult::failure(
                $acquisitionResult->errorReason ?? 'acquisition_failed',
                [
                    'source' => $acquisitionResult->sourceType,
                    'metadata' => $acquisitionResult->metadata
                ]
            );
        }

        // Process through pipeline
        $pipelineResult = $this->pipeline->process(
            $acquisitionResult->imagePath,
            $acquisitionResult->timestamp,
            $acquisitionResult->sourceType
        );

        if (!$pipelineResult->success) {
            aviationwx_log('error', 'Webcam pipeline failed', [
                'airport' => $this->airportId,
                'cam' => $this->camIndex,
                'reason' => $pipelineResult->errorReason,
                'source' => $acquisitionResult->sourceType
            ], 'app');
            
            // Cleanup staging file on pipeline failure
            if ($acquisitionResult->imagePath && file_exists($acquisitionResult->imagePath)) {
                @unlink($acquisitionResult->imagePath);
            }
            
            return WorkerResult::failure(
                $pipelineResult->errorReason ?? 'pipeline_failed',
                $pipelineResult->metadata
            );
        }

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);

        aviationwx_log('info', 'Webcam worker completed', [
            'airport' => $this->airportId,
            'cam' => $this->camIndex,
            'source' => $acquisitionResult->sourceType,
            'timestamp' => $pipelineResult->timestamp,
            'variants' => $pipelineResult->getVariantCount(),
            'formats' => $pipelineResult->getPromotedFormats(),
            'duration_ms' => $elapsed
        ], 'app');

        return WorkerResult::success([
            'source' => $acquisitionResult->sourceType,
            'timestamp' => $pipelineResult->timestamp,
            'variants' => $pipelineResult->getVariantCount(),
            'formats' => $pipelineResult->getPromotedFormats(),
            'duration_ms' => $elapsed,
            'original_path' => $pipelineResult->originalPath
        ]);
    }

    /**
     * Run push camera processing (multiple images per run)
     * 
     * Processes files in optimal order: newest first (pilot safety), then
     * oldest-to-newest (clear backlog before files age out).
     * 
     * @param float $startTime Processing start time
     * @return WorkerResult
     */
    private function runPushCamera(float $startTime): WorkerResult
    {
        // Check circuit breaker only (skip timing check for batch processing)
        $skipCheck = $this->strategy->shouldSkip();
        if ($skipCheck['skip'] && $skipCheck['reason'] === 'circuit_breaker') {
            aviationwx_log('info', 'Push camera skipped due to circuit breaker', [
                'airport' => $this->airportId,
                'cam' => $this->camIndex,
                'details' => $skipCheck['details'] ?? []
            ], 'app');
            return WorkerResult::skip($skipCheck['reason'], $skipCheck['details'] ?? []);
        }

        // Get files in optimal order: newest first, then oldest-to-newest
        /** @var PushAcquisitionStrategy $strategy */
        $strategy = $this->strategy;
        $fileData = $strategy->getOrderedFiles(PUSH_BATCH_LIMIT);
        $files = $fileData['files'];
        $totalPending = $fileData['total_pending'];

        if (empty($files)) {
            return WorkerResult::skip('no_new_files', ['total_pending' => 0]);
        }

        aviationwx_log('info', 'Processing push camera files', [
            'airport' => $this->airportId,
            'cam' => $this->camIndex,
            'files_to_process' => count($files),
            'total_pending' => $totalPending
        ], 'app');

        $processed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($files as $filePath) {
            // Acquire and validate file
            $acquisitionResult = $strategy->acquireFile($filePath);

            if (!$acquisitionResult->success) {
                if ($acquisitionResult->isSkip()) {
                    $skipped++;
                    continue;
                }
                $failed++;
                aviationwx_log('warning', 'Push file acquisition failed', [
                    'airport' => $this->airportId,
                    'cam' => $this->camIndex,
                    'file' => basename($filePath),
                    'reason' => $acquisitionResult->errorReason
                ], 'app');
                continue;
            }

            // Process through pipeline
            $pipelineResult = $this->pipeline->process(
                $acquisitionResult->imagePath,
                $acquisitionResult->timestamp,
                $acquisitionResult->sourceType
            );

            if (!$pipelineResult->success) {
                $failed++;
                if ($acquisitionResult->imagePath && file_exists($acquisitionResult->imagePath)) {
                    @unlink($acquisitionResult->imagePath);
                }
                aviationwx_log('warning', 'Push file pipeline failed', [
                    'airport' => $this->airportId,
                    'cam' => $this->camIndex,
                    'file' => basename($filePath),
                    'reason' => $pipelineResult->errorReason
                ], 'app');
                continue;
            }

            $processed++;
        }

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);

        // Update last processed time for rate limiting between worker runs
        if ($processed > 0) {
            $strategy->updateLastProcessedTime();
        }

        aviationwx_log('info', 'Push camera processing complete', [
            'airport' => $this->airportId,
            'cam' => $this->camIndex,
            'processed' => $processed,
            'failed' => $failed,
            'skipped' => $skipped,
            'remaining' => max(0, $totalPending - count($files)),
            'duration_ms' => $elapsed
        ], 'app');

        if ($processed === 0 && $failed === 0) {
            return WorkerResult::skip('no_valid_files', ['skipped' => $skipped]);
        }

        if ($processed === 0) {
            return WorkerResult::failure('all_failed', ['failed' => $failed]);
        }

        return WorkerResult::success([
            'source' => 'push',
            'processed' => $processed,
            'failed' => $failed,
            'skipped' => $skipped,
            'duration_ms' => $elapsed
        ]);
    }

    /**
     * Acquire file lock (hybrid strategy safety net)
     * 
     * Uses flock() for cleaner implementation without create/delete overhead.
     * Lock files provide crash resilience when ProcessPool tracking fails.
     * 
     * @return bool True if lock acquired, false if another worker has lock
     */
    private function acquireLock(): bool
    {
        $this->lockPath = $this->getLockPath();
        
        // Ensure lock directory exists
        $lockDir = dirname($this->lockPath);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }
        
        // Check for stale lock
        if (file_exists($this->lockPath)) {
            $lockAge = time() - filemtime($this->lockPath);
            $staleTimeout = getWorkerTimeout() + 30; // Worker timeout + 30s buffer
            
            if ($lockAge > $staleTimeout) {
                aviationwx_log('warning', 'Removing stale lock file', [
                    'airport' => $this->airportId,
                    'cam' => $this->camIndex,
                    'age' => $lockAge,
                    'timeout' => $staleTimeout
                ], 'app');
                @unlink($this->lockPath);
            }
        }

        // Open lock file (create if not exists)
        $this->lockFile = @fopen($this->lockPath, 'c');
        if (!$this->lockFile) {
            return false;
        }

        // Try to acquire exclusive lock (non-blocking)
        if (!@flock($this->lockFile, LOCK_EX | LOCK_NB)) {
            @fclose($this->lockFile);
            $this->lockFile = null;
            return false;
        }

        // Write lock info
        @ftruncate($this->lockFile, 0);
        @fwrite($this->lockFile, json_encode([
            'pid' => getmypid(),
            'airport' => $this->airportId,
            'cam' => $this->camIndex,
            'started' => time()
        ]));
        @fflush($this->lockFile);

        return true;
    }

    /**
     * Release file lock
     * 
     * Called by shutdown handler and explicitly on completion.
     */
    public function releaseLock(): void
    {
        if ($this->lockFile !== null) {
            @flock($this->lockFile, LOCK_UN);
            @fclose($this->lockFile);
            $this->lockFile = null;
        }

        // Remove lock file (optional, lock is released on close anyway)
        if ($this->lockPath !== null && file_exists($this->lockPath)) {
            @unlink($this->lockPath);
        }
    }

    /**
     * Get lock file path for this camera
     * 
     * @return string Lock file path
     */
    private function getLockPath(): string
    {
        return TEMP_DIR . '/webcam_lock_' . $this->airportId . '_' . $this->camIndex . '.lock';
    }

    /**
     * Get airport ID
     * 
     * @return string Airport identifier
     */
    public function getAirportId(): string
    {
        return $this->airportId;
    }

    /**
     * Get camera index
     * 
     * @return int Camera index
     */
    public function getCamIndex(): int
    {
        return $this->camIndex;
    }

    /**
     * Get source type of the camera
     * 
     * @return string Source type (e.g., 'mjpeg', 'push', 'rtsp')
     */
    public function getSourceType(): string
    {
        return $this->strategy->getSourceType();
    }

    /**
     * Check if this is a push camera
     * 
     * @return bool True if push camera
     */
    public function isPushCamera(): bool
    {
        return (isset($this->camConfig['type']) && $this->camConfig['type'] === 'push')
            || isset($this->camConfig['push_config']);
    }
}

/**
 * Factory for creating webcam workers
 */
class WebcamWorkerFactory
{
    /**
     * Create a worker for the specified camera
     * 
     * @param string $airportId Airport identifier
     * @param int $camIndex Camera index
     * @return WebcamWorker
     * @throws InvalidArgumentException If airport/camera not found
     */
    public static function create(string $airportId, int $camIndex): WebcamWorker
    {
        return new WebcamWorker($airportId, $camIndex);
    }

    /**
     * Validate airport ID format
     * 
     * Delegates to the global validateAirportId() function to ensure
     * consistent validation rules across the application.
     * 
     * @param string $airportId Airport ID to validate
     * @return bool True if valid
     */
    public static function validateAirportId(string $airportId): bool
    {
        // Use global validation function for consistency
        // Supports 3-50 character IDs with alphanumeric + hyphens
        return validateAirportId($airportId);
    }
}
