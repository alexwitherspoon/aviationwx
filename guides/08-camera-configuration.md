# 08 - Camera Configuration

## Goal
Configure cameras so they reliably provide clear still images to AviationWX with minimal ongoing maintenance by volunteers, predictable bandwidth use, and easy recovery after power or internet outages. This guide covers scheduled JPEG uploads via FTP, RTSP video streams, snapshot URLs, and the optional AviationWX Bridge for cameras without native upload capability.

This guide focuses on camera integration methods that work well for AviationWX:
1) **Scheduled JPEG uploads (FTPS/FTP/SFTP)** ✅ **preferred**
2) **RTSP** - works well when uploads aren't available
3) **Snapshot URL** - useful for some devices, varies widely by vendor
4) **AviationWX Bridge** - for cameras without native upload capability

> **Our preference: Push-style uploads (FTPS/SFTP/FTP)**
> Scheduled JPEG uploads typically produce **better image quality** than RTSP extraction. When the camera uploads a dedicated snapshot, it uses still-image encoding rather than video compression - resulting in clearer images with less pixelation. If your camera supports scheduled FTP uploads, we recommend using that method. If they don't - ther AviationWX Bridge device can upload photos from many types of systems.

For camera siting and privacy expectations, see:
- **Guide 02 - Location & Siting** (framing guidance)
- **Guide 01 - Permission Packet** (privacy rule of thumb)

---

## Choose your integration path

### Option A - Scheduled JPEG uploads (FTPS/FTP/SFTP) ✅ recommended
The camera pushes a JPEG image on a schedule (e.g., every 1 minute).

**Why this is great**
- simple "set it and forget it"
- predictable bandwidth
- easy to reason about freshness (you know when an upload should arrive)
- often **higher still-image quality** than sampling an RTSP stream: the camera can upload a full-resolution JPEG and take the time it needs, instead of forcing a video-style capture - typically meaning **less pixelation and more detail** with **less overall bandwidth**

### Option B - RTSP (extract still images)
The camera provides an RTSP stream, and AviationWX extracts still images.

**Why this works**

- compatible with most IP cameras and NVR systems
- no port forwarding to AviationWX required (we connect to you)
- good option when scheduled uploads aren't available

**Trade-offs vs. push uploads:**
- RTSP is optimized for video, so extracted frames may have more compression artifacts
- image quality can vary with network conditions
- push uploads give the camera time to encode a high-quality still image

**When RTSP is the right choice:**
- you have a high quality high bandwitdh rtsp system in place already
- camera doesn't support scheduled FTP/FTPS/SFTP uploads

### Option C - Snapshot URL
A URL returns a current still image.

**Why this can work**
- very simple when available
- but vendor support varies a lot (and some require cloud accounts or are unreliable)
- sometimes you already have done the work to make a snapshot from the camera available

### Option D - AviationWX Bridge (for cameras without upload capability)
A small local device captures images from your cameras and uploads them to AviationWX.

**Why this is useful**
- works with cameras that **don't support scheduled FTP uploads** (e.g., UniFi Protect, NVR systems)
- provides **higher reliability** with local queuing and automatic retry
- handles **RTSP-only cameras** with better quality than remote frame extraction
- unified solution for **mixed camera types** at a single airport

**When to consider the Bridge**
- your camera system doesn't offer FTP/FTPS/SFTP uploads
- you want extra reliability for remote or solar-powered installations
- you have multiple camera types that need unified handling

See **Guide 10 - AviationWX Bridge** for details.

---

## Known-good defaults

### FTPS / FTP / SFTP Connection Settings

AviationWX provides dedicated upload credentials for each camera. After you submit your airport (see **Guide 12**), you'll receive:
- A **username** unique to your camera
- A **password** for that account

**Connection details for all cameras:**

| Setting | Value |
|---------|-------|
| **Server** | `upload.aviationwx.org` |
| **FTPS Port** | `2121` (recommended) |
| **FTP Port** | `2121` |
| **SFTP Port** | `2222` |
| **Directory** | `/` (root  -  no subfolder needed) |
| **Filename** | Use timestamp in filename (e.g., `20250106_143022.jpg`) |
| **Overwrite** | No  -  each upload should be a new file |
| **File format** | JPEG preferred (`.jpg` or `.jpeg`) |
| **Max file size** | 100 MB per image |

