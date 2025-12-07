<?php

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/push-webcam-validator.php';

class PushWebcamValidatorTest extends TestCase
{
    public function testValidPushWebcamConfig()
    {
        $cam = [
            'name' => 'Test Camera',
            'type' => 'push',
            'push_config' => [
                'username' => 'aB3xK9mP2qR7vN',  // 14 chars
                'password' => 'mK8pL3nQ6rT9vW',  // 14 chars
                'protocol' => 'sftp',
                'port' => 2222,
                'max_file_size_mb' => 10,
                'allowed_extensions' => ['jpg', 'jpeg', 'png']
            ],
            'refresh_seconds' => 300
        ];
        
        $result = validatePushWebcamConfig($cam, 'kspb', 0);
        
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }
    
    public function testMissingPushConfig()
    {
        $cam = [
            'name' => 'Test Camera',
            'type' => 'push'
        ];
        
        $result = validatePushWebcamConfig($cam, 'kspb', 0);
        
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('push_config is required', $result['errors'][0]);
    }
    
    public function testInvalidUsernameLength()
    {
        $cam = [
            'name' => 'Test Camera',
            'type' => 'push',
            'push_config' => [
                'username' => 'short',
                'password' => 'mK8pL3nQ6rT9vW2',
                'protocol' => 'sftp'
            ]
        ];
        
        $result = validatePushWebcamConfig($cam, 'kspb', 0);
        
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('username must be exactly 14 characters', $result['errors'][0]);
    }
    
    public function testInvalidPasswordLength()
    {
        $cam = [
            'name' => 'Test Camera',
            'type' => 'push',
            'push_config' => [
                'username' => 'aB3xK9mP2qR7vN',
                'password' => 'short',
                'protocol' => 'sftp'
            ]
        ];
        
        $result = validatePushWebcamConfig($cam, 'kspb', 0);
        
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('password must be exactly 14 characters', $result['errors'][0]);
    }
    
    public function testInvalidProtocol()
    {
        $cam = [
            'name' => 'Test Camera',
            'type' => 'push',
            'push_config' => [
                'username' => 'aB3xK9mP2qR7vN',  // 14 chars
                'password' => 'mK8pL3nQ6rT9vW',  // 14 chars
                'protocol' => 'invalid'
            ]
        ];
        
        $result = validatePushWebcamConfig($cam, 'kspb', 0);
        
        $this->assertFalse($result['valid']);
        $errorMessages = implode(' ', $result['errors']);
        $this->assertStringContainsString('protocol must be one of', $errorMessages);
    }
    
    public function testValidProtocols()
    {
        $validProtocols = ['ftp', 'ftps', 'sftp'];
        
        foreach ($validProtocols as $protocol) {
            $cam = [
                'name' => 'Test Camera',
                'type' => 'push',
                'push_config' => [
                    'username' => 'aB3xK9mP2qR7vN',  // 14 chars
                    'password' => 'mK8pL3nQ6rT9vW',  // 14 chars
                    'protocol' => $protocol
                ]
            ];
            
            $result = validatePushWebcamConfig($cam, 'kspb', 0);
            $this->assertTrue($result['valid'], "Protocol {$protocol} should be valid");
        }
    }
    
    public function testInvalidRefreshSeconds()
    {
        $cam = [
            'name' => 'Test Camera',
            'type' => 'push',
            'push_config' => [
                'username' => 'aB3xK9mP2qR7vN',  // 14 chars
                'password' => 'mK8pL3nQ6rT9vW',  // 14 chars
                'protocol' => 'sftp'
            ],
            'refresh_seconds' => 30
        ];
        
        $result = validatePushWebcamConfig($cam, 'kspb', 0);
        
        $this->assertFalse($result['valid']);
        $errorMessages = implode(' ', $result['errors']);
        $this->assertStringContainsString('refresh_seconds must be at least 60', $errorMessages);
    }
    
    public function testInvalidFileSize()
    {
        $cam = [
            'name' => 'Test Camera',
            'type' => 'push',
            'push_config' => [
                'username' => 'aB3xK9mP2qR7vN',  // 14 chars
                'password' => 'mK8pL3nQ6rT9vW',  // 14 chars
                'protocol' => 'sftp',
                'max_file_size_mb' => 200
            ]
        ];
        
        $result = validatePushWebcamConfig($cam, 'kspb', 0);
        
        $this->assertFalse($result['valid']);
        $errorMessages = implode(' ', $result['errors']);
        $this->assertStringContainsString('max_file_size_mb must be between 1 and 100', $errorMessages);
    }
    
    public function testInvalidExtension()
    {
        $cam = [
            'name' => 'Test Camera',
            'type' => 'push',
            'push_config' => [
                'username' => 'aB3xK9mP2qR7vN',  // 14 chars
                'password' => 'mK8pL3nQ6rT9vW',  // 14 chars
                'protocol' => 'sftp',
                'allowed_extensions' => ['jpg', 'exe']
            ]
        ];
        
        $result = validatePushWebcamConfig($cam, 'kspb', 0);
        
        $this->assertFalse($result['valid']);
        $errorMessages = implode(' ', $result['errors']);
        $this->assertStringContainsString('invalid extension', $errorMessages);
    }
    
    public function testUniqueUsernames()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'webcams' => [
                        [
                            'type' => 'push',
                            'push_config' => [
                                'username' => 'aB3xK9mP2qR7vN',  // 14 chars
                                'password' => 'mK8pL3nQ6rT9vW',  // 14 chars
                                'protocol' => 'sftp'
                            ]
                        ],
                        [
                            'type' => 'push',
                            'push_config' => [
                                'username' => 'aB3xK9mP2qR7vN', // Duplicate
                                'password' => 'xY9zA2bC4dE6fG',  // 14 chars
                                'protocol' => 'ftp'
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateUniquePushUsernames($config);
        
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Duplicate username', $result['errors'][0]);
    }
    
    public function testValidUniqueUsernames()
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'webcams' => [
                        [
                            'type' => 'push',
                            'push_config' => [
                                'username' => 'aB3xK9mP2qR7vN',  // 14 chars
                                'password' => 'mK8pL3nQ6rT9vW',  // 14 chars
                                'protocol' => 'sftp'
                            ]
                        ],
                        [
                            'type' => 'push',
                            'push_config' => [
                                'username' => 'xY9zA2bC4dE6fG', // Different, 14 chars
                                'password' => 'mK8pL3nQ6rT9vW',  // 14 chars
                                'protocol' => 'ftp'
                            ]
                        ]
                    ]
                ]
            ]
        ];
        
        $result = validateUniquePushUsernames($config);
        
        $this->assertTrue($result['valid']);
    }
    
    public function testPushWebcamSettingsValidation()
    {
        $settings = [
            'global_allowed_ips' => ['192.168.1.1', '10.0.0.1'],
            'global_denied_ips' => [],
            'enforce_ip_allowlist' => false
        ];
        
        $result = validatePushWebcamSettings($settings);
        
        $this->assertTrue($result['valid']);
    }
    
    public function testInvalidIPAddress()
    {
        $settings = [
            'global_allowed_ips' => ['invalid.ip.address']
        ];
        
        $result = validatePushWebcamSettings($settings);
        
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Invalid IP address', $result['errors'][0]);
    }
}

