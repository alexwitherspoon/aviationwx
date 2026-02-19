# Weekly Link Check

The link check workflow discovers external links on airport dashboards, validates them, and creates/updates/closes GitHub issues for broken links.

## How It Works

1. **Discovery:** Fetches `https://aviationwx.org/sitemap.xml`, extracts airport dashboard URLs
2. **Scrape:** Fetches each dashboard HTML, extracts external `<a href>` links
3. **Check:** HEAD request to each URL; treats 301/302/4xx/5xx/timeout as unhealthy
4. **Issues:** Per-link GitHub issues—create when broken, update with comment each run, close when healthy

## Setup Requirements

### 1. Label

The workflow creates the `link-check` label automatically on first run. If it fails, create it manually:

- **Name:** `link-check`
- **Color:** `#1d76db`
- **Description:** Broken links from weekly dashboard check

### 2. Permissions

The workflow needs `issues: write`. This is set in the workflow file. The default `GITHUB_TOKEN` has this for the repository.

### 3. Schedule

Runs every Monday at 09:00 UTC. Can also be triggered manually via **Actions → Weekly Link Check → Run workflow**.

## Environment Variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `BASE_URL` | `https://aviationwx.org` | Base URL for sitemap and dashboards |
| `GITHUB_TOKEN` | (from Actions) | GitHub API authentication |
| `GITHUB_REPOSITORY` | (from Actions) | Repository (owner/repo) |
| `TIMEOUT_MINUTES` | `30` | Stop starting new work after this many minutes |

## Local Testing

Run without GitHub integration (outputs JSON only):

```bash
BASE_URL=https://aviationwx.org TIMEOUT_MINUTES=1 php scripts/link-check.php
```

With GitHub (requires token):

```bash
GITHUB_TOKEN=ghp_xxx BASE_URL=https://aviationwx.org php scripts/link-check.php
```

## Issue Format

- **Title:** `[Link Check] KSPB - Airport Website`
- **Body:** Link details, status, suggested fix for redirects
- **Mention:** `@alexwitherspoon` in new issues
- **Label:** `link-check`

## Notes

- Some servers return 405 for HEAD requests; those links are reported and may need manual verification
- The script checks production dashboards; ensure `https://aviationwx.org/sitemap.xml` is reachable
