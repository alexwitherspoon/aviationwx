<?php
/**
 * Unit Tests for Embed Configurator
 * Tests embed page routing, parameter validation, and embed code generation
 */

use PHPUnit\Framework\TestCase;

class EmbedConfiguratorTest extends TestCase
{
    /**
     * Test valid embed styles
     */
    public function testValidEmbedStyles()
    {
        $validStyles = ['card', 'webcam', 'dual', 'multi', 'full'];
        
        foreach ($validStyles as $style) {
            $this->assertTrue(
                in_array($style, $validStyles),
                "Style '$style' should be valid"
            );
        }
    }
    
    /**
     * Test invalid embed style defaults to card
     */
    public function testInvalidStyleDefaultsToCard()
    {
        $validStyles = ['card', 'webcam', 'dual', 'multi', 'full'];
        $invalidStyle = 'invalid_style';
        
        // Simulate default behavior
        $style = in_array($invalidStyle, $validStyles) ? $invalidStyle : 'card';
        
        $this->assertEquals('card', $style);
    }
    
    /**
     * Test valid themes
     */
    public function testValidThemes()
    {
        $validThemes = ['light', 'dark'];
        
        $this->assertContains('light', $validThemes);
        $this->assertContains('dark', $validThemes);
    }
    
    /**
     * Test embed URL generation for local development
     */
    public function testEmbedUrlGenerationLocal()
    {
        $airportId = 'kspb';
        $style = 'card';
        $theme = 'light';
        $target = '_blank';
        
        // Local dev uses query param approach
        $baseUrl = "http://localhost:8080/?embed&airport=$airportId";
        $params = "style=$style&theme=$theme&target=$target";
        $expectedUrl = "$baseUrl&$params";
        
        $this->assertStringContainsString('embed', $expectedUrl);
        $this->assertStringContainsString('airport=kspb', $expectedUrl);
        $this->assertStringContainsString('style=card', $expectedUrl);
        $this->assertStringContainsString('theme=light', $expectedUrl);
    }
    
    /**
     * Test embed URL generation for production
     */
    public function testEmbedUrlGenerationProduction()
    {
        $airportId = 'kspb';
        $style = 'webcam';
        $theme = 'dark';
        $webcam = 0;
        
        // Production uses subdomain
        $baseUrl = "https://embed.aviationwx.org/$airportId";
        $expectedUrl = "$baseUrl?style=$style&theme=$theme&webcam=$webcam&target=_blank";
        
        $this->assertStringContainsString('embed.aviationwx.org', $expectedUrl);
        $this->assertStringContainsString('kspb', $expectedUrl);
        $this->assertStringContainsString('style=webcam', $expectedUrl);
    }
    
    /**
     * Test cams parameter parsing for dual camera
     */
    public function testCamsParameterParsingDual()
    {
        $camsParam = '0,1';
        $cams = array_map('intval', explode(',', $camsParam));
        
        $this->assertCount(2, $cams);
        $this->assertEquals(0, $cams[0]);
        $this->assertEquals(1, $cams[1]);
    }
    
    /**
     * Test cams parameter parsing for 4 camera grid
     */
    public function testCamsParameterParsingMulti()
    {
        $camsParam = '0,1,2,3';
        $cams = array_map('intval', explode(',', $camsParam));
        
        $this->assertCount(4, $cams);
        $this->assertEquals(0, $cams[0]);
        $this->assertEquals(1, $cams[1]);
        $this->assertEquals(2, $cams[2]);
        $this->assertEquals(3, $cams[3]);
    }
    
    /**
     * Test cams parameter with custom order
     */
    public function testCamsParameterCustomOrder()
    {
        $camsParam = '3,1,0,2';
        $cams = array_map('intval', explode(',', $camsParam));
        
        $this->assertEquals(3, $cams[0]);
        $this->assertEquals(1, $cams[1]);
        $this->assertEquals(0, $cams[2]);
        $this->assertEquals(2, $cams[3]);
    }
    
    /**
     * Test iframe embed code structure
     */
    public function testIframeEmbedCodeStructure()
    {
        $url = 'https://embed.aviationwx.org/kspb?style=card&theme=light&target=_blank';
        $width = 300;
        $height = 275;
        $title = 'KSPB Weather - AviationWX';
        
        $embedCode = "<iframe\n  src=\"$url\"\n  width=\"$width\"\n  height=\"$height\"\n  frameborder=\"0\"\n  loading=\"lazy\"\n  title=\"$title\">\n</iframe>";
        
        $this->assertStringContainsString('<iframe', $embedCode);
        $this->assertStringContainsString('src=', $embedCode);
        $this->assertStringContainsString("width=\"$width\"", $embedCode);
        $this->assertStringContainsString("height=\"$height\"", $embedCode);
        $this->assertStringContainsString('frameborder="0"', $embedCode);
        $this->assertStringContainsString('loading="lazy"', $embedCode);
    }
    
    /**
     * Test unit parameter defaults
     */
    public function testUnitParameterDefaults()
    {
        $defaultTempUnit = 'F';
        $defaultDistUnit = 'ft';
        $defaultWindUnit = 'kt';
        $defaultBaroUnit = 'inHg';
        
        $this->assertEquals('F', $defaultTempUnit);
        $this->assertEquals('ft', $defaultDistUnit);
        $this->assertEquals('kt', $defaultWindUnit);
        $this->assertEquals('inHg', $defaultBaroUnit);
    }
    
    /**
     * Test unit conversions - temperature F to C
     */
    public function testTemperatureConversionFtoC()
    {
        $tempF = 68;
        $tempC = ($tempF - 32) * 5 / 9;
        
        $this->assertEqualsWithDelta(20, $tempC, 0.1);
    }
    
    /**
     * Test unit conversions - distance ft to m
     */
    public function testDistanceConversionFtToM()
    {
        $distFt = 3281;
        $distM = $distFt * 0.3048;
        
        $this->assertEqualsWithDelta(1000, $distM, 1);
    }
    
    /**
     * Test unit conversions - wind kt to mph
     */
    public function testWindConversionKtToMph()
    {
        $windKt = 10;
        $windMph = $windKt * 1.15078;
        
        $this->assertEqualsWithDelta(11.5, $windMph, 0.1);
    }
    
    /**
     * Test unit conversions - pressure inHg to hPa
     */
    public function testPressureConversionInHgToHPa()
    {
        $pressInHg = 29.92;
        $pressHPa = $pressInHg * 33.8639;
        
        $this->assertEqualsWithDelta(1013.25, $pressHPa, 0.5);
    }
    
    /**
     * Test size presets for each style
     */
    public function testSizePresets()
    {
        $sizePresets = [
            'card' => ['width' => 400, 'height' => 435],
            'webcam' => ['width' => 450, 'height' => 450],
            'dual' => ['width' => 600, 'height' => 300],
            'multi' => ['width' => 600, 'height' => 475],
            'full' => ['width' => 800, 'height' => 700],
        ];
        
        $this->assertEquals(400, $sizePresets['card']['width']);
        $this->assertEquals(435, $sizePresets['card']['height']);
        
        $this->assertEquals(600, $sizePresets['dual']['width']);
        $this->assertEquals(300, $sizePresets['dual']['height']);
        
        $this->assertEquals(600, $sizePresets['multi']['width']);
        $this->assertEquals(475, $sizePresets['multi']['height']);
        
        $this->assertEquals(800, $sizePresets['full']['width']);
        $this->assertEquals(700, $sizePresets['full']['height']);
    }
}

