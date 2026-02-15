<?php
/**
 * Webcam Processing Pipeline
 * 
 * Unified processing pipeline for webcam images from any source (pull or push).
 * Handles validation, variant generation, promotion, and cleanup.
 * 
 * Design priorities:
 * - Single image load through pipeline (efficiency)
 * - Fail-closed on validation errors (safety)
 * - Atomic file operations (reliability)
 * - Comprehensive health tracking (observability)
 * 
 * @package AviationWX
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/exif-utils.php';
require_once __DIR__ . '/webcam-error-detector.php';
require_once __DIR__ . '/webcam-format-generation.php';
require_once __DIR__ . '/webcam-rejection-logger.php';
require_once __DIR__ . '/variant-health.php';

/**
 * Result of pipeline processing
 */
class PipelineResult
{
    /** @var bool Whether processing succeeded */
    public bool $success;

    /** @var string|null Path to original image (if successful) */
    public ?string $originalPath;

    /** @var array Promoted variants [height => [format => path]] */
    public array $variants;

    /** @var int|null Image timestamp */
    public ?int $timestamp;

    /** @var string|null Error reason if failed */
    public ?string $errorReason;

    /** @var array Additional metadata */
    public array $metadata;

    private function __construct()
    {
        $this->variants = [];
        $this->metadata = [];
    }

    /**
     * Create a successful pipeline result
     */
    public static function success(
        string $originalPath,
        array $variants,
        int $timestamp,
        array $metadata = []
    ): self {
        $result = new self();
        $result->success = true;
        $result->originalPath = $originalPath;
        $result->variants = $variants;
        $result->timestamp = $timestamp;
        $result->errorReason = null;
        $result->metadata = $metadata;
        return $result;
    }

    /**
     * Create a failed pipeline result
     */
    public static function failure(string $errorReason, array $metadata = []): self
    {
        $result = new self();
        $result->success = false;
        $result->originalPath = null;
        $result->variants = [];
        $result->timestamp = null;
        $result->errorReason = $errorReason;
        $result->metadata = $metadata;
        return $result;
    }

    /**
     * Get list of promoted format types
     */
    public function getPromotedFormats(): array
    {
        $formats = [];
        foreach ($this->variants as $height => $heightFormats) {
            $formats = array_merge($formats, array_keys($heightFormats));
        }
        return array_unique($formats);
    }

    /**
     * Get count of total variants generated
     */
    public function getVariantCount(): int
    {
        $count = 0;
        foreach ($this->variants as $heightFormats) {
            $count += count($heightFormats);
        }
        return $count;
    }
}

/**
 * Processing pipeline for webcam images
 * 
 * Steps:
 * 1. Validate image format/dimensions
 * 2. Error frame detection (uniform color, pixelation, etc.)
 * 3. EXIF validation/normalization
 * 4. Variant generation (staging files)
 * 5. Atomic promotion with symlink updates
 * 6. History cleanup
 * 7. Health metrics tracking
 */
class ProcessingPipeline
{
    private string $airportId;
    private int $camIndex;
    private array $airportConfig;
    private array $camConfig;
    
    /** @var resource|null GD image resource (single load optimization) */
    private $gdImage = null;
    
    /** @var int|null Image width */
    private ?int $imageWidth = null;
    
    /** @var int|null Image height */
    private ?int $imageHeight = null;

    public function __construct(
        string $airportId,
        int $camIndex,
        array $camConfig,
        array $airportConfig
    ) {
        $this->airportId = $airportId;
        $this->camIndex = $camIndex;
        $this->camConfig = $camConfig;
        $this->airportConfig = $airportConfig;
    }

