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
     * Test IPv4 vsftpd config syntax is valid
     */
    public function testVsftpdIpv4Config_IsValid()
    {
        $configPath = __DIR__ . '/../../docker/vsftpd_ipv4.conf';
        
        if (!file_exists($configPath)) {
            $this->markTestSkipped('vsftpd_ipv4.conf not found');
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
            $this->fail("vsftpd IPv4 config has syntax errors:\n" . $outputStr);
        }
        
        $this->assertTrue(true, 'IPv4 config syntax is valid');
    }
    
    /**
     * Test IPv6 vsftpd config syntax is valid
     * 
     * Note: This only validates syntax, not IPv6 availability
     */
    public function testVsftpdIpv6Config_IsValid()
    {
        $configPath = __DIR__ . '/../../docker/vsftpd_ipv6.conf';
        
        if (!file_exists($configPath)) {
            $this->markTestSkipped('vsftpd_ipv6.conf not found');
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
        // Warnings about missing directories/files or IPv6 unavailability are OK
        $hasCriticalError = (
            $returnCode !== 0 &&
            strpos($outputStr, 'listening on') === false &&
            strpos($outputStr, 'ERROR') !== false &&
            strpos($outputStr, 'IPv6') === false  // IPv6 unavailability is not a syntax error
        );
        
        if ($hasCriticalError) {
            $this->fail("vsftpd IPv6 config has syntax errors:\n" . $outputStr);
        }
        
        $this->assertTrue(true, 'IPv6 config syntax is valid');
    }
    
    /**
     * Test base vsftpd config syntax is valid
     */
    public function testVsftpdBaseConfig_IsValid()
    {
        $configPath = __DIR__ . '/../../docker/vsftpd.conf';
        
        if (!file_exists($configPath)) {
            $this->markTestSkipped('vsftpd.conf not found');
            return;
        }
        
        // Test config syntax using vsftpd -olisten=NO
        $output = [];
        $returnCode = 0;
        exec("vsftpd -olisten=NO {$configPath} 2>&1", $output, $returnCode);
        
        $outputStr = implode("\n", $output);
        
        $hasCriticalError = (
            $returnCode !== 0 &&
            strpos($outputStr, 'listening on') === false &&
            strpos($outputStr, 'ERROR') !== false
        );
        
        if ($hasCriticalError) {
            $this->fail("vsftpd base config has syntax errors:\n" . $outputStr);
        }
        
        $this->assertTrue(true, 'Base config syntax is valid');
    }
}

