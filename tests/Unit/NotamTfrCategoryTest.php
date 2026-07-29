<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/tfr-category.php';

/**
 * Airspace TFR headline category classification (#271 polish).
 */
final class NotamTfrCategoryTest extends TestCase
{
    public function testSecuritySsi_NationalDefense_NotLabeledVip(): void
    {
        $text = "TX..AIRSPACE BROWNSVILLE, TX..TEMPORARY FLIGHT RESTRICTIONS.\n"
            . "PURSUANT TO 49 USC 40103(B)(3), THE FEDERAL AVIATION ADMINISTRATION (FAA) "
            . "CLASSIFIES THE AIRSPACE DEFINED IN THIS NOTAM AS 'NTL DEFENSE AIRSPACE'. "
            . "PURSUANT TO 14 CFR 99.7, SPECIAL SECURITY INSTRUCTIONS, ALL ACFT FLT OPS ARE "
            . "PROHIBITED: WI AN AREA DEFINED AS 2.5NM RADIUS OF 255950N0970921W "
            . "SFC-5000FT AGL. UAS OPS IN DCT SUPPORT OF AN ACT NTL DEFENSE, HOMELAND "
            . "SECURITY, LAW ENFORCEMENT, FIREFIGHTING, SAR, AND DISASTER RESPONSE MISSION. "
            . "AIRCRAFT DIRECTLY SUPPORTING ACTIVE SPACE OPERATIONS.";

        $this->assertSame('security', notamClassifyAirspaceTfrCategory($text));
        $headline = notamBuildAirspaceTfrHeadlineFromText($text);
        $this->assertStringStartsWith('Security TFR', $headline);
        $this->assertStringNotContainsString('VIP', $headline);
        $this->assertStringContainsString('2.5 NM radius', $headline);
        $this->assertStringContainsString('SFC - 5000 ft', $headline);
    }

    public function testVip_ExplicitVipToken_StillVip(): void
    {
        $text = 'MD..AIRSPACE THURMONT..TEMPORARY FLIGHT RESTRICTIONS. VIP MOVEMENT. '
            . 'WI AN AREA DEFINED AS 5NM RADIUS OF 393845N0772800W SFC-17999FT.';
        $this->assertSame('vip_disaster', notamClassifyAirspaceTfrCategory($text));
        $this->assertStringStartsWith('VIP TFR', notamBuildAirspaceTfrHeadlineFromText($text));
    }

    public function testVip_Section91137A3_StillVipDisaster(): void
    {
        $text = 'OR..AIRSPACE TEMPORARY FLIGHT RESTRICTIONS PURSUANT TO 14 CFR SECTION '
            . '91.137(A)(3) WI AN AREA DEFINED AS 5NM RADIUS OF 450000N1220000W SFC-5000FT.';
        $this->assertSame('vip_disaster', notamClassifyAirspaceTfrCategory($text));
    }

    public function testDisasterResponseBoilerplateAlone_DoesNotForceVip(): void
    {
        $text = 'TX..AIRSPACE TEMPORARY FLIGHT RESTRICTIONS. UAS OPS IN SUPPORT OF '
            . 'DISASTER RESPONSE MISSION MAY BE AUTHORIZED. WI AN AREA DEFINED AS '
            . '3NM RADIUS OF 260000N0970000W SFC-3000FT.';
        $this->assertSame('uas', notamClassifyAirspaceTfrCategory($text));
    }

    public function testFire_Section91137A2(): void
    {
        $text = 'OR..AIRSPACE TEMPORARY FLIGHT RESTRICTIONS PURSUANT TO 14 CFR SECTION '
            . '91.137(A)(2) FIRE FIGHTING ACFT OPS WI AN AREA DEFINED AS 5NM RADIUS OF '
            . '440000N1210000W SFC-9000FT.';
        $this->assertSame('fire', notamClassifyAirspaceTfrCategory($text));
        $this->assertStringStartsWith('Fire TFR', notamBuildAirspaceTfrHeadlineFromText($text));
    }

    public function testSpaceLaunch_RocketKeyword(): void
    {
        $text = 'FL..AIRSPACE TEMPORARY FLIGHT RESTRICTIONS FOR SPACE LAUNCH OPS '
            . 'WI AN AREA DEFINED AS 10NM RADIUS OF 283000N0803000W SFC-FL180.';
        $this->assertSame('space_launch', notamClassifyAirspaceTfrCategory($text));
        $this->assertStringStartsWith('Rocket test TFR', notamBuildAirspaceTfrHeadlineFromText($text));
    }
}
