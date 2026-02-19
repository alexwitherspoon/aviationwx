#!/usr/bin/env php
<?php
/**
 * Weekly Link Check - Discovers and validates external links on airport dashboards
 *
 * Fetches sitemap for airport list, scrapes each dashboard, checks external links.
 * When GITHUB_TOKEN is set, creates/updates/closes per-link issues for broken links.
 * Stops starting new work after TIMEOUT_MINUTES (default 30).
 *
 * Usage:
 *   php scripts/link-check.php
 *
 * Env: BASE_URL, GITHUB_TOKEN, GITHUB_REPOSITORY, TIMEOUT_MINUTES
 */

const LINK_CHECK_LABEL = 'link-check';
const GITHUB_API_DELAY_SECONDS = 2;
const HTTP_TIMEOUT_SECONDS = 15;
const USER_AGENT = 'AviationWX-LinkCheck/1.0';

$baseUrl = getenv('BASE_URL') ?: 'https://aviationwx.org';
$timeoutMinutes = (int) (getenv('TIMEOUT_MINUTES') ?: 30);
$githubToken = getenv('GITHUB_TOKEN');
$githubRepo = getenv('GITHUB_REPOSITORY') ?: 'alexwitherspoon/aviationwx.org';
$mentionUser = '@alexwitherspoon';

$startTime = time();
$timeLimitSeconds = $timeoutMinutes * 60;

/**
 * Check if we should stop (no new work after time limit)
 */
function shouldStop(int $startTime, int $limitSeconds): bool
{
    return (time() - $startTime) >= $limitSeconds;
}

/**
 * Extract airport IDs from sitemap XML
 *
 * @param string $sitemapUrl Full URL to sitemap.xml
 * @param int $startTime Start timestamp for time limit
 * @param int $limitSeconds Time limit in seconds
 * @return array<int, array{airport_id: string, url: string}>
 */
function fetchAirportUrlsFromSitemap(string $sitemapUrl, int $startTime, int $limitSeconds): array
{
    if (shouldStop($startTime, $limitSeconds)) {
        return [];
    }

    $xml = @file_get_contents(
        $sitemapUrl,
        false,
        stream_context_create([
            'http' => [
                'timeout' => HTTP_TIMEOUT_SECONDS,
                'user_agent' => USER_AGENT,
            ],
        ])
    );

    if ($xml === false || $xml === '') {
        return [];
    }

    $urls = [];
    if (preg_match_all('#<loc>https://([a-z0-9-]+)\.aviationwx\.org/</loc>#', $xml, $matches)) {
        foreach ($matches[1] as $airportId) {
            $urls[] = [
                'airport_id' => $airportId,
                'url' => "https://{$airportId}.aviationwx.org/",
            ];
        }
    }

    return $urls;
}

/**
 * Extract external links from dashboard HTML
 *
 * @param string $html Dashboard HTML
 * @param string $airportId Airport ID for context
 * @param string $baseDomain Base domain to exclude (e.g. aviationwx.org)
 * @return array<int, array{url: string, label: string}>
 */
function extractExternalLinks(string $html, string $airportId, string $baseDomain): array
{
    $links = [];
    $dom = new DOMDocument();

    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $anchors = $dom->getElementsByTagName('a');
    foreach ($anchors as $a) {
        $href = $a->getAttribute('href');
        if ($href === '') {
            continue;
        }

        $href = trim($href);
        if (strpos($href, 'mailto:') === 0 || strpos($href, 'tel:') === 0
            || strpos($href, '#') === 0 || strpos($href, 'javascript:') === 0
            || strpos($href, 'foreflightmobile://') === 0) {
            continue;
        }

        if (strpos($href, 'http://') !== 0 && strpos($href, 'https://') !== 0) {
            continue;
        }

        $parsed = parse_url($href);
        $host = $parsed['host'] ?? '';
        if ($host === '' || strpos($host, $baseDomain) !== false) {
            continue;
        }

        $label = trim($a->textContent ?? '');
        $label = preg_replace('/\s+/', ' ', $label);
        if ($label === '') {
            $label = parse_url($href, PHP_URL_HOST) ?: $href;
        }

        $links[] = ['url' => $href, 'label' => $label];
    }

    return $links;
}

/**
 * Check URL and return status
 *
 * @param string $url URL to check
 * @return array{status: int, final_url: string, error: string}
 */
