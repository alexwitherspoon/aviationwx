# 10 - AviationWX Bridge

## Goal

Get cameras and weather stations onto AviationWX when they can't publish directly. The Bridge is a small local device that captures still images from your cameras, collects readings from your weather station, and uploads both to aviationwx.org reliably.

This guide covers what the Bridge is, what it connects to, and how to install it. It is intentionally short. Deep configuration and operations live in the [Bridge repository](https://github.com/alexwitherspoon/aviationwx-bridge), and the links below point to the right page.

## Where the Bridge fits

AviationWX prefers a direct connection whenever the hardware supports one:

- **Reolink cameras** can publish images straight to aviationwx.org via FTP/FTPS. When your camera supports it, use that (see Guide 08).
- **Tempest stations** run on a clean cloud API with good properties like automatic sensor calibration. Use that when you have one (see Guide 09).

But a lot of sensors have no cloud path and no direct upload. A Davis station behind a WeatherLink Live hub, an Ecowitt gateway, a UniFi Protect NVR, an RTSP-only security camera. None of those can talk to aviationwx.org on their own. They need a device in the middle that reads the sensor and pushes the data.

The Bridge is that device. It sits on your local network and:

- captures still images from cameras (HTTP snapshot, RTSP, or ONVIF)
- collects weather readings from supported stations (see the table below)
- stamps each image with its observation time and verifies local time against NTP
- queues uploads while the internet is down and resumes when it returns
- uploads over an encrypted connection

One Bridge can carry cameras and weather stations at the same time, so a single airport can run both feeds off one small box.

| Use the Bridge when | Direct upload is better when |
|---------------------|------------------------------|
| The camera has no FTP/FTPS upload | The camera publishes FTP/FTPS and it works reliably |
| The camera only offers RTSP or an HTTP snapshot | You are already getting good images directly |
| The weather station has no cloud API | The station has a clean cloud API (Tempest) or pushes on its own |
| You want local queuing and one box for everything | You prefer fewer devices and your current path is stable |

## What it connects to

### Cameras

| Type | How it works | Good for |
|------|--------------|----------|
| **HTTP snapshot** | Bridge fetches a JPEG from a URL | Cameras with a `/snapshot.jpg`-style endpoint |
| **RTSP** | Bridge extracts still frames from the video stream (via ffmpeg) | NVRs, UniFi Protect, enterprise cameras |
| **ONVIF** | Industry-standard camera protocol | Many commercial IP cameras |

### Weather stations

| Type | How it works | Good for |
|------|--------------|----------|
| **Davis WeatherLink Live** | Bridge polls the Davis local API on your LAN | Vantage Pro2 or Vantage Vue with a WeatherLink Live hub |
| **Ecowitt gateway** | Bridge polls the gateway's live data endpoint | Ecowitt GW1000, GW1100, and similar gateways |
| **HTTP endpoint** | The station pushes Weather Underground-style updates to the Bridge | Stations or loggers that can POST to a URL |

Field-level configuration for every type (intervals, transmitter IDs, listen addresses, dialects) is in the [configuration reference](https://github.com/alexwitherspoon/aviationwx-bridge/blob/main/docs/CONFIG_SCHEMA.md) in the Bridge repo.

## Hardware

The Bridge ships as a standard Linux container (ARM64, x86-64, 32-bit ARMv7). It runs on any host that runs Docker: a single-board computer, a mini-PC, a server, or a VM.

| Tier | Specs | Notes |
|------|-------|-------|
| Minimum | 1GB RAM, Raspberry Pi 4-class quad-core CPU, 8GB storage | One camera, conservative intervals |
| Recommended | 3GB+ RAM, 16GB+ storage, wired ethernet | Multiple cameras and higher resolutions |

RAM is the binding constraint, not CPU. The Raspberry Pi 4 and 5 are the best-documented boards. Comparable hardware works too: Radxa ROCK, Orange Pi 5, Libre Computer, or an Intel N100-class mini-PC. These are examples, not endorsements.

**Below the floor:** Raspberry Pi 3 and Pi Zero 2 W are not recommended, even where the Bridge still starts.

For long-term reliability, prefer an NVMe SSD or a high-endurance SD card. The Bridge buffers images in RAM rather than on the storage medium, which is a big part of why small boards work.

## Installation

### Path A: Supervised install (recommended)

Best for a dedicated device at an airport with no IT staff. One command:

```text
curl -fsSL https://raw.githubusercontent.com/alexwitherspoon/aviationwx-bridge/main/scripts/install.sh | sudo bash
```

That installs Docker (if needed), the Bridge container, and a small update supervisor on the host. The supervisor:

- checks for updates on boot and once a day
- applies a new release when GitHub has one
- rolls back if the Bridge fails its health check after an update
- restarts the Bridge after a crash or a capture stall

You can also update manually (`sudo aviationwx update`) and check where things stand (`sudo aviationwx status`).

After installation:

- Web console: `http://<device-ip>:1229`
- Default password: `aviationwx`. Change it in Settings right away.

### Path B: Docker (IT-managed)

Best for environments that already run Docker. Your team keeps update and rollback control through their own tooling:

```text
docker pull ghcr.io/alexwitherspoon/aviationwx-org-bridge:latest
```

The [deployment guide](https://github.com/alexwitherspoon/aviationwx-bridge/blob/main/docs/DEPLOYMENT.md) has the full `docker run` and Docker Compose examples, including the tmpfs buffer sizing.

## Network exposure

The Bridge is a local LAN utility. The web console is for you, on your network. Do not port-forward port 1229 to the internet or bind it publicly. Reach it from your LAN or over a VPN, like any other admin interface on a small device.

## Web console

The console at `http://<device-ip>:1229` is where you add cameras and stations, set intervals, and watch what's happening. Screenshots live in the [repo](https://github.com/alexwitherspoon/aviationwx-bridge/tree/main/docs/images):

- [Dashboard](https://github.com/alexwitherspoon/aviationwx-bridge/blob/main/docs/images/dashboard.jpg)
- [Cameras](https://github.com/alexwitherspoon/aviationwx-bridge/blob/main/docs/images/cameras.jpg)
- [Add camera](https://github.com/alexwitherspoon/aviationwx-bridge/blob/main/docs/images/add-camera.jpg)
- [Settings](https://github.com/alexwitherspoon/aviationwx-bridge/blob/main/docs/images/settings.jpg)
- [Logs](https://github.com/alexwitherspoon/aviationwx-bridge/blob/main/docs/images/logs.jpg)

Changes to cameras, timezone, and time-sync settings apply immediately, no restart needed.

## Getting upload credentials

Same process as direct camera uploads:

1. **Submit your airport** following Guide 12
2. **Request Bridge credentials** in your email (mention you're using the AviationWX Bridge). Ask for one distinct SFTP username per camera. The Bridge rejects two cameras that share the same SFTP identity (same host, port, and username).
3. **Receive upload details** (server, port, username, password)
4. **Enter credentials** for each camera when you add or edit it in the web console. Upload settings are per-camera; there is no global credential slot.

Image uploads go to `upload.aviationwx.org` over SFTP, port 2222. The Bridge is SFTP only. Weather readings go to the aviationwx.org API. On a self-hosted deployment, the ports are defined in your core's `config.network_ports`.

## Reliability

| Feature | What it does |
|---------|--------------|
| **Local queuing** | Images buffer in RAM while the internet is down; uploads resume automatically |
| **Weather catch-up** | Recent station readings hold in a short in-memory window and replay when the uplink recovers |
| **Automatic retry** | Failed uploads retry with backoff |
| **Time verification** | EXIF validation on images, NTP checks on the device clock |
| **Host-key verification** | SFTP connections verify the server's SSH key against the aviationwx.org roster |
| **Automatic updates** | Supervisor applies releases on boot and daily, rolls back on failure (Path A) |
| **Self-recovery** | Restarts after crashes and capture stalls; no nightly reboot needed |

## Common scenarios

### Scenario 1: UniFi Protect cameras

UniFi Protect doesn't offer scheduled FTP uploads. The Bridge reads the local RTSP stream instead. **The Bridge must be on the same network as the NVR.**

**Get and convert the RTSP URL:**

1. **Get the URL from UniFi Protect:** Home → Protect → UniFi Devices → (select camera) → Settings → Advanced → toggle the resolution you want → copy the URL (e.g., `rtsps://192.168.1.1:7441/QxJCVMefFHfBnqrp?enableSrtp`)

2. **Convert it.** UniFi's URL needs three adjustments:
   - Change `rtsps://` to `rtsp://` (remove the second `s`)
   - Change port `7441` to `7447`
   - Remove `?enableSrtp` from the end

3. **Use it in the Bridge:** enter the converted URL as an RTSP camera. Leave the username and password empty. UniFi local RTSP has no authentication.

```
# What UniFi gives you:
rtsps://192.168.1.1:7441/QxJCVMefFHfBnqrp?enableSrtp

# What you enter in the Bridge:
rtsp://192.168.1.1:7447/QxJCVMefFHfBnqrp
```

### Scenario 2: Davis station without a cloud path

A Davis Vantage Pro2 or Vue behind a WeatherLink Live hub can publish straight through the Bridge. Point the Bridge at the hub's LAN address, run a test poll in the console, and pick the transmitter. No WeatherLink account needed.

The Bridge also needs its API key installed, and the station has to be enabled in the airport config. The aviationwx.org team sets both up. The `api` block and key live in the bridge config; see the [configuration reference](https://github.com/alexwitherspoon/aviationwx-bridge/blob/main/docs/CONFIG_SCHEMA.md) for how the block works.

### Scenario 3: NVR-only installations

An NVR that records continuously but can't push stills:

- the Bridge pulls RTSP from its camera feeds
- one Bridge handles all the cameras on the NVR

### Scenario 4: Cameras and station in one box

Many small airports end up with:

- a Reolink-style camera that can FTP directly, **or** an RTSP camera that needs the Bridge
- a Davis or Ecowitt station with no cloud

The Bridge handles both roles on one device, so power, networking, and monitoring stay in one place.

### Scenario 5: Remote and solar installations

At remote sites, the queuing matters most:

- images hold in RAM while the internet is down
- uploads resume when connectivity returns
- buffering reduces loss, but it is not a guarantee. The queue thins under sustained pressure, and a device restart drops whatever was in RAM

## Troubleshooting

### "Camera captures are failing"

- **Test the stream separately first:** open **VLC** on a computer on the same network (Media → Open Network Stream) and paste your RTSP URL. If VLC can't play it, the problem is the network or the camera, not the Bridge.
- Verify the camera URL is reachable from the Bridge device
- For UniFi local RTSP, confirm the username and password fields are empty
- Check the Bridge logs in the web console

### "Uploads aren't happening"

- **Test the upload separately first:** use **FileZilla** (or similar) to connect to `upload.aviationwx.org` with your credentials. If FileZilla can't upload a test file, the problem is credentials or network, not the Bridge.
- Verify the Bridge has internet access
- Check the credentials under Settings
- Watch the upload status for a growing queue

### "Weather readings aren't showing up"

- Run the test poll for the station in the console. For Davis, confirm you picked the right transmitter.
- Confirm the station and the Bridge share a network, and that the station's own app or display shows live data
- Check the station's status panel in the console for last-receive times

### "Images look pixelated"

- For RTSP, raise the camera's stream quality or switch to the main stream
- Check bandwidth between the camera and the Bridge
- Prefer a higher-quality snapshot source when one exists

### "The device isn't responding"

- Check power and network
- Health endpoints: `http://<device-ip>:1229/readyz` (capture readiness) and `/healthz` (process status)
- For a Raspberry Pi, power cycle it (unplug, wait 10 seconds, plug in)

For logs and the full operations runbook, see the [deployment guide](https://github.com/alexwitherspoon/aviationwx-bridge/blob/main/docs/DEPLOYMENT.md) and the [maintenance guide](https://github.com/alexwitherspoon/aviationwx-bridge/blob/main/docs/MAINTENANCE_GUIDE.md) in the repo.

## Decision checklist

Before you wire it up, confirm:

- ☐ **What it will carry:** camera list and/or station list
- ☐ **Hardware:** 1GB+ RAM and Pi 4-class CPU minimum; 3GB+ RAM preferred
- ☐ **Storage:** NVMe or high-endurance SD card
- ☐ **Network:** the Bridge can reach every camera and station on the LAN, plus the internet for uploads
- ☐ **Console access:** you can reach `:1229` from your own network (and only from there)
- ☐ **Credentials requested:** you've asked AviationWX for Bridge upload credentials

## Resources

| Resource | Link |
|----------|------|
| Bridge repository | https://github.com/alexwitherspoon/aviationwx-bridge |
| Configuration reference | [CONFIG_SCHEMA.md](https://github.com/alexwitherspoon/aviationwx-bridge/blob/main/docs/CONFIG_SCHEMA.md) |
| Deployment guide | [DEPLOYMENT.md](https://github.com/alexwitherspoon/aviationwx-bridge/blob/main/docs/DEPLOYMENT.md) |
| Queue and storage | [QUEUE_STORAGE.md](https://github.com/alexwitherspoon/aviationwx-bridge/blob/main/docs/QUEUE_STORAGE.md) |
| Maintenance guide | [MAINTENANCE_GUIDE.md](https://github.com/alexwitherspoon/aviationwx-bridge/blob/main/docs/MAINTENANCE_GUIDE.md) |
| Changelog | [CHANGELOG.md](https://github.com/alexwitherspoon/aviationwx-bridge/blob/main/CHANGELOG.md) |
| Request credentials | contact@aviationwx.org |

## Next

- If you haven't submitted your airport yet, see **Guide 12 - Submit a New Airport**
- For camera framing and siting, see **Guide 02 - Location & Siting**
- For direct camera FTP setup, see **Guide 08 - Camera Configuration**
- For weather station options, see **Guide 09 - Weather Station Configuration**

## Technical documentation

- [docs/CONFIGURATION.md](https://github.com/alexwitherspoon/aviationwx/blob/main/docs/CONFIGURATION.md) - configuration schema for airports, cameras, and weather sources
