<?php
/**
 * Unit tests for the Public API airport ID validator.
 *
 * Lock the validator to the shared airport-ID bounds so a config-valid
 * identifier is always servable and an over-max identifier is rejected.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/public-api/middleware.php';

class PublicApiAirportIdValidationTest extends TestCase
{
    public function testValidate_AcceptsLowercaseIcao(): void
    {
        $this->assertSame('kspb', validatePublicApiAirportId('kspb'));
    }

    public function testValidate_NormalizesUppercase(): void
    {
        $this->assertSame('kspb', validatePublicApiAirportId('KSPB'));
    }

    public function testValidate_AcceptsIdAtMaxLength(): void
    {
        $id = str_repeat('a', AIRPORT_ID_MAX_LENGTH);
        $this->assertSame($id, validatePublicApiAirportId($id));
    }

    public function testValidate_RejectsIdOverMaxLength(): void
    {
        $this->assertNull(validatePublicApiAirportId(str_repeat('a', AIRPORT_ID_MAX_LENGTH + 1)));
    }

    public function testValidate_RejectsEmpty(): void
    {
        $this->assertNull(validatePublicApiAirportId(''));
    }

    public function testValidate_RejectsWhitespace(): void
    {
        $this->assertNull(validatePublicApiAirportId('ks pb'));
    }

    public function testValidate_RejectsLeadingHyphen(): void
    {
        $this->assertNull(validatePublicApiAirportId('-airport'));
    }

    public function testValidate_RejectsConsecutiveHyphens(): void
    {
        $this->assertNull(validatePublicApiAirportId('air--port'));
    }
}