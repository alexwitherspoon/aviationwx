<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SponsorApplicationValidationTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        putenv('CONFIG_PATH=' . dirname(__DIR__, 2) . '/tests/Fixtures/airports.json.test');
        if (!defined('AVIATIONWX_SPONSOR_APPLICATION_LOAD_ONLY')) {
            define('AVIATIONWX_SPONSOR_APPLICATION_LOAD_ONLY', true);
        }
        require_once dirname(__DIR__, 2) . '/lib/config.php';
        require_once dirname(__DIR__, 2) . '/api/sponsor-application.php';
    }

    public function testValidateSponsorApplicationBody_RejectsNonStringMessage(): void
    {
        $errors = validateSponsorApplicationBody([
            'airport_id' => 'kspb',
            'org_name' => 'Test FBO',
            'contact_name' => 'Jane',
            'contact_email' => 'test@example.com',
            'org_type' => 'on_airport_business',
            'message' => ['x'],
        ]);

        self::assertArrayHasKey('message', $errors);
    }

    public function testValidateSponsorApplicationBody_RejectsNonHttpLogoUrl(): void
    {
        $errors = validateSponsorApplicationBody([
            'airport_id' => 'kspb',
            'org_name' => 'Test FBO',
            'contact_name' => 'Jane',
            'contact_email' => 'test@example.com',
            'org_type' => 'on_airport_business',
            'logo_url' => 'ftp://example.com/logo.png',
        ]);

        self::assertArrayHasKey('logo_url', $errors);
    }
}