function checkUrl(string $url): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_NOBODY => true,
        CURLOPT_USERAGENT => USER_AGENT,
    ]);

    $result = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
    $error = curl_error($ch);

    return [
        'status' => $status,
        'final_url' => $finalUrl,
        'error' => $error,
    ];
}

/**
 * Determine if link is unhealthy (broken, redirect, or error)
 */
function isUnhealthy(int $status, string $error): bool
{
    if ($error !== '') {
        return true;
    }
    if ($status >= 400) {
        return true;
    }
    if ($status >= 300 && $status < 400) {
        return true;
    }
    return false;
}

/**
 * Fetch existing link-check issues from GitHub
 *
 * @return array<string, array{number: int, state: string}>
 */
function fetchLinkCheckIssues(string $token, string $repo): array
{
    $map = [];
    $page = 1;

    do {
        $url = "https://api.github.com/repos/{$repo}/issues?state=all&labels=" . urlencode(LINK_CHECK_LABEL)
            . "&per_page=100&page={$page}";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $token,
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($code !== 200 || $body === false) {
            break;
        }

        $issues = json_decode($body, true);
        if (!is_array($issues)) {
            break;
        }

        foreach ($issues as $issue) {
            $title = $issue['title'] ?? '';
            if (preg_match('/^\[Link Check\] ([A-Za-z0-9-]+) - (.+)$/', $title, $m)) {
                $key = strtolower($m[1]) . '|' . $m[2];
                $map[$key] = [
                    'number' => (int) $issue['number'],
                    'state' => $issue['state'] ?? 'open',
                ];
            }
        }

        $page++;
    } while (count($issues) === 100);

    return $map;
}

/**
 * Create or update GitHub issue for broken link
 */
function createOrUpdateIssue(
    string $token,
    string $repo,
    string $airportId,
    string $label,
    string $url,
    int $status,
    string $finalUrl,
    string $error,
    string $mentionUser,
    array $existingIssues,
    int $startTime,
    int $limitSeconds
): ?int {
    $key = $airportId . '|' . $label;
    $existing = $existingIssues[$key] ?? null;

    $title = '[Link Check] ' . strtoupper($airportId) . ' - ' . $label;
    $statusText = $error !== '' ? $error : "HTTP {$status}";
    $redirectNote = ($status >= 301 && $status < 400) ? "\n\n**Suggested fix:** Update config URL to: {$finalUrl}" : '';
    $body = "{$mentionUser} — Broken link detected.\n\n"
        . "## Link Details\n"
        . "- **Airport:** " . strtoupper($airportId) . "\n"
        . "- **Link label:** {$label}\n"
        . "- **Dashboard:** https://{$airportId}.aviationwx.org\n"
        . "- **URL:** {$url}\n"
        . "- **Status:** {$statusText}{$redirectNote}\n\n"
        . "---\n*This issue is updated by the weekly link check. See comments for check history.*";

    if ($existing !== null) {
        sleep(GITHUB_API_DELAY_SECONDS);
        if (shouldStop($startTime, $limitSeconds)) {
            return null;
        }

        $comment = "Checked " . gmdate('Y-m-d H:i') . " UTC\nStatus: {$statusText}\n"
            . ($finalUrl !== $url ? "Redirects to: {$finalUrl}" : '');

        $commentUrl = "https://api.github.com/repos/{$repo}/issues/{$existing['number']}/comments";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $commentUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['body' => $comment]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($ch);

        if ($existing['state'] === 'closed') {
            sleep(GITHUB_API_DELAY_SECONDS);
            if (shouldStop($startTime, $limitSeconds)) {
                return null;
            }
            $reopenUrl = "https://api.github.com/repos/{$repo}/issues/{$existing['number']}";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $reopenUrl,
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => json_encode(['state' => 'open']),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/vnd.github+json',
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ],
            ]);
            curl_exec($ch);
        }

        return $existing['number'];
    }

    sleep(GITHUB_API_DELAY_SECONDS);
    if (shouldStop($startTime, $limitSeconds)) {
        return null;
    }

    $createUrl = "https://api.github.com/repos/{$repo}/issues";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $createUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'title' => $title,
            'body' => $body,
            'labels' => [LINK_CHECK_LABEL],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($code !== 201 && $code !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    return isset($data['number']) ? (int) $data['number'] : null;
}

