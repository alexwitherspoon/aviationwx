<?php
/**
 * Pull Webcam Metadata Storage
 *
 * Stores ETag and content checksum for pull cameras (static URL, federated).
 * Enables HTTP conditional requests (304) and checksum-based skip when image unchanged.
 * Safety-critical: prevents misrepresenting image age when source has not updated.
 *
 * @package AviationWX
 */

require_once __DIR__ . '/cache-paths.php';

/**
 * Get path to pull metadata file for a camera
 *
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return string Full path to metadata file
 */
function getWebcamPullMetadataPath(string $airportId, int $camIndex): string
{
    return getWebcamCameraDir($airportId, $camIndex) . '/pull_metadata.json';
}

/**
 * Read pull metadata (ETag and checksum) for conditional/checksum optimization
 *
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return array{etag: string|null, checksum: string|null} Stored values or nulls if none
 */
function getWebcamPullMetadata(string $airportId, int $camIndex): array
{
    $path = getWebcamPullMetadataPath($airportId, $camIndex);
    if (!file_exists($path) || !is_readable($path)) {
        return ['etag' => null, 'checksum' => null];
    }

    $content = @file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return ['etag' => null, 'checksum' => null];
    }

    $data = @json_decode($content, true);
    if (!is_array($data)) {
        return ['etag' => null, 'checksum' => null];
    }

    return [
        'etag' => isset($data['etag']) && is_string($data['etag']) ? $data['etag'] : null,
        'checksum' => isset($data['checksum']) && is_string($data['checksum']) ? $data['checksum'] : null,
    ];
}

/**
 * Save pull metadata after successful acquisition
 *
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param string|null $etag ETag from response header (null to clear)
 * @param string|null $checksum SHA-256 of raw image bytes (null to clear)
 * @return bool True on success
 */
function saveWebcamPullMetadata(string $airportId, int $camIndex, ?string $etag, ?string $checksum): bool
{
    $path = getWebcamPullMetadataPath($airportId, $camIndex);
    $dir = dirname($path);

    if (!is_dir($dir)) {
        if (@mkdir($dir, 0755, true) === false) {
            return false;
        }
    }

    $data = [
        'etag' => $etag,
        'checksum' => $checksum,
    ];

    $tmpPath = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmpPath, json_encode($data, JSON_PRETTY_PRINT)) === false) {
        return false;
    }

    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        return false;
    }

    return true;
}

/**
 * Compute SHA-256 checksum of raw image bytes
 *
 * Used to verify image content unchanged when server returns 200 instead of 304.
 *
 * @param string $data Raw image bytes
 * @return string 64-character hex SHA-256 hash
 */
function computeWebcamContentChecksum(string $data): string
{
    return hash('sha256', $data);
}
