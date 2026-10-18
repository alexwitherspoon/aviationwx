#!/usr/bin/env php
<?php
/**
 * Generate a WiFiLogger upload token (awxu_…) for airports.json weather_sources[].upload_token.
 *
 * Usage:
 *   php scripts/generate-upload-api-token.php
 *   php scripts/generate-upload-api-token.php --count 3
 *
 * Exit codes:
 *   0 - Success
 *   1 - Invalid arguments
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/upload/keys.php';

$count = 1;
for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--count' || $arg === '-n') {
        $next = $argv[$i + 1] ?? null;
        if ($next === null || !ctype_digit($next) || (int) $next < 1 || (int) $next > 20) {
            fwrite(STDERR, "ERROR: --count requires an integer from 1 to 20\n");
            exit(1);
        }
        $count = (int) $next;
        $i++;
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Generate AviationWX WiFiLogger upload tokens (prefix " . UPLOAD_API_KEY_PREFIX . ").\n";
        echo "Usage: php scripts/generate-upload-api-token.php [--count N]\n";
        echo "Paste output into airports.json weather_sources[].upload_token. Do not invent tokens by hand.\n";
        exit(0);
    }
    fwrite(STDERR, "ERROR: Unknown argument: {$arg}\n");
    exit(1);
}

for ($i = 0; $i < $count; $i++) {
    $token = generateUploadApiKey();
    if (!isValidUploadApiKeyShape($token)) {
        fwrite(STDERR, "ERROR: generated token failed shape validation\n");
        exit(1);
    }
    echo $token . PHP_EOL;
}

exit(0);