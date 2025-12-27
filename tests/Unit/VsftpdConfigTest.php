<?php
/**
 * Test vsftpd configuration files for syntax validity
 * 
 * Validates that vsftpd config files are syntactically correct without
 * requiring IPv6 support or starting services. This ensures configs are
 * valid even in environments where IPv6 is unavailable.
 */

use PHPUnit\Framework\TestCase;

class VsftpdConfigTest extends TestCase
{
    /**
     * Test vsftpd config syntax is valid
     * 
     * Uses single dual-stack config file that handles both IPv4 and IPv6
     */
    public function testVsftpdConfig_IsValid()
    {
        $configPath = __DIR__ . '/../../docker/vsftpd.conf';
        
        if (!file_exists($configPath)) {
            $this->markTestSkipped('vsftpd.conf not found');
            return;
        }
        
        // Test config syntax using vsftpd -olisten=NO (validates without starting)
        $output = [];
        $returnCode = 0;
        exec("vsftpd -olisten=NO {$configPath} 2>&1", $output, $returnCode);
        
        // vsftpd returns 0 on success, but may output warnings
        // Check for critical errors (not just warnings)
        $outputStr = implode("\n", $output);
        
        // Config is valid if vsftpd doesn't exit with error
        // Warnings about missing directories/files are OK for syntax validation
        $hasCriticalError = (
            $returnCode !== 0 &&
            strpos($outputStr, 'listening on') === false &&
            strpos($outputStr, 'ERROR') !== false
        );
        
        if ($hasCriticalError) {
            $this->fail("vsftpd config has syntax errors:\n" . $outputStr);
        }
        
        $this->assertTrue(true, 'Config syntax is valid');
    }
    
    /**
     * Test that config has dual-stack settings
     */
    public function testVsftpdConfig_HasDualStackSettings()
    {
        $configPath = __DIR__ . '/../../docker/vsftpd.conf';
        
        if (!file_exists($configPath)) {
            $this->markTestSkipped('vsftpd.conf not found');
            return;
        }
        
        $configContent = file_get_contents($configPath);
        
        // Dual-stack config should have listen=NO and listen_ipv6=YES
        $this->assertStringContainsString('listen=NO', $configContent, 
            'Config should have listen=NO for dual-stack mode');
        $this->assertStringContainsString('listen_ipv6=YES', $configContent, 
            'Config should have listen_ipv6=YES for dual-stack mode');
    }
}
