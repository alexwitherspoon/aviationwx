<?php
/**
 * Regression: SFTP status message must use $sshdRunning (not undefined $sftpRunning).
 *
 * @see lib/status-checks.php::checkFtpSftpServices()
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/status-checks.php';

final class FtpSftpStatusMessageTest extends TestCase
{
    public function testSourceUsesSshdRunningForSftpMessage(): void
    {
        $path = dirname(__DIR__, 2) . '/lib/status-checks.php';
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $this->assertStringContainsString(
            "\$messageParts[] = \$sshdRunning ? 'SFTP running' : 'SFTP not running';",
            $raw
        );
        $this->assertStringNotContainsString('$sftpRunning', $raw);
    }

    public function testCheckFtpSftpServices_MessageMatchesSshdStateWithoutWarnings(): void
    {
        $undefinedWarnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$undefinedWarnings): bool {
            if (
                str_contains($message, 'Undefined variable')
                || str_contains($message, 'Undefined array key')
            ) {
                $undefinedWarnings[] = $errno . ':' . $message;
            }

            return true;
        });

        try {
            $result = checkFtpSftpServices();
        } finally {
            restore_error_handler();
        }

        $this->assertSame(
            [],
            $undefinedWarnings,
            'checkFtpSftpServices must not emit undefined variable/key warnings'
        );
        $this->assertIsArray($result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('services', $result);
        $this->assertArrayHasKey('sshd', $result['services']);

        $sshdRunning = (bool) ($result['services']['sshd']['running'] ?? false);
        $message = (string) $result['message'];
        if (str_contains($message, 'SFTP disabled')) {
            $this->assertStringNotContainsString('SFTP running', $message);
            $this->assertStringNotContainsString('SFTP not running', $message);
            return;
        }

        $expected = $sshdRunning ? 'SFTP running' : 'SFTP not running';
        $this->assertStringContainsString($expected, $message);
    }
}
