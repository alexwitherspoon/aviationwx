# 10 - AviationWX Bridge (Optional Image Upload Tool)

## What is the AviationWX Bridge?

The AviationWX Bridge is an optional tool that runs on a small local device like a Raspberry Pi and handles capturing snapshots from local cameras, validating image quality and timestamps, and uploading them reliably to AviationWX via SFTP (or FTPS when required). Use the Bridge when your cameras don't support scheduled FTP uploads natively, such as UniFi Protect or NVR systems.

Think of it as a dedicated "camera assistant" that sits on your local network and makes sure images get to AviationWX-even when your cameras don't natively support scheduled FTP uploads.

```
+-----------------------------------------------------------------------------+
|                         WHEN TO USE THE BRIDGE                              |
+-----------------------------------------------------------------------------+
|                                                                             |
|  USE THE BRIDGE WHEN:                  SKIP THE BRIDGE WHEN:                |
|  ----------------------                -----------------------              |
|  • Camera doesn't support              • Camera has built-in FTP/FTPS       |
|    scheduled FTP uploads                 uploads that work reliably         |
|                                                                             |
|  • Camera only offers RTSP             • You're already getting good        |
|    or HTTP snapshots                     quality images directly            |
|                                                                             |
|  • You want higher reliability         • You prefer fewer devices           |
|    and local quality control             to maintain                        |
|                                                                             |
|  • You have multiple cameras           • Simple single-camera setup         |
|    that need unified handling            is working well                    |
|                                                                             |
+-----------------------------------------------------------------------------+
```

**This is optional.** Many airports work great with cameras that upload directly to AviationWX (see Guide 08). The Bridge is here for when that's not possible or when you want extra reliability.

---

## Why use a Bridge instead of direct camera uploads?

### Problem: Not all cameras support scheduled uploads

Many camera systems-especially **UniFi Protect**, **NVR-based systems**, and some **enterprise cameras**-don't offer built-in FTP/FTPS upload scheduling. They're designed around continuous video recording, not periodic still images.

### Problem: RTSP frame grabs can have quality issues

When AviationWX extracts still images from an RTSP video stream, the result depends on:
- network conditions at the moment of capture
- video compression artifacts
- the camera's encoding settings

This can lead to **pixelation**, **blur**, or **inconsistent quality**-especially on congested networks or over cellular connections.

### Solution: The Bridge handles it locally

The AviationWX Bridge runs on your local network, close to the cameras. It:
- captures high-quality snapshots directly from cameras
- validates timestamps and image integrity before upload
- queues images locally if the internet is temporarily down
- uploads via SFTP (preferred) or FTPS when connectivity is available

**The result:** more consistent image quality and reliable uploads, even with cameras that weren't designed for this workflow.

---

## How it works (architecture)

```
+-----------------------------------------------------------------------------+
|                          YOUR LOCAL NETWORK                                 |
+-----------------------------------------------------------------------------+
|                                                                             |
|  +-----------+     +-----------+     +-----------+                          |
|  |  Camera   |     |  Camera   |     |  Camera   |                          |
|  |  (HTTP)   |     |  (RTSP)   |     |  (ONVIF)  |                          |
|  +-----+-----+     +-----+-----+     +-----+-----+                          |
|        |                 |                 |                                |
|        +--------+--------+---------+-------+                                |
|                 |                  |                                        |
|                 v                  v                                        |
|  +------------------------------------------------------------------+       |
|  |                      AVIATIONWX BRIDGE                           |       |
|  |              (Raspberry Pi or similar device)                    |       |
|  +------------------------------------------------------------------+       |
|  |                                                                  |       |
|  |  +--------------+     +--------------+     +------------------+  |       |
|  |  |    Camera    |     |    Camera    |     |   Web Console    |  |       |
|  |  |    Workers   |     |    Workers   |     |   (port 1229)    |  |       |
|  |  |              |     |              |     |                  |  |       |
|  |  | Capture from |     | Capture from |     | Configure cameras|  |       |
|  |  | each source  |     | each source  |     | View status      |  |       |
|  |  +--------------+     +--------------+     +------------------+  |       |
|  |         |                   |                                    |       |
|  |         v                   v                                    |       |
|  |  +----------------------------------------------------------+    |       |
|  |  |           FILE QUEUE (RAM-based, avoids SD wear)         |    |       |
|  |  |                                                          |    |       |
|  |  |   camera-1/                 camera-2/                    |    |       |
|  |  |   ├── 20231225T143022Z.jpg  ├── 20231225T143052Z.jpg     |    |       |
|  |  |   └── 20231225T143122Z.jpg  └── ...                      |    |       |
|  |  +----------------------------------------------------------+    |       |
|  |                           |                                      |       |
|  |                           v                                      |       |
|  |  +----------------------------------------------------------+    |       |
|  |  |              UPLOAD WORKER (round-robin)                 |    |       |
|  |  |                                                          |    |       |
|  |  |   • Validates image quality                              |    |       |
|  |  |   • Verifies timestamps (EXIF + NTP)                     |    |       |
|  |  |   • Uploads via SFTP/FTPS to upload.aviationwx.org        |    |       |
|  |  |   • Retries with backoff if network hiccups              |    |       |
|  |  +----------------------------------------------------------+    |       |
|  |                                                                  |       |
|  +------------------------------------------------------------------+       |
|                                    |                                        |
+-----------------------------------------------------------------------------+
                                     |
                                     | SFTP / FTPS (encrypted)
                                     v
+-----------------------------------------------------------------------------+
|                         upload.aviationwx.org                               |
|                                                                             |
|                    Images appear on airport dashboard                       |
|                    with accurate observation timestamps                     |
+-----------------------------------------------------------------------------+
```

