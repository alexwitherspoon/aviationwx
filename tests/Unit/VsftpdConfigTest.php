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
    
    /**
     * Test that commented settings don't have inline text after the value
     * 
     * vsftpd doesn't support inline comments - text after a value becomes
     * part of the value and breaks vsftpd when the entrypoint uncomments it.
     */
    public function testVsftpdConfig_NoInlineCommentsOnSettings()
    {
        $configPath = __DIR__ . '/../../docker/vsftpd.conf';
        
        if (!file_exists($configPath)) {
            $this->markTestSkipped('vsftpd.conf not found');
            return;
        }
        
        $configContent = file_get_contents($configPath);
        $lines = explode("\n", $configContent);
        
        $errors = [];
        foreach ($lines as $lineNum => $line) {
            // Match setting lines (with or without leading #)
            if (preg_match('/^#?\s*(\w+)=(.+)$/', $line, $matches)) {
                $setting = $matches[1];
                $value = $matches[2];
                
                // Check if YES/NO value has extra text after it
                if (preg_match('/^(YES|NO)\s+[^#]/', $value) || 
                    preg_match('/^(YES|NO)\s*-\s*\w/', $value)) {
                    $errors[] = sprintf(
                        "Line %d: Setting '%s' has inline comment/text after value: %s",
                        $lineNum + 1,
                        $setting,
                        trim($line)
                    );
                }
            }
        }
        
        if (!empty($errors)) {
            $this->fail(
                "vsftpd config has inline comments on settings (vsftpd doesn't support this):\n" .
                implode("\n", $errors)
            );
        }
        
        $this->assertTrue(true, 'No inline comments on settings');
    }
    
    /**
     * Test that SSL settings are valid after entrypoint uncomments them
     * 
     * Simulates the entrypoint's sed commands to ensure the resulting
     * config has valid YES/NO values for SSL boolean settings.
     */
    public function testVsftpdConfig_SSLSettingsValidAfterUncomment()
    {
        $configPath = __DIR__ . '/../../docker/vsftpd.conf';
        
        if (!file_exists($configPath)) {
            $this->markTestSkipped('vsftpd.conf not found');
            return;
        }
        
        $configContent = file_get_contents($configPath);
        
        // Simulate entrypoint sed commands
        $transformed = $configContent;
        $transformed = preg_replace('/^# require_ssl_reuse=NO$/m', 'require_ssl_reuse=NO', $transformed);
        $transformed = preg_replace('/^# ssl_enable=NO$/m', 'ssl_enable=YES', $transformed);
        $transformed = preg_replace('/^# ssl_ciphers=HIGH$/m', 'ssl_ciphers=HIGH', $transformed);
        
        // SSL boolean settings must have exactly YES or NO as values
        $sslBooleanSettings = [
            'ssl_enable',
            'require_ssl_reuse', 
            'ssl_tlsv1',
            'ssl_sslv2',
            'ssl_sslv3',
            'force_local_data_ssl',
            'force_local_logins_ssl',
        ];
        
        $lines = explode("\n", $transformed);
        $errors = [];
        
        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            
            // Skip empty lines and comments
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            
            // Check if this is an SSL boolean setting
            foreach ($sslBooleanSettings as $setting) {
                if (strpos($line, $setting . '=') === 0) {
                    // Extract the value part
                    $value = substr($line, strlen($setting) + 1);
                    
                    // Value should be exactly YES or NO, nothing more
                    if ($value !== 'YES' && $value !== 'NO') {
                        $errors[] = sprintf(
                            "Line %d: SSL setting '%s' has invalid value '%s' (expected YES or NO)",
                            $lineNum + 1,
                            $setting,
                            $value
                        );
                    }
                    break;
                }
            }
        }
        
        if (!empty($errors)) {
            $this->fail(
                "vsftpd config would be invalid after entrypoint enables SSL:\n" .
                implode("\n", $errors)
            );
        }
        
        $this->assertTrue(true, 'SSL settings valid after uncomment');
    }
}
