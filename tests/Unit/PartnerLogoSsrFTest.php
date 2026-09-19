<?php
/**
 * Unit Tests for Partner Logo SSRF Protection
 *
 * Ensures isLogoUrlSafe() rejects loopback, link-local, RFC 1918, and
 * cloud-metadata IPs, while allowing public hostnames.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/partner-logo-cache.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PartnerLogoSsrFTest extends TestCase
{
    #[DataProvider('unsafeUrlProvider')]
    public function testIsLogoUrlSafe_UnsafeUrls_ReturnsFalse(string $url): void
    {
        $this->assertFalse(
            isLogoUrlSafe($url),
            "URL should be blocked as SSRF: {$url}"
        );
    }

    #[DataProvider('safeUrlProvider')]
    public function testIsLogoUrlSafe_SafeUrls_ReturnsTrue(string $url): void
    {
        $this->assertTrue(
            isLogoUrlSafe($url),
            "URL should be allowed: {$url}"
        );
    }

    public static function unsafeUrlProvider(): array
    {
        return [
            'loopback IPv4' => ['http://127.0.0.1/logo.png'],
            'loopback hostname' => ['http://localhost/logo.png'],
            'link-local metadata' => ['http://169.254.169.254/latest/meta-data/ami-id'],
            'link-local range' => ['http://169.254.1.1/logo.png'],
            'RFC 1918 10.x' => ['http://10.0.0.1/logo.png'],
            'RFC 1918 192.168.x' => ['http://192.168.1.1/logo.png'],
            'RFC 1918 172.16.x' => ['http://172.16.0.1/logo.png'],
            'IPv6 loopback' => ['http://[::1]/logo.png'],
            'IPv6 unspecified' => ['http://[::]/logo.png'],
            'IPv4-mapped IPv6 loopback' => ['http://[::ffff:127.0.0.1]/logo.png'],
            'IPv4-mapped IPv6 private' => ['http://[::ffff:10.0.0.1]/logo.png'],
            'IPv4-mapped IPv6 metadata' => ['http://[::ffff:169.254.169.254]/logo.png'],
            'IPv6 link-local fe80' => ['http://[fe80::1]/logo.png'],
            'IPv6 link-local fe90' => ['http://[fe90::1]/logo.png'],
            'IPv6 link-local febf' => ['http://[febf::1]/logo.png'],
            'IPv6 ULA fc00' => ['http://[fc00::1]/logo.png'],
            'IPv6 ULA fd00' => ['http://[fd00::1]/logo.png'],
            'IPv6 multicast ff02' => ['http://[ff02::1]/logo.png'],
            'IPv6 multicast ff00' => ['http://[ff00::1]/logo.png'],
            'file scheme' => ['file:///etc/passwd'],
            'ftp scheme' => ['ftp://example.com/logo.png'],
            'IPv4 unspecified' => ['http://0.0.0.0/logo.png'],
            'no host' => ['not-a-url'],
            'empty host' => ['http:///logo.png'],
        ];
    }

    public static function safeUrlProvider(): array
    {
        return [
            'public URL' => ['https://example.com/logo.png'],
            'public URL with www' => ['https://www.partner.org/images/logo.jpg'],
            'public URL with port' => ['https://example.com:443/logo.png'],
        ];
    }

    public function testIsPrivateIp_Loopback_ReturnsTrue(): void
    {
        $this->assertTrue(isPrivateIp('127.0.0.1'));
        $this->assertTrue(isPrivateIp('127.255.255.255'));
    }

    public function testIsPrivateIp_LinkLocal_ReturnsTrue(): void
    {
        $this->assertTrue(isPrivateIp('169.254.169.254'));
        $this->assertTrue(isPrivateIp('169.254.0.0'));
        $this->assertTrue(isPrivateIp('169.254.255.255'));
    }

    public function testIsPrivateIp_PrivateRange_ReturnsTrue(): void
    {
        $this->assertTrue(isPrivateIp('10.0.0.1'));
        $this->assertTrue(isPrivateIp('192.168.1.1'));
        $this->assertTrue(isPrivateIp('172.16.0.1'));
        $this->assertTrue(isPrivateIp('172.31.255.255'));
    }

    public function testIsPrivateIp_PublicIp_ReturnsFalse(): void
    {
        $this->assertFalse(isPrivateIp('8.8.8.8'));
        $this->assertFalse(isPrivateIp('1.1.1.1'));
    }

    public function testIsPrivateIp_IPv6Loopback_ReturnsTrue(): void
    {
        $this->assertTrue(isPrivateIp('::1'));
    }

    public function testIsPrivateIp_IPv4MappedIPv6Loopback_ReturnsTrue(): void
    {
        $this->assertTrue(isPrivateIp('::ffff:127.0.0.1'));
        $this->assertTrue(isPrivateIp('::ffff:169.254.169.254'));
        $this->assertTrue(isPrivateIp('::ffff:10.0.0.1'));
    }

    public function testIsPrivateIp_IPv4MappedIPv6Public_ReturnsFalse(): void
    {
        $this->assertFalse(isPrivateIp('::ffff:8.8.8.8'));
        $this->assertFalse(isPrivateIp('::ffff:1.1.1.1'));
    }

    public function testResolveRelativeUrl_ProtocolRelative_ResolvesWithScheme(): void
    {
        $result = resolveRelativeUrl('https://example.com/path/page.html', '//other.com/image.png');
        $this->assertSame('https://other.com/image.png', $result);
    }

    public function testResolveRelativeUrl_ProtocolRelativeHttpBase_ResolvesWithHttp(): void
    {
        $result = resolveRelativeUrl('http://example.com/path/page.html', '//other.com/image.png');
        $this->assertSame('http://other.com/image.png', $result);
    }

    public function testResolveRelativeUrl_RootRelative_ResolvesWithHostAndPort(): void
    {
        $result = resolveRelativeUrl('https://example.com:8443/path/page.html', '/images/logo.png');
        $this->assertSame('https://example.com:8443/images/logo.png', $result);
    }

    public function testResolveRelativeUrl_PathRelative_ResolvesWithBasePath(): void
    {
        $result = resolveRelativeUrl('https://example.com/path/to/page.html', 'image.png');
        $this->assertSame('https://example.com/path/to/image.png', $result);
    }

    public function testResolveRelativeUrl_AbsoluteUrl_ReturnsAsIs(): void
    {
        $result = resolveRelativeUrl('https://example.com/old', 'https://cdn.com/logo.png');
        $this->assertSame('https://cdn.com/logo.png', $result);
    }

    public function testResolveRelativeUrl_InternalRedirectBlocked(): void
    {
        $result = resolveRelativeUrl('https://example.com/page', '//127.0.0.1/logo.png');
        $this->assertSame('https://127.0.0.1/logo.png', $result);
        $this->assertFalse(isLogoUrlSafe($result));
    }

    public function testParseRedirectLocations_CaseInsensitive(): void
    {
        $headers = "HTTP/1.1 302 Found\r\nLocation: https://example.com/new\nX-Custom: header\r\n";
        $locations = parseRedirectLocations($headers);
        $this->assertCount(1, $locations);
        $this->assertSame('https://example.com/new', $locations[0]);
    }

    public function testParseRedirectLocations_LowercaseLocation(): void
    {
        $headers = "HTTP/1.1 302 Found\r\nlocation: https://example.com/new\n";
        $locations = parseRedirectLocations($headers);
        $this->assertCount(1, $locations);
        $this->assertSame('https://example.com/new', $locations[0]);
    }

    public function testParseRedirectLocations_MixedCase(): void
    {
        $headers = "HTTP/1.1 302 Found\r\nLOCATION: https://example.com/new\n";
        $locations = parseRedirectLocations($headers);
        $this->assertCount(1, $locations);
        $this->assertSame('https://example.com/new', $locations[0]);
    }

    public function testParseRedirectLocations_NoLocation(): void
    {
        $headers = "HTTP/1.1 200 OK\r\nContent-Type: image/png\r\n";
        $locations = parseRedirectLocations($headers);
        $this->assertSame([], $locations);
    }

    public function testParseRedirectLocations_EmptyHeaders(): void
    {
        $this->assertSame([], parseRedirectLocations(''));
    }

    public function testParseRedirectLocations_MultipleLocations(): void
    {
        $headers = "HTTP/1.1 302 Found\r\nLocation: https://a.com/x.png\r\nLocation: https://b.com/y.png\r\n";
        $locations = parseRedirectLocations($headers);
        $this->assertCount(2, $locations);
        $this->assertSame('https://a.com/x.png', $locations[0]);
    }

    public function testResolveLogoUrlHost_PinsResolvedIpsForRedirects(): void
    {
        // resolveLogoUrlHost returns the IPs that should be pinned via CURLOPT_RESOLVE
        $ips = resolveLogoUrlHost('http://1.1.1.1/logo.png');
        $this->assertIsArray($ips);
        $this->assertContains('1.1.1.1', $ips);
    }

    public function testResolveLogoUrlHost_ReturnsNullForInternalIp(): void
    {
        $this->assertNull(resolveLogoUrlHost('http://127.0.0.1/logo.png'));
        $this->assertNull(resolveLogoUrlHost('http://169.254.169.254/logo.png'));
    }

    public function testResolveLogoUrlHost_ReturnsNullForNonHttpScheme(): void
    {
        $this->assertNull(resolveLogoUrlHost('file:///etc/passwd'));
    }

    public function testResolveRelativeUrl_PreservesNonDefaultPort(): void
    {
        $result = resolveRelativeUrl('https://example.com:8443/path/page.html', '/image.png');
        $this->assertSame('https://example.com:8443/image.png', $result);
    }

    public function testResolveRelativeUrl_QueryOnly(): void
    {
        $result = resolveRelativeUrl('https://example.com/path/page.html', '?q=1');
        $this->assertSame('https://example.com/path/page.html?q=1', $result);
    }

    public function testResolveRelativeUrl_QueryOnlyReplacesExistingQuery(): void
    {
        $result = resolveRelativeUrl('https://example.com/path/page.html?old=1', '?new=1');
        $this->assertSame('https://example.com/path/page.html?new=1', $result);
    }

    public function testResolveRelativeUrl_FragmentOnlyPreservesQuery(): void
    {
        $result = resolveRelativeUrl('https://example.com/path/page.html?q=1', '#section');
        $this->assertSame('https://example.com/path/page.html?q=1#section', $result);
    }

    public function testResolveRelativeUrl_FragmentOnly(): void
    {
        $result = resolveRelativeUrl('https://example.com/path/page.html', '#section');
        $this->assertSame('https://example.com/path/page.html#section', $result);
    }

    public function testResolveRelativeUrl_PathRelativeNoDoubleSlash(): void
    {
        $result = resolveRelativeUrl('https://example.com/page.html', 'image.png');
        $this->assertSame('https://example.com/image.png', $result);
    }

    public function testResolveRelativeUrl_PathRelativeSubdirNoDoubleSlash(): void
    {
        $result = resolveRelativeUrl('https://example.com/path/to/page.html', 'image.png');
        $this->assertSame('https://example.com/path/to/image.png', $result);
    }
}