    /**
     * Process an acquired image through the pipeline
     * 
     * @param string $imagePath Path to staging image file
     * @param int $timestamp Capture timestamp
     * @param string $sourceType Source type for logging
     * @return PipelineResult Processing result
     */
    public function process(string $imagePath, int $timestamp, string $sourceType): PipelineResult
    {
        $startTime = microtime(true);

        // Cleanup orphaned staging files from previous runs
        $this->cleanupOrphanedStagingFiles();

        try {
            // Step 1: Load and validate image
            $loadResult = $this->loadImage($imagePath);
            if (!$loadResult['success']) {
                $this->recordRejection($imagePath, $loadResult['reason'], ['source' => $sourceType]);
                return PipelineResult::failure($loadResult['reason'], ['source' => $sourceType]);
            }

            // Step 2: Error frame detection
            $errorCheck = $this->checkErrorFrame($imagePath);
            if (!$errorCheck['passed']) {
                $this->recordRejection($imagePath, $errorCheck['reason'], [
                    'source' => $sourceType,
                    'confidence' => $errorCheck['confidence'] ?? 0,
                    'details' => $errorCheck['details'] ?? []
                ]);
                return PipelineResult::failure($errorCheck['reason'], [
                    'confidence' => $errorCheck['confidence'] ?? 0,
                    'details' => $errorCheck['details'] ?? []
                ]);
            }

            // Step 3: EXIF validation
            $exifResult = $this->validateExif($imagePath, $timestamp);
            if (!$exifResult['valid']) {
                $this->recordRejection($imagePath, 'exif_' . $exifResult['reason'], [
                    'source' => $sourceType,
                    'timestamp' => $timestamp
                ]);
                return PipelineResult::failure('exif_' . $exifResult['reason'], [
                    'reason' => $exifResult['reason']
                ]);
            }

            // Use validated timestamp (may have been adjusted)
            $validatedTimestamp = $exifResult['timestamp'] ?? $timestamp;

            // Step 4: Generate variants
            $variantResult = $this->generateVariants($imagePath, $validatedTimestamp);
            if ($variantResult['original'] === null) {
                return PipelineResult::failure('variant_generation_failed', [
                    'source' => $sourceType
                ]);
            }

            // Step 5: Store variant manifest
            $this->storeVariantManifest($validatedTimestamp, $variantResult);

            // Step 6: History cleanup
            $this->cleanupHistory();

            // Step 7: Track health metrics
            $this->trackHealthMetrics($variantResult);

            $elapsed = round((microtime(true) - $startTime) * 1000, 2);

            aviationwx_log('info', 'Pipeline processing complete', [
                'airport' => $this->airportId,
                'cam' => $this->camIndex,
                'source' => $sourceType,
                'timestamp' => $validatedTimestamp,
                'variants' => count($variantResult['variants']),
                'duration_ms' => $elapsed
            ], 'app');

            return PipelineResult::success(
                $variantResult['original'],
                $variantResult['variants'],
                $validatedTimestamp,
                [
                    'source' => $sourceType,
                    'width' => $variantResult['metadata']['width'] ?? $this->imageWidth,
                    'height' => $variantResult['metadata']['height'] ?? $this->imageHeight,
                    'duration_ms' => $elapsed
                ]
            );

        } finally {
            // Cleanup GD resource
            $this->destroyImage();
        }
    }

    /**
     * Load image and validate basic properties
     */
    private function loadImage(string $imagePath): array
    {
        if (!file_exists($imagePath) || !is_readable($imagePath)) {
            return ['success' => false, 'reason' => 'file_not_readable'];
        }

        // @: file can be deleted between existence check and size read (race)
        $size = @filesize($imagePath);
        if ($size === false || $size < 100) {
            return ['success' => false, 'reason' => 'file_too_small'];
        }

        if ($size > CACHE_FILE_MAX_SIZE) {
            return ['success' => false, 'reason' => 'file_too_large'];
        }

        // Detect format
        $format = detectImageFormat($imagePath);
        if ($format === null) {
            return ['success' => false, 'reason' => 'invalid_format'];
        }

        // Load into GD for validation (single load for pipeline)
        $imageData = @file_get_contents($imagePath);
        if ($imageData === false) {
            return ['success' => false, 'reason' => 'read_error'];
        }

        $this->gdImage = @imagecreatefromstring($imageData);
        unset($imageData); // Free memory

        if ($this->gdImage === false) {
            $this->gdImage = null;
            return ['success' => false, 'reason' => 'decode_error'];
        }

        // Get dimensions
        $this->imageWidth = imagesx($this->gdImage);
        $this->imageHeight = imagesy($this->gdImage);

        // Validate minimum dimensions
        if ($this->imageWidth < WEBCAM_ERROR_MIN_WIDTH || $this->imageHeight < WEBCAM_ERROR_MIN_HEIGHT) {
            return ['success' => false, 'reason' => 'dimensions_too_small'];
        }

        return [
            'success' => true,
            'format' => $format,
            'width' => $this->imageWidth,
            'height' => $this->imageHeight
        ];
    }

    /**
     * Check for error frames using loaded GD image
     */
    private function checkErrorFrame(string $imagePath): array
    {
        // Use the existing error detector (it loads the image again, but has sophisticated detection)
        // Future optimization: Modify detectErrorFrame to accept GD resource
        $result = detectErrorFrame($imagePath, $this->airportConfig);

        if ($result['is_error']) {
            return [
                'passed' => false,
                'reason' => 'error_frame',
                'confidence' => $result['confidence'],
                'details' => $result['reasons'] ?? []
            ];
        }

        return ['passed' => true];
    }

    /**
     * Validate EXIF data
     */
    private function validateExif(string $imagePath, int $timestamp): array
    {
        // Check if EXIF exists
        $exifCheck = validateExifTimestamp($imagePath);

        if (!$exifCheck['valid']) {
            return [
                'valid' => false,
                'reason' => $exifCheck['reason'] ?? 'invalid_timestamp'
            ];
        }

        // Use EXIF timestamp if available, otherwise use provided timestamp
        $exifTimestamp = $exifCheck['timestamp'] ?? 0;
        if ($exifTimestamp > 0) {
            $timestamp = $exifTimestamp;
        }

        return [
            'valid' => true,
            'timestamp' => $timestamp
        ];
    }