**Protocol recommendation:**
- **FTPS** (FTP over TLS)  -  recommended for most cameras; encrypted and widely supported
- **SFTP**  -  good alternative if your camera supports it; different protocol, also encrypted
- **FTP**  -  works but unencrypted; use only if FTPS/SFTP aren't available

> **Note:** FTPS and FTP use the same port (2121). FTPS adds TLS encryption. Most modern cameras support FTPS - look for "FTP over TLS", "Explicit TLS", or "FTPS" in your camera's settings.

**FTP server mode / transfer mode:**

If your camera asks about FTP server mode or transfer mode, use these settings:

| Setting | Recommended Value | Notes |
|---------|-------------------|-------|
| **FTP Mode** | **Auto** or **Passive** | Recommended for most connections |
| **Transfer Mode** | **Passive** (PASV) | More reliable through firewalls/NAT |
| **Port Mode** | Avoid if possible | Active/PORT mode often fails through routers |

> **Why passive mode?** Passive mode (PASV) is more reliable when your camera is behind a router or firewall. In passive mode, the camera initiates both the command and data connections, which works better with NAT and most network configurations. Active/PORT mode requires the server to connect back to your camera, which is often blocked by routers and firewalls.

### Recommended default settings for any camera

| Setting | Recommendation | Notes |
|---------|----------------|-------|
| **Upload interval** | **1 minute** | Good balance of freshness and bandwidth |
| **Image quality** | High (80%+) | Avoid maximum compression; pilots need detail |
| **Resolution** | 1080p or 720p | Higher resolution = upload less frequently |
| **File format** | JPEG | Required; PNG also accepted but larger |
| **Timezone** | Local airport timezone | Critical for accurate observation times |
| **Time sync (NTP)** | Enabled, sync every 60-120 minutes | Prevents time drift in outdoor conditions (see below) |
| **Time overlay** | Enabled | Burn timestamp into image for safety |
| **Name overlay** | Descriptive name | E.g., "South Runway", "North Approach" |
| **Night mode** | Camera default | Prefer usable image over perfect; avoid heavy blur |
| **After power loss** | Auto-resume uploads | Critical for reliability |

### Time and overlay settings (important for safety)

Accurate timestamps and clear identification are critical for aviation weather. Pilots need to know **when** an image was captured and **what view** they're looking at.

**Timezone configuration:**
- Set the camera to your **local airport timezone** (not UTC)
- This ensures the overlay timestamp matches what pilots expect
- Example: If your airport is in Central Time, set the camera to Central Time

**On-screen time display (OSD):**
- Enable the camera's **timestamp overlay** feature
- This burns the date and time directly into the image
- Even if metadata is lost, pilots can see when the image was captured
- Recommended format: `YYYY-MM-DD HH:MM:SS` or similar clear format

**Camera name overlay:**
- Add a **short, descriptive name** to identify the view
- Keep it simple and aviation-relevant
- Good examples: `South Runway`, `North Approach`, `Ramp View`, `Windsock Cam`
- Avoid: Generic names like `Camera 1` or overly long descriptions

**Overlay placement:**
- Position overlays in a **corner** (bottom-left or top-left is common)
- Avoid placing text over the **horizon or sky** - that's what pilots are looking at
- Use a contrasting background (semi-transparent black) for readability

> **Why this matters:** In aviation, knowing the observation time is safety-critical. A beautiful clear-sky image from 2 hours ago doesn't help a pilot making a go/no-go decision right now. The on-screen timestamp provides an instant visual confirmation of freshness.

**NTP (Network Time Protocol) configuration:**

Accurate time synchronization is critical for aviation weather. Cameras use a small quartz crystal oscillator to keep time between NTP syncs, and these crystals drift - especially in outdoor temperature extremes.

