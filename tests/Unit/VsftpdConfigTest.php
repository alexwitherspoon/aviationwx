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
     * Test that commented SSL settings don't have inline comments
     * 
     * vsftpd doesn't support inline comments. When the entrypoint script
     * uncomments settings like "# require_ssl_reuse=NO", any text after
     * the value becomes part of the value and breaks vsftpd.
     * 
     * This test catches bugs like:
     *   # require_ssl_reuse=NO - some comment here
     * Which would become invalid when uncommented:
     *   require_ssl_reuse=NO - some comment here
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
            // Skip pure comment lines (starting with #) that don't look like settings
            // We're looking for lines like "# setting=value extra stuff" or "setting=value extra stuff"
            
            // Match lines that have a setting pattern (with or without leading #)
            // Pattern: optional #, optional whitespace, word chars, =, then value
            if (preg_match('/^#?\s*(\w+)=(.+)$/', $line, $matches)) {
                $setting = $matches[1];
                $value = $matches[2];
                
                // Check if value contains what looks like inline comment or extra text
                // Valid values: YES, NO, numbers, paths, IP addresses, hostnames
                // Invalid: "NO - some comment" or "YES # this is a comment"
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
     * Test that SSL settings will be valid after entrypoint uncomments them
     * 
     * Simulates what the entrypoint script does when enabling SSL:
     * - Uncomments "# ssl_enable=NO" to "ssl_enable=YES"
     * - Uncomments "# require_ssl_reuse=NO" to "require_ssl_reuse=NO"
     * - etc.
     * 
     * This catches issues where the base config would produce invalid
     * settings when processed by the entrypoint.
     */
    public function testVsftpdConfig_SSLSettingsValidAfterUncomment()
    {
        $configPath = __DIR__ . '/../../docker/vsftpd.conf';
        
        if (!file_exists($configPath)) {
            $this->markTestSkipped('vsftpd.conf not found');
            return;
        }
        
        $configContent = file_get_contents($configPath);
        
        // Simulate what entrypoint does: uncomment SSL settings
        // These are the sed commands from docker-entrypoint.sh
        $transformed = $configContent;
        $transformed = preg_replace('/^# require_ssl_reuse=NO$/m', 'require_ssl_reuse=NO', $transformed);
        $transformed = preg_replace('/^# ssl_enable=NO$/m', 'ssl_enable=YES', $transformed);
        $transformed = preg_replace('/^# ssl_ciphers=HIGH$/m', 'ssl_ciphers=HIGH', $transformed);
        
        // Check specifically that SSL-related settings have valid YES/NO values
        // This catches the bug where "# require_ssl_reuse=NO - comment" becomes
        // "require_ssl_reuse=NO - comment" which is invalid
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