/**
 * Close issue and add comment that link is healthy
 *
 * @param string $token GitHub token
 * @param string $repo Repository (owner/repo)
 * @param int $issueNumber Issue number to close
 * @param int $startTime Start timestamp for time limit
 * @param int $limitSeconds Time limit in seconds
 */
function closeIssue(
    string $token,
    string $repo,
    int $issueNumber,
    int $startTime,
    int $limitSeconds
): void {
    sleep(GITHUB_API_DELAY_SECONDS);
    if (shouldStop($startTime, $limitSeconds)) {
        return;
    }

    $comment = "Checked " . gmdate('Y-m-d H:i') . " UTC\nStatus: 200 OK\nLink is healthy. Closing issue.";
    $commentUrl = "https://api.github.com/repos/{$repo}/issues/{$issueNumber}/comments";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $commentUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['body' => $comment]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    curl_exec($ch);

    sleep(GITHUB_API_DELAY_SECONDS);
    if (shouldStop($startTime, $limitSeconds)) {
        return;
    }

    $patchUrl = "https://api.github.com/repos/{$repo}/issues/{$issueNumber}";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $patchUrl,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode(['state' => 'closed']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    curl_exec($ch);
}

// --- Main ---

$existingIssues = [];
if ($githubToken !== '') {
    $existingIssues = fetchLinkCheckIssues($githubToken, $githubRepo);
}

$sitemapUrl = rtrim($baseUrl, '/') . '/sitemap.xml';
$airports = fetchAirportUrlsFromSitemap($sitemapUrl, $startTime, $timeLimitSeconds);

$broken = [];
$healthyWithIssue = [];
$closedIssueNumbers = [];
$checked = 0;

foreach ($airports as $airport) {
    if (shouldStop($startTime, $timeLimitSeconds)) {
        break;
    }

    $html = @file_get_contents(
        $airport['url'],
        false,
        stream_context_create([
            'http' => [
                'timeout' => HTTP_TIMEOUT_SECONDS,
                'user_agent' => USER_AGENT,
            ],
        ])
    );

    if ($html === false || $html === '') {
        continue;
    }

    $links = extractExternalLinks($html, $airport['airport_id'], 'aviationwx.org');
    $seen = [];

    foreach ($links as $link) {
        $dedupeKey = $airport['airport_id'] . '|' . $link['label'] . '|' . $link['url'];
        if (isset($seen[$dedupeKey])) {
            continue;
        }
        $seen[$dedupeKey] = true;

        if (shouldStop($startTime, $timeLimitSeconds)) {
            break 2;
        }

        $result = checkUrl($link['url']);
        $checked++;

        if (isUnhealthy($result['status'], $result['error'])) {
            $broken[] = [
                'airport_id' => $airport['airport_id'],
                'label' => $link['label'],
                'url' => $link['url'],
                'status' => $result['status'],
                'final_url' => $result['final_url'],
                'error' => $result['error'],
            ];

            if ($githubToken !== '') {
                createOrUpdateIssue(
                    $githubToken,
                    $githubRepo,
                    $airport['airport_id'],
                    $link['label'],
                    $link['url'],
                    $result['status'],
                    $result['final_url'],
                    $result['error'],
                    $mentionUser,
                    $existingIssues,
                    $startTime,
                    $timeLimitSeconds
                );
            }
        } else {
            $key = $airport['airport_id'] . '|' . $link['label'];
            if (isset($existingIssues[$key])) {
                $issueNum = $existingIssues[$key]['number'];
                if (!in_array($issueNum, $closedIssueNumbers, true)) {
                    $healthyWithIssue[] = $existingIssues[$key];
                    if ($githubToken !== '') {
                        closeIssue($githubToken, $githubRepo, $issueNum, $startTime, $timeLimitSeconds);
                        $closedIssueNumbers[] = $issueNum;
                    }
                }
            }
        }
    }
}

$elapsed = time() - $startTime;
echo json_encode([
    'elapsed_seconds' => $elapsed,
    'airports_checked' => count($airports),
    'links_checked' => $checked,
    'broken_count' => count($broken),
    'closed_count' => count($healthyWithIssue),
    'broken' => $broken,
    'stopped_early' => shouldStop($startTime, $timeLimitSeconds),
], JSON_PRETTY_PRINT) . "\n";
