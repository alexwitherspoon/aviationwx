<?php
/**
 * Webcam Acquisition Strategies
 * 
 * Strategy pattern implementation for webcam image acquisition.
 * Supports both pull (MJPEG, RTSP, static URL) and push (FTP upload) sources.
 * 
 * @package AviationWX
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/circuit-breaker.php';
require_once __DIR__ . '/exif-utils.php';
require_once __DIR__ . '/webcam-error-detector.php';
require_once __DIR__ . '/webcam-pull-metadata.php';

/**
 * Result of an image acquisition attempt
 */
class AcquisitionResult
{
    /** @var bool Whether acquisition succeeded */
    public bool $success;
    
    /** @var string|null Path to acquired image file (staging location) */
    public ?string $imagePath;
    
    /** @var int|null Capture timestamp (from EXIF or derived) */
    public ?int $timestamp;
    
    /** @var string Source type identifier (mjpeg, static, rtsp, push, federated, mock) */
    public string $sourceType;
    
    /** @var string|null Error reason if failed */
    public ?string $errorReason;
    
    /** @var array Additional metadata about acquisition */
    public array $metadata;

    private function __construct()
    {
        $this->metadata = [];
    }

    /**
     * Create a successful acquisition result
     */
    public static function success(
        string $imagePath,
        int $timestamp,
        string $sourceType,
        array $metadata = []
    ): self {
        $result = new self();
        $result->success = true;
        $result->imagePath = $imagePath;
        $result->timestamp = $timestamp;
        $result->sourceType = $sourceType;
        $result->errorReason = null;
        $result->metadata = $metadata;
        return $result;
    }

    /**
     * Create a failed acquisition result
     */
    public static function failure(
        string $errorReason,
        string $sourceType = 'unknown',
        array $metadata = []
    ): self {
        $result = new self();
        $result->success = false;
        $result->imagePath = null;
        $result->timestamp = null;
        $result->sourceType = $sourceType;
        $result->errorReason = $errorReason;
        $result->metadata = $metadata;
        return $result;
    }

    /**
     * Create a skip result (no work needed, not an error)
     */
    public static function skip(
        string $reason,
        string $sourceType = 'unknown',
        array $metadata = []
    ): self {
        $result = new self();
        $result->success = false;
        $result->imagePath = null;
        $result->timestamp = null;
        $result->sourceType = $sourceType;
        $result->errorReason = 'skip:' . $reason;
        $result->metadata = $metadata;
        return $result;
    }

    /**
     * Check if this result represents a skip (not an actual failure)
     */
    public function isSkip(): bool
    {
        return $this->errorReason !== null && strpos($this->errorReason, 'skip:') === 0;
    }

    /**
     * Get the skip reason (without 'skip:' prefix)
     */
    public function getSkipReason(): ?string
    {
        if (!$this->isSkip()) {
            return null;
        }
        return substr($this->errorReason, 5);
    }
}

/**
 * Interface for webcam image acquisition strategies
 */
interface AcquisitionStrategy
{
    /**
     * Acquire an image from the source
     * 
     * @return AcquisitionResult Result containing image path or error
     */
    public function acquire(): AcquisitionResult;

    /**
     * Get the source type identifier
     * 
     * @return string Source type (e.g., 'mjpeg', 'rtsp', 'push')
     */
    public function getSourceType(): string;

    /**
     * Check if acquisition should be skipped (circuit breaker, fresh cache, etc.)
     * 
     * @return array{skip: bool, reason: string|null}
     */
    public function shouldSkip(): array;
}

/**
 * Base class with common functionality for acquisition strategies
 */
abstract class BaseAcquisitionStrategy implements AcquisitionStrategy
{
    protected string $airportId;
    protected int $camIndex;
    protected array $camConfig;
    protected array $airportConfig;

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
     * Get staging file path for this camera
     */
    protected function getStagingPath(string $format = 'jpg'): string
    {
        $cameraDir = getWebcamCameraDir($this->airportId, $this->camIndex);
        if (!is_dir($cameraDir)) {
            @mkdir($cameraDir, 0755, true);
        }
        // Include PID and random number to prevent collisions
        return $cameraDir . '/staging_' . getmypid() . '_' . mt_rand(1000, 9999) . '.' . $format;
    }

    /**
     * Get timezone for this camera
     */
    protected function getTimezone(): string
    {
        return $this->airportConfig['timezone'] ?? 'UTC';
    }

    /**
     * Check circuit breaker status
     */
    public function shouldSkip(): array
    {
        $circuit = checkWebcamCircuitBreaker($this->airportId, $this->camIndex);
        if ($circuit['skip']) {
            return [
                'skip' => true,
                'reason' => 'circuit_breaker',
                'details' => [
                    'failures' => $circuit['failures'] ?? 0,
                    'backoff_remaining' => $circuit['backoff_remaining'] ?? 0,
                    'last_failure_reason' => $circuit['last_failure_reason'] ?? 'unknown'
                ]
            ];
        }
        return ['skip' => false, 'reason' => null];
    }

    /**
     * Record acquisition failure
     */
    protected function recordFailure(string $reason, string $severity = 'transient', ?int $httpCode = null): void
    {
        recordWebcamFailure($this->airportId, $this->camIndex, $severity, $httpCode, $reason);
    }

    /**
     * Record acquisition success
     */
    protected function recordSuccess(): void
    {
        recordWebcamSuccess($this->airportId, $this->camIndex);
    }

    /**
     * Ensure EXIF data exists on image
     * 
     * @param string $imagePath Path to image file
     * @param int|null $timestamp Optional timestamp to use (null = derive from image)
     * @return bool True if EXIF is valid, false if image should be rejected
     */
    protected function ensureExif(string $imagePath, ?int $timestamp = null): bool
    {
        $timezone = $this->getTimezone();
        $context = ['airport_id' => $this->airportId, 'cam_index' => $this->camIndex, 'source_type' => 'pull'];
        return ensureImageHasExif($imagePath, $timestamp, $timezone, $context);
    }

    /**
     * Validate EXIF timestamp
     * 
     * @param string $imagePath Path to image file
     * @return array{valid: bool, reason: string|null, timestamp: int}
     */
    protected function validateExifTimestamp(string $imagePath): array
    {
        return validateExifTimestamp($imagePath);
    }

    /**
     * Check for error frames (uniform color, pixelation, etc.)
     * 
     * @param string $imagePath Path to image file
     * @return array{is_error: bool, confidence: float, reasons: array}
     */
    protected function detectErrorFrame(string $imagePath): array
    {
        return detectErrorFrame($imagePath, $this->airportConfig);
    }
}

/**
 * Pull acquisition strategy for fetching images from remote sources
 * 
 * Supports: MJPEG streams, static JPEG/PNG URLs, RTSP streams, federated AviationWX API
 */
class PullAcquisitionStrategy extends BaseAcquisitionStrategy
{
    private string $sourceType;