**Key points:**
- The Bridge captures images locally at high quality
- Images are queued in RAM (not SD card) to avoid wear on flash storage
- Uploads happen via SFTP (preferred) or FTPS with automatic retry
- A web console lets you configure cameras and monitor status from any browser

---

## Hardware requirements

The Bridge is designed to run on minimal hardware. We recommend Raspberry Pi for most installations.

### Recommended: Raspberry Pi 5 (2GB+ RAM)

The **Raspberry Pi 5 with 2GB or more RAM** is the recommended choice for most Bridge deployments:

- Handles multiple cameras with headroom for reliability
- Faster image processing and upload handling
- Better thermal performance than earlier models
- Wide accessory ecosystem (cases, HATs, power supplies)

### Minimum: Raspberry Pi Zero 2 W

The **Raspberry Pi Zero 2 W** is the minimum viable hardware:

- Supports up to 4 cameras at 30-second intervals, 4K resolution
- Very low power consumption (good for solar/battery setups)
- Compact form factor for tight installations
- Limited headroom - works but may struggle under heavy load

### Alternative: Any Docker-capable device

IT-managed environments can run the Bridge on any Docker-capable hardware (NAS, server, VM, etc.).

### Hardware comparison

| Hardware | Best for | Notes |
|----------|----------|-------|
| **Raspberry Pi 5 (2GB+)** | Most installations (recommended) | Best balance of performance, reliability, and cost |
| **Raspberry Pi 5 (4GB/8GB)** | Heavy workloads, many cameras | Extra RAM rarely needed for Bridge alone |
| **Raspberry Pi Zero 2 W** | Budget or solar/remote sites | Works but limited headroom |
| **Raspberry Pi 4 (2GB+)** | If you already have one | Still works well, but Pi 5 preferred for new purchases |
| **Docker host / VM** | IT-managed environments | Use existing infrastructure |

### Optional accessories (recommended for reliability)

**NVMe HAT + SSD (recommended for long-term reliability)**

SD cards can wear out over time, especially in always-on deployments. An NVMe HAT with a small SSD (32GB+ is plenty) significantly improves long-term reliability:

- Faster boot and more consistent performance
- Much longer lifespan than SD cards
- Worth the ~$40-60 investment for production deployments

**PoE HAT (useful in some environments)**

A PoE (Power over Ethernet) HAT lets you power the Pi through the Ethernet cable:

- Simplifies cabling - one cable for power and network
- Cleaner installations, especially in equipment closets
- Requires a PoE switch or injector on the other end
- Good fit when the Bridge is co-located with PoE cameras

### Storage

The Bridge uses RAM for image queuing, so storage wear is minimal. However:

- **With NVMe SSD:** 32GB+ SSD is plenty; best long-term reliability
- **With SD card:** 16GB+ high-endurance card recommended; 8GB minimum

### Network

The device needs:
- Network access to your cameras (usually on the same LAN or VLAN)
- Internet access to upload to AviationWX (SFTP or FTPS outbound)

---

## Two installation paths

### Path A: Raspberry Pi ("set and forget")

