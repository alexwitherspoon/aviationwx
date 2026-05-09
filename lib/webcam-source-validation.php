<?php
/**
 * Webcam acquisition configuration gate (parallel to hasWeatherSources() for weather).
 *
 * Used by the scheduler queue and workers so reserved webcam slots without pull URLs
 * or push credentials do not run acquisition or appear as pool failures.
 */

/**
 * Whether this webcam entry has the minimum configuration for acquisition to be attempted.
 *
 * Rules:
 * - Optional `enabled: false` on the webcam object disables scheduling (placeholder slot).
 * - Push cameras require a non-empty `push_config.username`.
 * - `type: aviationwx_api` requires non-empty `base_url`.
 * - All other pull cameras require a non-empty `url` (RTSP, MJPEG, static, legacy http).
 *
 * @param array $webcam Single camera object from airport `webcams[]`
 * @return bool True when the worker should run acquisition for this slot
 */
function hasWebcamAcquisitionConfigured(array $webcam): bool
{
    if (array_key_exists('enabled', $webcam) && $webcam['enabled'] === false) {
        return false;
    }

    $isPush = (isset($webcam['type']) && $webcam['type'] === 'push')
        || isset($webcam['push_config']);

    if ($isPush) {
        $pushConfig = $webcam['push_config'] ?? null;
        if (!is_array($pushConfig)) {
            return false;
        }
        $user = $pushConfig['username'] ?? '';
        return is_string($user) && trim($user) !== '';
    }

    if (isset($webcam['type'])) {
        $explicitType = strtolower(trim((string) $webcam['type']));
        if ($explicitType === 'aviationwx_api') {
            $base = $webcam['base_url'] ?? '';
            return is_string($base) && trim($base) !== '';
        }
    }

    $url = $webcam['url'] ?? '';
    return is_string($url) && trim($url) !== '';
}
