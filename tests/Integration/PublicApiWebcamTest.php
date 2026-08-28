<?php
/**
 * Integration Tests for Public API Webcam Endpoints
 * 
 * Tests webcam image endpoint including metadata and format handling.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';

class PublicApiWebcamTest extends TestCase
{
    private static $apiBaseUrl;
    private static $testAirport = 'wcfx';
    private static $testCam = 0;
    private static bool $fixtureReady = false;
    
    public static function setUpBeforeClass(): void
    {
        self::$apiBaseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';

        $testCacheDir = getenv('TEST_CACHE_DIR');
        if (!is_string($testCacheDir) || $testCacheDir === '') {
            return;
        }
        if (rtrim($testCacheDir, '/') !== CACHE_BASE_DIR) {
            return;
        }

        self::$fixtureReady = true;
        self::removeFixtureTree();
        self::createTestImages();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$fixtureReady) {
            $this->markTestSkipped('TEST_CACHE_DIR must identify the isolated HTTP test cache');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$fixtureReady) {
            self::removeFixtureTree();
        }
        parent::tearDownAfterClass();
    }

    private static function removeFixtureTree(?string $dir = null): void
    {
        $dir ??= CACHE_WEBCAMS_DIR . '/' . self::$testAirport;
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                self::removeFixtureTree($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Create test images for testing (uses date/hour subdir structure)
     */
    private static function createTestImages(): void
    {
        $cacheDir = getWebcamCameraDir(self::$testAirport, self::$testCam);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $timestamp = time();
        $framesDir = getWebcamFramesDir(self::$testAirport, self::$testCam, $timestamp);
        if (!is_dir($framesDir)) {
            mkdir($framesDir, 0755, true);
        }
        $subdir = getWebcamFramesSubdir($timestamp);
        
        // Create original JPG in date/hour subdir
        $originalJpg = $framesDir . '/' . $timestamp . '_original.jpg';
        $img = imagecreatetruecolor(800, 600);
        $blue = imagecolorallocate($img, 0, 0, 255);
        imagefill($img, 0, 0, $blue);
        imagejpeg($img, $originalJpg);
        
        // Create original.jpg symlink (required by API)
        $originalSymlink = $cacheDir . '/original.jpg';
        if (file_exists($originalSymlink)) {
            @unlink($originalSymlink);
        }
        @symlink($subdir . '/' . $timestamp . '_original.jpg', $originalSymlink);
        
        // Create sized variants in WebP (config has 1080, 720, 360)
        $variants = [1080 => [800, 600], 720 => [720, 540], 360 => [360, 270]];
        foreach ($variants as $height => $dims) {
            $webpFile = $framesDir . '/' . $timestamp . '_' . $height . '.webp';
            if (function_exists('imagewebp')) {
                $img = imagecreatetruecolor($dims[0], $dims[1]);
                $red = imagecolorallocate($img, 255, 0, 0);
                imagefill($img, 0, 0, $red);
                imagewebp($img, $webpFile);
            }
        }
    }
    
    /**
     * Make an API request
     */
    private function apiRequest(string $endpoint, array $headers = []): array
    {
        // Small delay to avoid rate limiting in tests
        usleep(100000); // 100ms
        
        $url = self::$apiBaseUrl;
        $url .= str_starts_with($endpoint, '/api/') ? $endpoint : '/api/v1' . $endpoint;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
            'Accept: application/json'
        ], $headers));
        curl_setopt($ch, CURLOPT_HEADER, true);
        
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Parse response headers
        $headers = [];
        foreach (explode("\r\n", $headerStr) as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }
        
        return [
            'status' => $httpCode,
            'headers' => $headers,
            'content_type' => $contentType,
            'body' => $body,
            'json' => json_decode($body, true)
        ];
    }
    
    /**
     * Test metadata endpoint returns correct structure
     */
    public function testMetadataEndpoint_ReturnsCorrectStructure(): void
    {
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?metadata=1');
        
        if ($response['status'] === 0) {
            $this->markTestSkipped('Web server not available at ' . self::$apiBaseUrl);
        }
        
        // Should return 200 OK
        $this->assertEquals(200, $response['status'], 'Metadata endpoint should return 200');
        $this->assertStringContainsString('application/json', $response['content_type'], 'Should return JSON');
        
        // Check JSON structure
        $data = $response['json'];
        $this->assertIsArray($data, 'Response should be JSON array');
        $this->assertTrue($data['success'] ?? false, 'Success should be true');
        
        // Check data fields
        $this->assertArrayHasKey('data', $data, 'Should have data field');
        $this->assertArrayHasKey('timestamp', $data['data'], 'Should have timestamp');
        $this->assertArrayHasKey('timestamp_iso', $data['data'], 'Should have timestamp_iso');
        $this->assertArrayHasKey('age_seconds', $data['data'], 'Should have age_seconds');
        $this->assertArrayHasKey('stale', $data['data'], 'Should have stale flag');
        $this->assertArrayHasKey('stale_failclosed_seconds', $data['data'], 'Should have stale_failclosed_seconds');
        $this->assertIsInt($data['data']['age_seconds']);
        $this->assertGreaterThanOrEqual(0, $data['data']['age_seconds']);
        $this->assertIsBool($data['data']['stale']);
        $this->assertIsInt($data['data']['stale_failclosed_seconds']);
        $this->assertArrayHasKey('formats', $data['data'], 'Should have formats');
        $this->assertArrayHasKey('recommended_sizes', $data['data'], 'Should have recommended_sizes');
        $this->assertArrayHasKey('urls', $data['data'], 'Should have urls');
        
        // Check meta fields
        $this->assertArrayHasKey('meta', $data, 'Should have meta field');
        $this->assertArrayHasKey('airport_id', $data['meta'], 'Meta should have airport_id');
        $this->assertArrayHasKey('cam_index', $data['meta'], 'Meta should have cam_index');
        $this->assertArrayHasKey('refresh_seconds', $data['meta'], 'Meta should have refresh_seconds');
        $this->assertArrayHasKey('variant_heights', $data['meta'], 'Meta should have variant_heights');

        // age_seconds/stale are time-relative and must never be served from a
        // shared cache with outdated values; the metadata response must be no-store.
        $cacheControl = $response['headers']['Cache-Control'] ?? '';
        $this->assertStringContainsString('no-store', $cacheControl, 'Metadata should be no-store');
    }

    public function testImage_OverAgeOriginal_IsServed200WithStaleHeaders(): void
    {
        // Install a servable original captured past the fail-closed threshold
        // (~4h ago, default is 3h) and point the current symlink at it, so the
        // native-current request resolves a stale frame.
        $cacheDir = getWebcamCameraDir(self::$testAirport, self::$testCam);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $oldTs = time() - 14400;
        $framesDir = getWebcamFramesDir(self::$testAirport, self::$testCam, $oldTs);
        if (!is_dir($framesDir)) {
            mkdir($framesDir, 0755, true);
        }
        $subdir = getWebcamFramesSubdir($oldTs);
        $filename = $oldTs . '_original.jpg';
        $path = $framesDir . '/' . $filename;
        $img = imagecreatetruecolor(32, 24);
        imagejpeg($img, $path);

        self::unlinkOriginalFormatSymlinks();
        symlink($subdir . '/' . $filename, $cacheDir . '/original.jpg');

        try {
            $response = $this->apiRequest(
                // No fmt so this hits the native-original path (explicit fmt on the
                // original is rejected) and reaches the stale-frame branch.
                '/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image'
            );

            // A stale webcam is not a server error: serve the last known frame as 200,
            // but flag it and refuse to cache it as current.
            $this->assertSame(200, $response['status']);
            $cacheControl = $response['headers']['Cache-Control'] ?? '';
            $this->assertStringContainsString('no-store', $cacheControl);
            $this->assertArrayHasKey('Warning', $response['headers']);
            $this->assertMatchesRegularExpression('/110/', $response['headers']['Warning']);
            // Last-Modified reflects the capture timestamp, not the fresh file mtime.
            $this->assertArrayHasKey('Last-Modified', $response['headers']);
            $expectedLastModified = gmdate('D, d M Y H:i:s', $oldTs) . ' GMT';
            $this->assertSame($expectedLastModified, $response['headers']['Last-Modified']);

            // The download branch must apply the same stale policy for an over-age
            // frame: attachment, no-store, Warning 110, and capture-time Last-Modified.
            $download = $this->apiRequest(
                '/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?download=1'
            );
            $this->assertSame(200, $download['status']);
            $this->assertStringContainsString('attachment', $download['headers']['Content-Disposition'] ?? '');
            $this->assertStringContainsString('no-store', $download['headers']['Cache-Control'] ?? '');
            $this->assertMatchesRegularExpression('/110/', $download['headers']['Warning'] ?? '');
            $this->assertSame($expectedLastModified, $download['headers']['Last-Modified'] ?? '');

            // The metadata endpoint must report the same frame as stale so the two
            // surfaces cannot diverge for clients consuming the age fields.
            $meta = $this->apiRequest(
                '/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?metadata=1'
            );
            $this->assertSame(200, $meta['status']);
            $metaData = $meta['json']['data'] ?? [];
            $this->assertTrue($metaData['stale'] ?? false, 'Metadata should report stale=true');
            $this->assertGreaterThanOrEqual(
                $metaData['stale_failclosed_seconds'] ?? -1,
                $metaData['age_seconds'] ?? 0,
                'age_seconds should exceed the fail-closed threshold for a stale frame'
            );
        } finally {
            // Restore the default current original so later tests don't see 503
            // from the removed stale fixture (fixture is created once per class).
            self::restoreDefaultOriginalFixture($path);
        }
    }
    
    /**
     * Test metadata endpoint includes available formats
     */
    public function testMetadataEndpoint_IncludesAvailableFormats(): void
    {
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?metadata=1');
        
        if ($response['status'] === 0) {
            $this->markTestSkipped('Web server not available at ' . self::$apiBaseUrl);
        }
        
        $data = $response['json'];
        $formats = $data['data']['formats'] ?? [];
        
        $this->assertNotEmpty($formats, 'Formats should not be empty');
        
        // Original should always have at least JPG
        $this->assertArrayHasKey('original', $formats, 'Should have original variant');
        $this->assertContains('jpg', $formats['original'], 'Original should have JPG format');
    }
    
    /**
     * Test metadata endpoint provides working URLs
     */
    public function testMetadataEndpoint_ProvidesWorkingUrls(): void
    {
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?metadata=1');
        
        if ($response['status'] === 0) {
            $this->markTestSkipped('Web server not available at ' . self::$apiBaseUrl);
        }
        
        $data = $response['json'];
        $urls = $data['data']['urls'] ?? [];
        
        $this->assertNotEmpty($urls, 'URLs should not be empty');
        
        // Test at least one URL works
        foreach ($urls as $key => $url) {
            // Test the URL
            $imageResponse = $this->apiRequest($url);
            
            // Should return image or valid error
            $this->assertContains($imageResponse['status'], [200, 400, 503], 
                "URL $key should return valid status code");
            
            // If 200, should be an image
            if ($imageResponse['status'] === 200) {
                $this->assertStringContainsString('image/', $imageResponse['content_type'],
                    "URL $key should return image content type");
            }
            
            // Only test first URL to avoid rate limiting
            break;
        }
    }
    
    public function testExplicitFormatRequest_DisabledFormat_ReturnsError(): void
    {
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?fmt=webp');
        
        if ($response['status'] === 0) {
            $this->markTestSkipped('Web server not available at ' . self::$apiBaseUrl);
        }
        
        $this->assertEquals(400, $response['status'], 
            'Explicit request for a disabled generation format should return 400');
        
        $data = $response['json'];
        $this->assertFalse($data['success'] ?? true, 'Success should be false');
        $this->assertArrayHasKey('error', $data, 'Should have error field');
        $this->assertArrayHasKey('message', $data['error'], 'Error should have message');
        
        $message = $data['error']['message'];
        $this->assertStringContainsString('not enabled', $message,
            'Error message should mention the format is not enabled');
    }

    public function testExplicitFormatRequest_ArrayValue_ReturnsCleanJsonError(): void
    {
        $response = $this->apiRequest(
            '/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?fmt[]=webp&size=720'
        );

        if ($response['status'] === 0) {
            $this->markTestSkipped('Web server not available at ' . self::$apiBaseUrl);
        }

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('application/json', $response['content_type']);
        $this->assertIsArray($response['json'], 'Response body should be clean JSON without PHP warnings');
        $this->assertSame(
            'fmt must be a single value',
            $response['json']['error']['message'] ?? null
        );
    }

    public function testSizeRequest_PartialValue_ReturnsCleanJsonError(): void
    {
        $response = $this->apiRequest(
            '/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?size=720px'
        );

        $this->assertSame(400, $response['status']);
        $this->assertSame(
            'size must be original or an integer from 1 to 5000',
            $response['json']['error']['message'] ?? null
        );
    }

    public function testSizeRequest_MissingExactHeight_DoesNotReturnOriginal(): void
    {
        $response = $this->apiRequest(
            '/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?size=719'
        );

        $this->assertSame(503, $response['status']);
        $this->assertStringContainsString('application/json', $response['content_type']);
    }

    public function testHistoryTimestamp_ArrayValue_ReturnsCleanJsonError(): void
    {
        $response = $this->apiRequest(
            '/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/history?ts[]=1704067200'
        );

        $this->assertSame(400, $response['status']);
        $this->assertSame('ts must be a single value', $response['json']['error']['message'] ?? null);
    }

    public function testHistorySize_MissingExactHeight_DoesNotReturnOriginal(): void
    {
        $installedPath = null;
        try {
            $installedPath = self::installNativeOriginal('png');
            $timestamp = (int)explode('_', basename($installedPath), 2)[0];
            $response = $this->apiRequest(
                '/airports/' . self::$testAirport . '/webcams/' . self::$testCam .
                '/history?ts=' . $timestamp . '&size=719'
            );

            $this->assertSame(404, $response['status']);
            $this->assertStringContainsString('application/json', $response['content_type']);
        } finally {
            self::restoreDefaultOriginalFixture($installedPath);
        }
    }
    
    /**
     * Test explicit format request with size parameter works
     */
    public function testExplicitFormatRequest_WithSize_ReturnsImage(): void
    {
        // First get metadata to find an available WebP variant
        $metaResponse = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?metadata=1');
        $formats = $metaResponse['json']['data']['formats'] ?? [];
        
        // Find a variant that has WebP
        $webpSize = null;
        foreach ($formats as $variant => $variantFormats) {
            if ($variant !== 'original' && in_array('webp', $variantFormats)) {
                $webpSize = $variant;
                break;
            }
        }
        
        if ($webpSize === null) {
            $this->markTestSkipped('No WebP variants available for testing');
        }
        
        // Request the WebP variant
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?fmt=webp&size=' . $webpSize);
        
        // Should return 200 with WebP image
        $this->assertEquals(200, $response['status'], 
            'Explicit WebP request with size should return 200');
        $this->assertStringContainsString('image/webp', $response['content_type'],
            'Should return WebP content type');
    }
    
    /**
     * Test default request (no fmt parameter) returns the native original
     */
    public function testDefaultRequest_NoFmtParameter_ReturnsNativeOriginal(): void
    {
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image');
        
        if ($response['status'] === 0) {
            $this->markTestSkipped('Web server not available at ' . self::$apiBaseUrl);
        }
        
        $this->assertContains($response['status'], [200, 503], 
            'Default request should return 200 or 503');
        
        if ($response['status'] === 200) {
            $this->assertMatchesRegularExpression(
                '#image/(jpeg|png|webp)#',
                $response['content_type'],
                'Default request should return the native original content type'
            );
        }
    }

    public function testDefaultRequest_PngOriginal_ReturnsPngContentTypeAndMagic(): void
    {
        $this->assertNativeOriginalHttpResponse('png', 'image/png', "\x89PNG\r\n\x1a\n");
    }

    public function testDefaultRequest_WebpOriginal_ReturnsWebpContentTypeAndMagic(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is not available');
        }
        $this->assertNativeOriginalHttpResponse('webp', 'image/webp', 'RIFF');
    }

    public function testDownload_PngOriginal_ReturnsNativeAttachmentWithLiveCachePolicy(): void
    {
        $installedPath = null;
        try {
            $installedPath = self::installNativeOriginal('png');
            $response = $this->apiRequest(
                '/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?download=1'
            );

            $this->assertSame(200, $response['status']);
            $this->assertStringContainsString('image/png', $response['content_type']);
            $this->assertStringContainsString(
                'attachment;',
                $response['headers']['Content-Disposition'] ?? ''
            );
            $this->assertStringContainsString(
                '.png"',
                $response['headers']['Content-Disposition'] ?? ''
            );
            $cacheControl = $response['headers']['Cache-Control'] ?? '';
            $this->assertStringContainsString('max-age=60', $cacheControl);
            $this->assertStringNotContainsString('immutable', $cacheControl);
            $this->assertSame("\x89PNG\r\n\x1a\n", substr($response['body'], 0, 8));
            $this->assertIntegrityHeadersMatchBody($response);

            $etag = $response['headers']['ETag'] ?? null;
            $this->assertNotNull($etag);
            $conditional = $this->apiRequest(
                '/airports/' . self::$testAirport . '/webcams/' . self::$testCam .
                '/image?download=1',
                ['If-None-Match: ' . $etag]
            );
            $this->assertSame(304, $conditional['status']);
            $this->assertStringContainsString(
                'max-age=60',
                $conditional['headers']['Cache-Control'] ?? ''
            );
            $this->assertStringNotContainsString(
                'immutable',
                $conditional['headers']['Cache-Control'] ?? ''
            );
        } finally {
            self::restoreDefaultOriginalFixture($installedPath);
        }
    }

    public function testHistory_PngOriginal_ReturnsNativeImageWithImmutableCachePolicy(): void
    {
        $installedPath = null;
        try {
            $installedPath = self::installNativeOriginal('png');
            $timestamp = (int)explode('_', basename($installedPath), 2)[0];
            $response = $this->apiRequest(
                '/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/history?ts=' . $timestamp
            );

            $this->assertSame(200, $response['status']);
            $this->assertStringContainsString('image/png', $response['content_type']);
            $this->assertStringContainsString(
                'immutable',
                $response['headers']['Cache-Control'] ?? ''
            );
            $this->assertSame("\x89PNG\r\n\x1a\n", substr($response['body'], 0, 8));
            $this->assertIntegrityHeadersMatchBody($response);
            $this->assertConditionalImageHeaders($response, (
                '/airports/' . self::$testAirport . '/webcams/' . self::$testCam .
                '/history?ts=' . $timestamp
            ), true);
        } finally {
            self::restoreDefaultOriginalFixture($installedPath);
        }
    }

    public function testLegacyHistory_PngOriginalReturnsNativeImage(): void
    {
        $installedPath = null;
        try {
            $installedPath = self::installNativeOriginal('png');
            $timestamp = (int)explode('_', basename($installedPath), 2)[0];
            $response = $this->apiRequest(
                '/api/webcam-history.php?id=' . self::$testAirport .
                '&cam=' . self::$testCam .
                '&ts=' . $timestamp .
                '&fmt=png&size=original'
            );

            $this->assertSame(200, $response['status']);
            $this->assertStringContainsString('image/png', $response['content_type']);
            $this->assertSame("\x89PNG\r\n\x1a\n", substr($response['body'], 0, 8));
            $this->assertConditionalImageHeaders(
                $response,
                '/api/webcam-history.php?id=' . self::$testAirport .
                '&cam=' . self::$testCam .
                '&ts=' . $timestamp .
                '&fmt=png&size=original',
                true
            );
        } finally {
            self::restoreDefaultOriginalFixture($installedPath);
        }
    }

    public function testLegacyTimestamp_PngOriginalReturnsNativeImageWhenFormatOmitted(): void
    {
        $installedPath = null;
        try {
            $installedPath = self::installNativeOriginal('png');
            $timestamp = (int)explode('_', basename($installedPath), 2)[0];
            $response = $this->apiRequest(
                '/api/webcam.php?id=' . self::$testAirport .
                '&cam=' . self::$testCam .
                '&ts=' . $timestamp .
                '&size=original'
            );

            $this->assertSame(200, $response['status']);
            $this->assertStringContainsString('image/png', $response['content_type']);
            $this->assertSame("\x89PNG\r\n\x1a\n", substr($response['body'], 0, 8));
            $this->assertConditionalImageHeaders(
                $response,
                '/api/webcam.php?id=' . self::$testAirport .
                '&cam=' . self::$testCam .
                '&ts=' . $timestamp .
                '&size=original',
                true,
                false
            );
        } finally {
            self::restoreDefaultOriginalFixture($installedPath);
        }
    }

    public function testLegacyCurrent_PngOriginalReturnsNativeImageWhenFormatOmitted(): void
    {
        $installedPath = null;
        try {
            $installedPath = self::installNativeOriginal('png');
            $response = $this->apiRequest(
                '/api/webcam.php?id=' . self::$testAirport .
                '&cam=' . self::$testCam .
                '&size=original'
            );

            $this->assertSame(200, $response['status']);
            $this->assertStringContainsString('image/png', $response['content_type']);
            $this->assertSame("\x89PNG\r\n\x1a\n", substr($response['body'], 0, 8));
            $this->assertStringContainsString(
                '.png"',
                $response['headers']['Content-Disposition'] ?? ''
            );
        } finally {
            self::restoreDefaultOriginalFixture($installedPath);
        }
    }

    public function testLegacyCurrent_ArrayFormatReturnsCleanJsonError(): void
    {
        $response = $this->apiRequest(
            '/api/webcam.php?id=' . self::$testAirport .
            '&cam=' . self::$testCam .
            '&fmt%5B%5D=png'
        );

        $this->assertSame(400, $response['status']);
        $this->assertSame('fmt must be a single value', $response['json']['error'] ?? null);
    }

    public function testLegacyHistory_JpegAliasReturnsJpegImage(): void
    {
        $path = self::installTimestampedOriginal('jpeg', 'jpg');
        try {
            $timestamp = (int)explode('_', basename($path), 2)[0];
            $response = $this->apiRequest(
                '/api/webcam-history.php?id=' . self::$testAirport .
                '&cam=' . self::$testCam .
                '&ts=' . $timestamp .
                '&fmt=jpg&size=original'
            );

            $this->assertSame(200, $response['status']);
            $this->assertStringContainsString('image/jpeg', $response['content_type']);
            $this->assertSame("\xff\xd8\xff", substr($response['body'], 0, 3));
        } finally {
            self::removeInstalledNativeOriginal($path);
        }
    }

    public function testLegacyHistory_MislabeledPngRejectsExplicitFormat(): void
    {
        $path = self::installTimestampedOriginal('png', 'jpg');
        try {
            $timestamp = (int)explode('_', basename($path), 2)[0];
            $response = $this->apiRequest(
                '/api/webcam-history.php?id=' . self::$testAirport .
                '&cam=' . self::$testCam .
                '&ts=' . $timestamp .
                '&fmt=png&size=original'
            );

            $this->assertSame(400, $response['status']);
            $this->assertSame(
                'jpg',
                $response['json']['actual_format'] ?? null
            );
        } finally {
            self::removeInstalledNativeOriginal($path);
        }
    }

    public function testLegacyHistory_ArrayFormatReturnsCleanJsonError(): void
    {
        $response = $this->apiRequest(
            '/api/webcam-history.php?id=' . self::$testAirport .
            '&cam=' . self::$testCam .
            '&fmt%5B%5D=png'
        );

        $this->assertSame(400, $response['status']);
        $this->assertSame('fmt must be a single value', $response['json']['error'] ?? null);
    }

    public function testLegacyHistory_UnsupportedFormatReturnsCleanJsonError(): void
    {
        $response = $this->apiRequest(
            '/api/webcam-history.php?id=' . self::$testAirport .
            '&cam=' . self::$testCam .
            '&fmt=gif'
        );

        $this->assertSame(400, $response['status']);
        $this->assertSame('Unsupported image format', $response['json']['error'] ?? null);
    }

    public function testLegacyHistoryManifest_ClassifiesOriginalFromBytes(): void
    {
        $path = self::installTimestampedOriginal('png', 'jpg');
        $timestamp = (int)explode('_', basename($path), 2)[0];
        $variantPath = dirname($path) . '/' . $timestamp . '_720.png';
        $img = imagecreatetruecolor(720, 540);
        imagejpeg($img, $variantPath);
        try {
            $response = $this->apiRequest(
                '/api/webcam-history.php?id=' . self::$testAirport .
                '&cam=' . self::$testCam
            );
            $frames = $response['json']['frames'] ?? [];
            $matching = array_values(array_filter(
                $frames,
                static fn(array $frame): bool => ($frame['timestamp'] ?? null) === $timestamp
            ));

            $this->assertCount(1, $matching);
            $this->assertContains('jpg', $matching[0]['formats']);
            $this->assertNotContains('png', $matching[0]['formats']);
            $this->assertArrayNotHasKey('720', $matching[0]['variants']);
        } finally {
            if (is_file($variantPath)) {
                unlink($variantPath);
            }
            self::removeInstalledNativeOriginal($path);
        }
    }

    public function testLegacyHistory_MissingTimestampedSizeDoesNotServeStagingFile(): void
    {
        $stagingPath = getWebcamCameraDir(self::$testAirport, self::$testCam) .
            '/staging_720_jpg.tmp';
        $img = imagecreatetruecolor(720, 540);
        imagejpeg($img, $stagingPath);

        try {
            $response = $this->apiRequest(
                '/api/webcam-history.php?id=' . self::$testAirport .
                '&cam=' . self::$testCam .
                '&ts=' . (time() - 600) .
                '&fmt=jpg&size=720'
            );

            $this->assertSame(404, $response['status']);
        } finally {
            if (is_file($stagingPath)) {
                unlink($stagingPath);
            }
        }
    }

    public function testLegacyDownload_LatestUsesLiveCachePolicy(): void
    {
        $response = $this->apiRequest(
            '/api/webcam.php?id=' . self::$testAirport . '&cam=' . self::$testCam . '&download=1'
        );

        $this->assertSame(200, $response['status']);
        $cacheControl = $response['headers']['Cache-Control'] ?? '';
        $this->assertStringContainsString('max-age=60', $cacheControl);
        $this->assertStringNotContainsString('immutable', $cacheControl);
    }

    public function testLegacyDownload_TimestampUsesImmutableCachePolicy(): void
    {
        $installedPath = null;
        try {
            $installedPath = self::installNativeOriginal('png');
            $timestamp = (int)explode('_', basename($installedPath), 2)[0];
            $response = $this->apiRequest(
                '/api/webcam.php?id=' . self::$testAirport .
                '&cam=' . self::$testCam .
                '&ts=' . $timestamp .
                '&download=1'
            );

            $this->assertSame(200, $response['status']);
            $this->assertStringContainsString(
                'immutable',
                $response['headers']['Cache-Control'] ?? ''
            );
        } finally {
            self::restoreDefaultOriginalFixture($installedPath);
        }
    }

    public function testTransform_CorruptNewestOriginal_UsesNewestServableCapture(): void
    {
        $timestamp = time() + 120;
        $framesDir = getWebcamFramesDir(self::$testAirport, self::$testCam, $timestamp);
        if (!is_dir($framesDir)) {
            mkdir($framesDir, 0755, true);
        }
        $corruptPath = $framesDir . '/' . $timestamp . '_original.jpg';
        file_put_contents($corruptPath, 'not an image');

        try {
            $response = $this->apiRequest(
                '/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?width=320'
            );

            $this->assertSame(200, $response['status']);
            $dimensions = getimagesizefromstring($response['body']);
            $this->assertIsArray($dimensions);
            $this->assertSame(320, $dimensions[0]);
        } finally {
            self::removeInstalledNativeOriginal($corruptPath);
        }
    }

    public function testMetadata_CorruptNewestOriginal_UsesNewestServableCapture(): void
    {
        $path = '/airports/' . self::$testAirport . '/webcams/' . self::$testCam .
            '/image?metadata=1';
        $before = $this->apiRequest($path);
        $expectedTimestamp = $before['json']['data']['timestamp'] ?? null;
        $this->assertIsInt($expectedTimestamp);
        $timestamp = time() + 120;
        $framesDir = getWebcamFramesDir(self::$testAirport, self::$testCam, $timestamp);
        if (!is_dir($framesDir)) {
            mkdir($framesDir, 0755, true);
        }
        $corruptPath = $framesDir . '/' . $timestamp . '_original.jpg';
        file_put_contents($corruptPath, 'not an image');

        try {
            $response = $this->apiRequest($path);

            $this->assertSame(200, $response['status']);
            $this->assertSame(
                $expectedTimestamp,
                $response['json']['data']['timestamp'] ?? null
            );
        } finally {
            self::removeInstalledNativeOriginal($corruptPath);
        }
    }

    public function testLegacyMtime_CorruptNewestOriginal_UsesNewestServableCapture(): void
    {
        $before = $this->apiRequest(
            '/api/webcam.php?id=' . self::$testAirport .
            '&cam=' . self::$testCam .
            '&mtime=1'
        );
        $expectedTimestamp = $before['json']['timestamp'] ?? null;
        $this->assertIsInt($expectedTimestamp);

        $timestamp = time() + 120;
        $framesDir = getWebcamFramesDir(self::$testAirport, self::$testCam, $timestamp);
        if (!is_dir($framesDir)) {
            mkdir($framesDir, 0755, true);
        }
        $corruptPath = $framesDir . '/' . $timestamp . '_original.jpg';
        file_put_contents($corruptPath, 'not an image');

        try {
            $response = $this->apiRequest(
                '/api/webcam.php?id=' . self::$testAirport .
                '&cam=' . self::$testCam .
                '&mtime=1'
            );

            $this->assertSame(200, $response['status']);
            $this->assertSame($expectedTimestamp, $response['json']['timestamp'] ?? null);
        } finally {
            self::removeInstalledNativeOriginal($corruptPath);
        }
    }

    public function testSizeRequest_CorruptNewestOriginal_UsesNewestServableCapture(): void
    {
        $installedPath = self::installNativeOriginal('png');
        $validTimestamp = (int)explode('_', basename($installedPath), 2)[0];
        $variantPath = dirname($installedPath) . '/' . $validTimestamp . '_720.jpg';
        $variant = imagecreatetruecolor(1280, 720);
        imagejpeg($variant, $variantPath);

        $timestamp = time() + 120;
        $framesDir = getWebcamFramesDir(self::$testAirport, self::$testCam, $timestamp);
        if (!is_dir($framesDir)) {
            mkdir($framesDir, 0755, true);
        }
        $corruptPath = $framesDir . '/' . $timestamp . '_original.jpg';
        file_put_contents($corruptPath, 'not an image');

        try {
            $response = $this->apiRequest(
                '/airports/' . self::$testAirport . '/webcams/' . self::$testCam .
                '/image?size=720'
            );

            $this->assertSame(200, $response['status']);
            $dimensions = getimagesizefromstring($response['body']);
            $this->assertIsArray($dimensions);
            $this->assertSame(720, $dimensions[1]);
        } finally {
            self::removeInstalledNativeOriginal($corruptPath);
            if (is_file($variantPath)) {
                unlink($variantPath);
            }
            self::restoreDefaultOriginalFixture($installedPath);
        }
    }

    public function testSizeRequest_InvalidValueReturnsCleanJsonError(): void
    {
        $response = $this->apiRequest(
            '/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?size=bogus'
        );

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('application/json', $response['content_type']);
        $this->assertSame(
            'size must be original or an integer from 1 to 5000',
            $response['json']['error']['message'] ?? null
        );
    }

    public function testSizeRequest_WithDimensionReturnsCleanJsonError(): void
    {
        $response = $this->apiRequest(
            '/airports/' . self::$testAirport . '/webcams/' . self::$testCam .
            '/image?width=320&size=720'
        );

        $this->assertSame(400, $response['status']);
        $this->assertSame(
            'size cannot be combined with width or height',
            $response['json']['error']['message'] ?? null
        );
    }

    public function testSizeRequest_MissingVariantDoesNotReturnNativeOriginal(): void
    {
        $installedPath = null;
        try {
            $installedPath = self::installNativeOriginal('png');
            $response = $this->apiRequest(
                '/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?size=777'
            );

            $this->assertSame(503, $response['status']);
            $this->assertStringContainsString('application/json', $response['content_type']);
        } finally {
            self::restoreDefaultOriginalFixture($installedPath);
        }
    }

    public function testDownload_NoOriginalReturnsServiceUnavailable(): void
    {
        self::removeFixtureTree();
        try {
            $response = $this->apiRequest(
                '/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?download=1'
            );

            $this->assertSame(503, $response['status']);
        } finally {
            self::createTestImages();
        }
    }

    public function testOriginalFormatRequest_NoCaptureStillReturnsInvalidRequest(): void
    {
        self::removeFixtureTree();
        try {
            $response = $this->apiRequest(
                '/airports/' . self::$testAirport . '/webcams/' . self::$testCam .
                '/image?fmt=jpg'
            );

            $this->assertSame(400, $response['status']);
            $this->assertSame(
                'fmt applies to generated size variants. Omit fmt for the original.',
                $response['json']['error']['message'] ?? null
            );
        } finally {
            self::createTestImages();
        }
    }

    private function assertNativeOriginalHttpResponse(string $format, string $contentType, string $magicPrefix): void
    {
        $installedPath = null;
        try {
            $installedPath = self::installNativeOriginal($format);
            $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image');
            if ($response['status'] === 0) {
                $this->markTestSkipped('Web server not available at ' . self::$apiBaseUrl);
            }

            $this->assertSame(200, $response['status'], 'Isolated stack should serve the installed native original');
            $this->assertStringContainsString(
                $contentType,
                $response['content_type'],
                'Content-Type must match the native original'
            );
            $this->assertSame(
                $magicPrefix,
                substr($response['body'], 0, strlen($magicPrefix)),
                'Body magic bytes must match the native original'
            );
            if ($format === 'webp') {
                $this->assertSame('WEBP', substr($response['body'], 8, 4));
            }
            $this->assertIntegrityHeadersMatchBody($response);
            $this->assertConditionalImageHeaders(
                $response,
                '/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image',
                false
            );
        } finally {
            self::restoreDefaultOriginalFixture($installedPath);
        }
    }

    private function assertIntegrityHeadersMatchBody(array $response): void
    {
        $this->assertSame(
            'sha-256=:' . base64_encode(hash('sha256', $response['body'], true)) . ':',
            $response['headers']['Content-Digest'] ?? null
        );
        $this->assertSame(
            base64_encode(md5($response['body'], true)),
            $response['headers']['Content-MD5'] ?? null
        );
    }

    private function assertConditionalImageHeaders(
        array $response,
        string $path,
        bool $immutable,
        bool $expectCors = true
    ): void
    {
        $etag = $response['headers']['ETag'] ?? null;
        $this->assertNotNull($etag);

        $conditional = $this->apiRequest($path, ['If-None-Match: ' . $etag]);
        $this->assertSame(304, $conditional['status']);
        $this->assertSame('', $conditional['body']);
        $cacheControl = $conditional['headers']['Cache-Control'] ?? '';
        $this->assertSame($immutable, str_contains($cacheControl, 'immutable'));
        $this->assertNotSame('', $cacheControl);
        if ($expectCors) {
            $this->assertSame('*', $conditional['headers']['Access-Control-Allow-Origin'] ?? null);
        }
    }

    private static function restoreDefaultOriginalFixture(?string $installedPath): void
    {
        if ($installedPath !== null) {
            self::removeInstalledNativeOriginal($installedPath);
        }
        self::unlinkOriginalFormatSymlinks();
        self::createTestImages();
    }

    private static function unlinkOriginalFormatSymlinks(): void
    {
        $cacheDir = getWebcamCameraDir(self::$testAirport, self::$testCam);
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $link = $cacheDir . '/original.' . $ext;
            if (is_link($link) || file_exists($link)) {
                unlink($link);
            }
        }
    }

    private static function removeInstalledNativeOriginal(string $path): void
    {
        $hourDir = dirname($path);
        $dateDir = dirname($hourDir);
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
        self::rmdirIfEmpty($hourDir);
        self::rmdirIfEmpty($dateDir);
    }

    private static function rmdirIfEmpty(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items !== false && count($items) === 2) {
            rmdir($dir);
        }
    }

    private static function installNativeOriginal(string $format): string
    {
        $cacheDir = getWebcamCameraDir(self::$testAirport, self::$testCam);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        // Must outrank the JPEG fixture so getLatestImageTimestamp selects this file.
        $timestamp = time() + 1;
        $framesDir = getWebcamFramesDir(self::$testAirport, self::$testCam, $timestamp);
        if (!is_dir($framesDir)) {
            mkdir($framesDir, 0755, true);
        }
        $subdir = getWebcamFramesSubdir($timestamp);
        $filename = $timestamp . '_original.' . $format;
        $path = $framesDir . '/' . $filename;

        $img = imagecreatetruecolor(32, 24);
        $green = imagecolorallocate($img, 0, 128, 0);
        imagefill($img, 0, 0, $green);
        if ($format === 'png') {
            imagepng($img, $path);
        } else {
            imagewebp($img, $path);
        }
        self::unlinkOriginalFormatSymlinks();
        symlink($subdir . '/' . $filename, $cacheDir . '/original.' . $format);

        return $path;
    }

    private static function installTimestampedOriginal(string $extension, string $actualFormat): string
    {
        $timestamp = time() + 30;
        $framesDir = getWebcamFramesDir(self::$testAirport, self::$testCam, $timestamp);
        if (!is_dir($framesDir)) {
            mkdir($framesDir, 0755, true);
        }
        $path = $framesDir . '/' . $timestamp . '_original.' . $extension;
        $img = imagecreatetruecolor(32, 24);
        if ($actualFormat === 'jpg') {
            imagejpeg($img, $path);
        } else {
            imagepng($img, $path);
        }

        return $path;
    }
    
    /**
     * Test recommended_sizes are sorted descending
     */
    public function testMetadataEndpoint_RecommendedSizesSorted(): void
    {
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?metadata=1');
        
        $data = $response['json'];
        $sizes = $data['data']['recommended_sizes'] ?? [];
        
        $this->assertIsArray($sizes, 'recommended_sizes should be an array');
        if (count($sizes) > 1) {
            $sortedSizes = $sizes;
            rsort($sortedSizes);
            $this->assertEquals($sortedSizes, $sizes,
                'Recommended sizes should be sorted in descending order');
        }
    }
    
    /**
     * Test metadata endpoint returns 503 when no image available
     */
    public function testMetadataEndpoint_NoImage_Returns503(): void
    {
        // Use a non-existent webcam
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/99/image?metadata=1');
        
        if ($response['status'] === 0) {
            $this->markTestSkipped('Web server not available at ' . self::$apiBaseUrl);
        }
        
        // Should return 503 or 404
        $this->assertContains($response['status'], [404, 503], 
            'Non-existent webcam should return 404 or 503');
    }
    
    /**
     * Test history endpoint returns frame list
     * 
     * Note: May hit rate limits in local testing without API key
     */
    public function testHistoryEndpoint_ReturnsFrameList(): void
    {
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/history');
        
        if ($response['status'] === 0) {
            $this->markTestSkipped('Web server not available at ' . self::$apiBaseUrl);
        }
        
        // Should return 200 OK, 404 if history not configured, or 429 if rate limited
        $this->assertContains($response['status'], [200, 404, 429], 
            'History endpoint should return 200, 404, or 429');
        
        if ($response['status'] === 429) {
            $this->markTestSkipped('Rate limited - test requires API key');
        }
        
        if ($response['status'] === 200) {
            $data = $response['json'];
            $this->assertTrue($data['success'] ?? false, 'Success should be true');
            $this->assertArrayHasKey('frames', $data, 'Should have frames array');
            $this->assertIsArray($data['frames'], 'Frames should be array');
            
            // Check meta fields
            $this->assertArrayHasKey('meta', $data, 'Should have meta field');
            $this->assertArrayHasKey('frame_count', $data['meta'], 'Meta should have frame_count');
            $this->assertArrayHasKey('max_frames', $data['meta'], 'Meta should have max_frames');
        }
    }
    
    /**
     * Test history endpoint frame structure
     * 
     * Note: May hit rate limits in local testing without API key
     */
    public function testHistoryEndpoint_FrameStructure(): void
    {
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/history');
        
        if ($response['status'] === 429) {
            $this->markTestSkipped('Rate limited - test requires API key');
        }
        
        if ($response['status'] !== 200) {
            $this->markTestSkipped('History not available for this test');
        }
        
        $data = $response['json'];
        $frames = $data['frames'] ?? [];
        
        if (empty($frames)) {
            $this->markTestSkipped('No frames available for testing');
        }
        
        // Check first frame structure
        $frame = $frames[0];
        $this->assertArrayHasKey('timestamp', $frame, 'Frame should have timestamp');
        $this->assertArrayHasKey('timestamp_iso', $frame, 'Frame should have timestamp_iso');
        $this->assertArrayHasKey('url', $frame, 'Frame should have url');
        $this->assertArrayHasKey('formats', $frame, 'Frame should have formats');
        $this->assertArrayHasKey('variants', $frame, 'Frame should have variants');
        
        // Formats should be array
        $this->assertIsArray($frame['formats'], 'Formats should be array');
        $this->assertNotEmpty($frame['formats'], 'Formats should not be empty');
        
        // Variants should be array
        $this->assertIsArray($frame['variants'], 'Variants should be array');
        $this->assertNotEmpty($frame['variants'], 'Variants should not be empty');
    }
    
    /**
     * Test history endpoint uses cache (performance test)
     * 
     * This verifies the APCu cache implementation by making multiple requests
     * and ensuring the second request is faster (cached).
     * 
     * Note: May hit rate limits in local testing without API key
     */
    public function testHistoryEndpoint_UsesCache(): void
    {
        // Clear APCu cache if available
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
        
        // First request (cache miss)
        $start1 = microtime(true);
        $response1 = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/history');
        $time1 = microtime(true) - $start1;
        
        if ($response1['status'] === 429) {
            $this->markTestSkipped('Rate limited - test requires API key');
        }
        
        if ($response1['status'] !== 200) {
            $this->markTestSkipped('History not available for this test');
        }
        
        // Second request (should be cached)
        $start2 = microtime(true);
        $response2 = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/history');
        $time2 = microtime(true) - $start2;
        
        if ($response2['status'] === 429) {
            $this->markTestSkipped('Rate limited on second request');
        }
        
        // Both should succeed
        $this->assertEquals(200, $response2['status'], 'Second request should succeed');
        
        // Second request should return same data
        $this->assertEquals(
            $response1['json']['frames'],
            $response2['json']['frames'],
            'Cached response should match original'
        );
        
        // Note: We don't assert on timing in CI because timing can vary
        // The cache logic is tested; timing is environment-dependent
    }
    
    /**
     * Test history endpoint frame count matches meta
     * 
     * Note: May hit rate limits in local testing without API key
     */
    public function testHistoryEndpoint_FrameCountMatchesMeta(): void
    {
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/history');
        
        if ($response['status'] === 429) {
            $this->markTestSkipped('Rate limited - test requires API key');
        }
        
        if ($response['status'] !== 200) {
            $this->markTestSkipped('History not available for this test');
        }
        
        $data = $response['json'];
        $frames = $data['frames'] ?? [];
        $frameCount = $data['meta']['frame_count'] ?? -1;
        
        $this->assertEquals(count($frames), $frameCount,
            'Frame count in meta should match actual frames array length');
    }

    /**
     * GET /v1/airports/{id}/webcams returns approximate_heading on active airports.
     */
    public function testListWebcams_IncludesApproximateHeadingOnActiveAirport(): void
    {
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams');

        if ($response['status'] === 0) {
            $this->markTestSkipped('Web server not available at ' . self::$apiBaseUrl);
        }

        if ($response['status'] === 429) {
            $this->markTestSkipped('Rate limited - test requires API key');
        }

        $this->assertEquals(200, $response['status'], 'Webcam list should return 200');

        $data = $response['json'];
        $this->assertTrue($data['success'] ?? false);
        $webcams = $data['webcams'] ?? [];
        $this->assertNotEmpty($webcams, 'Webcam API fixture should have webcams');

        foreach ($webcams as $webcam) {
            $this->assertArrayHasKey('approximate_heading', $webcam);
            $this->assertArrayNotHasKey('approximate_heading_reference', $webcam);
            $this->assertIsInt($webcam['approximate_heading']);
            $this->assertArrayHasKey('image_url', $webcam);
        }
    }

    /**
     * Maintenance airports may return null approximate_heading when not configured.
     */
    public function testListWebcams_NullHeadingOnMaintenanceAirport(): void
    {
        $response = $this->apiRequest('/airports/pdx/webcams');

        if ($response['status'] === 0) {
            $this->markTestSkipped('Web server not available at ' . self::$apiBaseUrl);
        }

        if ($response['status'] === 429) {
            $this->markTestSkipped('Rate limited - test requires API key');
        }

        $this->assertEquals(200, $response['status']);

        $webcams = $response['json']['webcams'] ?? [];
        $this->assertNotEmpty($webcams);

        foreach ($webcams as $webcam) {
            $this->assertArrayHasKey('approximate_heading', $webcam);
            $this->assertArrayNotHasKey('approximate_heading_reference', $webcam);
            $this->assertNull($webcam['approximate_heading']);
        }
    }

    /**
     * Disabled airports are not served by the Public API.
     */
    public function testListWebcams_DisabledAirportReturns404(): void
    {
        $response = $this->apiRequest('/airports/ksea/webcams');

        if ($response['status'] === 0) {
            $this->markTestSkipped('Web server not available at ' . self::$apiBaseUrl);
        }

        if ($response['status'] === 429) {
            $this->markTestSkipped('Rate limited - test requires API key');
        }

        $this->assertEquals(404, $response['status']);
    }
}
