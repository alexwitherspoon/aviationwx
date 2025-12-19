<?php
/**
 * Unit Tests for Webcam Format Generation
 * 
 * Tests format detection and validation functionality:
 * - detectImageFormat() - JPEG, PNG, WebP, AVIF detection
 * - isValidAvifFile() - AVIF header validation
 */

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/webcam-format-generation.php';
require_once __DIR__ . '/../../api/webcam.php';

class WebcamFormatGenerationTest extends TestCase
{
    private $testImageDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testImageDir = sys_get_temp_dir() . '/webcam_format_test_' . uniqid();
        @mkdir($this->testImageDir, 0755, true);
    }
    
    protected function tearDown(): void
    {
        // Clean up test images
        if (is_dir($this->testImageDir)) {
            $files = glob($this->testImageDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->testImageDir);
        }
        parent::tearDown();
    }
    
    /**
     * Create a minimal JPEG file
     */
    private function createTestJpeg($filename): string
    {
        $path = $this->testImageDir . '/' . $filename;
        // Minimal valid JPEG: SOI marker + EOI marker
        file_put_contents($path, "\xFF\xD8\xFF\xD9");
        return $path;
    }
    
    /**
     * Create a minimal PNG file
     */
    private function createTestPng($filename): string
    {
        $path = $this->testImageDir . '/' . $filename;
        // PNG signature: 89 50 4E 47 0D 0A 1A 0A
        file_put_contents($path, "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A" . str_repeat("\x00", 8));
        return $path;
    }
    
    /**
     * Create a minimal WebP file
     */
    private function createTestWebp($filename): string
    {
        $path = $this->testImageDir . '/' . $filename;
        // WebP: RIFF header + WEBP identifier
        $content = "RIFF" . pack("V", 12) . "WEBP";
        file_put_contents($path, $content);
        return $path;
    }
    
    /**
     * Create a minimal AVIF file
     */
    private function createTestAvif($filename): string
    {
        $path = $this->testImageDir . '/' . $filename;
        // AVIF: ftyp box with avif major brand
        // [4 bytes size][4 bytes 'ftyp'][4 bytes major brand 'avif'][...]
        $size = pack("N", 20); // 20 bytes total
        $ftyp = "ftyp";
        $majorBrand = "avif";
        $content = $size . $ftyp . $majorBrand . str_repeat("\x00", 8);
        file_put_contents($path, $content);
        return $path;
    }
    
    public function testDetectImageFormat_JpegFile_ReturnsJpg(): void
    {
        $file = $this->createTestJpeg('test.jpg');
        $format = detectImageFormat($file);
        $this->assertEquals('jpg', $format);
    }
    
    public function testDetectImageFormat_PngFile_ReturnsPng(): void
    {
        $file = $this->createTestPng('test.png');
        $format = detectImageFormat($file);
        $this->assertEquals('png', $format);
    }
    
    public function testDetectImageFormat_WebpFile_ReturnsWebp(): void
    {
        $file = $this->createTestWebp('test.webp');
        $format = detectImageFormat($file);
        $this->assertEquals('webp', $format);
    }
    
    public function testDetectImageFormat_AvifFile_ReturnsAvif(): void
    {
        $file = $this->createTestAvif('test.avif');
        $format = detectImageFormat($file);
        $this->assertEquals('avif', $format);
    }
    
    public function testDetectImageFormat_InvalidFile_ReturnsNull(): void
    {
        $file = $this->testImageDir . '/invalid.txt';
        file_put_contents($file, "not an image");
        $format = detectImageFormat($file);
        $this->assertNull($format);
    }
    
    public function testDetectImageFormat_NonExistentFile_ReturnsNull(): void
    {
        $file = $this->testImageDir . '/nonexistent.jpg';
        $format = detectImageFormat($file);
        $this->assertNull($format);
    }
    
    public function testDetectImageFormat_EmptyFile_ReturnsNull(): void
    {
        $file = $this->testImageDir . '/empty.jpg';
        file_put_contents($file, "");
        $format = detectImageFormat($file);
        $this->assertNull($format);
    }
    
    public function testDetectImageFormat_ShortFile_ReturnsNull(): void
    {
        $file = $this->testImageDir . '/short.jpg';
        file_put_contents($file, "\xFF"); // Only 1 byte
        $format = detectImageFormat($file);
        $this->assertNull($format);
    }
    
    public function testIsValidAvifFile_ValidAvif_ReturnsTrue(): void
    {
        $file = $this->createTestAvif('test.avif');
        $result = isValidAvifFile($file);
        $this->assertTrue($result);
    }
    
    public function testIsValidAvifFile_AvifWithAvisBrand_ReturnsTrue(): void
    {
        $path = $this->testImageDir . '/test.avif';
        // AVIF with 'avis' major brand (AVIF image sequence)
        $size = pack("N", 20);
        $ftyp = "ftyp";
        $majorBrand = "avis";
        $content = $size . $ftyp . $majorBrand . str_repeat("\x00", 8);
        file_put_contents($path, $content);
        
        $result = isValidAvifFile($path);
        $this->assertTrue($result);
    }
    
    public function testIsValidAvifFile_InvalidFile_ReturnsFalse(): void
    {
        $file = $this->createTestJpeg('test.jpg');
        $result = isValidAvifFile($file);
        $this->assertFalse($result);
    }
    
    public function testIsValidAvifFile_NonExistentFile_ReturnsFalse(): void
    {
        $file = $this->testImageDir . '/nonexistent.avif';
        $result = isValidAvifFile($file);
        $this->assertFalse($result);
    }
    
    public function testIsValidAvifFile_EmptyFile_ReturnsFalse(): void
    {
        $file = $this->testImageDir . '/empty.avif';
        file_put_contents($file, "");
        $result = isValidAvifFile($file);
        $this->assertFalse($result);
    }
    
    public function testIsValidAvifFile_ShortFile_ReturnsFalse(): void
    {
        $file = $this->testImageDir . '/short.avif';
        file_put_contents($file, "\x00\x00\x00\x00ftyp"); // Too short
        $result = isValidAvifFile($file);
        $this->assertFalse($result);
    }
    
    public function testIsValidAvifFile_NonFtypBox_ReturnsFalse(): void
    {
        $file = $this->testImageDir . '/notavif.avif';
        // Valid box structure but not ftyp
        $size = pack("N", 20);
        $boxType = "moov"; // Not ftyp
        $content = $size . $boxType . str_repeat("\x00", 12);
        file_put_contents($file, $content);
        
        $result = isValidAvifFile($file);
        $this->assertFalse($result);
    }
}

