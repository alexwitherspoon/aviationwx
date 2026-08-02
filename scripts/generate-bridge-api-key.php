#!/usr/bin/env php
<?php
/**
 * Generate a bridge API key (awxb_…) for airports.json bridges[].api_key.
 *
 * Usage:
 *   php scripts/generate-bridge-api-key.php
 *   php scripts/generate-bridge-api-key.php --count 3
 *
 * Exit codes:
 *   0 - Success
 *   1 - Invalid arguments
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bridge/keys.php';

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
        echo "Generate AviationWX bridge API keys (prefix " . BRIDGE_API_KEY_PREFIX . ").\n";
        echo "Usage: php scripts/generate-bridge-api-key.php [--count N]\n";
        echo "Paste output into airports.json bridges[].api_key. Do not invent keys by hand.\n";
        exit(0);
    }
    fwrite(STDERR, "ERROR: Unknown argument: {$arg}\n");
    exit(1);
}

for ($i = 0; $i < $count; $i++) {
    $key = generateBridgeApiKey();
    if (!isValidBridgeApiKeyShape($key)) {
        fwrite(STDERR, "ERROR: generated key failed shape validation\n");
        exit(1);
    }
    echo $key . PHP_EOL;
}

exit(0);
