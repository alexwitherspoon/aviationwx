<?php
/**
 * Shared non-image payloads for webcam allowlist tests.
 */

trait WebcamUnsupportedPayloads
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsupportedWebcamPayloadProvider(): array
    {
        $pad = static function (string $header): string {
            return $header . str_repeat("\x00", 200);
        };

        return [
            'pdf' => ['pdf', $pad("%PDF-1.4\n")],
            'pe_exe' => ['exe', $pad("MZ" . str_repeat("\x90", 64))],
            'elf' => ['elf', $pad("\x7FELF")],
            'gif' => ['gif', $pad("GIF89a")],
            'bmp' => ['bmp', $pad("BM")],
            'zip' => ['zip', $pad("PK\x03\x04")],
            'html' => ['html', $pad("<!DOCTYPE html>")],
            'random' => ['bin', str_repeat("\x01\x02\x03\x04", 50)],
        ];
    }
}