    public function __construct(
        string $airportId,
        int $camIndex,
        array $camConfig,
        array $airportConfig
    ) {
        parent::__construct($airportId, $camIndex, $camConfig, $airportConfig);
        $this->sourceType = $this->detectSourceType();
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    /**
     * Detect source type from camera configuration
     */
    private function detectSourceType(): string
    {
        // Explicit type in config takes precedence
        if (isset($this->camConfig['type'])) {
            $type = strtolower(trim($this->camConfig['type']));
            if (in_array($type, ['mjpeg', 'static_jpeg', 'static_png', 'rtsp', 'aviationwx_api'])) {
                return $type;
            }
        }

        // Auto-detect from URL
        $url = $this->camConfig['url'] ?? '';
        
        if (stripos($url, 'rtsp://') === 0 || stripos($url, 'rtsps://') === 0) {
            return 'rtsp';
        }
        
        if (preg_match('/\.(jpg|jpeg)$/i', $url)) {
            return 'static_jpeg';
        }
        
        if (preg_match('/\.(png)$/i', $url)) {
            return 'static_png';
        }

        // Default to MJPEG stream
        return 'mjpeg';
    }

    public function acquire(): AcquisitionResult
    {
        // Check for mock mode
        if (function_exists('shouldMockExternalServices') && shouldMockExternalServices()) {
            return $this->acquireMock();
        }

        $stagingPath = $this->getStagingPath();

        switch ($this->sourceType) {
            case 'aviationwx_api':
                return $this->acquireFederated($stagingPath);
            case 'rtsp':
                return $this->acquireRtsp($stagingPath);
            case 'static_jpeg':
            case 'static_png':
                return $this->acquireStatic($stagingPath);
            case 'mjpeg':
            default:
                return $this->acquireMjpeg($stagingPath);
        }
    }

    /**
     * Acquire mock image for development/testing
     */
    private function acquireMock(): AcquisitionResult
    {
        if (!function_exists('generateMockWebcamImage')) {
            require_once __DIR__ . '/mock-webcam.php';
        }

        $stagingPath = $this->getStagingPath();
        $mockData = generateMockWebcamImage($this->airportId, $this->camIndex);

        if (@file_put_contents($stagingPath, $mockData) === false) {
            return AcquisitionResult::failure('mock_write_failed', 'mock');
        }

        $timestamp = time();
        if (!$this->ensureExif($stagingPath, $timestamp)) {
            @unlink($stagingPath);
            return AcquisitionResult::failure('exif_add_failed', 'mock');
        }

        $this->recordSuccess();
        return AcquisitionResult::success($stagingPath, $timestamp, 'mock', ['mock' => true]);
    }

    /**
     * Acquire from federated AviationWX API
     *
     * Uses HTTP conditional (If-None-Match) and checksum to skip when image unchanged.
     */
    private function acquireFederated(string $stagingPath): AcquisitionResult
    {
        $baseUrl = rtrim($this->camConfig['base_url'] ?? '', '/');
        $apiKey = $this->camConfig['api_key'] ?? null;
        $timeout = $this->camConfig['timeout_seconds'] ?? 15;

        if (empty($baseUrl)) {
            return AcquisitionResult::failure('missing_base_url', 'federated');
        }

        $url = "{$baseUrl}/api/v1/webcams/{$this->airportId}/{$this->camIndex}/latest";
        $metadata = getWebcamPullMetadata($this->airportId, $this->camIndex);

        $headers = ['Accept: image/jpeg'];
        if ($apiKey) {
            $headers[] = "X-API-Key: {$apiKey}";
        }
        $cachedEtag = sanitizeEtagForHeader($metadata['etag'] ?? null);
        if ($cachedEtag !== null) {
            $headers[] = 'If-None-Match: ' . $cachedEtag;
        } elseif ($metadata['etag'] !== null) {
            aviationwx_log('warning', 'Invalid ETag in webcam pull metadata; skipping If-None-Match', [
                'airport' => $this->airportId,
                'cam' => $this->camIndex,
            ], 'app');
        }

        $responseEtag = null;
        $headerCallback = function ($ch, $headerLine) use (&$responseEtag) {
            if (stripos($headerLine, 'ETag:') === 0) {
                $responseEtag = sanitizeEtagForHeader(trim(substr($headerLine, 5)));
            }
            return strlen($headerLine);
        };

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'AviationWX-Federation/1.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HEADERFUNCTION => $headerCallback,
        ]);

        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 304) {
            $this->recordSuccess();
            return AcquisitionResult::skip('unchanged_304', 'federated', ['etag' => $cachedEtag]);
        }

        if ($imageData === false || $httpCode !== 200) {
            $this->recordFailure('http_' . $httpCode, 'transient', $httpCode);
            return AcquisitionResult::failure('http_error', 'federated', [
                'http_code' => $httpCode,
                'error' => $error
            ]);
        }

        $checksum = computeWebcamContentChecksum($imageData);
        if ($metadata['checksum'] !== null && $checksum === $metadata['checksum']) {
            // Persist new ETag when content unchanged so future requests can use 304
            saveWebcamPullMetadata($this->airportId, $this->camIndex, $responseEtag, $checksum);
            $this->recordSuccess();
            return AcquisitionResult::skip('unchanged_checksum', 'federated', ['checksum' => $checksum]);
        }

        // Validate image data
        if (!$this->isValidImageData($imageData)) {
            $this->recordFailure('invalid_image_data', 'transient');
            return AcquisitionResult::failure('invalid_image_data', 'federated');
        }

        if (@file_put_contents($stagingPath, $imageData) === false) {
            $this->recordFailure('write_failed', 'transient');
            return AcquisitionResult::failure('write_failed', 'federated');
        }

        // Validate and add EXIF
        $timestamp = time();
        if (!$this->ensureExif($stagingPath, $timestamp)) {
            @unlink($stagingPath);
            $this->recordFailure('exif_add_failed', 'transient');
            return AcquisitionResult::failure('exif_add_failed', 'federated');
        }

        $exifCheck = $this->validateExifTimestamp($stagingPath);
        if (!$exifCheck['valid']) {
            @unlink($stagingPath);
            $this->recordFailure('exif_invalid: ' . $exifCheck['reason'], 'transient');
            return AcquisitionResult::failure('exif_invalid', 'federated', ['reason' => $exifCheck['reason']]);
        }

        if (!saveWebcamPullMetadata($this->airportId, $this->camIndex, $responseEtag, $checksum)) {
            aviationwx_log('warning', 'Failed to save webcam pull metadata', [
                'airport' => $this->airportId,
                'cam' => $this->camIndex,
            ], 'app');
        }
        $this->recordSuccess();
        return AcquisitionResult::success($stagingPath, $timestamp, 'federated');
    }

    /**
     * Acquire from RTSP stream using ffmpeg
     */
    private function acquireRtsp(string $stagingPath): AcquisitionResult
    {
        // Check ffmpeg availability
        if (!$this->isFfmpegAvailable()) {
            return AcquisitionResult::failure('ffmpeg_not_available', 'rtsp');
        }

        $url = $this->camConfig['url'] ?? '';
        $transport = strtolower($this->camConfig['rtsp_transport'] ?? 'tcp');
        $timeout = $this->camConfig['rtsp_fetch_timeout'] ?? intval(getenv('RTSP_TIMEOUT') ?: RTSP_DEFAULT_TIMEOUT);
        $maxRuntime = $this->camConfig['rtsp_max_runtime'] ?? RTSP_MAX_RUNTIME;
        $retries = RTSP_DEFAULT_RETRIES;
        $backoffDelays = RTSP_BACKOFF_DELAYS;

        $isRtsps = stripos($url, 'rtsps://') === 0;
        if ($isRtsps) {
            $transport = 'tcp'; // RTSPS requires TCP
        }

        $timeoutUs = max(1, $timeout) * 1000000;
        $attempt = 0;

        while ($attempt <= $retries) {
            $attempt++;

            // Backoff before each attempt
            if (isset($backoffDelays[$attempt - 1])) {
                sleep($backoffDelays[$attempt - 1]);
            }

            $jpegTmp = $stagingPath . '.attempt' . $attempt . '.jpg';

            // Build ffmpeg command
            $cmdArray = [
                'ffmpeg',
                '-hide_banner',
                '-loglevel', 'warning',
                '-rtsp_transport', $transport,
                '-timeout', (string)$timeoutUs
            ];

            if ($isRtsps) {
                $cmdArray[] = '-rtsp_flags';
                $cmdArray[] = 'prefer_tcp';
                $cmdArray[] = '-fflags';
                $cmdArray[] = 'nobuffer';
            }

            $cmdArray = array_merge($cmdArray, [
                '-i', $url,
                '-t', (string)max(1, $maxRuntime),
                '-frames:v', '1',
                '-q:v', '2',
                '-y', $jpegTmp
            ]);

            $cmd = implode(' ', array_map('escapeshellarg', $cmdArray)) . ' 2>&1';
            exec($cmd, $output, $exitCode);
            $errorOutput = implode("\n", $output);

            if ($exitCode === 0 && file_exists($jpegTmp) && filesize($jpegTmp) > 1000) {
                // Check for error frames
                $errorCheck = $this->detectErrorFrame($jpegTmp);
                if ($errorCheck['is_error']) {
                    aviationwx_log('warning', 'RTSP error frame detected', [
                        'airport' => $this->airportId,
                        'cam' => $this->camIndex,
                        'confidence' => $errorCheck['confidence'],
                        'reasons' => $errorCheck['reasons']
                    ], 'app');
                    @unlink($jpegTmp);
                    continue; // Retry
                }

                // Add EXIF
                $timestamp = time();
                if (!$this->ensureExif($jpegTmp, $timestamp)) {
                    @unlink($jpegTmp);
                    continue; // Retry
                }

                // Validate EXIF
                $exifCheck = $this->validateExifTimestamp($jpegTmp);
                if (!$exifCheck['valid']) {
                    @unlink($jpegTmp);
                    continue; // Retry
                }

                // Move to final staging path
                if (@rename($jpegTmp, $stagingPath)) {
                    $this->recordSuccess();
                    return AcquisitionResult::success($stagingPath, $timestamp, 'rtsp');
                }
                @unlink($jpegTmp);
            }

            @unlink($jpegTmp);
        }

        $errorCode = $this->classifyRtspError($exitCode ?? 1, $errorOutput ?? '');
        $this->recordFailure('rtsp_' . $errorCode, $this->mapErrorSeverity($errorCode));
        return AcquisitionResult::failure('rtsp_failed', 'rtsp', [
            'error_code' => $errorCode,
            'attempts' => $attempt
        ]);
    }

    /**
     * Acquire from static image URL (JPEG or PNG)
     *
     * Uses HTTP conditional (If-None-Match) and checksum to skip when image unchanged.
     * Safety-critical: prevents misrepresenting image age when source has not updated.
     */
    private function acquireStatic(string $stagingPath): AcquisitionResult
    {
        $url = $this->camConfig['url'] ?? '';
        $metadata = getWebcamPullMetadata($this->airportId, $this->camIndex);

        $headers = [];
        $cachedEtag = sanitizeEtagForHeader($metadata['etag'] ?? null);
        if ($cachedEtag !== null) {
            $headers[] = 'If-None-Match: ' . $cachedEtag;
        } elseif ($metadata['etag'] !== null) {
            aviationwx_log('warning', 'Invalid ETag in webcam pull metadata; skipping If-None-Match', [
                'airport' => $this->airportId,
                'cam' => $this->camIndex,
            ], 'app');
        }

        $responseEtag = null;
        $headerCallback = function ($ch, $headerLine) use (&$responseEtag) {
            if (stripos($headerLine, 'ETag:') === 0) {
                $responseEtag = sanitizeEtagForHeader(trim(substr($headerLine, 5)));
            }
            return strlen($headerLine);
        };

        $curlOpts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'AviationWX Webcam Bot',
            CURLOPT_MAXFILESIZE => CACHE_FILE_MAX_SIZE,
            CURLOPT_HEADERFUNCTION => $headerCallback,
        ];
        if (!empty($headers)) {
            $curlOpts[CURLOPT_HTTPHEADER] = $headers;
        }

        $ch = curl_init();
        curl_setopt_array($ch, $curlOpts);

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 304) {
            $this->recordSuccess();
            return AcquisitionResult::skip('unchanged_304', $this->sourceType, ['etag' => $cachedEtag]);
        }

        if ($error || $httpCode !== 200 || empty($data) || strlen($data) <= 100) {
            $this->recordFailure('http_' . $httpCode, 'transient', $httpCode !== 200 ? $httpCode : null);
            return AcquisitionResult::failure('fetch_failed', $this->sourceType, [
                'http_code' => $httpCode,
                'error' => $error
            ]);
        }

        $checksum = computeWebcamContentChecksum($data);
        if ($metadata['checksum'] !== null && $checksum === $metadata['checksum']) {
            // Persist new ETag when content unchanged so future requests can use 304
            saveWebcamPullMetadata($this->airportId, $this->camIndex, $responseEtag, $checksum);
            $this->recordSuccess();
            return AcquisitionResult::skip('unchanged_checksum', $this->sourceType, ['checksum' => $checksum]);
        }

        // Detect and handle image format
        $isPng = strpos($data, "\x89PNG") === 0;
        $isJpeg = strpos($data, "\xff\xd8") === 0;

        if (!$isJpeg && !$isPng) {
            $this->recordFailure('invalid_format', 'transient');
            return AcquisitionResult::failure('invalid_format', $this->sourceType);
        }

        // Convert PNG to JPEG if needed
        if ($isPng) {
            $img = @imagecreatefromstring($data);
            if (!$img) {
                $this->recordFailure('png_decode_failed', 'transient');
                return AcquisitionResult::failure('png_decode_failed', $this->sourceType);
            }
            if (!@imagejpeg($img, $stagingPath, 85)) {
                $this->recordFailure('jpeg_encode_failed', 'transient');
                return AcquisitionResult::failure('jpeg_encode_failed', $this->sourceType);
            }
        } else {
            if (@file_put_contents($stagingPath, $data) === false) {
                $this->recordFailure('write_failed', 'transient');
                return AcquisitionResult::failure('write_failed', $this->sourceType);
            }
        }

        // Check for error frames
        $errorCheck = $this->detectErrorFrame($stagingPath);
        if ($errorCheck['is_error']) {
            require_once __DIR__ . '/webcam-rejection-logger.php';
            saveRejectedWebcam($stagingPath, $this->airportId, $this->camIndex, 'error_frame', [
                'source' => $this->sourceType,
                'confidence' => $errorCheck['confidence'],
                'reasons' => $errorCheck['reasons']
            ]);
            @unlink($stagingPath);
            $this->recordFailure('error_frame', 'transient');
            return AcquisitionResult::failure('error_frame', $this->sourceType, $errorCheck);
        }

        // Add and validate EXIF
        $timestamp = time();
        if (!$this->ensureExif($stagingPath, $timestamp)) {
            @unlink($stagingPath);
            $this->recordFailure('exif_add_failed', 'transient');
            return AcquisitionResult::failure('exif_add_failed', $this->sourceType);
        }

        $exifCheck = $this->validateExifTimestamp($stagingPath);
        if (!$exifCheck['valid']) {
            @unlink($stagingPath);
            $this->recordFailure('exif_invalid', 'transient');
            return AcquisitionResult::failure('exif_invalid', $this->sourceType, ['reason' => $exifCheck['reason']]);
        }

        if (!saveWebcamPullMetadata($this->airportId, $this->camIndex, $responseEtag, $checksum)) {
            aviationwx_log('warning', 'Failed to save webcam pull metadata', [
                'airport' => $this->airportId,
                'cam' => $this->camIndex,
            ], 'app');
        }
        $this->recordSuccess();
        return AcquisitionResult::success($stagingPath, $timestamp, $this->sourceType);
    }

    /**
     * Acquire from MJPEG stream
     */
    private function acquireMjpeg(string $stagingPath): AcquisitionResult
    {
        $url = $this->camConfig['url'] ?? '';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'AviationWX Webcam Bot',
            CURLOPT_HTTPHEADER => ['Connection: close'],
        ]);

        // Custom handler to stop after first complete JPEG frame
        $data = '';
        $foundJpegEnd = false;
        $startTime = time();

        $outputHandler = function ($ch, $chunk) use (&$data, &$foundJpegEnd, $startTime) {
            $data .= $chunk;

            if (strpos($data, "\xff\xd9") !== false) {
                $foundJpegEnd = true;
                return 0; // Signal curl to stop
            }

            if (strlen($data) > MJPEG_MAX_SIZE || (time() - $startTime) > MJPEG_MAX_TIME) {
                return 0;
            }

            return strlen($chunk);
        };

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, $outputHandler);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || empty($data) || strlen($data) < 1000) {
            $this->recordFailure('http_' . $httpCode, 'transient', $httpCode !== 200 ? $httpCode : null);
            return AcquisitionResult::failure('fetch_failed', 'mjpeg', ['http_code' => $httpCode]);
        }

        // Extract JPEG from stream data
        $jpegStart = strpos($data, "\xff\xd8");
        $jpegEnd = strpos($data, "\xff\xd9");

        if ($jpegStart === false || $jpegEnd === false || $jpegEnd <= $jpegStart) {
            $this->recordFailure('no_jpeg_frame', 'transient');
            return AcquisitionResult::failure('no_jpeg_frame', 'mjpeg');
        }

        $jpegData = substr($data, $jpegStart, $jpegEnd - $jpegStart + 2);
        $jpegSize = strlen($jpegData);

        if ($jpegSize < 1024 || $jpegSize > CACHE_FILE_MAX_SIZE) {
            $this->recordFailure('invalid_size', 'transient');
            return AcquisitionResult::failure('invalid_size', 'mjpeg', ['size' => $jpegSize]);
        }

        // Validate JPEG can be parsed
        if (function_exists('imagecreatefromstring')) {
            $testImg = @imagecreatefromstring($jpegData);
            if ($testImg === false) {
                $this->recordFailure('invalid_jpeg', 'transient');
                return AcquisitionResult::failure('invalid_jpeg', 'mjpeg');
            }
        }

        if (@file_put_contents($stagingPath, $jpegData) === false) {
            $this->recordFailure('write_failed', 'transient');
            return AcquisitionResult::failure('write_failed', 'mjpeg');
        }

        // Check for error frames
        $errorCheck = $this->detectErrorFrame($stagingPath);
        if ($errorCheck['is_error']) {
            require_once __DIR__ . '/webcam-rejection-logger.php';
            saveRejectedWebcam($stagingPath, $this->airportId, $this->camIndex, 'error_frame', [
                'source' => 'mjpeg',
                'confidence' => $errorCheck['confidence'],
                'reasons' => $errorCheck['reasons']
            ]);
            @unlink($stagingPath);
            $this->recordFailure('error_frame', 'transient');
            return AcquisitionResult::failure('error_frame', 'mjpeg', $errorCheck);
        }

        // Add and validate EXIF
        $timestamp = time();
        if (!$this->ensureExif($stagingPath, $timestamp)) {
            @unlink($stagingPath);
            $this->recordFailure('exif_add_failed', 'transient');
            return AcquisitionResult::failure('exif_add_failed', 'mjpeg');
        }

        $exifCheck = $this->validateExifTimestamp($stagingPath);
        if (!$exifCheck['valid']) {
            @unlink($stagingPath);
            $this->recordFailure('exif_invalid', 'transient');
            return AcquisitionResult::failure('exif_invalid', 'mjpeg', ['reason' => $exifCheck['reason']]);
        }

        $this->recordSuccess();
        return AcquisitionResult::success($stagingPath, $timestamp, 'mjpeg');
    }

    /**
     * Check if ffmpeg is available
     */
    private function isFfmpegAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            $output = [];
            $return = 0;
            @exec('ffmpeg -version 2>&1', $output, $return);
            $available = ($return === 0);
        }
        return $available;
    }

    /**
     * Validate image data has valid JPEG/PNG signature
     */
    private function isValidImageData(string $data): bool
    {
        if (strlen($data) < 100) {
            return false;
        }
        // Check for JPEG or PNG signature
        return strpos($data, "\xff\xd8") === 0 || strpos($data, "\x89PNG") === 0;
    }

    /**
     * Classify RTSP error for circuit breaker
     */
    private function classifyRtspError(int $exitCode, string $errorOutput): string
    {
        $output = strtolower($errorOutput);

        if (stripos($output, 'timeout') !== false || $exitCode === 124) {
            return 'timeout';
        }
        if (stripos($output, 'unauthorized') !== false || stripos($output, '401') !== false) {
            return 'auth';
        }
        if (stripos($output, 'ssl') !== false || stripos($output, 'certificate') !== false) {
            return 'tls';
        }
        if (stripos($output, 'could not resolve') !== false || stripos($output, 'dns') !== false) {
            return 'dns';
        }
        if (stripos($output, 'connection refused') !== false) {
            return 'connection';
        }

        return 'unknown';
    }

    /**
     * Map error code to severity for backoff
     */
    private function mapErrorSeverity(string $code): string
    {
        switch ($code) {
            case 'auth':
            case 'tls':
                return 'permanent';
            default:
                return 'transient';
        }
    }
}

