# 09 — Camera Configuration (Uploads + “Known-Good Defaults”)

## Goal
Configure cameras so they reliably provide **clear still images** to AviationWX with:
- minimal ongoing maintenance,
- predictable bandwidth use,
- and easy recovery after power/internet hiccups.

This guide focuses on camera integration methods that work well for AviationWX:
1) **Scheduled JPEG uploads (FTP/FTPs/SFTP)** — recommended when the camera supports it
2) **RTSP** — a solid fallback when uploads aren’t supported
3) **Snapshot URL** — useful for some devices, varies widely by vendor

For camera siting and privacy expectations, see:
- **Guide 02 — Location & Siting** (framing guidance)
- **Guide 01 — Permission Packet** (privacy rule of thumb)

---

## Choose your integration path

### Option A — Scheduled JPEG uploads (FTP/FTPs/SFTP) ✅ recommended
The camera pushes a JPEG image on a schedule (e.g., every 1–15 minutes).

**Why this is great**
- simple “set it and forget it”
- predictable bandwidth
- easy to reason about freshness (you know when an upload should arrive)
- often **higher still-image quality** than sampling an RTSP stream: the camera can upload a full-resolution JPEG and take the time it needs, instead of forcing a video-style capture—typically meaning **less pixelation and more detail** with **less overall bandwidth**

### Option B — RTSP (extract still images)
The camera provides an RTSP stream, and a system extracts still images.

**Why this is useful**
- works with many cameras that don’t have built-in upload scheduling
- can support higher-quality still images depending on the camera and extraction method

### Option C — Snapshot URL
A URL returns a current still image.

**Why this can work**
- very simple when available
- but vendor support varies a lot (and some require cloud accounts or are unreliable)

---

## “Known-good defaults” (start here)
These defaults are intentionally conservative and reliable.

- **Update interval:** start at **5 minutes** (then adjust to 1–15 minutes)
- **Image quality:** “High” JPEG, but avoid huge files if the connection is LTE
- **Frame rate:** irrelevant if you’re uploading stills (don’t chase video settings)
- **Time sync:** ensure the camera time/date is reasonable
- **Night mode:** prefer a usable image over a perfect one; avoid settings that create heavy blur
- **Stability:** prioritize mounts and power stability; a shaky camera is a useless camera

---

## Reolink (tested models) — recommended path
Tested models:
- **Reolink RLC-810WA**
- **Reolink RLC-510A**

### Reolink setup checklist (high-level)
1) ☐ Update camera firmware (if appropriate)
2) ☐ Set a strong admin password
3) ☐ Choose one integration method:
   - ☐ FTP/FTPs upload on a schedule (preferred)
   - ☐ RTSP stream (fallback)
4) ☐ Set the update interval (start at 5 minutes)
5) ☐ Validate upload reliability for 24–72 hours

### Reolink: Scheduled upload (FTP/FTPs) — the practical recipe
Most of the time you’ll configure:
- a destination server (FTP/FTPs/SFTP)
- a schedule (every X minutes)
- a JPEG snapshot/image push

**What to validate**
- uploads arrive on schedule
- file size is reasonable for your internet plan
- the camera keeps uploading after a power outage / reboot

> Note: exact menu labels vary by model and firmware. The goal isn’t a perfect menu map — it’s the reliability checks above.

### Reolink: RTSP fallback
If scheduled uploads are unavailable or unreliable:
- enable RTSP on the camera
- ensure credentials are set
- validate that your pipeline can consistently extract a still image every 1–15 minutes

---

## UniFi Protect cameras (when an airport already runs UniFi)
UniFi can be a great fit if the airport/FBO already has a UniFi ecosystem and someone locally can maintain it.

Practical approaches:
- use a supported method to provide **still images** to AviationWX (varies by setup)
- if RTSP is available for the camera stream, that’s often the cleanest integration option

**Key point:** choose an approach that does not require constant manual steps or “someone has to log in every week.”

---

## Generic camera checklist (works for any vendor)
Use this checklist when evaluating a camera for AviationWX.

### Capability checklist
- ☐ Supports at least one of: **FTP/FTPs/SFTP**, **RTSP**, or **snapshot URL**
- ☐ Can operate without a paid cloud plan for the features you need
- ☐ Can be configured to auto-recover after power outages
- ☐ Outdoor-rated or can be installed in a proper weatherproof housing

### Reliability checklist
- ☐ Still image is readable in common conditions (rain/fog/overcast)
- ☐ Image exposure is not constantly blown out at sunrise/sunset
- ☐ Uploads/feeds remain stable over at least **24–72 hours**
- ☐ Mount is stable (no vibration blur)

### Security + hygiene checklist (plain language)
- ☐ Use a strong admin password
- ☐ Don’t expose the camera admin interface to the public internet
- ☐ Prefer encrypted transfers when possible (**FTPs/SFTP**)
- ☐ Document credentials and ownership so the airport isn’t stuck if a volunteer moves on

---

## Troubleshooting (common issues)
### “It worked for a week then stopped”
- confirm power stability (and consider UPS if needed)
- confirm the router/LTE modem recovered after an outage
- confirm the camera still has network connectivity (DHCP changes are common)

### “Uploads are huge / LTE costs are too high”
- increase the interval (e.g., from 1 minute → 5 minutes)
- reduce JPEG quality slightly
- ensure you’re not accidentally sending video

### “Night images are unusable”
- check camera night settings (avoid settings that create heavy motion blur)
- consider a slightly different angle to reduce glare
- consider a second camera optimized for sky/horizon if that helps at your field

### “The camera view is good, but not useful for pilots”
- revisit Guide 02 framing guidance
- consider adding a second “wide sky / horizon landmarks” camera

---

## Decision (write it down)
- Camera model(s):
- Integration method: FTP/FTPs/SFTP / RTSP / Snapshot URL
- Update interval:
- Where credentials are stored:
- Who can reboot / access the camera:
- How you verify it’s working (simple check):

## Next
If you want, we can add **Guide 10 — Weather Station Configuration** (Tempest / Davis / Ambient defaults, siting checks, and sanity tests).
