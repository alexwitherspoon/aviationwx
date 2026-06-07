<?php

declare(strict_types=1);

/**
 * Shared TFR keyword heuristics for parsed NOTAM text and raw AIXM XML.
 */

/**
 * Whether text contains TFR keyword indicators used by the banner pipeline.
 *
 * Matches {@see isTfr()} and {@see notamAixmXmlMayBeTfr()} so geo pre-filter and
 * post-parse classification stay aligned.
 *
 * @param string $text NOTAM body text or raw AIXM XML from NMS
 * @return bool True when TFR-like keywords are present
 */
function notamTextMayIndicateTfr(string $text): bool
{
    if (stripos($text, 'TFR') !== false) {
        return true;
    }
    if (stripos($text, 'TEMPORARY FLIGHT RESTRICTION') !== false) {
        return true;
    }

    return stripos($text, 'RESTRICTED') !== false && stripos($text, 'AIRSPACE') !== false;
}