/**
 * Push acquisition strategy for processing uploaded images
 * 
 * Handles images uploaded via FTP/SFTP to the server.
 * Checks both FTP and SFTP upload directories for files.
 */
class PushAcquisitionStrategy extends BaseAcquisitionStrategy
{
    /** @var array Upload directories to check (ftp and sftp) */
    private array $uploadDirs = [];

    public function __construct(
        string $airportId,
        int $camIndex,
        array $camConfig,
        array $airportConfig
    ) {
        parent::__construct($airportId, $camIndex, $camConfig, $airportConfig);
        $this->uploadDirs = $this->getUploadDirectories();
    }

    public function getSourceType(): string
    {
        return 'push';
    }

    /**
     * Get all upload directories for this camera (FTP and SFTP)
     * 
     * @return array Array of directory paths that exist
     */
    private function getUploadDirectories(): array
    {
        $username = $this->camConfig['push_config']['username'] ?? null;
        if (!$username) {
            return [];
        }
        
        $dirs = [];
        
        // FTP upload directory
        $ftpDir = getWebcamFtpUploadDir($this->airportId, $username);
        if (is_dir($ftpDir)) {
            $dirs['ftp'] = $ftpDir;
        }
        
        // SFTP upload directory
        $sftpDir = getWebcamSftpUploadDir($username);
        if (is_dir($sftpDir)) {
            $dirs['sftp'] = $sftpDir;
        }
        
        return $dirs;
    }
    