**Why NTP sync frequency matters:**
- Camera quartz crystals drift faster in temperature extremes (outdoor installations)
- Default NTP sync (once per day / 1440 minutes) allows significant drift accumulation
- A camera drifting +/- 5-10 minutes can show incorrect observation times
- In aviation, even a few minutes matters for rapidly changing conditions
- NTP sync requests are lightweight - syncing hourly has negligible network impact

**Recommended NTP settings:**

| Setting | Recommendation | Notes |
|---------|----------------|-------|
| **NTP enabled** | Yes (required) | Disable any manual time setting |
| **NTP server** | Camera default (pool.ntp.org) | Or use your router/local NTP server |
| **Sync interval** | **60-120 minutes** | Much better than default 1440 minutes (24 hours) |
| **Time zone** | Local airport time zone | Critical - set correctly |
| **DST (Daylight Saving Time)** | Auto (if available) | Ensures correct time year-round |

> **Safety-critical recommendation:** Configure your camera to sync time every **60-120 minutes** instead of the typical default of once per day (1440 minutes). This prevents time drift from accumulating and ensures accurate observation timestamps. NTP sync is a tiny, infrequent network request and won't meaningfully impact bandwidth or power consumption.

**Reolink NTP configuration:**
1. Navigate to **Device Settings** → **System** → **Time**
2. Enable **NTP**
3. Set **NTP Server**: Use default or enter your preferred NTP server
4. Set **Time Zone**: Select your local airport time zone
5. **Sync Interval**: Look for "NTP Interval" or "Sync Frequency"
   - Change from default 1440 minutes → **60 or 120 minutes**
   - [Reolink NTP configuration guide](https://support.reolink.com/hc/en-us/articles/360018700554-How-to-Use-NTP-to-Synchronize-Time-via-Web-Browsers/)

> **Note:** Some camera models may not expose the NTP sync interval in the UI. If you can't find this setting, verify via the web interface or contact the manufacturer. Most modern IP cameras support configurable NTP intervals.

**Testing time accuracy:**
1. Set up NTP with recommended interval (60-120 minutes)
2. Let camera run for 24-48 hours outdoors
3. Compare camera timestamp overlay against accurate time source
4. Drift should be minimal (< 30 seconds) with proper NTP sync

**If time drift persists:**
- Verify NTP server is reachable (test with `ping` or check camera logs)
- Confirm time zone is set correctly (not UTC unless your airport is in UTC)
- Check for network issues that might prevent NTP sync
- Consider upgrading camera firmware if available
- As a last resort, use a more frequent sync interval (30-60 minutes)

**Upload interval guidance:**

| Interval | When to use |
|----------|-------------|
| **1 minute** | Standard recommendation for most airports |
| **30 seconds** | Fast-changing weather, active fields |
| **2-5 minutes** | Limited bandwidth (LTE, satellite) |
| **5-15 minutes** | Very constrained connections, solar/battery power |
| **15-30 minutes** | Minimum acceptable; only for severely limited setups |

> **Power-saving tip:** VFR-only airports may reduce upload frequency at night (or pause entirely) to conserve data and power. If your camera or scheduling system supports time-based rules, consider uploading every 5-15 minutes after sunset and resuming 1-minute uploads at sunrise.

**Resolution vs. frequency tradeoff:**

AviationWX processes images automatically - we resize and optimize for web delivery regardless of what you upload. However, we recommend:
- **1080p at 1 minute**  -  best balance for most connections
- **720p at 1 minute**  -  good for slower connections
- **4K at 5 minutes**  -  if you want maximum detail and have bandwidth

Avoid: 4K every 10 seconds (excessive bandwidth, often unnecessary).

We will reject images that are over-pixelated, heavily compressed, or unreadable. When in doubt, upload less frequently at higher quality.

### RTSP Stream Settings

> **Prefer push uploads when available:** If your camera supports FTPS/SFTP/FTP scheduled uploads, that typically produces better image quality than RTSP extraction.

For cameras that don't support scheduled uploads, AviationWX can extract still images from RTSP video streams. You provide the stream URL, and AviationWX captures frames at regular intervals.

**How it works:**
- Your camera continuously broadcasts an RTSP video stream on your local network
- You configure your router/firewall to allow AviationWX to reach the stream (port forwarding)
- AviationWX connects periodically and extracts a single JPEG frame
- The extraction interval is configured on the AviationWX side (not the camera)

**What to provide when submitting your airport:**

| Information | Example | Notes |
|-------------|---------|-------|
| **RTSP URL** | `rtsp://user:pass@your-ip:554/stream` | Full URL with credentials |
| **Stream type** | Main stream or Sub stream | Main = higher quality, Sub = lower bandwidth |
| **Port** | 554 (default) | May vary by camera |
| **Transport** | TCP (recommended) | UDP available if needed |
| **Desired interval** | 60 seconds | How often to capture a frame |

**RTSP URL format:**
```
rtsp://username:password@camera-ip:port/stream-path
```

**Common stream paths by vendor:**

| Vendor | Main Stream Path | Sub Stream Path |
|--------|------------------|-----------------|
| **Reolink** | `/h264Preview_01_main` | `/h264Preview_01_sub` |
| **Hikvision** | `/Streaming/Channels/101` | `/Streaming/Channels/102` |
| **Dahua** | `/cam/realmonitor?channel=1&subtype=0` | `/cam/realmonitor?channel=1&subtype=1` |
| **Axis** | `/axis-media/media.amp` | varies |
| **Amcrest** | `/cam/realmonitor?channel=1&subtype=0` | `/cam/realmonitor?channel=1&subtype=1` |
| **Generic ONVIF** | varies | varies |

> **Security note:** Create a **dedicated read-only user** for AviationWX rather than sharing your admin credentials. Most cameras support creating viewer accounts with limited permissions.

**Main stream vs. Sub stream:**

| Stream | Resolution | Bandwidth | When to use |
|--------|------------|-----------|-------------|
| **Main stream** | Full (e.g., 4K, 1080p) | Higher | Good internet, maximum detail needed |
| **Sub stream** | Reduced (e.g., 720p, 480p) | Lower | Limited bandwidth, LTE connections |

**Recommended defaults:**
- Use **main stream at 1080p** for most connections
- Use **sub stream** if bandwidth is severely limited
- **1 minute interval** is standard; adjust based on needs
- **TCP transport** is more reliable than UDP for most networks

**Network requirements:**

For AviationWX to reach your RTSP stream, you'll need:
1. **Static IP or Dynamic DNS**  -  Your camera needs a reachable address
2. **Port forwarding**  -  Forward the RTSP port (usually 554) to your camera's internal IP
3. **Firewall rules**  -  Allow inbound connections on the RTSP port

> **Alternative:** If you don't want to expose your camera to the internet, use the **AviationWX Bridge** (Guide 10). The Bridge runs on your local network and handles the upload securely.

**RTSPS (Secure RTSP):**

Some cameras support encrypted RTSP streams using TLS:
- URL starts with `rtsps://` instead of `rtsp://`
- Typically uses port 322 instead of 554
- Provides encrypted video transport
- AviationWX supports RTSPS - just use the `rtsps://` URL

---

## Reolink cameras (recommended example)

Reolink cameras are well-tested with AviationWX and offer reliable scheduled FTP uploads. The settings below apply to most Reolink models, though exact menu labels may vary by model and firmware version.

**Tested models:**
- Reolink RLC-810WA
- Reolink RLC-510A
- Reolink RLC-810A
- Other Reolink models with FTP capability

### Reolink setup checklist

1. ☐ Update camera firmware (if appropriate)
2. ☐ Set a strong admin password
3. ☐ Configure FTP upload settings (see below)
4. ☐ Set the upload schedule
5. ☐ Test upload reliability for 24-72 hours

### Step-by-step: Configure FTP/FTPS on Reolink

**Access the FTP settings:**

1. Open Reolink Client on your PC (or use the web interface)
2. Add your camera and log in
3. Navigate to: **Device Settings** → **Network** → **Advanced** → **FTP**

**Enter these settings:**

| Reolink Setting | Value to Enter |
|-----------------|----------------|
| **FTP Server** | `upload.aviationwx.org` |
| **Port** | `2121` |
| **Username** | *(provided by AviationWX for your camera)* |
| **Password** | *(provided by AviationWX for your camera)* |
| **Directory** | `/` or leave blank |
| **Anonymous** | Disabled / Off |
| **Encryption** | FTPS / TLS / SSL (if available) |
| **Server Mode** | **Auto** or **Passive** (if available) |

> **FTP Mode Note:** If your Reolink camera shows options for "FTP Mode", "Server Mode", or "Transfer Mode", select **Auto** or **Passive** mode. Avoid "PORT" or "Active" mode as it often doesn't work reliably through routers. Most modern Reolink firmware defaults to Auto, which intelligently selects the best mode.

**Configure file upload type:**

| Reolink Setting | Recommendation |
|-----------------|----------------|
| **File Type** | Images only (not video) |
| **Interval** | 60 seconds (1 minute) |

> **Note:** Some Reolink models show "FTP Postpone" for motion-triggered recording. For AviationWX, you want **scheduled/interval uploads**, not motion-triggered.

**Enable the FTP schedule:**

1. Click **FTP Schedule** or **Schedule**
2. Enable FTP uploads
3. Set to upload continuously (24/7) or customize for day/night if power-saving

**Test your configuration:**

1. Click **FTP Test** or **Test** button
2. Confirm a test image uploads successfully
3. Check AviationWX (after a few minutes) to verify images are appearing

### Common Reolink settings to check

| Setting | Where to find it | Recommendation |
|---------|------------------|----------------|
| **Image quality** | Display → Image | High or above |
| **Resolution** | Display → Stream | Main stream: 1080p or higher |
| **Timezone** | System → Time | Set to local airport timezone |
| **Time sync (NTP)** | System → Time | Enable NTP, set sync interval to 60-120 minutes |
| **Time overlay** | Display → OSD | Enable; position in corner |
| **Camera name** | Display → OSD → Channel Name | E.g., "South Runway" |
| **Auto-reboot** | System → Maintenance | Weekly reboot can help reliability |

**Reolink OSD (On-Screen Display) setup:**
1. Navigate to **Display** → **OSD** (or **On-Screen Display**)
2. Enable **Time** display
3. Set **Channel Name** to a descriptive name (e.g., "North Approach")
4. Position both in a corner that doesn't obscure the sky/horizon
5. Ensure font is readable but not too large

### Reolink RTSP (alternative to FTP)

Most Reolink cameras support both FTP uploads and RTSP. We recommend trying FTP first for better image quality, but RTSP works well if FTP isn't an option for your setup.

**Enable RTSP on your Reolink:**

1. Open Reolink Client or web interface
2. Navigate to: **Device Settings** → **Network** → **Advanced** → **Port Settings**
3. Ensure RTSP is enabled (usually on by default)
4. Note the RTSP port (default: 554)

**Reolink RTSP URL formats:**

| Stream | URL Pattern |
|--------|-------------|
| **Main stream (high quality)** | `rtsp://username:password@camera-ip:554/h264Preview_01_main` |
| **Sub stream (lower bandwidth)** | `rtsp://username:password@camera-ip:554/h264Preview_01_sub` |

**Example:**
```
rtsp://admin:YourPassword@192.168.1.100:554/h264Preview_01_main
```

**What to send to AviationWX:**
- The full RTSP URL (with credentials)
- Your public IP address or Dynamic DNS hostname
- Which stream (main or sub) you want us to use
- Confirm port 554 is forwarded to the camera

**Network setup for RTSP:**
1. Assign a static internal IP to your camera (e.g., 192.168.1.100)
2. Forward port 554 (or your RTSP port) on your router to the camera's IP
3. Test by accessing the stream from outside your network

> **Prefer not to open ports?** Use the **AviationWX Bridge** instead (Guide 10). The Bridge captures frames locally and uploads them - no port forwarding needed.

---

## UniFi Protect cameras

UniFi Protect can be a great fit if the airport/FBO already has a UniFi ecosystem and someone locally can maintain it.

**Key limitation:** UniFi Protect does **not** support scheduled FTP uploads. Use one of these alternatives:

**Option 1: AviationWX Bridge (recommended)**
- Install the Bridge on a local device (Raspberry Pi or similar)
- The Bridge captures snapshots via RTSP and uploads to AviationWX
- See **Guide 10 - AviationWX Bridge**

**Option 2: RTSP extraction**
- UniFi cameras support RTSP streams
- AviationWX can extract still images from the stream
- Provide the RTSP URL and credentials when submitting your airport

### UniFi Protect RTSP URL formats

UniFi Protect provides two types of RTSP streams with different ports and security levels:

| Type | URL Pattern | Port | Security |
|------|-------------|------|----------|
| **Local RTSP** | `rtsp://nvr-ip:7447/STREAM_ID` | 7447 | Unencrypted |
| **Shared RTSPS** | `rtsps://nvr-ip:7441/STREAM_ID?enableSrtp` | 7441 | Encrypted (SRTP) |

**Examples:**
```
# Local RTSP (unencrypted) - recommended for AviationWX Bridge
rtsp://192.168.1.1:7447/FKEFbCxO0CiAF3TH

# Shared RTSPS (encrypted) - UniFi's default shared URL
rtsps://192.168.1.1:7441/FKEFbCxO0CiAF3TH?enableSrtp
```

**Which URL to use:**

| Use Case | Recommended URL Type |
|----------|---------------------|
| **AviationWX Bridge on local network** | Local RTSP (port 7447) - simpler, no encryption overhead |
| **Remote RTSP extraction** | Shared RTSPS (port 7441) - encrypted for security over internet |
| **Port forwarding to AviationWX** | Shared RTSPS (port 7441) - always use encryption when exposing to internet |

**How to find your RTSP URL in UniFi Protect:**

1. Open the UniFi Protect web interface or app
2. Navigate to **Cameras** and select the camera
3. Go to **Settings** → **Advanced**
4. Look for **RTSP** section
5. Enable RTSP if not already enabled
6. Copy the stream URL - the `STREAM_ID` is the unique identifier for that camera

> **Important:** The `STREAM_ID` (e.g., `FKEFbCxO0CiAF3TH`) is unique to each camera and is generated by UniFi Protect. You cannot make up this ID - you must copy it from the UniFi interface.

**Local RTSP vs Shared RTSPS:**

- **Local RTSP (port 7447):** No authentication required, unencrypted. Best for the AviationWX Bridge running on the same local network as the NVR. Simpler setup, lower latency.

- **Shared RTSPS (port 7441):** Uses SRTP encryption, may require the `?enableSrtp` parameter. Use this when exposing the stream outside your local network or when security is a priority.

**Key point:** Choose an approach that does not require constant manual steps or "someone has to log in every week."

---

## Generic camera checklist (any vendor)

Use this checklist when evaluating any camera for AviationWX.

### Capability checklist
- ☐ Supports at least one of: **FTPS/FTP/SFTP**, **RTSP**, or **snapshot URL**
- ☐ Can upload JPEG images on a schedule (not just motion-triggered)
- ☐ Can operate without a paid cloud plan for the features you need
- ☐ Can be configured to auto-recover after power outages
- ☐ Outdoor-rated or can be installed in a proper weatherproof housing

### Reliability checklist
- ☐ Still image is readable in common conditions (rain/fog/overcast)
- ☐ Image exposure is not constantly blown out at sunrise/sunset
- ☐ Uploads remain stable over at least **24-72 hours**
- ☐ Mount is stable (no vibration blur)
- ☐ Camera resumes uploads after power/network outage

### Security + hygiene checklist
- ☐ Use a strong admin password
- ☐ Don't expose the camera admin interface to the public internet
- ☐ Use encrypted transfers (**FTPS or SFTP** preferred over plain FTP)
- ☐ Document credentials and ownership so the airport isn't stuck if a volunteer moves on

### FTP/FTPS/SFTP configuration summary

| Setting | Value |
|---------|-------|
| Server | `upload.aviationwx.org` |
| FTPS/FTP Port | `2121` |
| SFTP Port | `2222` |
| Directory | `/` (root) |
| Filename | Timestamp preferred (e.g., `YYYYMMDD_HHMMSS.jpg`) |
| Format | JPEG (`.jpg` / `.jpeg`) |
| Interval | 60 seconds (adjust based on bandwidth) |
| Encryption | FTPS recommended |

---

## Troubleshooting

### "FTP test fails" or "Connection refused"
- Verify the server address is exactly `upload.aviationwx.org`
- Confirm port is `2121` for FTPS/FTP or `2222` for SFTP
- Check username and password are entered correctly (case-sensitive)
- Ensure your network allows outbound connections on the required port
- Try disabling any firewall temporarily to test
- **If using FTP/FTPS:** Change FTP mode to **Passive** or **Auto** (not PORT/Active mode)
- Some cameras have "Extended Passive Mode" - try enabling this if passive mode alone doesn't work

### "It worked for a week then stopped"
- Confirm power stability (consider UPS if needed)
- Confirm the router/LTE modem recovered after an outage
- Confirm the camera still has network connectivity (DHCP lease may have changed)
- Check if the camera rebooted and lost FTP settings (some cameras need "save" explicitly)

### "Uploads are huge / LTE costs are too high"
- Increase the interval (e.g., from 1 minute → 5 minutes)
- Reduce resolution (e.g., 4K → 1080p)
- Reduce JPEG quality slightly (but stay above 70%)
- Ensure you're uploading images, not video clips
- Consider night-time upload reduction for VFR-only fields

### "Night images are unusable"
- Check camera night/IR settings (avoid settings that create heavy motion blur)
- Consider a slightly different angle to reduce glare
- Accept that some night images will be low-quality; that's okay
- Consider a second camera optimized for sky/horizon if needed

### "Images are rejected or not appearing"
- Verify file format is JPEG (not PNG, BMP, or raw)
- Check file size is under 100 MB
- Ensure images aren't over-compressed or pixelated
- Confirm the camera clock is reasonably accurate (within a few minutes)

### "The camera view is good, but not useful for pilots"
- Revisit **Guide 02** framing guidance
- Consider adding a second "wide sky / horizon landmarks" camera

### RTSP: "Stream connection failed" or "Timeout"
- Verify port forwarding is configured correctly on your router
- Confirm the camera's internal IP hasn't changed (use static IP or DHCP reservation)
- Check that your ISP allows inbound connections on the RTSP port
- Some ISPs block port 554 - try a non-standard port (e.g., 8554) if needed
- Verify credentials are correct (RTSP URLs are case-sensitive)

### RTSP: "Images are blurry or pixelated"
- Switch from sub stream to main stream for higher resolution
- Check camera's video encoding settings (H.264 is well-supported)
- Increase bitrate if your camera allows it
- Ensure camera lens is clean and properly focused

### RTSP: "Stream works locally but not remotely"
- Confirm Dynamic DNS is updating your public IP correctly
- Verify port forwarding rule points to the camera's current internal IP
- Check if your firewall is blocking the RTSP port
- Some cameras require specific "external" or "WAN" stream settings

---

## Decision (write it down)

After configuring your camera, document these details:

**For all integration methods:**

| Item | Your Answer |
|------|-------------|
| Camera model(s) | |
| Integration method | FTPS / SFTP / FTP / RTSP / Bridge |
| Capture/upload interval | |
| Resolution | |
| Where credentials are stored | |
| Who can reboot / access the camera | |
| How you verify it's working | |

**For FTP/FTPS/SFTP (push uploads):**

| Item | Your Answer |
|------|-------------|
| Server | `upload.aviationwx.org` |
| Port | 2121 (FTPS/FTP) / 2222 (SFTP) |
| Protocol | FTPS / FTP / SFTP |
| Username | *(from AviationWX)* |

**For RTSP (stream extraction):**

| Item | Your Answer |
|------|-------------|
| RTSP URL | |
| Stream type | Main / Sub |
| Public IP or DDNS hostname | |
| RTSP port (forwarded) | |
| Camera username (view-only) | |

---

## Next steps

1. **Submit your airport**  -  See **Guide 12** to request upload credentials
2. **Configure your camera**  -  Use the settings in this guide
3. **Monitor for 24-72 hours**  -  Verify reliable uploads before considering it "done"
4. **Weather station setup**  -  See **Guide 09** for weather station configuration
