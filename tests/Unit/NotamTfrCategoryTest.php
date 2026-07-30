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

    public function testPresidential91_141_LabeledVipEvenWithSsiMarkers(): void
    {
        $text = 'MD..AIRSPACE THURMONT, MARYLAND..TEMPORARY FLIGHT RESTRICTIONS. '
            . "PURSUANT TO 49 USC 40103(B)(3), FAA CLASSIFIES THIS AS 'NTL DEFENSE AIRSPACE'. "
            . 'PURSUANT TO 14 CFR 99.7, SPECIAL SECURITY INSTRUCTIONS. '
            . 'PURSUANT TO 14 CFR 91.141, ALL ACFT FLT OPS ARE PROHIBITED WI AN AREA DEFINED AS '
            . '30NM RADIUS OF 393855N0772800W SFC-17999FT. OFFICE OF THE PRESIDENT / USSS. '
            . 'UAS EXCEPTION LISTS INCLUDE FIREFIGHTING AND DISASTER RESPONSE MISSION.';

        $this->assertSame('vip_disaster', notamClassifyAirspaceTfrCategory($text));
        $this->assertStringStartsWith('VIP TFR', notamBuildAirspaceTfrHeadlineFromText($text));
    }

    public function testVip_ExplicitVipToken_StillVip(): void
    {
        $text = 'MD..AIRSPACE THURMONT..TEMPORARY FLIGHT RESTRICTIONS. VIP MOVEMENT. '
            . 'WI AN AREA DEFINED AS 5NM RADIUS OF 393845N0772800W SFC-17999FT.';
        $this->assertSame('vip_disaster', notamClassifyAirspaceTfrCategory($text));
        $this->assertStringStartsWith('VIP TFR', notamBuildAirspaceTfrHeadlineFromText($text));
    }

    public function testDisasterRelief_NotVipWording(): void
    {
        $text = 'OR..AIRSPACE TEMPORARY FLIGHT RESTRICTIONS FOR DISASTER RELIEF OPS '
            . 'WI AN AREA DEFINED AS 5NM RADIUS OF 450000N1220000W SFC-5000FT.';
        $this->assertSame('vip_disaster', notamClassifyAirspaceTfrCategory($text));
        $this->assertStringStartsWith('Disaster relief TFR', notamBuildAirspaceTfrHeadlineFromText($text));
    }

    public function testVip_Section91137A3_StillVipDisaster(): void
    {
        $text = 'OR..AIRSPACE TEMPORARY FLIGHT RESTRICTIONS PURSUANT TO 14 CFR SECTION '
            . '91.137(A)(3) WI AN AREA DEFINED AS 5NM RADIUS OF 450000N1220000W SFC-5000FT.';
        $this->assertSame('vip_disaster', notamClassifyAirspaceTfrCategory($text));
        $this->assertStringStartsWith('VIP TFR', notamBuildAirspaceTfrHeadlineFromText($text));
    }

    public function testDisasterResponseBoilerplateAlone_DoesNotForceVip(): void
    {
        $text = 'TX..AIRSPACE TEMPORARY FLIGHT RESTRICTIONS. UAS OPS IN SUPPORT OF '
            . 'DISASTER RESPONSE MISSION MAY BE AUTHORIZED. WI AN AREA DEFINED AS '
            . '3NM RADIUS OF 260000N0970000W SFC-3000FT.';
        $this->assertSame('uas', notamClassifyAirspaceTfrCategory($text));
    }

    public function testFirefightingOneWordInSsi_DoesNotClassifyAsFire(): void
    {
        $text = "TX..AIRSPACE BROWNSVILLE..TEMPORARY FLIGHT RESTRICTIONS. "
            . "PURSUANT TO 49 USC 40103(B)(3) 'NTL DEFENSE AIRSPACE'. "
            . 'PURSUANT TO 14 CFR 99.7, SPECIAL SECURITY INSTRUCTIONS. '
            . 'UAS OPS IN DCT SUPPORT OF FIREFIGHTING AND DISASTER RESPONSE MISSION. '
            . 'WI AN AREA DEFINED AS 2.5NM RADIUS OF 255950N0970921W SFC-5000FT.';
        $this->assertSame('security', notamClassifyAirspaceTfrCategory($text));
        $this->assertNotSame('fire', notamClassifyAirspaceTfrCategory($text));
    }

    public function testFire_Section91137A2(): void
    {
        $text = 'OR..AIRSPACE TEMPORARY FLIGHT RESTRICTIONS PURSUANT TO 14 CFR SECTION '
            . '91.137(A)(2) FIRE FIGHTING ACFT OPS WI AN AREA DEFINED AS 5NM RADIUS OF '
            . '440000N1210000W SFC-9000FT.';
        $this->assertSame('fire', notamClassifyAirspaceTfrCategory($text));
        $this->assertStringStartsWith('Fire TFR', notamBuildAirspaceTfrHeadlineFromText($text));
    }

    public function testHazardSurface_Section91137A1(): void
    {
        $text = 'HI..AIRSPACE TEMPORARY FLIGHT RESTRICTIONS PURSUANT TO 14 CFR SECTION '
            . '91.137(A)(1) WI AN AREA DEFINED AS 5NM RADIUS OF 193000N1551500W SFC-5000FT.';
        $this->assertSame('hazard_surface', notamClassifyAirspaceTfrCategory($text));
        $this->assertStringStartsWith('Hazard TFR', notamBuildAirspaceTfrHeadlineFromText($text));
    }

    public function testSpaceLaunch_LaunchAct_LabeledSpaceLaunchNotTest(): void
    {
        $text = 'NV..AIRSPACE BLACK ROCK..TEMPORARY FLIGHT RESTRICTIONS WI AN AREA DEFINED AS '
            . '15NM RADIUS OF 405259N1190204W SFC-UNL TO PROVIDE A SAFE ENVIRONMENT FOR '
            . 'ROCKET LAUNCH ACT.';
        $this->assertSame('space_launch', notamClassifyAirspaceTfrCategory($text));
        $this->assertStringStartsWith('Space launch TFR', notamBuildAirspaceTfrHeadlineFromText($text));
    }

    public function testSpaceLaunch_CapeCanaveralSpaceOpsArea_NotGenericTfr(): void
    {
        $text = 'FL..AIRSPACE CAPE CANAVERAL, FL..TEMPORARY FLIGHT RESTRICTION. '
            . 'PURSUANT TO 14 CFR SECTION 91.143, SPACE OPS AREA, ACFT OPS ARE PROHIBITED '
            . 'WI AN AREA DEFINED AS 285116N0804219W TO 290730N0803000W TO 283625N0800242W '
            . 'TO POINT OF ORIGIN SFC-FL180.';
        $this->assertSame('space_launch', notamClassifyAirspaceTfrCategory($text));
        $headline = notamBuildAirspaceTfrHeadlineFromText($text);
        $this->assertStringStartsWith('Space launch TFR', $headline);
        $this->assertDoesNotMatchRegularExpression('/^TFR - /', $headline);
        $this->assertStringContainsString('FL180', $headline);
    }

    public function testSpaceLaunch_RocketEngineTest_LabeledRocketTest(): void
    {
        $text = 'ZLC UT..AIRSPACE OGDEN, UT..TEMPORARY FLIGHT RESTRICTIONS WITHIN AN AREA '
            . 'DEFINED AS 5NM RADIUS OF 413900N1122300W STATIC GROUND BASED ROCKET ENGINE TEST.';
        $this->assertSame('space_launch', notamClassifyAirspaceTfrCategory($text));
        $this->assertStringStartsWith('Rocket test TFR', notamBuildAirspaceTfrHeadlineFromText($text));
    }

    public function testUasGathering_44812_LabeledEventUas(): void
    {
        $text = 'MD..AIRSPACE COLLEGE PARK, MD..TEMPORARY FLIGHT RESTRICTIONS. PURSUANT TO '
            . '49 U.S.C. SECTION 44812 FOR PROTECTION OF LARGE PUBLIC GATHERINGS. UAS FLT OPS '
            . 'ARE PROHIBITED WI AN AREA DEFINED AS 1NM RADIUS OF 385925N0765650W SFC-400FT.';
        $this->assertSame('uas_gathering', notamClassifyAirspaceTfrCategory($text));
        $this->assertStringStartsWith('Event UAS TFR', notamBuildAirspaceTfrHeadlineFromText($text));
    }

    public function testAirshow_AerialDemonstration_NotSportingEventLabel(): void
    {
        $text = 'ME..AIRSPACE OWLS HEAD..TEMPORARY FLIGHT RESTRICTION. PURSUANT TO 14 CFR '
            . 'SECTION 91.145, MANAGEMENT OF ACFT OPS IN THE VICINITY OF AERIAL DEMONSTRATIONS '
            . 'AND MAJOR SPORTING EVENTS, ACFT OPS ARE PROHIBITED WI AN AREA DEFINED AS 5NM '
            . 'RADIUS OF 440343N0690554W SFC-8000FT.';
        $this->assertSame('airshow', notamClassifyAirspaceTfrCategory($text));
        $this->assertStringStartsWith('Airshow TFR', notamBuildAirspaceTfrHeadlineFromText($text));
    }

    public function testSporting_StadiumWithoutAerialDemo(): void
    {
        $text = 'TX..AIRSPACE ARLINGTON..TEMPORARY FLIGHT RESTRICTIONS FOR A MAJOR SPORTING '
            . 'EVENT AT THE STADIUM. WI AN AREA DEFINED AS 3NM RADIUS OF 324500N0970500W SFC-3000FT.';
        $this->assertSame('sporting', notamClassifyAirspaceTfrCategory($text));
        $this->assertStringStartsWith('Sporting event TFR', notamBuildAirspaceTfrHeadlineFromText($text));
    }

    public function testGeneral_NonTfrText_NotLabeledTfr(): void
    {
        $text = 'RWY 15/33 CLSD';
        $this->assertSame('general', notamClassifyAirspaceTfrCategory($text));
        $this->assertSame('Airspace notice', notamBuildAirspaceTfrHeadlineFromText($text));
    }
}