    /**
     * Get primary upload directory (for backward compatibility)
     * Returns FTP directory if available, otherwise SFTP
     * 
     * @deprecated Use getUploadDirectories() instead
     */
    private function getUploadDirectory(): ?string
    {
        if (!empty($this->uploadDirs)) {
            return reset($this->uploadDirs);
        }
        return null;
    }

    /**
     * Check if acquisition should be skipped
     */
    public function shouldSkip(): array
    {
        // First check circuit breaker
        $parentCheck = parent::shouldSkip();
        if ($parentCheck['skip']) {
            return $parentCheck;
        }

        // Check if any upload directory exists
        if (empty($this->uploadDirs)) {
            return ['skip' => true, 'reason' => 'no_upload_dir'];
        }

        // Check last processed time vs refresh interval
        $lastProcessed = $this->getLastProcessedTime();
        $refreshSeconds = $this->getRefreshSeconds();
        $timeSinceLast = time() - $lastProcessed;

        if ($timeSinceLast < $refreshSeconds) {
            return [
                'skip' => true,
                'reason' => 'not_due',
                'details' => [
                    'last_processed' => $lastProcessed,
                    'refresh_seconds' => $refreshSeconds,
                    'time_since_last' => $timeSinceLast
                ]
            ];
        }

        return ['skip' => false, 'reason' => null];
    }

