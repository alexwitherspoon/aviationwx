# Configuration Guide

All configuration lives in a single `airports.json` file with two sections:
- **`config`** - Global defaults
- **`airports`** - Per-airport settings

### Which `airports.json` file is used?

`resolveAirportsConfigFilePath()` in `lib/config.php` is the single resolver used by `loadConfig()`, `getConfigFilePath()`, and the status page. The first path that exists as a **regular file** wins:

1. **`CONFIG_PATH`** (when set and the path is an existing file)
2. **`/var/www/html/secrets/airports.json`** (Docker production mount)
3. **`config/airports.json`** (repository default next to `lib/`)
4. **Non-production only:** `../aviationwx.org-secrets/airports.json` then `../aviationwx-secrets/airports.json` (local maintainer layout)
5. **`/var/www/html/airports.json`** (host mount last resort)

If `CONFIG_PATH` points at a missing path, it is skipped and the remaining candidates are evaluated in order.

---

## Quick Reference

### Global Options (`config` section)

| Option | Default | Description |
|--------|---------|-------------|
| `default_timezone` | `UTC` | Fallback timezone for airports |
| `base_domain` | `aviationwx.org` | Base domain for subdomains. Used for CORS allowlist on M2M API (*.aviationwx.org). |
| `public_ip` | - | Optional: explicit IPv4 for FTP passive mode (use only if DNS unavailable at startup) |
| `public_ipv6` | - | Optional: reserved for future IPv6 support |
| `upload_hostname` | `upload.{base_domain}` | Hostname for FTP/SFTP uploads (recommended) |
| `upload_health_probe` | disabled | Production: functional FTPS/SFTP probes and automatic daemon recovery; see [Upload health probe](#upload-health-probe) |
| `network_ports` | - | Optional (self-hosted prod): TCP ports for the app stack, UFW, and in-container services; see [Network configuration](#network-configuration). |
| `dynamic_dns_refresh_seconds` | `0` | Re-resolve DNS for DDNS (0=disabled, min 60). When set, root cron runs `/usr/local/libexec/aviationwx/maybe-run-update-pasv-address.sh` every minute (same sources in `scripts/` in the repo); it gates on this interval and invokes `update-pasv-address.sh` from the same directory as that wrapper. |
| `webcam_refresh_default` | `60` | Default webcam refresh (seconds) |
| `cache_file_max_size_mb` | `25` | Max size in MiB for webcam pipeline loads, HTTP/MJPEG pull downloads, partner logo fetches, and **default** push upload acceptance (integer **1--100**). |
| `push_upload_allowed_extensions` | omit | Optional: extensions **preserved** during FTP/SFTP push inbox debris cleanup; merged with each push camera `push_config.allowed_extensions`. Values must be from **jpg, jpeg, png, webp**. Omit the key for the full default (all four). |
| `cleanup_push_upload_debris_max_age_seconds` | `10800` (3 hours) | Optional: minimum file age (mtime) before a non-allowed extension is deleted from push FTP/SFTP inboxes. Integer **600--604800** (10 minutes to 7 days). Hourly `scripts/cleanup-push-upload-debris.php` and the daily `cleanup-cache.php` step use this value. |
| `weather_refresh_default` | `60` | Default weather refresh (seconds) |
| `metar_refresh_seconds` | `60` | METAR refresh interval (min: 60) |
| `notam_refresh_seconds` | `600` | NOTAM refresh interval (10 minutes; lower urgency than weather) |
| `minimum_refresh_seconds` | `5` | Minimum allowed refresh interval |
| `scheduler_config_reload_seconds` | `60` | Config reload check interval |
| `weather_worker_pool_size` | `5` | Concurrent weather workers. After upstream throttles and METAR bulk are enabled, consider lowering this if you still see upstream 429s or throttle skips in `cache/weather_health.json`; raise only when fetches are consistently fast and under provider limits. |
| `webcam_worker_pool_size` | `5` | Concurrent webcam workers |
| `notam_worker_pool_size` | `1` | Concurrent NOTAM worker processes. **Keep at 1** in most deployments: NMS allows 1 req/s and the scheduler starts at most one new NOTAM job per tick (`NOTAM_SCHEDULER_MAX_ENQUEUE_PER_LOOP`). Values greater than 1 do not increase parallel NMS API calls; they only let additional workers stay in flight while others wait on rate limits or finish parsing. `validateAirportsJsonStructure()` warns when this is set above 1. |
| `station_power_worker_pool_size` | `1` | Concurrent station power fetch workers (`fetch-station-power.php`) |
| `station_power_refresh_seconds` | `900` (15 min) | Default dashboard poll interval for `/api/station-power.php` (minimum 60; overridable per airport) |
| `worker_timeout_seconds` | `90` | Worker process timeout |
| `webcam_generate_webp` | `false` | Generate WebP globally |
| `faa_crop_margins` | see below | Default crop margins for FAA profile (percentages) |
| **Webcam History Settings** |||
| `webcam_history_retention_hours` | `24` | Hours of history to retain (preferred) |
| `webcam_history_default_hours` | `3` | Default period shown in UI |
| `webcam_history_preset_hours` | `[1, 3, 6, 24]` | Period options in UI |
| `webcam_history_max_frames` | - | *Deprecated* - use retention_hours |
| `http_integrity_digest_cache_ttl_seconds` | max(webcam_history, weather_history) | APCu TTL for Content-Digest/MD5 cache; defaults to longest retention (images + weather) |
| `default_preferences` | - | Default unit toggle settings (see below) |
| `magnetic_declination` | - | Global override (degrees) for runway diagram and `wind_direction_magnetic`. When set to a numeric value (including `0`), applies to airports without a per-airport override and skips offline WMM. Omit to allow the cascade to reach WMM when lat/lon are set. |
| `notam_cache_ttl_seconds` | `3600` | NOTAM cache TTL |
| `notam_api_client_id` | - | NOTAM API client ID |
| `notam_api_client_secret` | - | NOTAM API client secret |
| **OpenWeatherMap Integration** |||
| `openweathermap_api_key` | - | API key for cloud layer tiles (optional, [free at openweathermap.org](https://home.openweathermap.org/api_keys)) |
| **Cloudflare Analytics** |||
| `cloudflare.api_token` | - | Cloudflare API token (Analytics:Read) |
| `cloudflare.zone_id` | - | Cloudflare Zone ID |
| `cloudflare.account_id` | - | Cloudflare Account ID |
| **Client Version Management** |||
| `dead_man_switch_days` | `7` | Full cleanup when the client build is this many days behind the server, or this old without confirmed contact (0 = disabled) |
| `stuck_client_cleanup` | `false` | Inject cleanup for clients stuck on old code |
| **Staleness Thresholds (3-tier model)** |||
| `stale_warning_seconds` | `600` | Warning tier threshold (yellow indicator) |
| `stale_error_seconds` | `3600` | Error tier threshold (red indicator) |
| `stale_failclosed_seconds` | `10800` | Failclosed tier (hide stale data) |
| `metar_stale_warning_seconds` | `3600` | METAR warning threshold |
| `metar_stale_error_seconds` | `7200` | METAR error threshold |
| `metar_stale_failclosed_seconds` | `10800` | METAR failclosed threshold |
| `notam_stale_warning_seconds` | `900` | NOTAM warning threshold |
| `notam_stale_error_seconds` | `1800` | NOTAM error threshold |
| `notam_stale_failclosed_seconds` | `3600` | NOTAM failclosed threshold |

### Airport Options (`airports.{id}` section)

**Airport IDs must be lowercase** (e.g., `kspb`, not `KSPB`). This prevents case sensitivity issues with file paths and cache directories.

| Option | Default | Description |
|--------|---------|-------------|
| **Required** |||
| `name` | - | Display name |
| `enabled` | `false` | Must be `true` to activate |
| `lat` | - | Latitude |
| `lon` | - | Longitude |
| **Identifiers** |||
| `icao` | - | ICAO code (e.g., `KSPB`) |
| `iata` | - | IATA code (e.g., `SPB`) |
| `faa` | - | FAA LID (e.g., `03S`) |
| `iso_country` | - | Optional ISO 3166-1 alpha-2 (two letters, validated). Highest-precedence hint for effective country, Public API `iso_country`, and aviation-region link selection. |
| `formerly` | `[]` | Previous identifiers for NOTAM matching |
| **Location** |||
| `address` | - | City, State display |
| `elevation_ft` | - | Field elevation in feet |
| `timezone` | global default | Timezone (e.g., `America/Los_Angeles`) |
| **Status** |||
| `maintenance` | `false` | Show maintenance banner |
| `unlisted` | `false` | Hide from discovery (map, search, sitemap). When true, worker failures (webcam/weather/NOTAM) are treated as expected during commissioning; logged at info, exit 2 (skip) so process pool does not log "worker failed". |
| `limited_availability` | `false` | Off-grid/solar/battery site; shows informational banner when data unavailable |
| `limited_availability_outage_seconds` | `1800` | When to show outage banner for limited_availability sites (default 30 min); override per-airport or globally |
| `station_power` | - | Optional facility power telemetry for `limited_availability` sites. Object shape: `provider` (string, e.g. `vrm`) and `config` (provider-specific). **Requires** `limited_availability: true`. Staleness for this block is **not** tied to METAR/weather fail-closed rules. The dashboard uses neutral **Station Power** labels only (no vendor branding in the UI). **Manual refresh:** same command as the scheduler: `php scripts/fetch-station-power.php --worker <airport_id>`; with local Docker: `make station-power-fetch AIRPORT=<airport_id>` (containers must be running). |
| `station_power_refresh_seconds` | `config.station_power_refresh_seconds` or 900 | Per-airport override for how often the browser polls `/api/station-power.php` (minimum 60). Scheduler fetch interval for upstream data is separate (`STATION_POWER_FETCH_INTERVAL_SECONDS`, default 10 minutes). |
| **Refresh Overrides** |||
| `webcam_refresh_seconds` | global default | Override webcam refresh for this airport |
| `weather_refresh_seconds` | global default | Override weather refresh for this airport |
| **Feature Overrides** |||
| `webcam_history_retention_hours` | global default | Hours of history to retain |
| `webcam_history_default_hours` | global default | Default period shown in UI |
| `webcam_history_preset_hours` | global default | Period options in UI |
| `default_preferences` | global default | Override unit toggle defaults for this airport |
| **Density altitude performance** |||
| `runway_length_ft` | NASR runways on file | Optional override for the density altitude performance cue. See [Density altitude performance overrides](#density-altitude-performance-overrides). |
| `runway_surface` | `ASPH` when length override is set | NASR-style surface code when `runway_length_ft` is set (e.g. `TURF`, `ASPH`). Drives POH grass correction on non-paved surfaces. |
| `runway_ends` | - | Optional per-end departure data when `runway_length_ft` is set. See [Density altitude performance overrides](#density-altitude-performance-overrides). |
| `ourairports_ident` | - | OurAirports open-data ident for internal runway joins (e.g. `US-4027`, `CYAV`). Not shown as the pilot-facing airport code. See [Density altitude performance overrides](#density-altitude-performance-overrides). |
| `ourairports_id` | - | Optional stable OurAirports integer id from `airports.csv` when set alongside `ourairports_ident`. |
| **Data Sources** |||
| `weather_sources` | `[]` | Array of weather source configurations (see Weather Sources section) |
| `webcams` | `[]` | Array of webcam configurations |
| **Metadata** |||
| `runways` | `[]` | Runway definitions |
| `magnetic_declination` | global or WMM | Magnetic declination in degrees for runway wind diagram and `wind_direction_magnetic`. Positive = East (mag N east of true N), negative = West. Manual override; when absent, computed from bundled offline WMM when lat/lon are set. |
| `frequencies` | `{}` | Radio frequencies (see [Radio frequencies](#radio-frequencies)) |
| `services` | `{}` | Available services |
| `partners` | `[]` | Partner organizations |
| `links` | `[]` | Custom external links (Public API `GET /v1/airports/{id}` returns these as `custom_links`; built-in links are `external_links`). |
| **Link Overrides** |||
| `airnav_url` | auto | Override AirNav link |
| `faa_weather_url` | auto | Override FAA Weather link (US link bundle only) |
| `regional_weather_url` | - | Override the primary built-in regional authority URL for that airport's link region |
| `regional_weather_label` | - | Label for regional weather link (e.g., "NAV Canada WxCam") |
| `foreflight_url` | auto | Override ForeFlight link |

**Regional link behavior:** Built-in external links use a **data-driven profile** per link region derived from effective country (ISO 3166-1 alpha-2): optional `iso_country` → `inferIso3166Alpha2FromIcaoPrefix()` (K/C/Y-style ICAO hints) → FAA LID (implies US) → merged geometry aggregate in `airport_country_resolution.json` when `config_sha256` matches (otherwise merge skipped) → US or Canada from `address` parsing. First-pass regions include **us** (built-in rows: AirNav, FAA Weather, ForeFlight; no SkyVector row in that profile), **ca**, **au**, **nz**, **gb**, **eu** (EU members, EEA, CH, and listed microstates), **mx**, **br**, **jp**, with US territories grouped under **us**. When the region is **unknown**, only explicit overrides (`airnav_url`, `faa_weather_url`, `regional_weather_url`, `foreflight_url`) produce built-ins; use custom `links` for anything else. The scheduler rebuilds the geometry aggregate when `airports.json` changes, the aggregate is missing or invalid, or it is older than the configured max age (30 days by default).

### Density altitude performance overrides

The weather API `density_altitude_performance` cue compares AFM takeoff charts to runway context.

**Runway source precedence** (first match wins):

1. `runway_length_ft` / `runway_surface` / optional `runway_ends` in `airports.json` (operator override)
2. FAA NASR active land runways (US)
3. OurAirports active land runways when NASR has no row: match `ourairports_ident` first, then ICAO, FAA, or config slug against the runway cache
4. Weather-only elevation bands (`fallback: true`)

Set `ourairports_ident` when the strip has no FAA or ICAO code but a row exists on [OurAirports](https://ourairports.com/data/) (Public Domain). Example: 45 Ranch → `US-4027`. Optional `ourairports_id` (integer from `airports.csv`) documents the stable OurAirports primary key; runway lookup uses `ourairports_ident`. These fields are for internal data joins only; dashboard labels still use ICAO > IATA > FAA.

US airports with a NASR match score **every** active land runway and map the cue from the global best-performing departure end. Obstructions use approach-side filing on the reciprocal end (same as NASR).

When `runway_length_ft` is set on an airport, that length replaces NASR and OurAirports runway selection. Optional `runway_surface` sets the surface code for POH grass correction (non-paved surfaces add 15% of ground roll to chart total).

Optional `runway_ends` supplies per-threshold data (NASR-aligned) when NASR and OurAirports have no usable runway row (private strips, pending OurAirports submissions). Each entry:

| Field | Description |
|-------|-------------|
| `end_id` | Runway end ident (e.g. `17`, `35L`) |
| `displaced_thr_len` | Displaced threshold in feet (reduces roll available when departing from this end) |
| `tkof_dist_avbl` | Declared takeoff distance available in feet (caps roll available when departing from this end) |
| `obstruction.hgt_ft` | Controlling obstacle height in feet (approach side of this threshold) |
| `obstruction.dist_ft` | Distance from this threshold to the obstacle along the centerline (approach side; affects departure from the **opposite** end) |
| `obstruction.slope` | Obstacle clearance slope (e.g. `20` for 20:1) |
| `obstruction.type` | Optional label (e.g. `TREES`, `HILL`) |

When `runway_ends` is omitted, the override behaves as before: length and surface only, with no obstruction data. When any end includes usable `obstruction.hgt_ft` and `obstruction.dist_ft`, the full POH obstruction model runs for departures from the reciprocal end and **warning** tier is allowed (not capped at caution).

Wind-based departure-end selection uses `runways[0].heading_1` / `heading_2` when present alongside `runway_ends`.

**Operator-provided length caveat (no `runway_ends`):** The override builds a synthetic runway with **no departure-end data** (`ends` is empty). NASR departure obstructions are **not** applied. Only runway-length stress is evaluated. The cue can **under-alert** when departure obstacles matter on that strip.

**Tier cap without obstruction data:** Because the override has no departure-end obstructions, the indicator cannot emit **warning** (🚩); tier is capped at **caution** when length/surface stress alone would otherwise trigger warning. `tkof_dist_avbl` and `displaced_thr_len` affect stress but do not lift the caution cap without usable `obstruction.hgt_ft` and `obstruction.dist_ft` on at least one end. The same cap applies to OurAirports-only runway context.

Policy detail: `docs/SAFETY_CRITICAL_CALCULATIONS.md` (Density Altitude Performance) and `docs/DATA_FLOW.md`.

### Radio frequencies

The `frequencies` object maps service names to MHz strings. Align keys with chart / FAA Airport/Facility Directory style where possible:

| Key | When to use |
|-----|-------------|
| `tower`, `ground`, `atis`, `clearance_delivery`, `approach`, `departure` | As published for controlled airports. |
| `ctaf` | Common traffic frequency at non-towered airports. When the source lists **CTAF/UNICOM** on **one** frequency, use **`ctaf` only**. Do not also add `unicom` with the same MHz (duplicate line in the UI, not two services). |
| `unicom` | When UNICOM is **distinct** from CTAF (e.g. towered field FBO/airport advisory on 122.8 while traffic uses tower), or when only UNICOM is given without a separate CTAF. |
| `awos`, `asos` | Automated weather as published. |

Values are strings in MHz (e.g. `"122.8"`, `"123.05"`).

### Webcam Options (`webcams[]` array items)

| Option | Default | Description |
|--------|---------|-------------|
| **Common** |||
| `name` | - | Display name |
| `approximate_heading` | - | **Required** when the airport is `enabled: true` and not in `maintenance`. Integer **0**-**360**: direction the camera lens points, in **true north** degrees. Optional on maintenance or disabled airports until commissioned. Aim within ±10° when measuring (operational target, not validated). See [Guide 02](../guides/02-location-and-siting.md) (how to measure) and [Guide 08](../guides/08-camera-configuration.md) (installer checklist). |
| `enabled` | `true` | When `false`, the slot stays in config/UI but acquisition fields such as `url`, `push_config`, and `base_url` are not required and workers do not run for this camera |
| **Conditional** |||
| `url` | - | Stream/image URL for pull types (`http` / `mjpeg` / `static_jpeg` / `static_png` / `rtsp`). Not used for `push` (credentials live under `push_config`) or for `aviationwx_api` (use `base_url`). Omit for `enabled: false` placeholders. |
| **Optional** |||
| `type` | auto-detect | `rtsp`, `mjpeg`, `static_jpeg`, `static_png`, `push`, `aviationwx_api` |
| `refresh_seconds` | airport default | Override refresh for this camera |
| `crop_margins` | global default | FAA profile crop margins override (percentages) |
| **RTSP Options** |||
| `rtsp_transport` | `tcp` | `tcp` or `udp` |
| `rtsp_fetch_timeout` | `10` | Frame capture timeout (seconds) |
| `rtsp_max_runtime` | `6` | Max ffmpeg runtime (seconds) |
| **Push Options** |||
| `push_config.username` | - | 14 alphanumeric chars |
| `push_config.password` | - | 14 alphanumeric chars |
| `push_config.max_file_size_mb` | *(inherit global)* | Optional per-camera cap (integer **1** through **`config.cache_file_max_size_mb`**). Omit to use the global `cache_file_max_size_mb` for FTP/SFTP acceptance. Set lower to limit a single camera (bandwidth or policy). |
| `push_config.allowed_extensions` | `["jpg","jpeg","png","webp"]` | Allowed file types (subset of **jpg, jpeg, png, webp**) |
| `push_config.upload_file_max_age_seconds` | `1800` | Max file age before abandonment (600-7200) |
| `push_config.stability_check_timeout_seconds` | `15` | Stability check timeout (10-30) |
| **aviationwx_api (federated)** |||
| `base_url` | - | Required when `type` is `aviationwx_api`: root URL of the Public API host |
| `api_key` | - | Optional API key when the remote endpoint requires authentication |
| `timeout_seconds` | *(inherit)* | Optional HTTP timeout in seconds (integer **1**-**300**) |
| `camera_index` | - | Optional remote camera index (integer **0**-**99**) |

**Scheduling:** The scheduler only runs acquisition for cameras with `enabled !== false` and enough configuration to fetch: pull cameras need a non-empty `url`, except `type: aviationwx_api`, which uses `base_url` instead; push cameras need `push_config.username`. Placeholder slots (`enabled: false` or missing acquisition fields) are skipped, matching the weather pipeline rule for airports without `weather_sources`.

**Config hygiene:** Use JSON boolean `true` / `false` for `enabled` (a string like `"false"` is not treated as disabled). For non-push cameras, omit `push_config` entirely. If `push_config` is present, the slot is treated as a push camera for scheduling and validation, even when `type` is something else. Remove stale `push_config` blocks when switching a slot to pull or `aviationwx_api`.

### Configuration Hierarchy

Settings resolve in this order (first match wins):

1. **Per-webcam** - `webcams[].refresh_seconds`
2. **Per-airport** - `airport.webcam_refresh_seconds` or `airport.weather_refresh_seconds`
3. **Global** - `config.webcam_refresh_default` or `config.weather_refresh_default`
4. **Built-in default** - 60 seconds

### Default Preferences Hierarchy

Unit toggle defaults resolve in this order (first match wins):

1. **User preference** - stored in browser cookie/localStorage
2. **Per-airport** - `airport.default_preferences`
3. **Global** - `config.default_preferences`
4. **Built-in default** - US aviation standards (12hr, °F, ft, inHg, kts)

---

## Global Configuration

```json
{
  "config": {
    "default_timezone": "UTC",
    "base_domain": "aviationwx.org",
    "upload_hostname": "upload.aviationwx.org",
    
    "dead_man_switch_days": 7,
    "stuck_client_cleanup": false,
    
    "stale_warning_seconds": 600,
    "stale_error_seconds": 3600,
    "stale_failclosed_seconds": 10800,
    "limited_availability_outage_seconds": 1800,
    
    "webcam_refresh_default": 60,
    "weather_refresh_default": 60,
    "metar_refresh_seconds": 60,
    "notam_refresh_seconds": 600,
    "minimum_refresh_seconds": 5,
    
    "weather_worker_pool_size": 5,
    "webcam_worker_pool_size": 5,
    "notam_worker_pool_size": 1,
    "worker_timeout_seconds": 90,
    
    "webcam_generate_webp": false,
    "webcam_history_max_frames": 12,
    
    "notam_cache_ttl_seconds": 3600,
    "notam_api_client_id": "your-client-id",
    "notam_api_client_secret": "your-secret",
    
    "cloudflare": {
      "api_token": "your-analytics-read-token",
      "zone_id": "your-zone-id",
      "account_id": "your-account-id"
    }
  },
  "airports": { ... }
}
```

The `config` section is optional; sensible defaults apply if omitted.

### Network Configuration

Configure the server's public network identity for FTP/SFTP services and URL generation.

| Option | Type | Description |
|--------|------|-------------|
| `base_domain` | string | Base domain for URL generation (e.g., `aviationwx.org`) |
| `public_ip` | string | Optional: explicit IPv4 for FTP passive mode (use only if DNS unavailable at startup) |
| `public_ipv6` | string | Optional: reserved for future IPv6 support |
| `upload_hostname` | string | Hostname for FTP/SFTP uploads |
| `network_ports` | object | Optional object defining TCP ports for self-hosted production (all port values must be JSON **numbers**, not strings). `deploy-configure-firewall.sh` applies host UFW/iptables/NAT; the web container entrypoint sets **vsftpd** `listen_port` from **`ftp_control`** only (passive range from the map), **sshd** (SFTP on `sftp`), and **fail2ban** jails. Omitted keys use defaults: `http` 80, `https` 443, `ftp_control` 2121, `ftps_explicit_tls` 2122, `sftp` 2222, `ftp_passive_min`/`max` 50000–51000, `ssh` 22, `ftps_alt` null. **`ftps_explicit_tls`** is used for host firewall/fail2ban when that inbound port differs from `ftp_control`; vsftpd still binds a single control port (`ftp_control`). **`ssh`** opens the host admin SSH port in UFW only. **`ftps_alt`**: optional extra inbound control port on the host; NAT REDIRECT targets **`ftp_control`**. |
| `dynamic_dns_refresh_seconds` | integer | Re-resolve DNS periodically (0=disabled, min 60 when enabled). Enforced by root cron + `/usr/local/libexec/aviationwx/maybe-run-update-pasv-address.sh` in the container (sources under `scripts/` in the repo). |

**Network ports (`network_ports`):** When `network_ports` is present, it must be a JSON **object** (not an array), and each set port field must be a JSON **number** (not a quoted string); config validation, `deploy-configure-firewall.sh`, and `docker-entrypoint.sh` enforce this. On deploy, `deploy-configure-firewall.sh` reads `~/airports.json` (or `AIRPORTS_JSON`). At container start, `docker-entrypoint.sh` reads `config.network_ports` from `CONFIG_PATH` / `config/airports.json` and configures **vsftpd** with a **single** control listener on **`ftp_control`** (plus passive ports), **sshd** (SFTP on `sftp`), and **fail2ban**. Host-facing ports such as **`ftps_explicit_tls`** and **`ftps_alt`** are for UFW/NAT/fail2ban when inbound ports differ from the container bind; they do not add a second vsftpd listener. **Nginx** uses `docker/nginx.conf`; keep its `listen` ports consistent with `network_ports.http` and `network_ports.https` when you customize them. **Apache** listens on `127.0.0.1:8080` behind nginx and is not configured through `network_ports`.

**FTP Passive Mode Resolution Priority:**

1. **`public_ip`** - If set, use explicit IP (no DNS lookup). Use only when DNS is unavailable at startup.
2. **`upload_hostname`** - If `public_ip` not set, resolve via DNS (recommended; survives IP changes)
3. **`upload.{base_domain}`** - Default fallback if `upload_hostname` not set
4. **`upload.aviationwx.org`** - Final fallback

### Upload health probe

Production-only functional checks for FTPS and SFTP upload paths. When enabled, `upload-probe-runner.sh` uploads a small test file every `interval_sec` (default 30). `service-watchdog.sh` evaluates the heartbeat every 50 seconds and may restart **vsftpd** or the container **sshd** after consecutive failures (at most once per 30 minutes per daemon).

| Field | Type | Description |
|-------|------|-------------|
| `enabled` | boolean | Must be `true` to activate (not `1` or `"true"`) |
| `interval_sec` | integer | Probe period in seconds (15-300, default 30) |
| `probe_connect_host` | string | Connect host for on-box probes. Empty uses `upload_hostname`. Production Docker (`network_mode: host`) should use `127.0.0.1` so probes hit local vsftpd/sshd without hairpin NAT through the public IP |
| `ftps` | object | `username` and `password` for FTPS probe (passive TLS upload) |
| `sftp` | object | `username` and `password` for SFTP probe (upload under `files/`) |

Credential shape matches push cameras: `username` is alphanumeric, max 14 characters; `password` is exactly 14 alphanumeric characters. Config validation (`validate-airports-json.php` / `validateRuntimeConfigSchema`) enforces these rules.

**Requirements:**

- Use a **dedicated** probe account synced via `sync-push-config.php`. Usernames must **not** match any push camera `push_config.username` (config validation enforces this).
- Do not use a live camera account: probe uploads use a fixed remote name (`aviationwx-probe-healthcheck.txt`) and must not enter the webcam pipeline.
- Apache container health does not reflect upload health; use heartbeat and logs under [Operations](OPERATIONS.md#upload-health-probe-and-service-watchdog).
- Heartbeat `ftps.duration_sec` / `sftp.duration_sec` report probe wall time in seconds (not milliseconds).

**Production Docker:** The web container uses host networking. Set `probe_connect_host` to `127.0.0.1` so functional probes connect to local listeners on `network_ports.ftp_control` and `network_ports.sftp`. Cameras still use `upload_hostname` as usual.

**Recommended: Hostname (default)**

Use `upload_hostname` for most deployments. Hostname resolution survives IP changes and works for both static and dynamic IPs:

```json
{
  "config": {
    "base_domain": "aviationwx.org",
    "upload_hostname": "upload.aviationwx.org"
  }
}
```

**Optional: Explicit IP (edge cases)**

Set `public_ip` only if DNS is unavailable or unreliable at container startup (e.g., restricted environments, early boot before DNS is ready):

```json
{
  "config": {
    "base_domain": "aviationwx.org",
    "public_ip": "178.128.130.116",
    "upload_hostname": "upload.aviationwx.org"
  }
}
```

**Dynamic DNS (DDNS) Support:**

For self-hosted instances with dynamic IPs (e.g., home internet with DDNS), use hostname only - vsftpd resolves at connection time:

```json
{
  "config": {
    "base_domain": "weather.myairport.org",
    "upload_hostname": "upload.weather.myairport.org",
    "dynamic_dns_refresh_seconds": 300
  }
}
```

When `dynamic_dns_refresh_seconds` is enabled:
- Root cron in the container runs `/usr/local/libexec/aviationwx/maybe-run-update-pasv-address.sh` every minute; it reads `getDynamicDnsRefreshSeconds()` by invoking PHP as `www-data` (`runuser`), then only runs `update-pasv-address.sh` from the same libexec directory when the interval has elapsed (same interval semantics as before; resolution is within one minute because cron is minutely). The throttle timestamp is stored at `/var/lib/aviationwx/pasv-ddns.last` and the wrapper append-only log at `/var/lib/aviationwx/dynamic-dns-pasv.log` (both under the root-only `/var/lib/aviationwx` directory in the image, not world-writable `/tmp` and not under the shared `/var/log/aviationwx` tree).
- If the IP has changed, vsftpd's `pasv_address` is updated automatically
- vsftpd is restarted to apply the new IP (brief interruption to active FTP sessions)
- If `public_ip` is set, dynamic DNS refresh is automatically disabled (not needed)

**Self-Hosted/Federation:**

For self-hosted instances, configure your own domain. Hostname is recommended:

```json
{
  "config": {
    "base_domain": "weather.myairport.org",
    "upload_hostname": "upload.weather.myairport.org"
  }
}
```

---

## Airport Configuration

### Minimal Example

```json
{
  "airports": {
    "kspb": {
      "name": "Scappoose Industrial Airpark",
      "enabled": true,
      "lat": 45.7710278,
      "lon": -122.8618333,
      "timezone": "America/Los_Angeles",
      "weather_sources": [
        { "type": "metar", "station_id": "KSPB" }
      ]
    }
  }
}
```

### Complete Example

```json
{
  "airports": {
    "kspb": {
      "name": "Scappoose Industrial Airpark",
      "enabled": true,
      "maintenance": false,
      "unlisted": false,
      
      "icao": "KSPB",
      "iata": "SPB",
      "faa": "SPB",
      
      "address": "Scappoose, Oregon",
      "lat": 45.7710278,
      "lon": -122.8618333,
      "elevation_ft": 58,
      "timezone": "America/Los_Angeles",
      
      "webcam_refresh_seconds": 30,
      "weather_refresh_seconds": 60,
      "webcam_history_max_frames": 24,
      
      "weather_sources": [
        {
          "type": "tempest",
          "station_id": "149918",
          "api_key": "your-key"
        },
        {
          "type": "nws",
          "station_id": "KSPB"
        },
        {
          "type": "metar",
          "station_id": "KSPB",
          "nearby_stations": ["KVUO", "KHIO"]
        }
      ],
      
      "webcams": [
        {
          "name": "Runway Camera",
          "url": "rtsp://camera.local:554/stream",
          "type": "rtsp",
          "refresh_seconds": 30
        },
        {
          "name": "Field View",
          "url": "https://example.com/cam.jpg",
          "refresh_seconds": 120
        }
      ],
      
      "runways": [
        { "name": "15/33", "heading_1": 152, "heading_2": 332 }
      ],
      "frequencies": {
        "ctaf": "122.8",
        "asos": "135.875"
      },
      "services": {
        "fuel": "100LL, Jet-A",
        "repairs_available": true
      },
      "partners": [
        {
          "name": "Local Aviation Club",
          "url": "https://club.example.com",
          "logo": "https://club.example.com/logo.png"
        }
      ],
      "links": [
        { "label": "Airport Website", "url": "https://airport.example.com" }
      ]
    }
  }
}
```

### Airport Identifiers

Priority order for URL routing (highest first):
1. **ICAO** - `KSPB` → `kspb.aviationwx.org`
2. **IATA** - `SPB` → redirects to ICAO
3. **FAA** - `03S` → `03s.aviationwx.org` (if no ICAO)
4. **Airport ID** - JSON key as fallback

All identifiers are case-insensitive. Non-primary identifiers 301 redirect to primary.

### Status Flags

**`enabled`** (default: `false`)
- Must be `true` for airport to be accessible
- When `false`: returns 404, excluded from homepage/sitemap, no data fetching

**`maintenance`** (default: `false`)
- Shows warning banner: "⚠️ This airport is currently under maintenance"
- Status page shows orange indicator
- APIs continue to function normally

**`unlisted`** (default: `false`)
- Hides airport from discovery channels while keeping it fully operational
- When `true`:
  - Data fetching continues normally (weather, webcams, NOTAMs)
  - Accessible via direct URL (e.g., `test.aviationwx.org`)
  - Hidden from: airport map, navigation search, sitemaps (XML/HTML), public API
  - Page includes `<meta name="robots" content="noindex, nofollow">` to prevent search indexing
- Use cases: test sites, new airports being commissioned, private beta testing
- To include unlisted airports in API: `GET /v1/airports?include_unlisted=true`

**State Matrix:**

| `enabled` | `unlisted` | Result |
|-----------|------------|--------|
| `false` | any | Disabled (404, no data fetching) |
| `true` | `false` | Fully public (default behavior) |
| `true` | `true` | Operational but hidden from discovery |

### Timezone

Affects daily statistics reset (midnight), sunrise/sunset display, and local time display on dashboards. The dashboard uses server-computed timezone abbreviations (reliable IANA data) to avoid browser Intl API bugs (e.g., PST shown for MST). Use PHP timezone identifiers:
- `America/New_York`, `America/Chicago`, `America/Denver`, `America/Los_Angeles`
- `America/Anchorage`, `Pacific/Honolulu`, `UTC`

---

## Weather Sources

All weather sources are configured in a unified `weather_sources` array. Sources are fetched in parallel and aggregated; the freshest data from any source wins for each field. METAR typically provides ceiling and cloud_cover (other sources do not provide these fields).

### Source Types

| Type | Description | Update Frequency |
|------|-------------|------------------|
| `tempest` | Tempest Weather Station | ~1 minute |
| `ambient` | Ambient Weather Network | ~1 minute |
| `weatherlink_v2` | Davis WeatherLink (newer devices) | Depends on Davis subscription (see below) |
| `weatherlink_v1` | Davis WeatherLink (legacy devices) | Depends on Davis subscription (see below) |
| `pwsweather` | PWSWeather/AerisWeather | Variable |
| `synopticdata` | SynopticData API | Variable |
| `nws` | NWS ASOS API (api.weather.gov) | ~5 minutes |
| `awosnet` | AWOSnet (awosnet.com XML endpoint) | ~10 minutes |
| `swob_auto` | Nav Canada Weather (automated stations) | ~5 minutes |
| `swob_man` | Nav Canada Weather (manned stations) | ~5 minutes |
| `metar` | NOAA Aviation Weather METAR | ~60 minutes |
| `dyaconlive` | Dyacon MS-100 advisory aviation station (DyaconLive+ API) | ~10 minutes |

**Davis WeatherLink update intervals** (per [WeatherLink v2 Data Permissions](https://weatherlink.github.io/v2-api/data-permissions)): **Basic (free)** = most recent 15-minute record; **Pro (paid)** = most recent 5-minute record; **Pro+ (paid)** = most recent record (~1 minute). Historic data is only available on Pro/Pro+.

### Tempest Weather

```json
"weather_sources": [
  {
    "type": "tempest",
    "station_id": "149918",
    "api_key": "your-api-key"
  }
]
```

**Fetch behavior (safety-critical):** The worker always requests the **federated station** observation URL first. If WeatherFlow returns HTTP success but **no usable `obs` row**, or **`obs[0]` exists but has no sensor measurements** (e.g. only a timestamp), the same fetch cycle **automatically** calls the **stations metadata** endpoint, resolves the first **`ST`** (Tempest sensor) `device_id`, and requests **`/observations/device/{device_id}`**. Parsing then maps WeatherFlow's **`obs_st`** numeric layout into the same internal fields as a normal station response. You still configure only `station_id` and `api_key`; no extra fields are required for standard hub-plus-one-Tempest installs. Stations with multiple `ST` devices use the **first `ST` entry** in the API device list (documented for deterministic behavior).

### Ambient Weather

```json
"weather_sources": [
  {
    "type": "ambient",
    "api_key": "your-api-key",
    "application_key": "your-app-key",
    "mac_address": "AA:BB:CC:DD:EE:FF"
  }
]
```

`mac_address` is optional and uses the first device if omitted.

### DyaconLive

[Dyacon](https://dyacon.com/) MS-100 series **advisory aviation** weather stations on [DyaconLive](https://dyacon.com/dyaconlive/). Product overview: [Dyacon aviation weather stations](https://dyacon.com/aviation-weather-station/). Requires **DyaconLive+** and API access from Dyacon (`support@dyacon.net`). Authentication uses your DyaconLive web login (no separate API key in the portal).

Dyacon hardware measures wind, temperature, humidity, and barometric pressure (rain when a gauge is installed). DyaconLive Aviation Mode can display derived values such as estimated cloud base in the portal, but the API exposes sensor time series only. AviationWX does **not** populate ceiling or visibility from Dyacon. Pair with `metar` or another aviation source if you need those fields.

```json
"weather_sources": [
  {
    "type": "dyaconlive",
    "station_id": 130114,
    "username": "your-dyaconlive-email",
    "password": "your-dyaconlive-password"
  }
]
```

| Field | Description |
|-------|-------------|
| `station_id` | Numeric station ID from `GET https://api.dyacon.net/stations` after token auth (not the public widget `pid`) |
| `username` | DyaconLive login email |
| `password` | DyaconLive password |
| `timezone` | Optional IANA timezone for `/data` date boundaries (defaults to airport `timezone`) |

**Pressure:** Dyacon reports station pressure at field elevation. The adapter converts to sea-level altimeter setting (inHg) using airport `elevation_ft`, matching METAR and other sources. If `elevation_ft` is missing on the airport, pressure is omitted.

**Polling:** The global scheduler still runs every 60 seconds. The adapter skips upstream HTTP when the latest 10-minute bucket is already in local state, and fetches when behind or catching up after a miss. Wind uses 10-minute averages (`wind10m_*`).

API docs: https://api.dyacon.net/docs

### Davis WeatherLink v2 (Newer Devices)

For WeatherLink Live, WeatherLink Console, and EnviroMonitor systems. Data interval depends on Davis subscription; see [Davis WeatherLink update intervals](#source-types) above.

```json
"weather_sources": [
  {
    "type": "weatherlink_v2",
    "api_key": "your-api-key",
    "api_secret": "your-api-secret",
    "station_id": "123456"
  }
]
```

**Getting v2 Credentials:**

| Field | Where to Find It |
|-------|------------------|
| `api_key` | WeatherLink Account page → "Generate v2 Key" |
| `api_secret` | Generated with API Key (shown only once!) |
| `station_id` | **We'll look this up for you** - just provide your API Key and Secret |

The Station ID is a numeric value not displayed in the WeatherLink web interface.
When you submit your API Key and Secret, we'll use the API to discover your Station ID.

See the [Weather Station Guide](../guides/09-weather-station-configuration.md) for detailed step-by-step instructions.

### Davis WeatherLink v1 (Legacy Devices)

For older devices: Vantage Connect, WeatherLinkIP, WeatherLink USB/Serial loggers. Same subscription-based intervals as v2; see [Davis WeatherLink update intervals](#source-types) above or [WeatherLink v2 Data Permissions](https://weatherlink.github.io/v2-api/data-permissions) for device-specific tables.

```json
"weather_sources": [
  {
    "type": "weatherlink_v1",
    "device_id": "001D0A12345678",
    "api_token": "your-api-token"
  }
]
```

**Getting v1 Credentials:**

| Field | Where to Find It |
|-------|------------------|
| `device_id` | Printed on a label on your physical device (12-16 characters) |
| `api_token` | WeatherLink Account page → API Token section |

See the [Weather Station Guide](../guides/09-weather-station-configuration.md) for photos and detailed instructions.

### PWSWeather (AerisWeather)

```json
"weather_sources": [
  {
    "type": "pwsweather",
    "station_id": "KMAHANOV10",
    "client_id": "your-aeris-client-id",
    "client_secret": "your-aeris-client-secret"
  }
]
```

### SynopticData

```json
"weather_sources": [
  {
    "type": "synopticdata",
    "station_id": "YOUR_STATION_ID",
    "api_token": "your-api-token"
  }
]
```

### NWS ASOS (National Weather Service)

High-frequency (~5 minute) observations from ASOS stations via the NWS API. Requires explicit `station_id` configuration.

```json
"weather_sources": [
  {
    "type": "nws",
    "station_id": "KSPB"
  }
]
```

The `station_id` must be a valid airport ICAO code (e.g., `KSPB`, `KPDX`). Only airport stations are accepted.

Observations use `/stations/{station_id}/observations/latest`. `/points/{lat},{lon}` metadata (grid mapping) is cached under `cache/nws-points/` for 12 hours (`NWS_POINTS_CACHE_TTL_SECONDS`). The scheduler runs `scripts/refresh-nws-points.php` in the background every `NWS_POINTS_REFRESH_INTERVAL_SECONDS` (default 1 hour) for enabled airports with an NWS source and valid `lat`/`lon`; only stale cache entries are refetched. `nwsFetchPoints()` reads the same cache for on-demand lookups.

### AWOSnet

Fetches weather from AWOSnet data endpoint (awiAwosNet.php). The main page uses JavaScript to load data; we fetch the PHP endpoint directly with a Referer header. Structured XML fields are treated as the primary/authoritative data source, and the embedded METAR string is used to fill gaps or augment fields when available. When the page shows "///" (data not available), values are normalized to null.

```json
"weather_sources": [
  {
    "type": "awosnet",
    "station_id": "ks40"
  }
]
```

The `station_id` is the AWOSnet station identifier used in the subdomain (e.g., `ks40` for http://ks40.awosnet.com). Use lowercase.

### Nav Canada Weather (Canadian Airports)

Fetches weather from Nav Canada AWOS/HWOS stations via the SWOB-ML XML feed (hosted by Environment Canada). Use `swob_auto` for automated stations (NAV Canada AWOS) or `swob_man` for manned stations (NAV Canada HWOS). Most airports use one or the other, not both.

**swob_auto** – Automated stations (e.g., CYAV Winnipeg/St. Andrews, CBBC Bella Bella):

```json
"weather_sources": [
  {
    "type": "swob_auto",
    "station_id": "CYAV"
  }
]
```

**swob_man** – Manned stations (e.g., CYVR Vancouver, CYYZ Toronto, CYOW Ottawa):

```json
"weather_sources": [
  {
    "type": "swob_man",
    "station_id": "CYVR"
  }
]
```

The `station_id` must be a 4-letter ICAO code (e.g., `CYAV`, `CYVR`). Case is normalized to uppercase.

### METAR (NOAA Aviation Weather)

METAR data from NOAA Aviation Weather provides aviation-specific observations including visibility, ceiling, and cloud cover. No API key required.

```json
"weather_sources": [
  {
    "type": "metar",
    "station_id": "KSPB",
    "nearby_stations": ["KVUO", "KHIO"]
  }
]
```

`nearby_stations` provides fallback stations if the primary METAR station is unavailable. Whether a METAR source is co-located or supplemental for site health and display is determined at runtime (active station ICAO vs `airport.icao`), not by config key placement. The same co-located vs supplemental principles apply to other remote weather source types; see [Supplemental remote weather policy](DATA_FLOW.md#supplemental-remote-weather-policy).

The scheduler also refreshes the shared AWC METAR bulk gzip when **more than one airport is enabled** (`scripts/refresh-metar-bulk.php`, interval `METAR_BULK_REFRESH_INTERVAL_SECONDS` in `lib/constants.php`) and writes per-ICAO JSON slices under `cache/metar-bulk/stations/`. A successful refresh also writes `cache/metar-bulk/meta.json` with `fetched_at`, `written`, and `scanned` row counts; logs include `metar_bulk_age_seconds` (seconds since that snapshot). Failed refreshes log the last known age when meta is present. Weather workers read a fresh slice before calling the per-station HTTP API in that mode (`metarResolveStationResponse()` from `UnifiedFetcher` and `fetchMETARFromStation()`). Bulk ingest requires the gzip CSV header to match the canonical AWC column list in `lib/metar-bulk-csv-schema.php` (tests fail on drift). A **single enabled airport** uses per-station HTTP only (no national gzip download).

### Upstream rate limiting (weather fetch)

`lib/upstream-rate-limit.php` applies a file-backed token bucket per provider and credential fingerprint before outbound weather API calls (`cache/upstream-limits/`). Limits are defined in `lib/constants.php` (`UPSTREAM_RATE_LIMIT_*` per provider). Shared API keys (for example Tempest `api_key`) share one bucket across all airports using that key. Keyless sources use per-station identity in the fingerprint (`station_id` for AWOSnet, SWOB, NWS, METAR HTTP; `base_url` + `airport_id` for `aviationwx_api`).

**Ambient Weather** uses two coordinated buckets per request: `api_key` (1 req/s, burst 1) and `application_key` (3 req/s shared across all stations using that developer key, burst 3). HTTP **429** global backoff for Ambient coordinates on `application_key` so all airports under one developer key back off together. **WeatherLink** burst is capped at 3 (upstream allows 10 req/s per API key). **NWS** burst is capped at 2 because api.weather.gov limits are undisclosed.

When the bucket is empty, that source is skipped for one fetch cycle (weather may be stale until the next refresh). If `cache/upstream-limits/` cannot be written, the limiter **fails open** (requests proceed) and logs `upstream rate limit state unavailable`. Monitor those warnings and `upstream_rate_limit_fail_open` counters in `cache/weather_health.json` (refreshed by the scheduler). HTTP **429** responses from weather upstreams increment `upstream_429` and per-provider `upstream_429_{source}` counters. On the status page, expand **Weather Data Fetching** to see per-provider 429 counts for the last hour. PHPUnit and mock mode skip throttling.

`metarResolveStationResponse()` in `lib/weather/adapter/metar-v1.php` reports outcomes as `METAR_RESOLVE_*` constants (`ok`, `throttled`, `circuit_open`, `http_failed`, `invalid_station`). Only `http_failed` and `invalid_station` count as fetch failures for the per-airport METAR circuit breaker; `throttled` is a self-throttle skip for one cycle.

### NOTAM upstream rate limiting and health

`lib/notam/rate-limit.php` paces NMS traffic with a flock-backed token bucket (`cache/upstream-limits/`) keyed by `notam_api_client_id` + `notam_api_base_url`. The client uses `NOTAM_RATE_LIMIT_REQUESTS_PER_MINUTE` (default 54) as a margin under the documented 60/min NMS cap. Workers wait for a token (poll `NOTAM_RATE_LIMIT_POLL_MICROSECONDS`, fail open after `NOTAM_RATE_LIMIT_MAX_WAIT_SECONDS`) so location and geo queries for one airport still complete.

Location and geo requests run through `lib/notam/http.php`, which captures response headers, retries once on HTTP **429** or **503** after a capped `Retry-After` wait (`NOTAM_429_RETRY_MAX_WAIT_SECONDS`), and coordinates fleet-wide pauses via `lib/notam/circuit-breaker.php` (`global_notam_{fingerprint}` in `cache/backoff.json`). While a pause is active, new NMS calls defer without hitting the network. A successful HTTP **200** clears the pause. Default pause length without `Retry-After` is `NOTAM_GLOBAL_BACKOFF_DEFAULT_SECONDS` (60).

The scheduler staggers NOTAM enqueue across the refresh window (`notamStaggerOffsetSeconds()` in `lib/notam/scheduling.php`) and starts at most `NOTAM_SCHEDULER_MAX_ENQUEUE_PER_LOOP` (default 1) new NOTAM worker per scheduler tick.

**Worker pool vs enqueue cap:** `notam_worker_pool_size` limits how many NOTAM workers may run at once; the per-tick enqueue cap limits how many *new* jobs the scheduler starts each second. With the default pool size of 1, extra capacity is unused by design. Raising the pool without raising the enqueue cap (or without a higher NMS rate limit) leaves slots idle. Raising both still serializes on the shared NMS token bucket in `lib/notam/rate-limit.php`. Prefer `notam_worker_pool_size: 1` unless profiling shows benefit from workers overlapping non-API work (for example parse/cache while another worker waits on HTTP).

HTTP **429** and other NMS outcomes increment counters in `cache/notam_health.json` via `lib/notam-health.php` (scheduler flush every 60 seconds). On the status page, expand **NOTAM Data Fetching** for per-endpoint 429 counts (location, geo, auth) in the last hour.

On HTTP **429** or **503**, weather upstreams use a shared `global_weather_{provider}_{fingerprint}` backoff key in `lib/circuit-breaker.php` so all airports using that credential back off together. For Ambient, the global key uses the `application_key` fingerprint only; per-request throttling still checks both `api_key` and `application_key` buckets. A successful fetch clears both per-airport and global keys for that credential.

When the upstream response includes **`Retry-After`** or **`X-RateLimit-Reset`**, weather and NOTAM backoff use the header hint clamped to 15 minutes (`BACKOFF_MAX_RETRY_AFTER_SECONDS` in `lib/constants.php`).

### Backup Sources

Mark a source as backup by adding `"backup": true`. Backup sources are only used when primary sources fail or are stale:

```json
"weather_sources": [
  {
    "type": "tempest",
    "station_id": "149918",
    "api_key": "your-key"
  },
  {
    "type": "ambient",
    "api_key": "backup-key",
    "application_key": "backup-app-key",
    "backup": true
  },
  {
    "type": "metar",
    "station_id": "KSPB"
  }
]
```

### Multiple Sources Example

Combine multiple sources for redundancy and data quality. All sources are fetched in parallel:

```json
"weather_sources": [
  {
    "type": "tempest",
    "station_id": "149918",
    "api_key": "your-key"
  },
  {
    "type": "nws",
    "station_id": "KSPB"
  },
  {
    "type": "metar",
    "station_id": "KSPB",
    "nearby_stations": ["KVUO", "KHIO"]
  }
]
```

The aggregator uses the freshest data for each field. METAR typically provides ceiling and cloud_cover (other sources do not provide these fields).

---

## Webcam Configuration

### Format Detection

Automatic detection based on URL:
- `rtsp://` or `rtsps://` → RTSP stream
- `.jpg`, `.jpeg` → Static JPEG
- `.png` → Static PNG (converted to JPEG)
- Other URLs → MJPEG stream

Override with explicit `type` field.

### MJPEG Stream

```json
{
  "name": "Main Field View",
  "url": "https://example.com/mjpg/video.mjpg"
}
```

### Static Image

```json
{
  "name": "Weather Station Cam",
  "url": "https://wx.example.com/webcam.jpg",
  "refresh_seconds": 120
}
```

### RTSP Stream

```json
{
  "name": "Runway Camera",
  "url": "rtsp://camera.example.com:554/stream1",
  "type": "rtsp",
  "rtsp_transport": "tcp",
  "refresh_seconds": 30,
  "rtsp_fetch_timeout": 10,
  "rtsp_max_runtime": 6
}
```

For secure RTSP (RTSPS), use `rtsps://` URL with `"type": "rtsp"`.

#### UniFi Protect RTSP URLs

UniFi Protect cameras use specific ports for RTSP streams:

| Type | URL Pattern | Port |
|------|-------------|------|
| Local RTSP (unencrypted) | `rtsp://nvr-ip:7447/STREAM_ID` | 7447 |
| Shared RTSPS (encrypted) | `rtsps://nvr-ip:7441/STREAM_ID?enableSrtp` | 7441 |

**Local RTSP example (recommended for local AviationWX Bridge):**
```json
{
  "name": "UniFi Camera",
  "url": "rtsp://192.168.1.1:7447/FKEFbCxO0CiAF3TH",
  "type": "rtsp",
  "rtsp_transport": "tcp",
  "refresh_seconds": 60
}
```

**Shared RTSPS example (for remote access with encryption):**
```json
{
  "name": "UniFi Camera (Secure)",
  "url": "rtsps://192.168.1.1:7441/FKEFbCxO0CiAF3TH?enableSrtp",
  "type": "rtsp",
  "rtsp_transport": "tcp",
  "refresh_seconds": 60
}
```

The `STREAM_ID` is unique to each camera and must be copied from the UniFi Protect interface (Settings → Advanced → RTSP).

### Push Webcam (SFTP/FTP/FTPS)

For cameras that upload images to the server:

```json
{
  "name": "Runway Camera (Push)",
  "type": "push",
  "refresh_seconds": 60,
  "push_config": {
    "username": "kspbCam0Push01",
    "password": "SecurePass1234",
    "max_file_size_mb": 10,
    "allowed_extensions": ["jpg", "jpeg"],
    
    // Optional: Advanced tuning (usually not needed)
    "upload_file_max_age_seconds": 1800,      // Max age before file abandonment (default: 1800, range: 600-7200)
    "stability_check_timeout_seconds": 15     // Stability check timeout (default: 15, range: 10-30)
  }
}
```

**Connection details:**
- SFTP: port from `config.network_ports.sftp` (default 2222), host from `upload_hostname` / `upload.{base_domain}`
- FTP/FTPS: control port from `config.network_ports.ftp_control` (default 2121), same host as SFTP
- **SSH host key roster:** `GET https://{upload_hostname}/.well-known/aviationwx-upload-ssh-host-keys.json` returns live SHA256 fingerprints from the container sshd host keys (`/etc/ssh/ssh_host_*_key.pub`). Responses use aggressive no-store cache headers (`no-store`, `max-age=0`, `s-maxage=0`). Clients can compare against `ssh-keyscan -p {sftp_port} {upload_hostname}`.
- **Both protocols enabled**: Each push camera gets FTP and SFTP with the same credentials
- Restricted client networks: set `config.network_ports.ftps_alt` for an extra inbound control port (NAT to `ftp_control`); `deploy-configure-firewall.sh` applies UFW and NAT on deploy. See [FTPS alternate control port (NAT redirect)](OPERATIONS.md#ftps-alternate-control-port-nat-redirect).

**Upload paths:**
- **FTP**: Upload to `/` (vsftpd lands in FTP directory)
- **SFTP**: Upload to `/files/` (chroot requires subdirectory)

Directory structure (separate hierarchies for FTP and SFTP):
```
/cache/ftp/{airport}/{username}/   <- FTP uploads (ftp:www-data 2775)
/var/sftp/{username}/              <- SFTP chroot (root:root 755)
/var/sftp/{username}/files/        <- SFTP uploads (ftp:www-data 2775)
```

Note: SFTP uses `/var/sftp/` (outside cache) because SSH chroot requires
ALL parent directories to be root-owned. `/var/www/html/cache/` is www-data owned.

**Permission maintenance:** `scripts/repair-sftp-chroot-permissions.sh` (installed as `/usr/local/libexec/aviationwx/repair-sftp-chroot-permissions.sh`) restores chroot ownership. It runs from `sync-push-config.php` on every invocation, from `set-cache-permissions.sh` at container start and nightly (01:00 UTC cron), and from `create-sftp-user.sh` when a user is created. On the production host, `/tmp/aviationwx-cache/sftp` is bind-mounted to `/var/sftp`; avoid recursive `chown` on the whole cache tree that includes `sftp/{username}/`. See [Bridge / SFTP uploads fail (chroot permissions)](OPERATIONS.md#bridge--sftp-uploads-fail-chroot-permissions).

The processor checks both FTP and SFTP directories automatically.

**Subfolder support:** Cameras that create date-based folder structures (e.g., `2026/01/06/image.jpg`) are fully supported. The system recursively searches up to 10 levels deep and automatically cleans up empty folders after processing.

**Upload stability detection:** The system uses adaptive stability checking that starts conservative (20 consecutive stable checks = 10 seconds) and automatically optimizes based on the camera's historical upload performance. After 20+ successful uploads, it can reduce to as low as 5 checks (2.5 seconds) for fast connections.

**Advanced tuning parameters:**
- `upload_file_max_age_seconds`: Files older than this are considered stuck/abandoned and deleted (default: 30 minutes). Increase for known very slow connections (up to 2 hours).
- `stability_check_timeout_seconds`: How long the worker waits for an in-progress upload before returning to try again later (default: 15 seconds). Most sites should use the default.

### Webcam Variants

The system automatically generates multiple image sizes (variants) from the original image to optimize bandwidth and display performance. Variants are identified by height in pixels to support diverse aspect ratios.

**Configuration:**

Variants are configured via `webcam_variant_heights` at three levels (priority: per-camera → per-airport → global):

```json
{
  "config": {
    "webcam_variant_heights": [1080, 720, 360]
  },
  "airports": {
    "kspb": {
      "webcam_variant_heights": [1080, 720, 360],
      "webcams": [
        {
          "name": "Runway Camera",
          "variant_heights": [1080, 720]
        }
      ]
    }
  }
}
```

**How It Works:**
- Original images are preserved at full resolution
- Variants are generated by height (e.g., 1080px, 720px, 360px)
- Width is calculated from height to preserve aspect ratio
- Ultra-wide cameras are capped at 3840px width (prevents extreme widths)
- Only variants ≤ original height are generated
- Variants are stored as `{timestamp}_{height}.{format}` (e.g., `1703700000_1080.jpg`)

**Default Heights:** `[1080, 720, 360]` (supports common 16:9, 2:1, and 3:1 aspect ratios)

**API Usage:**
- Request specific variant: `/webcam.php?id=kspb&cam=0&size=720`
- Request original: `/webcam.php?id=kspb&cam=0&size=original` (default)
- History player automatically selects appropriate variant based on display size

### FAA Profile (Crop Margins)

AviationWX participates in the **FAA Weather Camera Program (WCPO)**, publishing webcam imagery to the FAA's official aviation weather camera network. The FAA WCPO requires specific image formats without third-party timestamps or watermarks.

The `profile=faa` API parameter produces WCPO-compliant images by applying configurable crop margins to exclude camera OSD timestamps and watermarks.

**API Usage:**
```
GET /v1/airports/kspb/webcams/0/image?profile=faa
```

**FAA Profile Behavior:**
- Applies crop margins to exclude edge content (timestamps, watermarks)
- Forces 4:3 aspect ratio
- Forces JPG format
- Quality-capped: 1280x960 if source supports it, otherwise 640x480 (no upscaling)

**Global Default Margins:**

Configure default crop margins (percentages) in the global config:

```json
{
  "config": {
    "faa_crop_margins": {
      "top": 5,
      "bottom": 4,
      "left": 0,
      "right": 4
    }
  }
}
```

**Per-Webcam Override:**

Override margins for specific cameras with unusual timestamp positions:

```json
{
  "webcams": [
    {
      "name": "Runway Camera",
      "url": "rtsp://...",
      "crop_margins": {
        "top": 8
      }
    }
  ]
}
```

**Margin Values:**
- All values are **percentages** (0-50) of source image dimensions
- Top/bottom: percentage of source height
- Left/right: percentage of source width
- Only specified edges are overridden; others use global defaults

**Config Hierarchy:**
1. Per-webcam `crop_margins` (highest priority)
2. Global `faa_crop_margins`
3. Built-in defaults: `{ top: 7, bottom: 4, left: 0, right: 4 }`

**Percentage Scaling Examples:**

| Margin | 720p (1280x720) | 1080p (1920x1080) | 4K (3840x2160) |
|--------|-----------------|-------------------|----------------|
| 5% top | 36px | 54px | 108px |
| 4% bottom | 29px | 43px | 86px |
| 4% right | 51px | 77px | 154px |

---

## Webcam History (Time-lapse)

Stores recent frames for time-lapse playback. Webcam images are stored at `cache/webcams/{airport}/{cam}/{YYYY-MM-DD}/{HH}/`.

### Storage Architecture

- **Date/Hour Subdirs**: `{YYYY-MM-DD}/{HH}/` limits files per directory (~500/hour)
- **Unified Storage**: Timestamped files serve as both current and historical images
- **Symlinks**: `current.jpg`, `original.jpg` at camera root point to latest in date/hour subdir
- **Retention**: Controlled by `webcam_history_retention_hours` config

### Configuration

History retention uses `webcam_history_retention_hours`:

| Setting | Default | Description |
|---------|---------|-------------|
| `webcam_history_retention_hours` | `24` | Hours of history to retain |
| `webcam_history_default_hours` | `3` | Default period shown in player UI |
| `webcam_history_preset_hours` | `[1, 3, 6, 24]` | Period selection buttons in UI |

The history player is enabled when `retention_hours > 0` and at least 2 frames exist.

### Set Globally

```json
{
  "config": {
    "webcam_history_retention_hours": 24,
    "webcam_history_default_hours": 3,
    "webcam_history_preset_hours": [1, 3, 6, 24]
  }
}
```

### Override Per-Airport

Use per-airport overrides for airports with different retention needs:

```json
{
  "airports": {
    "kspb": {
      "webcam_history_retention_hours": 48,
      "webcam_history_default_hours": 6
    }
  }
}
```

### Cleanup Safety Net

Cleanup uses a 2x safety multiplier to prevent data loss:
- Expected frames = `retention_hours × (3600 / refresh_seconds)`
- Max frames = `expected_frames × 2.0`

This ensures frames aren't deleted prematurely if timestamps don't align perfectly.

### Player UI Period Selection

The history player shows period preset buttons (e.g., "1h", "3h", "6h", "All") allowing users to select how much history to view:

- Only presets with sufficient data (≥90% coverage) are shown
- The default period is configurable
- Users can select "All" to view the entire retention period
- Lazy loading: frames are only downloaded when played or scrubbed to

### Legacy Configuration

The old `webcam_history_max_frames` setting is deprecated but still supported:
- If only `max_frames` is set, it's converted to hours automatically
- If both are set, `retention_hours` takes precedence
- A deprecation warning is logged when legacy config is detected

### Player URLs

- `https://kspb.aviationwx.org/?cam=0` - Opens player
- `https://kspb.aviationwx.org/?cam=0&autoplay` - Auto-plays
- `https://kspb.aviationwx.org/?cam=0&autoplay&hideui` - Kiosk mode
- `https://kspb.aviationwx.org/?cam=0&period=3h` - Opens with 3-hour period selected
- `https://kspb.aviationwx.org/?cam=0&period=all` - Opens with all history

### Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `Space` | Play/pause |
| `←` / `→` | Previous/next frame |
| `Home` / `End` | First/last frame |
| `H` | Toggle hide UI |
| `Escape` | Close player |

---

## Default Preferences (Unit Toggles)

Configure default units for the airport page toggle buttons. User preferences (stored in cookies) always take priority over these defaults.

### Available Preferences

| Preference | Options | Default |
|------------|---------|---------|
| `time_format` | `12hr`, `24hr` | `12hr` |
| `temp_unit` | `F`, `C` | `F` |
| `distance_unit` | `ft`, `m` | `ft` |
| `baro_unit` | `inHg`, `hPa`, `mmHg` | `inHg` |
| `wind_speed_unit` | `kts`, `mph`, `km/h` | `kts` |

### Set Global Defaults

```json
{
  "config": {
    "default_preferences": {
      "time_format": "24hr",
      "temp_unit": "C",
      "distance_unit": "m",
      "baro_unit": "hPa",
      "wind_speed_unit": "kts"
    }
  }
}
```

### Override Per-Airport

```json
{
  "airports": {
    "egll": {
      "default_preferences": {
        "temp_unit": "C",
        "baro_unit": "hPa"
      }
    }
  }
}
```

### Priority Order

1. **User preference** - stored in browser cookie/localStorage (persists across visits)
2. **Per-airport** - `airport.default_preferences`
3. **Global** - `config.default_preferences`
4. **Built-in** - US aviation standards (12hr, °F, ft, inHg, kts)

Only include preferences you want to change from defaults. Users who have previously set a preference will keep their choice.

---

## TFR NOTAM overlay (airport map)

The airport network map loads aggregated TFR GeoJSON from the Internal API route **`GET /api/notam-map.php`** (built from per-airport NOTAM caches). Restrictions are drawn as polygons or true-radius circles. **Detail copy (NOTAM id, schedule line, vertical summary, FAA link) opens in a Leaflet popup on tap or click only** so touch devices do not get a second hover tooltip over the same feature.

---

## Weather Overlays (Airport Map)

The airport network map at https://airports.aviationwx.org/ can display weather overlays from two sources:
1. **RainViewer** - Precipitation radar (no API key required)
2. **OpenWeatherMap** - Cloud cover, temperature, wind, pressure (requires free API key)

Both services are proxied through `/api/map-tiles.php` for server-side caching and usage metrics.

---

### RainViewer Precipitation Radar

**Always available** - No configuration required. Displays real-time precipitation radar overlay.

The browser requests `/api/rainviewer-weather-maps.php` (manifest JSON, same shape as RainViewer upstream `weather-maps.json`), then requests tiles using the **12-character hex frame id** from `radar.past[].path` as query parameter `radar` on `/api/map-tiles.php`. Unix timestamps in the tile path are **not** accepted by RainViewer tilecache (410 Gone).

- **Source**: [RainViewer](https://www.rainviewer.com/)
- **Data**: Precipitation intensity (rain/snow)
- **Update frequency**: Every 10 minutes
- **Cache TTL**: 15 minutes (server-side)
- **API key**: Not required
- **Max zoom**: 7 (tiles scale up at higher zoom levels)

The precipitation radar layer is always enabled and accessible through the map controls (☔).

RainViewer limits tile requests to zoom level **7** and **100 requests per IP per minute**. The map client requests tiles at zoom 7 and scales them at higher zoom. Server-side caching reduces pressure on that limit.

---

### OpenWeatherMap Weather Layers

**Optional** - Requires a free API key. When configured, enables cloud cover and other weather overlays.

#### Available Weather Layers

When configured, the following layers are available:
- **Cloud Cover** (`clouds_new`) - Cloud coverage overlay (exposed in UI)
- **Precipitation** (`precipitation_new`) - Rain/snow intensity  
- **Temperature** (`temp_new`) - Temperature gradient map
- **Wind Speed** (`wind_new`) - Wind speed visualization
- **Pressure** (`pressure_new`) - Atmospheric pressure

Currently, only the cloud layer is exposed in the UI. Additional layers can be added to the map controls in `pages/airports.php` if desired.

#### Getting an API Key

1. Sign up for a free account at [OpenWeatherMap](https://home.openweathermap.org/users/sign_up)
2. Navigate to [API Keys](https://home.openweathermap.org/api_keys)
3. Generate a new API key
4. Wait 10-20 minutes for the key to activate (standard OpenWeatherMap activation time)

#### Configuration

Add your API key to the global `config` section in `airports.json`:

```json
{
  "config": {
    "openweathermap_api_key": "your_api_key_here"
  }
}
```

#### Behavior

- **When configured**: Cloud layer toggle (☁️) appears in the map controls
- **When not configured**: Cloud layer toggle is hidden (precipitation radar still works)
- Free tier includes 60 calls/minute, 1,000,000 calls/month (sufficient for most deployments)

---

### Tile Proxy and Caching

All weather tiles (RainViewer and OpenWeatherMap) are proxied through `/api/map-tiles.php` for:
- **Server-side caching** - Reduces external API calls
- **Usage metrics** - Track tile requests for monitoring
- **Consistent CORS** - Unified cross-origin handling
- **Rate limiting** - Abuse protection

#### Multi-Layer Caching Architecture

**Caching layers (from fastest to slowest):**
1. **Browser cache** - Tiles cached in user's browser (session-based)
2. **Nginx proxy cache** - Shared cache at reverse proxy level
   - OpenWeatherMap: 1 hour TTL
   - RainViewer: 15 minutes TTL (radar updates frequently)
3. **PHP file cache** - Server-side cache at application level
   - OpenWeatherMap: 1 hour TTL
   - RainViewer: 15 minutes TTL
4. **External API** - Only hit when caches miss

**How this works in practice:**
- First user viewing a tile: Hits external API (counts against rate limit if applicable)
- Same user viewing same tile again: Browser cache (0 API calls)
- Different user viewing same tile (within TTL): Nginx cache (0 API calls)
- All users share the same server-side caches

**Optimization settings:**
- Tiles only load between zoom levels 3-12 (aviation planning range)
- Tiles only refresh when user stops panning (not during drag)
- Additional tiles kept in memory to reduce re-fetching
- Cache headers include `stale-while-revalidate` for resilience

#### Estimated Usage (OpenWeatherMap)

With server caching:
- First deployment day: ~500-2,000 tiles fetched (filling cache)
- Subsequent days: ~50-200 tiles/day (cache refreshes)
- Per user: Typically 0-5 API calls (most tiles already cached)
- High traffic (100 users/day): Still under 1,000 API calls/day
- **Total monthly: ~10,000-50,000 calls** (well under 1M limit)

**Rate limit handling:**
If you exceed 60 calls/minute (very rare with caching), OpenWeatherMap returns HTTP 429. The proxy will serve stale cached tiles as fallback.

**Monitoring your usage:**
- Check your API usage at: https://home.openweathermap.org/statistics
- If you consistently hit rate limits, consider starting with cloud layer disabled by default

---

### Abuse Protection

The tile proxy includes **permissive rate limiting** (300 requests/minute per IP):
- Legitimate users won't hit this limit (normal usage: ~10-50 tiles/session)
- Blocks obvious abuse (bots, scrapers, automated tools)
- Returns HTTP 429 with `Retry-After: 60` header when exceeded
- Rate limit window resets every minute

**Why permissive?**
- Panning the map quickly can load 20-50 tiles in seconds
- Multiple browser tabs or family members sharing IP need headroom
- Focus is on abuse prevention, not usage restriction

**Monitoring:**
Rate limit violations are logged to help identify abuse patterns:
```
aviationwx_log('warning', 'map tiles rate limit exceeded', ...)
```

---

### Testing

After adding your API key:
1. Visit https://airports.aviationwx.org/
2. Look for the cloud toggle button (☁️) in the map controls
3. Click to enable/disable the cloud overlay
4. Adjust opacity using the slider

---

## Runway Configuration

Two formats are supported:

**Heading format** (simple, for single runways or when lat/lon unavailable):

```json
"runways": [
  { "name": "15/33", "heading_1": 152, "heading_2": 332 },
  { "name": "28L/10R", "heading_1": 280, "heading_2": 100 }
]
```

**Lat/lon format** (preferred for accurate geometry; use `scripts/convert-runways-to-latlon.php` to generate from FAA/OurAirports):

```json
"runways": [
  {
    "name": "15/33",
    "15": { "lat": 45.777901, "lon": -122.863998 },
    "33": { "lat": 45.764198, "lon": -122.860001 }
  },
  {
    "name": "28L/10R",
    "28L": { "lat": 45.549599, "lon": -122.959999 },
    "10R": { "lat": 45.535, "lon": -122.944 }
  }
]
```

Each runway end ident (e.g. `15`, `33`, `28L`, `10R`) maps to `{ lat, lon }` coordinates. Labels are placed by bearing from airport center. Run `php scripts/convert-runways-to-latlon.php kspb khio` to fetch from FAA/OurAirports and output the lat/lon schema.

**Compare manual vs cache:** `CONFIG_PATH=/path/to/airports.json php scripts/compare-runways.php` compares manual runway definitions with FAA/OurAirports data. Use `--tolerance 0.0001` (default, ~11m) or `--tolerance 0.001` (~111m) to control what counts as a match.

**List airports missing runway data:** `php scripts/list-airports-missing-runways.php` lists configured airports that have no runways in the cache (manual runways are skipped). Run `scripts/fetch-runways.php` first to populate the cache.

Parallel runways (L/C/R) are automatically detected and displayed side-by-side.

**Magnetic declination** (optional): Override per-airport or globally for runway wind diagram alignment. Positive = East (mag N east of true N). When no override is set, declination is computed from the bundled NOAA World Magnetic Model (offline WMM) using airport lat/lon. See [Magnetic declination](#magnetic-declination) below.

---

## Magnetic declination

Magnetic declination drives the runway wind diagram and `wind_direction_magnetic` in weather responses. Values are in degrees; positive = East (magnetic north east of true north), negative = West.

### Resolution cascade

1. **Per-airport `magnetic_declination`** in `airports.{id}` when set to a numeric value
2. **Global `config.magnetic_declination`** when set to a numeric value
3. **Offline WMM** from bundled NOAA coefficients when the airport has numeric `lat` and `lon`
4. **`0`** when none of the above apply (fail-safe default)

Non-numeric override values are ignored so the cascade can fall through to WMM or `0`.

### Offline WMM

Coefficients ship in `data/wmm/` (see `data/wmm/manifest.json` for model epoch and validity window). No API key or network access is required. Typical accuracy is on the order of **0.3-0.5°** for CONUS; error grows toward the poles and with stale coefficients past the model validity window.

When coefficients are unreadable or outside the validity window, the cascade fails closed to `0` and logs an internal warning.

### Manual overrides

Use per-airport or global overrides when you need a fixed value (for example a field survey) or when lat/lon are missing. Overrides take precedence over WMM.

---

## Partners & Links

### Partners

Displayed prominently above footer:

```json
"partners": [
  {
    "name": "Local Aviation Club",
    "url": "https://club.example.com",
    "logo": "https://club.example.com/logo.png",
    "description": "Supporting local aviation"
  }
]
```

**Logo values:**

| Form | Example | Notes |
|------|---------|-------|
| Remote URL | `https://club.example.com/logo.png` | Served via `/api/partner-logo.php`; image cached under `cache/partners/` for 30 days |
| Local path | `/partner-logos/club-logo.png` | File deployed beside `airports.json` (secrets repo); not web-public except through the logo API |

Logos are cached locally for 30 days (remote URLs). Text fallback if the image fails to load.

**Contrast-aware tiles:** When the logo file is readable (local path or a warmed remote cache entry), the airport page samples opaque pixels once and embeds mean luminance on the partner link when opaque coverage is below `PARTNER_LOGO_OPAQUE_COVERAGE_THRESHOLD` (0.85 in `lib/constants.php`; mostly transparent logos with light or dark marks that need a contrasting tile). Logos with baked-in backgrounds (JPEG or PNG with high opaque coverage) keep the default tile. The dashboard applies a dark or light tile background when a light logo would sit on a light card (or a dark logo on a dark card), without inverting the image. Results are cached in `cache/partners/lum/` (keyed by image path and invalidated when the file mtime changes). Remote logos only get contrast hints after the image cache exists (first page view may use the default tile until cache warm).

### Custom Links

Appear in the dashboard link row after built-in links (AirNav, FAA Weather, regional, ForeFlight when shown). In the Public API, the same entries are returned under `custom_links` on `GET /v1/airports/{id}` (config file key remains `links`).

```json
"links": [
  { "label": "Airport Website", "url": "https://airport.example.com" },
  { "label": "FBO", "url": "https://fbo.example.com" }
]
```

### External Link Overrides

Standard links auto-generate from best identifier. Override when needed:

```json
"airnav_url": "https://www.airnav.com/airport/KSPB"
```

**Regional weather links:** Canadian airports (ICAO C*) automatically show "NAV Canada Weather" (CFPS Weather and NOTAM). Australian airports (ICAO Y*) show "Airservices Weather Cams". To link to a specific camera site (e.g., a NAV Canada metcam site with known ID), use `regional_weather_url` and optional `regional_weather_label`:

```json
"regional_weather_url": "https://www.metcam.navcanada.ca/lb/cameraSite.jsp?lang=e&id=170",
"regional_weather_label": "NAV Canada WxCam (Calgary Springbank)"
```

---

## Refresh Intervals

### Hierarchy

1. Per-webcam: `webcams[].refresh_seconds`
2. Per-airport: `webcam_refresh_seconds`, `weather_refresh_seconds`
3. Global: `webcam_refresh_default`, `weather_refresh_default`
4. Built-in: 60 seconds

### Constraints

- Minimum: `minimum_refresh_seconds` (default: 5)
- METAR minimum: 60 seconds (data published hourly)

### Example: Fast Webcam, Slow Weather

```json
{
  "airports": {
    "kspb": {
      "webcam_refresh_seconds": 15,
      "weather_refresh_seconds": 300,
      "webcams": [
        { "name": "Priority Cam", "url": "...", "refresh_seconds": 10 },
        { "name": "Static Cam", "url": "...", "refresh_seconds": 120 }
      ]
    }
  }
}
```

---

## SSL Certificate Setup (FTPS)

FTPS requires the wildcard certificate (`*.aviationwx.org`).

### Quick Check

```bash
# Verify certificate
ls -la /etc/letsencrypt/live/aviationwx.org/
openssl x509 -in /etc/letsencrypt/live/aviationwx.org/fullchain.pem -noout -dates

# Check vsftpd SSL status
docker compose -f docker/docker-compose.prod.yml exec web grep "^ssl_enable=" /etc/vsftpd/vsftpd.conf

# Enable SSL manually if needed
docker compose -f docker/docker-compose.prod.yml exec web enable-vsftpd-ssl.sh
```

### Certificate Chain

1. Generate wildcard cert: `certbot certonly --dns-cloudflare -d aviationwx.org -d '*.aviationwx.org'`
2. Mount in Docker: `/etc/letsencrypt:/etc/letsencrypt:rw`
3. Container validates and enables SSL on startup
4. Restart container after renewal

See [DEPLOYMENT.md](DEPLOYMENT.md) for full certificate setup.

---

## Cloudflare Analytics Integration

AviationWX can integrate with Cloudflare Analytics to display real-time traffic, bandwidth, and security metrics on the status page and homepage.

### What It Provides

When configured, Cloudflare Analytics provides:

- **Unique Visitors** - Daily unique visitors across all pages
- **Total Requests** - Total HTTP requests (includes images, API calls, assets)
- **Bandwidth** - Total data transferred (GB)
- **Requests/Visitor** - Engagement metric (avg requests per visitor)
- **Threats Blocked** - Security events blocked by Cloudflare

These metrics appear on:
- **Status Page** (`status.aviationwx.org`) - Full metrics grid in header
- **Homepage** (`aviationwx.org`) - "Pilots Served Today" in hero section

### Configuration

Add the following to your `airports.json` config section:

```json
{
  "config": {
    "cloudflare": {
      "api_token": "your-analytics-read-token",
      "zone_id": "your-zone-id",
      "account_id": "your-account-id"
    }
  }
}
```

### Setup Steps

1. **Create API Token** (Cloudflare Dashboard → My Profile → API Tokens):
   - Use "Analytics:Read" template
   - Or create custom token with permissions:
     - Zone → Analytics → Read
     - Account → Analytics → Read (optional, for account-level metrics)
   - Scope to specific zone or all zones
   - Copy the token (only shown once!)

2. **Find Zone ID**:
   - Go to your domain in Cloudflare Dashboard
   - Right sidebar → "API" section → Zone ID
   - Copy the ID (format: `a1b2c3d4e5f6...`)

3. **Find Account ID** (optional):
   - Cloudflare Dashboard → Click domain
   - Right sidebar → Account ID
   - Copy the ID

4. **Add to Configuration**:
   - Update `airports.json` with credentials
   - Restart application: `make restart`
   - Verify: Check status page for metrics

### Caching Behavior

- **APCu Cache**: 30 minutes (in-memory, fast)
- **File Cache Fallback**: 2 hours (if APCu cleared)
- **Stale Data Strategy**: Shows last valid data if API fails (better than showing zeros)
- **API Rate Limits**: Respects Cloudflare's GraphQL API limits

### Privacy & Security

- **Read-Only**: Token has no write permissions
- **Analytics Only**: Cannot modify DNS, firewall, or other settings
- **No PII**: Only aggregated metrics (no visitor IPs or user data)
- **Local Caching**: Reduces API calls and improves performance

### Disabling Analytics

To disable Cloudflare Analytics:

1. **Remove config**: Delete `cloudflare` section from `airports.json`
2. **Restart**: `make restart`
3. **Result**: Metrics section hidden on status page, homepage shows static airport counts

### Testing

Run the Cloudflare Analytics test suite:

```bash
# Unit tests (includes mock mode tests)
vendor/bin/phpunit tests/Unit/CloudflareAnalyticsTest.php

# Check configuration
php -r "require 'lib/config.php'; \$c = loadConfig(); var_dump(isset(\$c['config']['cloudflare']));"
```

### Troubleshooting

**Metrics showing zeros:**
- Check API token permissions (Analytics:Read required)
- Verify Zone ID is correct
- Check Cloudflare has data for your zone (may take 24h for new zones)
- Review logs: `grep -i cloudflare /var/log/aviationwx/app.log`

**Metrics not appearing:**
- Ensure `cloudflare` config section exists
- Restart after config changes: `make restart`
- Check APCu is available: `php -r "var_dump(function_exists('apcu_fetch'));"`
- Verify file cache fallback: `ls -la cache/cloudflare_analytics.json`

**API errors:**
- Token expired or revoked (regenerate in Cloudflare Dashboard)
- Rate limit exceeded (wait 5-10 minutes, caching should prevent this)
- Zone not on account (verify Zone ID matches your domain)

---

## Public API Configuration

When `config.public_api.enabled` is true, the Public API and weather history features are available. Wind rose data uses a configurable rolling window.

### Canonical base URL (optional)

| Option | Default | Description |
|--------|---------|-------------|
| `canonical_base_url` | `https://api.aviationwx.org/v1` | Optional. Absolute `http://` or `https://` base URL for Public API v1 with no trailing slash. Used by `getCanonicalPublicApiV1BaseUrl()` and the API docs page. Omit to use this default. Set when your deployment’s public API origin differs (self-hosted). |

**Nginx:** On **api.aviationwx.org**, `/api/v1/` redirects must target the same host and path prefix as this value. CD installs **`docker/nginx.conf`** from the repository (see `docker/docker-compose.prod.yml`). Any manual nginx overlay must preserve **`embed.aviationwx.org`** on-host Public API v1 routing (rewrite + `/v1/` → `api/v1/router.php`) so cross-origin `fetch()` to `/api/v1` on the embed host does not hit an off-host redirect before CORS. Deploy runs **`scripts/verify-embed-nginx-conf.php`** in the **`web` container** after image build (not on the bare host). CI covers the same rules via **`tests/Unit/NginxEmbedVhostConfigTest.php`**.

### Rate limits

Anonymous and partner tiers use `config.public_api.rate_limits` with `requests_per_minute`, `requests_per_hour`, and `requests_per_day` per tier. **Numeric defaults are not listed here** so documentation does not drift from code.

- **Defaults:** [`lib/public-api/config.php`](../lib/public-api/config.php) (`getPublicApiRateLimits()`).
- **Example shape:** [`config/airports.json.example`](../config/airports.json.example) under `config.public_api.rate_limits`.
- **Live service:** [api.aviationwx.org](https://api.aviationwx.org) shows the effective limits for the public deployment.

### Wind Rose Options

| Option | Default | Description |
|--------|---------|-------------|
| `wind_rose_window_hours` | `1` | Hours of observations to include in wind rose petals (e.g. 1 = last hour, 3 = last 3 hours). Minimum 1. |
| `wind_rose_period_label` | (derived) | Optional override for display label (e.g. "last hour", "last 3 hours"). When omitted, derived from `wind_rose_window_hours`. |

Example:

```json
{
  "config": {
    "public_api": {
      "enabled": true,
      "weather_history_enabled": true,
      "weather_history_retention_hours": 24,
      "wind_rose_window_hours": 3,
      "wind_rose_period_label": "last 3 hours"
    }
  }
}
```

---

## Validation

The validator uses strict checking: unknown fields are rejected.

### Adding New Fields

1. Update `lib/config.php` - add to allowed fields, add validation
2. Update `tests/Unit/ConfigValidationTest.php` - add tests
3. Update this documentation
4. Update `config/airports.json.example`

### Test Configuration

```bash
# Start Docker development environment
make dev

# Validate config (inside container)
docker compose -f docker/docker-compose.yml exec web \
  php -r "require 'lib/config.php'; var_dump(validateAirportsJsonStructure(loadAirportsConfig()));"

# Test API endpoint
curl http://localhost:8080/api/weather.php?airport=kspb
```

---

## Configuration Files

| File | Purpose |
|------|---------|
| `config/airports.json` | All configuration |
| `cache/weather/{airport}.json` | Cached weather data |
| `cache/weather/history/{airport}.json` | Weather history (24h) |
| `cache/webcams/{airport}/{cam}/` | Webcam images (current and historical) |
| `cache/webcams/{airport}/{cam}/{YYYY-MM-DD}/{HH}/` | Date/hour subdirs (~500 files each) |
| `cache/webcams/{airport}/{cam}/{YYYY-MM-DD}/{HH}/{ts}_original.{ext}` | Original timestamped images |
| `cache/webcams/{airport}/{cam}/{YYYY-MM-DD}/{HH}/{ts}_{height}.{ext}` | Variant timestamped images |
| `cache/webcams/{airport}/{cam}/pull_metadata.json` | Pull cameras: ETag + checksum for conditional/unchanged skip |
| `cache/webcams/{airport}/{cam}/current.{ext}` | Latest webcam (symlink to date/hour subdir) |
| `cache/map_tiles/{layer}/{z}/{x}/{y}.png` | Map tile cache (hierarchical) |
| `cache/rate_limits/{prefix}/{hash}.json` | Rate limit state (prefix = first 2 chars of hash) |
| `cache/ftp/{airport}/{username}/` | FTP push uploads (ftp:www-data 2775) |
| `/var/sftp/{username}/` | SFTP chroot (root:root 755) - outside cache |
| `/var/sftp/{username}/files/` | SFTP push uploads (ftp:www-data 2775) |
| `cache/peak_gusts/{airport}.json` | Per-airport daily peak gust tracking |
| `cache/temp_extremes/{airport}.json` | Per-airport daily temperature extremes |