Best for dedicated devices at airports with minimal IT support. One command installs everything:

```text
curl -fsSL https://get.aviationwx.org/bridge | sudo bash
```

This installs:
- Docker (if not already present)
- The AviationWX Bridge container
- Automatic security updates with rollback

After installation, open `http://<device-ip>:1229` to configure cameras.

### Path B: Docker (IT-managed)

Best for environments with existing Docker infrastructure:

```text
docker pull ghcr.io/alexwitherspoon/aviationwx-bridge:latest
```

Your IT team manages updates via their existing tooling (Portainer, Watchtower, Kubernetes, etc.).

---

## Configuration overview

The Bridge includes a **web console** for configuration:

```
+-----------------------------------------------------------------------------+
|  AVIATIONWX BRIDGE - Web Console (http://device-ip:1229)                    |
+-----------------------------------------------------------------------------+
|                                                                             |
|  CAMERAS                                                                    |
|  +-------------------------------------------------------------------+      |
|  |  Camera 1: North Runway                          [Enabled] [Edit] |      |
|  |  Type: HTTP snapshot                                              |      |
|  |  URL: http://192.168.1.100/snapshot.jpg                           |      |
|  |  Interval: 60 seconds                                             |      |
|  |  Last capture: 45 seconds ago                    Status: OK       |      |
|  +-------------------------------------------------------------------+      |
|  |  Camera 2: South Approach                        [Enabled] [Edit] |      |
|  |  Type: RTSP                                                       |      |
|  |  URL: rtsp://192.168.1.101/stream1                                |      |
|  |  Interval: 60 seconds                                             |      |
|  |  Last capture: 12 seconds ago                    Status: OK       |      |
|  +-------------------------------------------------------------------+      |
|                                                                             |
|  UPLOAD STATUS                                                              |
|  +-------------------------------------------------------------------+      |
|  |  Destination: upload.aviationwx.org                               |      |
|  |  Last successful upload: 2 minutes ago                            |      |
|  |  Queue depth: 0 images                           Status: OK       |      |
|  +-------------------------------------------------------------------+      |
|                                                                             |
+-----------------------------------------------------------------------------+
```

**What you configure:**
- Camera sources (HTTP snapshot URL, RTSP stream, or ONVIF)
- Capture interval (1 second to 30 minutes)
- Local timezone (for EXIF interpretation)
- Upload credentials (provided by AviationWX)

---

## Supported camera types

| Type | How it works | Good for |
|------|--------------|----------|
| **HTTP snapshot** | Bridge fetches a JPEG from a URL | Cameras with `/snapshot.jpg` endpoints |
| **RTSP** | Bridge extracts frames from video stream | NVRs, UniFi Protect, enterprise cameras |
| **ONVIF** | Industry-standard protocol | Many commercial IP cameras |

The Bridge uses `ffmpeg` for RTSP extraction, which gives more control over quality than grabbing frames remotely.

---

## Getting upload credentials

The AviationWX Bridge uses the same credential process as direct camera uploads:

