<?php
/**
 * Internal HTTP base URL for requests from CLI workers to Apache / PHP-FPM on localhost.
 *
 * Scheduler and scripts cannot use APCu populated by FPM, so they call HTTP endpoints
 * (e.g. metrics flush, weather refresh). The base URL must match where Apache listens.
 *
 * Reuses WEATHER_REFRESH_URL so production docker-compose sets one variable for both
 * weather refresh and metrics flush (see docker-compose.prod.yml).
 */

/**
 * Base URL for HTTP requests to the app Apache vhost from inside the same container or host.
 *
 * Uses getenv('WEATHER_REFRESH_URL') when set (same as scripts/fetch-weather.php). Otherwise
 * uses http://localhost:{APP_PORT or PORT or 8080}.
 *
 * @return string Base URL without trailing slash (e.g. http://localhost:8080)
 */
function getInternalApacheBaseUrl(): string {
    $baseUrl = getenv('WEATHER_REFRESH_URL');
    if ($baseUrl !== false && $baseUrl !== '') {
        return rtrim($baseUrl, '/');
    }

    $port = getenv('APP_PORT') ?: getenv('PORT') ?: '8080';

    return rtrim('http://localhost:' . $port, '/');
}