    public function acquire(): AcquisitionResult
    {
        $username = $this->camConfig['push_config']['username'] ?? null;
        if (!$username) {
            return AcquisitionResult::failure('no_username_configured', 'push');
        }

        if (empty($this->uploadDirs)) {
            return AcquisitionResult::skip('no_upload_dir', 'push');
        }

        // Find newest valid image
        $uploadFile = $this->findNewestValidImage();
        if (!$uploadFile) {
            return AcquisitionResult::skip('no_new_files', 'push');
        }

        // Validate image content
        $validationResult = $this->validateUploadedImage($uploadFile);
        if (!$validationResult['valid']) {
            $this->recordStabilityMetrics($validationResult['stability_time'] ?? 0, false);
            return AcquisitionResult::failure($validationResult['reason'], 'push', $validationResult);
        }

        // Normalize EXIF timestamp
        $timezone = $this->getTimezone();
        if (!normalizeExifToUtc($uploadFile, $this->airportId, $this->camIndex, $timezone)) {
            require_once __DIR__ . '/webcam-image-metrics.php';
            trackWebcamImageRejected($this->airportId, $this->camIndex, 'invalid_exif_timestamp');
            $this->recordStabilityMetrics($validationResult['stability_time'] ?? 0, false);
            return AcquisitionResult::failure('invalid_exif_timestamp', 'push');
        }

        // Get timestamp from EXIF
        $timestamp = getSourceCaptureTime($uploadFile);
        if ($timestamp <= 0) {
            $timestamp = time();
        }

        // Cross-validate EXIF against upload mtime (timestamp drift check)
        $uploadMtime = @filemtime($uploadFile);
        if ($uploadMtime !== false) {
            $drift = abs($uploadMtime - $timestamp);
            if ($drift > 7200) { // > 2 hours drift
                require_once __DIR__ . '/webcam-rejection-logger.php';
                logWebcamRejection($this->airportId, $this->camIndex, 'timestamp_drift', 
                    sprintf('EXIF differs from upload time by %d seconds', $drift));
                $this->recordStabilityMetrics($validationResult['stability_time'] ?? 0, false);
                return AcquisitionResult::failure('timestamp_drift', 'push', ['drift_seconds' => $drift]);
            }
        }

        // Move to staging (the image is now validated)
        $stagingPath = $this->getStagingPath();
        if (!@rename($uploadFile, $stagingPath)) {
            // Try copy + delete as fallback (cross-filesystem)
            if (!@copy($uploadFile, $stagingPath)) {
                $this->recordFailure('move_failed', 'transient');
                return AcquisitionResult::failure('move_failed', 'push');
            }
            @unlink($uploadFile);
        }

        // Record success metrics
        $this->recordStabilityMetrics($validationResult['stability_time'] ?? 0, true);
        $this->updateLastProcessedTime();
        $this->recordSuccess();

        return AcquisitionResult::success($stagingPath, $timestamp, 'push', [
            'original_file' => basename($uploadFile),
            'stability_time' => $validationResult['stability_time'] ?? 0
        ]);
    }

