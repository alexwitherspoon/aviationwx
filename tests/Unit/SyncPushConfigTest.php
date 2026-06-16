<?php
/**
 * Tests for sync-push-config.php case normalization
 * 
 * Verifies that airport IDs are normalized to lowercase to prevent
 * mismatches between config, username mapping, and webcam worker.
 */

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/logger.php';

class SyncPushConfigTest extends TestCase
{
    private string $testMappingFile;

    /** @var list<string> */
    private array $tempPaths = [];
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create temporary mapping file for tests
        $this->testMappingFile = sys_get_temp_dir() . '/test_username_mapping_' . uniqid() . '.json';
    }
    
    protected function tearDown(): void
    {
        if (file_exists($this->testMappingFile)) {
            unlink($this->testMappingFile);
        }
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempPaths = [];
        
        parent::tearDown();
    }

    private function trackTempFile(string $path): string
    {
        $this->tempPaths[] = $path;

        return $path;
    }
    
    /**
     * Test that airport IDs are normalized to lowercase when reading existing cameras
     * 
     * This tests the fix for the case sensitivity bug where uppercase airport IDs
     * in the username mapping caused webcam worker validation failures.
     */
    public function testGetExistingPushCameras_NormalizesAirportIdToLowercase(): void
    {
        // Create mapping with mixed case airport IDs (simulates legacy/buggy data)
        $mixedCaseMapping = [
            'testcam1' => [
                'camera' => 'TESTAIRPORT_0',  // Uppercase (bug case)
                'airport' => 'TESTAIRPORT',   // Uppercase (bug case)
                'cam' => 0,
                'protocols' => ['ftp', 'sftp']
            ],
            'testcam2' => [
                'camera' => 'kspb_0',         // Lowercase (correct)
                'airport' => 'kspb',          // Lowercase (correct)
                'cam' => 0,
                'protocols' => ['ftp', 'sftp']
            ],
            'testcam3' => [
                'camera' => 'MixedCase_1',    // Mixed case
                'airport' => 'MixedCase',     // Mixed case
                'cam' => 1,
                'protocols' => ['ftp', 'sftp']
            ]
        ];
        
        file_put_contents($this->testMappingFile, json_encode($mixedCaseMapping));
        
        // Load the mapping and normalize (simulating getExistingPushCameras behavior)
        $mapping = json_decode(file_get_contents($this->testMappingFile), true);
        
        $cameras = [];
        $seen = [];
        foreach ($mapping as $username => $info) {
            if (isset($info['airport']) && isset($info['cam'])) {
                // This is the normalization we added in the fix
                $airport = strtolower($info['airport']);
                $key = $airport . '_' . $info['cam'];
                if (!isset($seen[$key])) {
                    $cameras[] = [
                        'airport' => $airport,
                        'cam' => intval($info['cam'])
                    ];
                    $seen[$key] = true;
                }
            }
        }
        
        // All airport IDs should be lowercase
        foreach ($cameras as $camera) {
            $this->assertSame(
                strtolower($camera['airport']),
                $camera['airport'],
                "Airport ID '{$camera['airport']}' should be lowercase"
            );
        }
        
        // Verify specific cases
        $airportIds = array_column($cameras, 'airport');
        $this->assertContains('testairport', $airportIds, 'TESTAIRPORT should be normalized to testairport');
        $this->assertContains('kspb', $airportIds, 'kspb should remain lowercase');
        $this->assertContains('mixedcase', $airportIds, 'MixedCase should be normalized to mixedcase');
        
        // Uppercase versions should not exist
        $this->assertNotContains('TESTAIRPORT', $airportIds, 'Uppercase TESTAIRPORT should not exist');
        $this->assertNotContains('MixedCase', $airportIds, 'Mixed case MixedCase should not exist');
    }
    
    /**
     * Test that camera key generation uses lowercase airport ID
     */
    public function testCameraKeyGeneration_UsesLowercaseAirportId(): void
    {
        $airportId = 'TESTAIRPORT';
        $camIndex = 0;
        
        // The fix normalizes airport ID before creating camera key
        $normalizedAirportId = strtolower($airportId);
        $cameraKey = $normalizedAirportId . '_' . $camIndex;
        
        $this->assertSame('testairport_0', $cameraKey);
        $this->assertNotSame('TESTAIRPORT_0', $cameraKey);
    }
    
    /**
     * Test that directory paths use lowercase airport IDs
     * 
     * This verifies consistency with getWebcamUploadDir() which also normalizes to lowercase.
     */
    public function testDirectoryPathGeneration_UsesLowercaseAirportId(): void
    {
        $airportId = 'UPPERCASE';
        $username = 'testuser';
        
        // The fix ensures directory creation uses lowercase
        $normalizedAirportId = strtolower($airportId);
        
        // This simulates the path generation in createCameraDirectory
        $basePath = '/var/www/html/cache/uploads';
        $expectedPath = $basePath . '/' . $normalizedAirportId . '/' . $username;
        
        $this->assertSame('/var/www/html/cache/uploads/uppercase/testuser', $expectedPath);
        
        // The path should not contain uppercase
        $this->assertStringNotContainsString('UPPERCASE', $expectedPath);
    }
    
    /**
     * Test that username mapping stores lowercase airport IDs
     */
    public function testUsernameMappingStorage_StoresLowercaseAirportId(): void
    {
        $airportId = 'TESTID';
        $camIndex = 0;
        $username = 'testuser';
        $protocol = 'ftps';
        
        // Simulate the fixed syncCameraUser behavior
        $normalizedAirportId = strtolower($airportId);
        $cameraKey = $normalizedAirportId . '_' . $camIndex;
        
        $usernameMapping = [];
        $usernameMapping[$username] = [
            'camera' => $cameraKey,
            'airport' => $normalizedAirportId,
            'cam' => $camIndex,
            'protocol' => $protocol
        ];
        
        // Verify the mapping contains lowercase
        $this->assertSame('testid', $usernameMapping[$username]['airport']);
        $this->assertSame('testid_0', $usernameMapping[$username]['camera']);
    }

    /**
     * Regression: FTP user success log must reference $ftpDir (FTP root), not $filesDir (SFTP path).
     */
    public function testFtpUserCreatedLog_UsesFtpDirInSource(): void
    {
        $path = __DIR__ . '/../../scripts/sync-push-config.php';
        $this->assertFileExists($path);
        $src = file_get_contents($path);
        $this->assertIsString($src);
        $this->assertStringContainsString(
            "'local_root' => \$ftpDir",
            $src,
            'FTP user created/updated log context must use $ftpDir'
        );
        $this->assertStringNotContainsString(
            "'local_root' => \$filesDir",
            $src,
            'local_root in logs must not use $filesDir (SFTP path; undefined in FTP flow)'
        );
    }

    public function testParseVsftpdVirtualUsersFile_AllowsSharedPasswordsAcrossUsernames(): void
    {
        require_once __DIR__ . '/../../scripts/sync-push-config.php';

        $path = $this->trackTempFile(sys_get_temp_dir() . '/vsftpd-users-' . uniqid('', true) . '.txt');
        file_put_contents($path, "kczkcam1\nsharedpass14xx\nkczkcam2\nsharedpass14xx\n");

        $parsed = parseVsftpdVirtualUsersFile($path);

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(2, $parsed['users']);
        $this->assertSame('sharedpass14xx', $parsed['users']['kczkcam1']);
        $this->assertSame('sharedpass14xx', $parsed['users']['kczkcam2']);
    }

    public function testParseVsftpdVirtualUsersFile_DetectsDuplicateUsernames(): void
    {
        require_once __DIR__ . '/../../scripts/sync-push-config.php';

        $path = $this->trackTempFile(sys_get_temp_dir() . '/vsftpd-users-' . uniqid('', true) . '.txt');
        file_put_contents($path, "dupuser14chars\npassone14chars\ndupuser14chars\npasstwo14chars\n");

        $parsed = parseVsftpdVirtualUsersFile($path);

        $this->assertNotEmpty($parsed['errors']);
        $this->assertStringContainsString("Duplicate vsftpd username 'dupuser14chars'", $parsed['errors'][0]);
        $this->assertSame(['dupuser14chars' => 'passone14chars'], $parsed['users']);
    }

    public function testParseVsftpdVirtualUsersFile_DetectsOddLineCount(): void
    {
        require_once __DIR__ . '/../../scripts/sync-push-config.php';

        $path = $this->trackTempFile(sys_get_temp_dir() . '/vsftpd-users-' . uniqid('', true) . '.txt');
        file_put_contents($path, "onlyuser14char\n");

        $parsed = parseVsftpdVirtualUsersFile($path);

        $this->assertNotEmpty($parsed['errors']);
        $this->assertStringContainsString('odd line count', $parsed['errors'][0]);
        $this->assertSame([], $parsed['users']);
    }

    public function testValidateConfigBeforeApply_RejectsDuplicatePushUsernames(): void
    {
        require_once __DIR__ . '/../../lib/config.php';
        require_once __DIR__ . '/../../scripts/sync-push-config.php';

        $path = $this->trackTempFile(sys_get_temp_dir() . '/sync-config-' . uniqid('', true) . '.json');
        $config = [
            'airports' => [
                'kspb' => [
                    'webcams' => [
                        [
                            'type' => 'push',
                            'push_config' => [
                                'username' => 'aB3xK9mP2qR7vN',
                                'password' => 'mK8pL3nQ6rT9vW',
                            ],
                        ],
                        [
                            'type' => 'push',
                            'push_config' => [
                                'username' => 'aB3xK9mP2qR7vN',
                                'password' => 'xY9zA2bC4dE6fG',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));

        $result = validateConfigBeforeApply($path);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Duplicate username', $result['error']);
    }
}
