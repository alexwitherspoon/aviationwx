<?php
/**
 * Unit tests for HTTP integrity headers (ETag, Content-Digest, Content-MD5)
 *
 * TDD-style tests for lib/http-integrity.php helpers.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/http-integrity.php';
require_once __DIR__ . '/../../lib/config.php';

class HttpIntegrityTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/aviationwx_http_integrity_test_' . getmypid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            array_map('unlink', glob($this->tempDir . '/*'));
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    /**
     * computeFileEtag returns weak ETag format W/"hash"
     */
    public function testComputeFileEtag_ReturnsWeakEtagFormat(): void
    {
        $etag = computeFileEtag('/path/to/file', 12345, 100);
        $this->assertStringStartsWith('W/"', $etag);
        $this->assertStringEndsWith('"', $etag);
        $this->assertMatchesRegularExpression('/^W\/"[a-f0-9]{40}"$/', $etag);
    }

    /**
     * computeFileEtag is deterministic for same inputs
     */
    public function testComputeFileEtag_IsDeterministic(): void
    {
        $etag1 = computeFileEtag('/path/file', 100, 500);
        $etag2 = computeFileEtag('/path/file', 100, 500);
        $this->assertSame($etag1, $etag2);
    }

    /**
     * computeFileEtag differs when path, mtime, or size changes
     */
    public function testComputeFileEtag_DiffersWhenInputsChange(): void
    {
        $base = computeFileEtag('/path/file', 100, 500);
        $this->assertNotSame($base, computeFileEtag('/path/file2', 100, 500));
        $this->assertNotSame($base, computeFileEtag('/path/file', 101, 500));
        $this->assertNotSame($base, computeFileEtag('/path/file', 100, 501));
    }

    /**
     * computeContentDigestFromString returns RFC 9530 format sha-256=:base64:
     */
    public function testComputeContentDigestFromString_ReturnsRfc9530Format(): void
    {
        $digest = computeContentDigestFromString('hello');
        $this->assertStringStartsWith('sha-256=:', $digest);
        $this->assertStringEndsWith(':', $digest);
        $inner = substr($digest, 9, -1);
        $this->assertNotEmpty($inner);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $inner, 'Inner should be valid base64');
    }

    /**
     * computeContentDigestFromString is deterministic
     */
    public function testComputeContentDigestFromString_IsDeterministic(): void
    {
        $d1 = computeContentDigestFromString('test content');
        $d2 = computeContentDigestFromString('test content');
        $this->assertSame($d1, $d2);
    }

    /**
     * computeContentDigestFromString differs for different content
     */
    public function testComputeContentDigestFromString_DiffersForDifferentContent(): void
    {
        $d1 = computeContentDigestFromString('content A');
        $d2 = computeContentDigestFromString('content B');
        $this->assertNotSame($d1, $d2);
    }

    /**
     * computeFileContentDigest matches computeContentDigestFromString for same content
     */
    public function testComputeFileContentDigest_MatchesStringDigestForSameContent(): void
    {
        $content = 'identical content for file and string';
        $filePath = $this->tempDir . '/digest_test.txt';
        file_put_contents($filePath, $content);

        $fileDigest = computeFileContentDigest($filePath);
        $stringDigest = computeContentDigestFromString($content);

        $this->assertNotNull($fileDigest);
        $this->assertSame($stringDigest, $fileDigest);
    }

    /**
     * computeFileContentDigest returns null for non-existent file
     */
    public function testComputeFileContentDigest_ReturnsNullForMissingFile(): void
    {
        $result = computeFileContentDigest($this->tempDir . '/nonexistent_' . time());
        $this->assertNull($result);
    }

    /**
     * computeContentMd5FromString returns base64-encoded MD5
     */
    public function testComputeContentMd5FromString_ReturnsBase64Format(): void
    {
        $md5 = computeContentMd5FromString('hello');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $md5);
        $decoded = base64_decode($md5, true);
        $this->assertNotFalse($decoded);
        $this->assertSame(16, strlen($decoded), 'MD5 raw output is 16 bytes');
    }

    /**
     * computeContentMd5FromString is deterministic
     */
    public function testComputeContentMd5FromString_IsDeterministic(): void
    {
        $m1 = computeContentMd5FromString('test');
        $m2 = computeContentMd5FromString('test');
        $this->assertSame($m1, $m2);
    }

    /**
     * computeFileContentMd5 matches computeContentMd5FromString for same content
     */
    public function testComputeFileContentMd5_MatchesStringMd5ForSameContent(): void
    {
        $content = 'md5 test content';
        $filePath = $this->tempDir . '/md5_test.txt';
        file_put_contents($filePath, $content);

        $fileMd5 = computeFileContentMd5($filePath);
        $stringMd5 = computeContentMd5FromString($content);

        $this->assertNotNull($fileMd5);
        $this->assertSame($stringMd5, $fileMd5);
    }

    /**
     * computeFileContentMd5 returns null for non-existent file
     */
    public function testComputeFileContentMd5_ReturnsNullForMissingFile(): void
    {
        $result = computeFileContentMd5($this->tempDir . '/nonexistent_' . time());
        $this->assertNull($result);
    }

    /**
     * addIntegrityHeadersForFile returns false for non-existent file
     */
    public function testAddIntegrityHeadersForFile_ReturnsFalseForMissingFile(): void
    {
        $result = addIntegrityHeadersForFile($this->tempDir . '/nonexistent_' . time());
        $this->assertFalse($result);
    }

    /**
     * addIntegrityHeadersForFile returns false for empty file (size 0)
     */
    public function testAddIntegrityHeadersForFile_ReturnsFalseForEmptyFile(): void
    {
        $emptyFile = $this->tempDir . '/empty.txt';
        file_put_contents($emptyFile, '');
        $result = addIntegrityHeadersForFile($emptyFile);
        $this->assertFalse($result);
    }

    /**
     * addIntegrityHeadersForFile returns false for valid file when no conditional match
     * (false = did not send 304, caller should send body)
     */
    public function testAddIntegrityHeadersForFile_ReturnsFalseForValidFile(): void
    {
        $content = 'valid file content';
        $filePath = $this->tempDir . '/valid.txt';
        file_put_contents($filePath, $content);

        ob_start();
        $result = addIntegrityHeadersForFile($filePath);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertSame('', $output);
        $this->assertNotSame(304, http_response_code());
    }

    /**
     * addIntegrityHeadersForFile returns true (304 sent) when If-None-Match matches
     */
    public function testAddIntegrityHeadersForFile_ReturnsTrueWhenIfNoneMatchMatches(): void
    {
        $content = 'content for 304 test';
        $filePath = $this->tempDir . '/304test.txt';
        file_put_contents($filePath, $content);
        $mtime = filemtime($filePath);
        $size = filesize($filePath);
        $etag = computeFileEtag($filePath, $mtime, $size);

        $_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = '';

        ob_start();
        $result = addIntegrityHeadersForFile($filePath, $mtime);
        $output = ob_get_clean();

        unset($_SERVER['HTTP_IF_NONE_MATCH']);

        $this->assertTrue($result);
        $this->assertSame(304, http_response_code());
    }

    /**
     * Content-Digest verifies body integrity (digest matches actual content)
     */
    public function testContentDigest_VerifiesBodyIntegrity(): void
    {
        $content = 'integrity verification test';
        $digest = computeContentDigestFromString($content);
        $expectedHash = hash('sha256', $content, true);
        $expectedBase64 = base64_encode($expectedHash);
        $this->assertStringContainsString($expectedBase64, $digest);
    }

    /**
     * getFileDigestsWithCache returns same values as uncached computation
     */
    public function testGetFileDigestsWithCache_MatchesUncachedValues(): void
    {
        $content = 'cached digest test content';
        $filePath = $this->tempDir . '/cache_test.txt';
        file_put_contents($filePath, $content);
        $mtime = filemtime($filePath);

        $cached = getFileDigestsWithCache($filePath, $mtime);
        $this->assertNotNull($cached);
        $this->assertSame(computeContentDigestFromString($content), $cached['digest']);
        $this->assertSame(computeContentMd5FromString($content), $cached['md5']);
    }

    /**
     * getHttpIntegrityDigestTtlSeconds returns TTL >= 24h when config has 24h retention
     */
    public function testGetHttpIntegrityDigestTtlSeconds_ReturnsConfigDrivenTtl(): void
    {
        $ttl = getHttpIntegrityDigestTtlSeconds();
        $this->assertGreaterThanOrEqual(86400, $ttl, 'TTL should be at least 24h (86400s) for default config');
        $this->assertLessThanOrEqual(2592000, $ttl, 'TTL should be reasonable (max 30 days)');
    }

    /**
     * Cached digest returns same result on second call (cache hit)
     */
    public function testGetFileDigestsWithCache_ReturnsConsistentOnRepeatCalls(): void
    {
        if (!function_exists('apcu_fetch')) {
            $this->markTestSkipped('APCu not available');
        }

        $content = 'repeat cache test';
        $filePath = $this->tempDir . '/repeat_test.txt';
        file_put_contents($filePath, $content);
        $mtime = filemtime($filePath);

        $first = getFileDigestsWithCache($filePath, $mtime);
        $second = getFileDigestsWithCache($filePath, $mtime);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first['digest'], $second['digest']);
        $this->assertSame($first['md5'], $second['md5']);
    }

}