    /**
     * Find the newest valid image in all upload directories (FTP and SFTP)
     * 
     * Note: No time-based filtering - files are moved after processing,
     * so they won't appear again. Time filtering was removed to fix a bug
     * where backlog files could be orphaned.
     */
    private function findNewestValidImage(): ?string
    {
        // Collect files from all upload directories (FTP and SFTP)
        $files = [];
        foreach ($this->uploadDirs as $protocol => $dir) {
            $dirFiles = $this->recursiveGlobImages($dir);
            $files = array_merge($files, $dirFiles);
        }
        
        if (empty($files)) {
            return null;
        }

        $maxFileAge = $this->getUploadFileMaxAge();
        $stabilityTimeout = $this->getStabilityCheckTimeout();
        $requiredStableChecks = $this->getRequiredStableChecks();

        // Sort by mtime (newest first)
        usort($files, function ($a, $b) {
            return @filemtime($b) - @filemtime($a);
        });

        foreach ($files as $file) {
            $fileAge = time() - @filemtime($file);

            // Too new - skip (minimum 3 seconds)
            if ($fileAge < 3) {
                continue;
            }

            // Too old - abandoned upload, clean it up
            if ($fileAge > $maxFileAge) {
                aviationwx_log('warning', 'Push upload too old, deleting', [
                    'file' => basename($file),
                    'age' => $fileAge,
                    'max_age' => $maxFileAge,
                    'airport' => $this->airportId,
                    'cam' => $this->camIndex
                ], 'app');
                @unlink($file);
                continue;
            }

            // Check file stability
            $stabilityTime = 0;
            if (!$this->isFileStable($file, $requiredStableChecks, $stabilityTimeout, $stabilityTime)) {
                continue; // File still being written
            }

            // Ensure EXIF exists
            $timezone = $this->getTimezone();
            $context = ['airport_id' => $this->airportId, 'cam_index' => $this->camIndex, 'source_type' => 'push'];
            if (!ensureImageHasExif($file, null, $timezone, $context)) {
                continue;
            }

            return $file;
        }

        return null;
    }

    /**
     * Validate uploaded image file
     */
    private function validateUploadedImage(string $file): array
    {
        $pushConfig = $this->camConfig['push_config'] ?? [];
        $result = ['valid' => true, 'stability_time' => 0];

        // Check file exists and is readable
        if (!file_exists($file) || !is_readable($file)) {
            return ['valid' => false, 'reason' => 'file_not_readable'];
        }

        $size = filesize($file);

        // Check minimum size
        if ($size < 100) {
            return ['valid' => false, 'reason' => 'size_too_small', 'size' => $size];
        }

        // Check maximum size
        $maxSizeBytes = ($pushConfig['max_file_size_mb'] ?? 100) * 1024 * 1024;
        if ($size > $maxSizeBytes) {
            return ['valid' => false, 'reason' => 'size_limit_exceeded', 'size' => $size, 'max' => $maxSizeBytes];
        }

        // Check allowed extensions
        if (isset($pushConfig['allowed_extensions']) && is_array($pushConfig['allowed_extensions'])) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $allowed = array_map('strtolower', $pushConfig['allowed_extensions']);
            if (!in_array($ext, $allowed)) {
                return ['valid' => false, 'reason' => 'extension_not_allowed', 'extension' => $ext];
            }
        }

        // Check MIME type
        $mime = @mime_content_type($file);
        if (!in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'])) {
            return ['valid' => false, 'reason' => 'invalid_mime_type', 'mime' => $mime];
        }

        // Check image format
        require_once __DIR__ . '/webcam-format-generation.php';
        $format = detectImageFormat($file);
        if ($format === null) {
            return ['valid' => false, 'reason' => 'invalid_format'];
        }

        // Check for truncated uploads
        require_once __DIR__ . '/webcam-history.php';
        $isComplete = true;
        if ($format === 'jpeg') {
            $isComplete = isJpegComplete($file);
        } elseif ($format === 'png') {
            $isComplete = isPngComplete($file);
        } elseif ($format === 'webp') {
            $isComplete = isWebpComplete($file);
        }

        if (!$isComplete) {
            return ['valid' => false, 'reason' => 'incomplete_upload', 'format' => $format];
        }

        // Validate GD can parse the image
        if (function_exists('imagecreatefromstring')) {
            $imageData = @file_get_contents($file);
            if ($imageData === false) {
                return ['valid' => false, 'reason' => 'file_read_error'];
            }
            $testImg = @imagecreatefromstring($imageData);
            if ($testImg === false) {
                return ['valid' => false, 'reason' => 'image_corrupt'];
            }
            unset($imageData);
        }

        // Check for error frames (JPEG only)
        if ($format === 'jpeg') {
            $errorCheck = $this->detectErrorFrame($file);
            if ($errorCheck['is_error']) {
                require_once __DIR__ . '/webcam-rejection-logger.php';
                saveRejectedWebcam($file, $this->airportId, $this->camIndex, 'error_frame', [
                    'source' => 'push',
                    'confidence' => $errorCheck['confidence'],
                    'reasons' => $errorCheck['reasons']
                ]);
                return ['valid' => false, 'reason' => 'error_frame', 'details' => $errorCheck];
            }
        }

        // Validate EXIF timestamp
        $exifCheck = validateExifTimestamp($file);
        if (!$exifCheck['valid']) {
            return ['valid' => false, 'reason' => 'exif_invalid', 'details' => $exifCheck];
        }

