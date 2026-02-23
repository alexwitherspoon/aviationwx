<?php
/**
 * Unit Tests for CORS allowlist (getCorsAllowOriginForAviationWx)
 *
 * Public API CORS is tightened to *.aviationwx.org and localhost for dev.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cors.php';

class CorsAllowlistTest extends TestCase
{
    /**
     * Bare domain aviationwx.org is allowed
     */
    public function testAviationWxOrg_Allowed(): void
    {
        $this->assertSame('https://aviationwx.org', getCorsAllowOriginForAviationWx('https://aviationwx.org'));
    }

    /**
     * Subdomains of aviationwx.org are allowed
     */
    public function testSubdomains_Allowed(): void
    {
        $this->assertSame('https://kspb.aviationwx.org', getCorsAllowOriginForAviationWx('https://kspb.aviationwx.org'));
        $this->assertSame('https://embed.aviationwx.org', getCorsAllowOriginForAviationWx('https://embed.aviationwx.org'));
        $this->assertSame('https://api.aviationwx.org', getCorsAllowOriginForAviationWx('https://api.aviationwx.org'));
        $this->assertSame('https://www.aviationwx.org', getCorsAllowOriginForAviationWx('https://www.aviationwx.org'));
    }

    /**
     * Localhost is allowed for local dev
     */
    public function testLocalhost_Allowed(): void
    {
        $this->assertSame('http://localhost', getCorsAllowOriginForAviationWx('http://localhost'));
        $this->assertSame('http://localhost:8080', getCorsAllowOriginForAviationWx('http://localhost:8080'));
        $this->assertSame('http://127.0.0.1', getCorsAllowOriginForAviationWx('http://127.0.0.1'));
        $this->assertSame('http://127.0.0.1:8080', getCorsAllowOriginForAviationWx('http://127.0.0.1:8080'));
    }

    /**
     * Third-party origins are not allowed
     */
    public function testThirdParty_NotAllowed(): void
    {
        $this->assertNull(getCorsAllowOriginForAviationWx('https://evil.com'));
        $this->assertNull(getCorsAllowOriginForAviationWx('https://aviationwx.org.evil.com'));
        $this->assertNull(getCorsAllowOriginForAviationWx('https://fake-aviationwx.org'));
    }

    /**
     * Null and empty return null
     */
    public function testNullOrEmpty_ReturnsNull(): void
    {
        $this->assertNull(getCorsAllowOriginForAviationWx(null));
        $this->assertNull(getCorsAllowOriginForAviationWx(''));
    }

    /**
     * Invalid URLs return null
     */
    public function testInvalidUrl_ReturnsNull(): void
    {
        $this->assertNull(getCorsAllowOriginForAviationWx('not-a-url'));
        $this->assertNull(getCorsAllowOriginForAviationWx('ftp://aviationwx.org'));
    }

    /**
     * Custom base_domain from config allows that domain
     */
    public function testCustomBaseDomain_Allowed(): void
    {
        $this->assertSame(
            'https://example.com',
            getCorsAllowOriginForAviationWx('https://example.com', 'example.com')
        );
        $this->assertSame(
            'https://api.example.com',
            getCorsAllowOriginForAviationWx('https://api.example.com', 'example.com')
        );
    }
}