    /**
     * Generate variants from source image
     */
    private function generateVariants(string $imagePath, int $timestamp): array
    {
        // Use the existing variant generation (handles all the complexity)
        return generateVariantsFromOriginal(
            $imagePath,
            $this->airportId,
            $this->camIndex,
            $timestamp
        );
    }

    /**
     * Store variant manifest for status reporting
     */
    private function storeVariantManifest(int $timestamp, array $variantResult): void
    {
        require_once __DIR__ . '/webcam-variant-manifest.php';
        storeVariantManifest($this->airportId, $this->camIndex, $timestamp, $variantResult);
    }

    /**
     * Cleanup old history files based on retention config
     */
    private function cleanupHistory(): void
    {
        cleanupOldTimestampFiles($this->airportId, $this->camIndex);
    }

    /**
     * Track health metrics for observability
     */
    private function trackHealthMetrics(array $variantResult): void
    {
        // Metrics already tracked in generateVariantsFromOriginal
        // This is a hook for additional pipeline-specific metrics if needed
        
        require_once __DIR__ . '/webcam-image-metrics.php';
        trackWebcamImageVerified($this->airportId, $this->camIndex);
    }

    /**
     * Record rejection for debugging/quarantine
     */
    private function recordRejection(string $imagePath, string $reason, array $metadata = []): void
    {
        // Save to quarantine for debugging
        saveRejectedWebcam(
            $imagePath,
            $this->airportId,
            $this->camIndex,
            $reason,
            $metadata
        );

        // Track rejection metrics
        require_once __DIR__ . '/webcam-image-metrics.php';
        trackWebcamImageRejected($this->airportId, $this->camIndex, $reason);

        aviationwx_log('warning', 'Pipeline image rejected', [
            'airport' => $this->airportId,
            'cam' => $this->camIndex,
            'reason' => $reason,
            'file' => basename($imagePath),
            'metadata' => $metadata
        ], 'app');
    }

    /**
     * Cleanup orphaned staging files from crashed workers
     * 
     * Staging files are named staging_<PID>_<random>.<ext>
     * Only cleans up files from OTHER processes that are > 5 minutes old.
     * Files from the current process are never cleaned (still being processed).
     */
    private function cleanupOrphanedStagingFiles(): void
    {
        $cameraDir = getWebcamCameraDir($this->airportId, $this->camIndex);
        $pattern = $cameraDir . '/staging_*';
        
        $stagingFiles = glob($pattern);
        if ($stagingFiles === false || empty($stagingFiles)) {
            return;
        }

        $cleanedCount = 0;
        $now = time();
        $maxAge = 3600; // 1 hour = orphaned (conservative to handle old uploads and slow processing)
        $currentPid = getmypid();

        foreach ($stagingFiles as $stagingFile) {
            // Extract PID from filename (staging_<PID>_<random>.<ext>)
            $basename = basename($stagingFile);
            if (preg_match('/^staging_(\d+)_/', $basename, $matches)) {
                $filePid = (int)$matches[1];
                // Never clean up files from current process
                if ($filePid === $currentPid) {
                    continue;
                }
            }

            $mtime = @filemtime($stagingFile);
            if ($mtime === false) {
                continue;
            }

            $age = $now - $mtime;
            if ($age > $maxAge) {
                if (@unlink($stagingFile)) {
                    $cleanedCount++;
                }
            }
        }

        if ($cleanedCount > 0) {
            aviationwx_log('info', 'Cleaned orphaned staging files', [
                'airport' => $this->airportId,
                'cam' => $this->camIndex,
                'count' => $cleanedCount
            ], 'app');
        }
    }

    /**
     * Destroy GD image resource
     */
    private function destroyImage(): void
    {
        // imagedestroy() has no effect since PHP 8.0 and is deprecated in PHP 8.5
        // Just null out the reference to allow garbage collection
        $this->gdImage = null;
    }

    /**
     * Get image dimensions (after loading)
     */
    public function getImageDimensions(): ?array
    {
        if ($this->imageWidth === null || $this->imageHeight === null) {
            return null;
        }
        return [
            'width' => $this->imageWidth,
            'height' => $this->imageHeight
        ];
    }
}

/**
 * Factory for creating pipeline instances
 */
class ProcessingPipelineFactory
{
    /**
     * Create a pipeline for the specified camera
     */
    public static function create(
        string $airportId,
        int $camIndex,
        array $camConfig,
        array $airportConfig
    ): ProcessingPipeline {
        return new ProcessingPipeline($airportId, $camIndex, $camConfig, $airportConfig);
    }
}