        return $result;
    }

    /**
     * Check if file has achieved stability (not being written to)
     */
    private function isFileStable(string $file, int $requiredChecks, int $timeout, float &$stabilityTime): bool
    {
        $startTime = microtime(true);
        $maxWaitTime = $startTime + $timeout;

        $lastSize = null;
        $lastMtime = null;
        $stableChecks = 0;

        while (microtime(true) < $maxWaitTime) {
            $currentSize = @filesize($file);
            $currentMtime = @filemtime($file);

            if ($currentSize === false || $currentMtime === false) {
                $stabilityTime = microtime(true) - $startTime;
                return false; // File disappeared
            }

            if ($lastSize !== null && $lastMtime !== null) {
                if ($currentSize === $lastSize && $currentMtime === $lastMtime) {
                    $stableChecks++;
                    if ($stableChecks >= $requiredChecks) {
                        $stabilityTime = microtime(true) - $startTime;
                        return true;
                    }
                } else {
                    $stableChecks = 0; // Reset
                }
            }

            $lastSize = $currentSize;
            $lastMtime = $currentMtime;

            if (microtime(true) + (STABILITY_CHECK_INTERVAL_MS / 1000) < $maxWaitTime) {
                usleep(STABILITY_CHECK_INTERVAL_MS * 1000);
            }
        }

        $stabilityTime = microtime(true) - $startTime;
        return false;
    }

    /**
     * Recursively find all images in directory
     */
    private function recursiveGlobImages(string $dir, int $maxDepth = 10, int $depth = 0): array
    {
        $files = [];
        if (!is_dir($dir) || $depth > $maxDepth) {
            return $files;
        }

        $dir = rtrim($dir, '/') . '/';

        $images = glob($dir . '*.{jpg,jpeg,png,webp}', GLOB_BRACE);
        if ($images !== false) {
            $files = array_merge($files, $images);
        }

        $subdirs = glob($dir . '*', GLOB_ONLYDIR);
        if ($subdirs !== false) {
            foreach ($subdirs as $subdir) {
                $files = array_merge($files, $this->recursiveGlobImages($subdir, $maxDepth, $depth + 1));
            }
        }

        return $files;
    }

    /**
     * Get last processed time from state file
     */
    private function getLastProcessedTime(): int
    {
        $stateFile = getWebcamStatePath($this->airportId, $this->camIndex);
        if (!file_exists($stateFile)) {
            return 0;
        }

        $data = @json_decode(@file_get_contents($stateFile), true);
        
        // Validate state file structure (safety improvement)
        if (!is_array($data) || !isset($data['last_processed']) || !is_int($data['last_processed'])) {
            aviationwx_log('warning', 'Corrupted state file, resetting', [
                'airport' => $this->airportId,
                'cam' => $this->camIndex
            ], 'app');
            return time(); // Fail-closed: don't reprocess old uploads
        }

        return $data['last_processed'];
    }

    /**
     * Update last processed time in state file
     */
    public function updateLastProcessedTime(): void
    {
        $stateFile = getWebcamStatePath($this->airportId, $this->camIndex);
        $stateDir = dirname($stateFile);

        if (!is_dir($stateDir)) {
            @mkdir($stateDir, 0755, true);
        }

        $data = ['last_processed' => time()];
        $tmpFile = $stateFile . '.tmp.' . getmypid();
        
        if (@file_put_contents($tmpFile, json_encode($data, JSON_PRETTY_PRINT)) !== false) {
            @rename($tmpFile, $stateFile);
        }
    }

    /**
     * Get refresh seconds for this camera
     */
    private function getRefreshSeconds(): int
    {
        $refresh = $this->camConfig['refresh_seconds'] 
            ?? $this->airportConfig['webcam_refresh_seconds'] 
            ?? DEFAULT_WEBCAM_REFRESH;
        return max(60, intval($refresh));
    }

    /**
     * Get maximum file age before abandonment
     */
    private function getUploadFileMaxAge(): int
    {
        $override = $this->camConfig['push_config']['upload_file_max_age_seconds'] ?? null;
        if ($override !== null) {
            return max(MIN_UPLOAD_FILE_MAX_AGE_SECONDS, min(MAX_UPLOAD_FILE_MAX_AGE_SECONDS, intval($override)));
        }
        return UPLOAD_FILE_MAX_AGE_SECONDS;
    }

    /**
     * Get stability check timeout
     */
    private function getStabilityCheckTimeout(): int
    {
        $override = $this->camConfig['push_config']['stability_check_timeout_seconds'] ?? null;
        if ($override !== null) {
            return max(MIN_STABILITY_CHECK_TIMEOUT_SECONDS, min(MAX_STABILITY_CHECK_TIMEOUT_SECONDS, intval($override)));
        }
        return DEFAULT_STABILITY_CHECK_TIMEOUT_SECONDS;
    }

    /**
     * Get required stable checks based on historical performance
     */
    private function getRequiredStableChecks(): int
    {
        $key = "stability_metrics_{$this->airportId}_{$this->camIndex}";
        $metrics = apcu_fetch($key);

        if (!$metrics) {
            $metrics = $this->loadStabilityMetricsFromDisk();
            if ($metrics) {
                apcu_store($key, $metrics, 7 * 86400);
            }
        }

        if (!$metrics || count($metrics['stability_times'] ?? []) < MIN_SAMPLES_FOR_OPTIMIZATION) {
            return DEFAULT_STABLE_CHECKS;
        }

        $rejectionRate = ($metrics['accepted'] + $metrics['rejected']) > 0 
            ? $metrics['rejected'] / ($metrics['accepted'] + $metrics['rejected']) 
            : 0;

        if ($rejectionRate > REJECTION_RATE_THRESHOLD_HIGH) {
            return DEFAULT_STABLE_CHECKS;
        }

        $times = $metrics['stability_times'];
        sort($times);
        $p95Index = (int)ceil(0.95 * count($times)) - 1;
        $p95Time = $times[max(0, $p95Index)];

        $checksNeeded = ceil(($p95Time / (STABILITY_CHECK_INTERVAL_MS / 1000)) * P95_SAFETY_MARGIN);
        return max(MIN_STABLE_CHECKS, min(MAX_STABLE_CHECKS, $checksNeeded));
    }

    /**
     * Record stability metrics
     */
    private function recordStabilityMetrics(float $stabilityTime, bool $accepted): void
    {
        $key = "stability_metrics_{$this->airportId}_{$this->camIndex}";
        $metrics = apcu_fetch($key);

        if (!$metrics) {
            $metrics = $this->loadStabilityMetricsFromDisk() ?? [
                'stability_times' => [],
                'accepted' => 0,
                'rejected' => 0,
                'last_updated' => time()
            ];
        }

        if ($accepted) {
            $metrics['accepted']++;
            $metrics['stability_times'][] = $stabilityTime;
            $metrics['stability_times'] = array_slice($metrics['stability_times'], -STABILITY_SAMPLES_TO_KEEP);
        } else {
            $metrics['rejected']++;
        }

        $metrics['last_updated'] = time();
        apcu_store($key, $metrics, 7 * 86400);

        // Periodically persist to disk
        static $counter = 0;
        if (++$counter % 5 === 0) {
            $this->saveStabilityMetricsToDisk($metrics);
        }
    }

    /**
     * Load stability metrics from disk
     */
    private function loadStabilityMetricsFromDisk(): ?array
    {
        $cacheDir = CACHE_BASE_DIR . '/stability_metrics';
        $file = $cacheDir . "/{$this->airportId}_{$this->camIndex}.json";

        if (!file_exists($file)) {
            return null;
        }

        $data = @json_decode(@file_get_contents($file), true);
        if (!is_array($data) || !isset($data['stability_times'])) {
            return null;
        }

        return $data;
    }

    /**
     * Save stability metrics to disk
     */
    private function saveStabilityMetricsToDisk(array $metrics): void
    {
        $cacheDir = CACHE_BASE_DIR . '/stability_metrics';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $file = $cacheDir . "/{$this->airportId}_{$this->camIndex}.json";
        $tmpFile = $file . '.tmp.' . getmypid();

        if (@file_put_contents($tmpFile, json_encode($metrics, JSON_PRETTY_PRINT)) !== false) {
            @rename($tmpFile, $file);
        }
    }

    /**
     * Get files ordered for batch processing
     * 
     * Returns newest file first (for pilot safety - current conditions),
     * then oldest-to-newest (to clear backlog before files age out).
     * 
     * @param int $limit Maximum files to return
     * @return array{files: string[], total_pending: int}
     */
    public function getOrderedFiles(int $limit = PUSH_BATCH_LIMIT): array
    {
        if (empty($this->uploadDirs)) {
            return ['files' => [], 'total_pending' => 0];
        }

        // Collect files from all upload directories (FTP and SFTP)
        $files = [];
        foreach ($this->uploadDirs as $protocol => $dir) {
            if (is_dir($dir)) {
                $dirFiles = $this->recursiveGlobImages($dir);
                $files = array_merge($files, $dirFiles);
            }
        }
        
        if (empty($files)) {
            return ['files' => [], 'total_pending' => 0];
        }

        $maxFileAge = $this->getUploadFileMaxAge();
        $now = time();

        // Filter and annotate with mtime, delete abandoned files
        $validFiles = [];
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime === false) {
                continue;
            }

            $fileAge = $now - $mtime;

            // Too new (still being written) - skip for now
            if ($fileAge < 3) {
                continue;
            }

            // Too old - delete as abandoned upload
            if ($fileAge > $maxFileAge) {
                aviationwx_log('warning', 'Push upload too old, deleting', [
                    'file' => basename($file),
                    'age' => $fileAge,
                    'max_age' => $maxFileAge,
                    'airport' => $this->airportId,
                    'cam' => $this->camIndex
                ], 'app');
                @unlink($file);
                continue;
            }

            $validFiles[] = ['path' => $file, 'mtime' => $mtime];
        }

        $totalPending = count($validFiles);

        if ($totalPending === 0) {
            return ['files' => [], 'total_pending' => 0];
        }

        // Sort by mtime ascending (oldest first)
        usort($validFiles, fn($a, $b) => $a['mtime'] <=> $b['mtime']);

        // Extract newest (last element after ascending sort)
        $newest = array_pop($validFiles);

        // Build result: newest first, then oldest-to-newest
        $result = [$newest['path']];

        foreach ($validFiles as $file) {
            if (count($result) >= $limit) {
                break;
            }
            $result[] = $file['path'];
        }

        return ['files' => $result, 'total_pending' => $totalPending];
    }

    /**
     * Acquire and validate a specific file
     * 
     * Used for batch processing where files are pre-selected by getOrderedFiles().
     * Performs stability check, validation, EXIF normalization, and moves to staging.
     * 
     * @param string $filePath Path to file to process
     * @return AcquisitionResult
     */
    public function acquireFile(string $filePath): AcquisitionResult
    {
        if (!file_exists($filePath)) {
            return AcquisitionResult::skip('file_missing', 'push');
        }

        // Check file age FIRST (fail fast before expensive operations)
        $mtime = @filemtime($filePath);
        if ($mtime === false) {
            return AcquisitionResult::skip('file_stat_failed', 'push');
        }

        $fileAge = time() - $mtime;
        $maxFileAge = $this->getUploadFileMaxAge();

        // Too new - still being written
        if ($fileAge < 3) {
            return AcquisitionResult::skip('file_too_new', 'push', ['age' => $fileAge]);
        }

        // Too old - abandoned upload, delete it
        if ($fileAge > $maxFileAge) {
            aviationwx_log('warning', 'Push upload too old, deleting', [
                'file' => basename($filePath),
                'age' => $fileAge,
                'max_age' => $maxFileAge,
                'airport' => $this->airportId,
                'cam' => $this->camIndex
            ], 'app');
            @unlink($filePath);
            return AcquisitionResult::skip('file_too_old', 'push', ['age' => $fileAge, 'max_age' => $maxFileAge]);
        }

        // Stability check - ensure file is not still being written
        $stabilityTime = 0.0;
        $stabilityTimeout = $this->getStabilityCheckTimeout();
        $requiredStableChecks = $this->getRequiredStableChecks();

        if (!$this->isFileStable($filePath, $requiredStableChecks, $stabilityTimeout, $stabilityTime)) {
            return AcquisitionResult::skip('file_unstable', 'push', ['stability_time' => $stabilityTime]);
        }

        // Ensure EXIF exists BEFORE validation (adds from filename timestamp if missing)
        // Must happen before validateUploadedImage() which checks EXIF timestamp
        $timezone = $this->getTimezone();
        $context = ['airport_id' => $this->airportId, 'cam_index' => $this->camIndex, 'source_type' => 'push'];
        if (!ensureImageHasExif($filePath, null, $timezone, $context)) {
            require_once __DIR__ . '/webcam-image-metrics.php';
            trackWebcamImageRejected($this->airportId, $this->camIndex, 'no_exif_timestamp');
            $this->recordStabilityMetrics($stabilityTime, false);
            return AcquisitionResult::failure('no_exif_timestamp', 'push');
        }

        // Validate image content (including EXIF timestamp)
        $validationResult = $this->validateUploadedImage($filePath);
        if (!$validationResult['valid']) {
            $this->recordStabilityMetrics($validationResult['stability_time'] ?? 0, false);
            return AcquisitionResult::failure($validationResult['reason'], 'push', $validationResult);
        }

        // Normalize EXIF timestamp to UTC
        if (!normalizeExifToUtc($filePath, $this->airportId, $this->camIndex, $timezone)) {
            require_once __DIR__ . '/webcam-image-metrics.php';
            trackWebcamImageRejected($this->airportId, $this->camIndex, 'invalid_exif_timestamp');
            $this->recordStabilityMetrics($validationResult['stability_time'] ?? 0, false);
            return AcquisitionResult::failure('invalid_exif_timestamp', 'push');
        }

        // Get timestamp from EXIF
        $timestamp = getSourceCaptureTime($filePath);
        if ($timestamp <= 0) {
            $timestamp = time();
        }

        // Cross-validate EXIF against upload mtime (timestamp drift check)
        $uploadMtime = @filemtime($filePath);
        if ($uploadMtime !== false) {
            $drift = abs($uploadMtime - $timestamp);
            if ($drift > 7200) { // > 2 hours drift
                require_once __DIR__ . '/webcam-rejection-logger.php';
                logWebcamRejection($this->airportId, $this->camIndex, 'timestamp_drift',
                    sprintf('EXIF differs from upload time by %d seconds', $drift));
                $this->recordStabilityMetrics($validationResult['stability_time'] ?? 0, false);
                return AcquisitionResult::failure('timestamp_drift', 'push', ['drift_seconds' => $drift]);
            }
        }

        // Move to staging
        $stagingPath = $this->getStagingPath();
        if (!@rename($filePath, $stagingPath)) {
            // Try copy + delete as fallback (cross-filesystem)
            if (!@copy($filePath, $stagingPath)) {
                $this->recordFailure('move_failed', 'transient');
                return AcquisitionResult::failure('move_failed', 'push');
            }
            @unlink($filePath);
        }

        // Record success metrics
        $this->recordStabilityMetrics($stabilityTime, true);
        $this->recordSuccess();

        return AcquisitionResult::success($stagingPath, $timestamp, 'push', [
            'original_file' => basename($filePath),
            'stability_time' => $stabilityTime
        ]);
    }
}

/**
 * Factory for creating acquisition strategies
 */
class AcquisitionStrategyFactory
{
    /**
     * Create the appropriate acquisition strategy for a camera
     * 
     * @param string $airportId Airport identifier
     * @param int $camIndex Camera index
     * @param array $camConfig Camera configuration
     * @param array $airportConfig Airport configuration
     * @return AcquisitionStrategy
     */
    public static function create(
        string $airportId,
        int $camIndex,
        array $camConfig,
        array $airportConfig
    ): AcquisitionStrategy {
        // Check if this is a push camera
        $isPush = (isset($camConfig['type']) && $camConfig['type'] === 'push') 
            || isset($camConfig['push_config']);

        if ($isPush) {
            return new PushAcquisitionStrategy($airportId, $camIndex, $camConfig, $airportConfig);
        }

        return new PullAcquisitionStrategy($airportId, $camIndex, $camConfig, $airportConfig);
    }
}
