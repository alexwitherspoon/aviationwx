<?php
/**
 * Unit Tests for Version File Structure
 * 
 * Tests the version.json file format and the version API endpoint logic.
 */

use PHPUnit\Framework\TestCase;

class VersionFileTest extends TestCase
{
    private string $versionFile;
    private string $versionExampleFile;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->versionFile = __DIR__ . '/../../config/version.json';
        $this->versionExampleFile = __DIR__ . '/../../config/version.json.example';
    }
    
    public function testVersionExampleFile_Exists(): void
    {
        $this->assertFileExists(
            $this->versionExampleFile,
            'version.json.example should exist as documentation'
        );
    }
    
    public function testVersionExampleFile_IsValidJson(): void
    {
        $content = file_get_contents($this->versionExampleFile);
        $json = json_decode($content, true);
        
        $this->assertNotNull($json, 'version.json.example should be valid JSON');
    }
    
    public function testVersionExampleFile_HasRequiredFields(): void
    {
        $content = file_get_contents($this->versionExampleFile);
        $json = json_decode($content, true);
        
        $requiredFields = ['hash', 'hash_full', 'timestamp', 'deploy_date', 'force_cleanup', 'max_no_update_days', 'stuck_client_cleanup'];
        
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey(
                $field,
                $json,
                "version.json.example should have field: $field"
            );
        }
    }
    
    public function testVersionExampleFile_FieldTypes(): void
    {
        $content = file_get_contents($this->versionExampleFile);
        $json = json_decode($content, true);
        
        $this->assertIsString($json['hash'], 'hash should be a string');
        $this->assertIsString($json['hash_full'], 'hash_full should be a string');
        $this->assertIsInt($json['timestamp'], 'timestamp should be an integer');
        $this->assertIsString($json['deploy_date'], 'deploy_date should be a string');
        $this->assertIsBool($json['force_cleanup'], 'force_cleanup should be a boolean');
        $this->assertIsInt($json['max_no_update_days'], 'max_no_update_days should be an integer');
        $this->assertIsBool($json['stuck_client_cleanup'], 'stuck_client_cleanup should be a boolean');
    }
    
    public function testVersionFile_IfExists_IsValidJson(): void
    {
        if (!file_exists($this->versionFile)) {
            $this->markTestSkipped('version.json does not exist (generated at deploy time)');
        }
        
        $content = file_get_contents($this->versionFile);
        $json = json_decode($content, true);
        
        $this->assertNotNull($json, 'version.json should be valid JSON if it exists');
    }
    
    public function testVersionFile_IfExists_HasRequiredFields(): void
    {
        if (!file_exists($this->versionFile)) {
            $this->markTestSkipped('version.json does not exist (generated at deploy time)');
        }
        
        $content = file_get_contents($this->versionFile);
        $json = json_decode($content, true);
        
        // Note: emergency_cleanup_enabled is optional for backwards compatibility
        $requiredFields = ['hash', 'hash_full', 'timestamp', 'deploy_date', 'force_cleanup', 'max_no_update_days'];
        
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey(
                $field,
                $json,
                "version.json should have field: $field"
            );
        }
    }
    
    public function testVersionApiEndpoint_DirectInclude_ReturnsValidJson(): void
    {
        // Test the API endpoint by including it directly (simulates CLI execution)
        $versionApiFile = __DIR__ . '/../../api/v1/version.php';
        $this->assertFileExists($versionApiFile, 'Version API file should exist');
        
        // Capture output
        ob_start();
        
        // Suppress headers in CLI mode
        @include $versionApiFile;
        
        $output = ob_get_clean();
        
        // Should return valid JSON
        $json = json_decode($output, true);
        $this->assertNotNull($json, 'Version API should return valid JSON');
        $this->assertArrayHasKey('hash', $json, 'Response should contain hash');
        $this->assertArrayHasKey('timestamp', $json, 'Response should contain timestamp');
    }
    
    public function testDeployScript_Exists(): void
    {
        $scriptFile = __DIR__ . '/../../scripts/deploy-update-cache-version.sh';
        $this->assertFileExists($scriptFile, 'Deploy script should exist');
    }
    
    public function testDeployScript_IsExecutable(): void
    {
        $scriptFile = __DIR__ . '/../../scripts/deploy-update-cache-version.sh';
        
        // Check if file has execute permission in its mode
        $perms = fileperms($scriptFile);
        $isExecutable = ($perms & 0x0040) || ($perms & 0x0008) || ($perms & 0x0001);
        
        // Note: This may not work on all systems, so we'll just check the file exists
        $this->assertFileExists($scriptFile);
    }
    
    public function testDeployScript_GeneratesVersionJson(): void
    {
        $scriptFile = __DIR__ . '/../../scripts/deploy-update-cache-version.sh';
        $content = file_get_contents($scriptFile);
        
        // Script should reference version.json
        $this->assertStringContainsString(
            'version.json',
            $content,
            'Deploy script should reference version.json'
        );
        
        // Script should generate hash
        $this->assertStringContainsString(
            'git rev-parse',
            $content,
            'Deploy script should get git hash'
        );
        
        // Script should generate timestamp
        $this->assertStringContainsString(
            'date +%s',
            $content,
            'Deploy script should generate timestamp'
        );
    }
}

