<?php

/**
 * Unit tests for OurAirports CSV body validation.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/ourairports/urls.php';

class OurAirportsCsvValidationTest extends TestCase
{
    public function testRejectsEmptyBody(): void
    {
        $this->assertFalse(ourAirportsCsvBodyIsValid('', 'airports'));
    }

    public function testAcceptsAirportsHeader(): void
    {
        $body = "id,ident,type,name,latitude_deg,longitude_deg\n1,KLAX,large_airport,LAX,0,0\n";

        $this->assertTrue(ourAirportsCsvBodyIsValid($body, 'airports'));
    }

    public function testAcceptsRunwaysHeader(): void
    {
        $body = "id,airport_ref,length_ft,surface\n1,1,5000,ASP\n";

        $this->assertTrue(ourAirportsCsvBodyIsValid($body, 'runways'));
    }

    public function testAcceptsFrequenciesHeader(): void
    {
        $body = "id,airport_ref,frequency_mhz,type,description\n1,1,118.0,ATIS,ATIS\n";

        $this->assertTrue(ourAirportsCsvBodyIsValid($body, 'airport_frequencies'));
    }

    public function testRejectsHtmlErrorPage(): void
    {
        $body = "<!DOCTYPE html><html><body>Not Found</body></html>";

        $this->assertFalse(ourAirportsCsvBodyIsValid($body, 'airports'));
    }

    public function testAcceptsQuotedAirportsHeaderFromUpstream(): void
    {
        $body = "\"id\",\"ident\",\"type\",\"name\",\"latitude_deg\",\"longitude_deg\"\n1,KLAX,large_airport,LAX,0,0\n";

        $this->assertTrue(ourAirportsCsvBodyIsValid($body, 'airports'));
    }

    public function testAcceptsQuotedRunwaysHeaderFromUpstream(): void
    {
        $body = "\"id\",\"airport_ref\",\"length_ft\",\"surface\"\n1,1,5000,ASP\n";

        $this->assertTrue(ourAirportsCsvBodyIsValid($body, 'runways'));
    }

    public function testAcceptsQuotedFrequenciesHeaderFromUpstream(): void
    {
        $body = "\"id\",\"airport_ref\",\"frequency_mhz\",\"type\",\"description\"\n1,1,118.0,ATIS,ATIS\n";

        $this->assertTrue(ourAirportsCsvBodyIsValid($body, 'airport_frequencies'));
    }
}