1. **Submit your airport** following Guide 12
2. **Request "Bridge" credentials** in your email (mention you're using the AviationWX Bridge)
3. **Receive upload details** (server, port, username, password)
4. **Enter credentials** in the Bridge web console

**Protocol preference:** We recommend **SFTP** by default. Use SFTP in all cases unless FTPS is specifically required for your setup. AviationWX provides credentials for `upload.aviationwx.org` (SFTP port 2222, or FTPS port 2121 when needed).

---

## Reliability features

The Bridge is designed for unattended operation at remote locations:

| Feature | What it does |
|---------|--------------|
| **Local queuing** | If internet drops, images queue locally until connectivity returns |
| **Automatic retry** | Failed uploads retry with exponential backoff |
| **Health monitoring** | Built-in health endpoint for external monitoring |
| **NTP validation** | Verifies system time is accurate before upload |
| **Automatic updates** | Security updates apply automatically (Path A) with rollback if something breaks |
| **RAM-based queue** | Avoids SD card wear on Raspberry Pi devices |

---

## Common scenarios

### Scenario 1: UniFi Protect cameras

UniFi Protect doesn't offer scheduled FTP uploads. The Bridge connects to the RTSP stream, extracts still images, and uploads them. **The Bridge must be on the same network as the NVR.**

**Get and convert the RTSP URL:**

1. **Get the URL from UniFi Protect:** Home → Protect → UniFi Devices → (select camera) → Settings → Advanced → toggle the resolution you want → copy the URL (e.g., `rtsps://192.168.1.1:7441/QxJCVMefFHfBnqrp?enableSrtp`)

2. **Convert it** — UniFi's URL needs a few adjustments. Apply these three changes:
   - Change `rtsps://` to `rtsp://` (remove the second `s`)
   - Change port `7441` to `7447`
   - Remove `?enableSrtp` from the end

3. **Use in the Bridge:** Enter the converted URL in the Bridge web console. Leave the username and password fields empty—UniFi Local RTSP does not use authentication.

**Example:**
```
# What UniFi gives you:
rtsps://192.168.1.1:7441/QxJCVMefFHfBnqrp?enableSrtp

# What to enter in the Bridge:
rtsp://192.168.1.1:7447/QxJCVMefFHfBnqrp
```

### Scenario 2: NVR-only installations

Some airports have an NVR that records continuously but can't push stills. The Bridge can:
- pull RTSP from the NVR's camera feeds
- handle multiple cameras through a single Bridge device

### Scenario 3: Mixed camera types

You might have:
- one camera with HTTP snapshot (easy)
- one camera with only RTSP (needs extraction)
- one older ONVIF camera

The Bridge handles all of them with a unified configuration.

### Scenario 4: Remote/solar installations

At remote sites where reliability matters most:
- the Bridge queues images during internet outages
- uploads resume automatically when connectivity returns
- no lost observations due to brief network hiccups

---

## Troubleshooting

### "Camera captures are failing"
- **Test RTSP separately:** To confirm the feed is reachable, try it from a computer on the same network as the camera or NVR. Open **VLC** (Media → Open Network Stream) and paste your RTSP URL. If VLC can play the stream, the Bridge should be able to reach it too. If VLC fails, the issue is likely with the network or camera configuration—troubleshoot that first.
- Verify the camera URL is reachable from the Bridge device
- For RTSP, confirm the stream path (and that username/password are empty for UniFi Local RTSP)
- Check the Bridge logs via the web console

### "Uploads aren't happening"
- **Test SFTP/FTPS separately:** Use **FileZilla** (or similar) to connect to `upload.aviationwx.org` with your credentials. If FileZilla can connect and upload a test file, the Bridge should be able to as well. If FileZilla fails, the issue is likely with credentials or network connectivity—check those first.
- Verify internet connectivity from the Bridge device
- Check upload credentials in the web console
- Look for queue buildup (images waiting to upload)

### "Images look pixelated"
- For RTSP sources, try increasing capture quality settings
- Ensure the camera's video stream is configured for high quality
- Check network bandwidth between camera and Bridge

### "Bridge device isn't responding"
- Verify power to the device
- Check that the device is on the network
- For Raspberry Pi: try power cycling (unplug, wait 10 seconds, plug in)

For detailed troubleshooting, see the [Bridge documentation](https://github.com/alexwitherspoon/aviationwx-bridge).

---

## Decision checklist

Before setting up a Bridge, confirm:

- ☐ **Why you need it:** Camera(s) don't support direct FTP uploads, or you want higher reliability
- ☐ **Hardware choice:** Raspberry Pi 5 2GB+ (recommended), Pi Zero 2 W (minimum), or Docker host
- ☐ **Storage choice:** NVMe HAT + SSD (recommended) or high-endurance SD card (minimum)
- ☐ **Power/network:** Standard power supply, or PoE HAT if simplifying cabling
- ☐ **Camera compatibility:** You know the camera URLs/protocols (HTTP, RTSP, ONVIF)
- ☐ **Network access:** Bridge device can reach cameras on local network
- ☐ **Internet access:** Bridge device can reach `upload.aviationwx.org`
- ☐ **Credentials requested:** You've contacted AviationWX for upload credentials

---

## Resources

| Resource | Link |
|----------|------|
| **Bridge GitHub repository** | https://github.com/alexwitherspoon/aviationwx-bridge |
| **Installation documentation** | See README in GitHub repository |
| **Configuration reference** | See `/docs` folder in GitHub repository |
| **Request credentials** | contact@aviationwx.org |

---

## Next

- If you haven't submitted your airport yet, see **Guide 12 - Submit a New Airport**
- For camera framing and siting, see **Guide 02 - Location & Siting**
- For direct camera FTP setup (when cameras support it), see **Guide 08 - Camera Configuration**


