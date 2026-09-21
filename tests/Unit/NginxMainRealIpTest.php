<?php
/**
 * Regression tests for docker/nginx-main.conf real_ip configuration.
 *
 * Ensures set_real_ip_from is present for all Cloudflare CIDR ranges and
 * real_ip_header is set to CF-Connecting-IP, so nginx replaces REMOTE_ADDR
 * with the real client IP before proxying to Apache.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

class NginxMainRealIpTest extends TestCase
{
    private static function nginxMainConfPath(): string
    {
        return dirname(__DIR__, 2) . '/docker/nginx-main.conf';
    }

    private function readConfig(): string
    {
        $path = self::nginxMainConfPath();
        $this->assertFileExists($path, 'docker/nginx-main.conf must exist');
        $content = file_get_contents($path);
        $this->assertIsString($content);
        return $content;
    }

    /**
     * Find the matching closing brace for the http block, skipping single-quoted
     * strings and # comments so the log_format JSON braces are not miscounted.
     */
    private function findHttpBlockEnd(string $content, int $httpOpen): ?int
    {
        $depth = 0;
        $length = strlen($content);
        $inSingleQuote = false;
        $inComment = false;

        for ($i = $httpOpen; $i < $length; $i++) {
            $char = $content[$i];

            if ($inComment) {
                if ($char === "\n") {
                    $inComment = false;
                }
                continue;
            }
            if ($inSingleQuote) {
                if ($char === "'") {
                    $inSingleQuote = false;
                }
                continue;
            }
            if ($char === '#') {
                $inComment = true;
            } elseif ($char === "'") {
                $inSingleQuote = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * real_ip_header must trust Cloudflare's CF-Connecting-IP header.
     */
    public function testNginxMainConf_RealIpHeader_UsesCloudflareHeader(): void
    {
        $content = $this->readConfig();
        $this->assertStringContainsString(
            'real_ip_header CF-Connecting-IP',
            $content,
            'nginx-main.conf must set real_ip_header to CF-Connecting-IP'
        );
    }

    /**
     * Every Cloudflare IPv4 range must be listed as a trusted proxy.
     */
    public function testNginxMainConf_HasAllCloudflareIPv4Ranges_AllRangesPresent(): void
    {
        $content = $this->readConfig();
        $requiredRanges = [
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
        ];
        foreach ($requiredRanges as $range) {
            $this->assertStringContainsString(
                'set_real_ip_from ' . $range,
                $content,
                "nginx-main.conf must trust CF IPv4 range: {$range}"
            );
        }
    }

    /**
     * Every Cloudflare IPv6 range must be listed as a trusted proxy.
     */
    public function testNginxMainConf_HasAllCloudflareIPv6Ranges_AllRangesPresent(): void
    {
        $content = $this->readConfig();
        $requiredRanges = [
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ];
        foreach ($requiredRanges as $range) {
            $this->assertStringContainsString(
                'set_real_ip_from ' . $range,
                $content,
                "nginx-main.conf must trust CF IPv6 range: {$range}"
            );
        }
    }

    /**
     * real_ip_header must live inside the http block, not outside it.
     */
    public function testNginxMainConf_RealIpHeader_IsInsideHttpBlock(): void
    {
        $content = $this->readConfig();
        $realIpPos = strpos($content, 'real_ip_header CF-Connecting-IP');
        $httpOpen = strpos($content, 'http {');

        $this->assertNotFalse($realIpPos, 'real_ip_header directive must be present');
        $this->assertNotFalse($httpOpen, 'http block must be present');

        $httpEnd = $this->findHttpBlockEnd($content, $httpOpen);
        $this->assertNotNull($httpEnd, 'http block must be closed');

        $this->assertGreaterThan($httpOpen, $realIpPos, 'real_ip_header must be inside the http block');
        $this->assertLessThan($httpEnd, $realIpPos, 'real_ip_header must be inside the http block');
    }
}
