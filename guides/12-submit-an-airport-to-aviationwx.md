# 12 - Submit a New Airport to AviationWX (What to send + what happens next)

## Goal
Make it easy for a normal, non-technical airport volunteer to send AviationWX the information needed to add the airport dashboard, connect weather data and cameras, and validate everything meets the project goals. This guide provides a simple checklist of required information including airport identifiers, weather station API credentials, camera connection details, and local contact information.

**Send everything to:** `contact@aviationwx.org`

---

## Why Join AviationWX?

### Benefits for Your Airport

- **Free Dashboard**: Professional weather and webcam display at no cost
- **Pilot Safety**: Help pilots make better go/no-go decisions with real-time conditions
- **Zero Maintenance**: AviationWX handles all the software, hosting, and updates
- **Embed Widgets**: Get embeddable weather widgets for your airport's website

### FAA Weather Camera Program

AviationWX participates in the **FAA Weather Camera Program (WCPO)**, which means your webcam images can be published to the FAA's official aviation weather camera network. This provides:

- **Wider Visibility**: Your cameras appear on FAA weather resources used by pilots nationwide
- **Official Recognition**: Contribution to FAA safety infrastructure
- **Standardized Format**: Images are automatically formatted to meet FAA requirements (4:3 aspect ratio, proper metadata)
- **No Extra Work**: If your camera meets our standard requirements, FAA compatibility is automatic

> **Note**: FAA participation is optional and requires your camera to meet quality and reliability standards. We'll let you know if your setup qualifies during the validation process.

## Need help?
If you're not sure how to find your weather station API details or camera connection information, **reach out anyway**.
I'm happy to help you gather the required details and pick the simplest working setup for your airport.

> Tip: You do *not* need to invent your own dashboard or data pipeline. AviationWX is designed so local groups can focus on installing reliable equipment while the shared platform handles the dashboard, formatting, and "freshness" indicators.

---

## Step 0 - Before you send anything
If you get stuck at any point, email `contact@aviationwx.org` and we'll help you through it.

Please review these guides first (they'll save time):
- **Guide 01 - Permission Packet** (permission + privacy expectations)
- **Guide 07 - Equipment Recommendations** (tested weather stations + cameras)
- **Guide 08 - Camera Configuration** (FTP/FTPs/SFTP recommended path)
- **Guide 09 - Weather Station Configuration** (siting + sanity checks)

---

## Step 1 - The "airport basics" checklist (copy/paste)
In your email, include:

- **Airport identifier:** (ICAO / FAA / IATA)
- **Airport name:**  
- **City / State / Country:**  
- **Who is the local steward / maintainer?** (name + phone/email)  
- **Who approved the install?** (airport owner/sponsor/manager or stewardship group)  
- **Install location summary:** (e.g., "hangar roof", "existing pole near …")  
- **Power source:** (grid / solar+battery / hybrid)  
- **Internet source:** (LAN / Wi‑Fi / LTE / Starlink / point-to-point)  
- **Target update cadence:** (typical: 1-15 minutes)

Optional but helpful:
- **Photos of the install site** (even cellphone photos are fine)
- **A sample camera image** (one representative still)

---

## Step 2 - Weather data (choose one path)

### Option A - Tempest (recommended default)
Please send:
- **Tempest Station ID**
- **Tempest Access Token** (personal access token is best)

How to find/create these:
- Tempest's API uses a URL pattern like: `.../observations/station/[your_station_id]?token=[your_access_token]` (so we need both the station ID and token).  
- Tempest also supports creating a personal access token in the Tempest settings ("Data Authorizations").

References (if you want the official docs):
```text
Tempest API docs:
https://weatherflow.github.io/Tempest/api/

Create a personal access token (Data Authorizations):
https://tempestwx.com/settings/tokens
```  

### Option B - Davis WeatherLink (Vantage Pro2, etc.)
Please send:
- **WeatherLink v2 API Key**
- **WeatherLink v2 API Secret**
- **Station ID** (if you have it / can find it)

How to find/create these:
- WeatherLink's v2 tutorial explains generating a v2 API Key and Secret from your WeatherLink account page.
- Treat the **API Secret as sensitive** (it should not be posted publicly).

References:
```text
WeatherLink v2 API tutorial (generate v2 key + secret):
https://weatherlink.github.io/v2-api/tutorial
```

### Option C - Ambient Weather (WS‑2902, etc.)
Please send:
- **Ambient Weather API Key** (sometimes called a *User Key* or *Device Key*)
- **Ambient Weather Application Key** (required for the REST API)
- **Station / Device name** (so we can confirm we picked the right one)

How to find/create these:
- Ambient provides account settings instructions for generating a key.
- Their REST API documentation notes that two keys are required.

References:
```text
Ambient FAQ (create API key):
https://ambientweather.com/faqs/question/view/id/1834/

Ambient REST API docs:
https://ambientweather.docs.apiary.io/
```

### Option D - Official airport weather source (ASOS/AWOS)
If you're using an official airport weather feed:
- tell us **what the airport identifier is**
- and whether the field has **ASOS / AWOS** (and which one, if known)

AviationWX can combine official sources (when present) with local sensors so pilots get the best picture available.

### Option E - AWOSnet
If your airport's AWOS is hosted on [awosnet.com](https://awosnet.com):
- tell us the **AWOSnet station ID** (e.g., `ks40` from `http://ks40.awosnet.com`)

No API key needed. Updates approximately every 10 minutes.

---

## Step 3 - Camera connection (choose one path)
AviationWX supports multiple cameras. Typical installs are **1-4 cameras**.

### Option A - Scheduled JPEG uploads (FTP/FTPs/SFTP) ✅ recommended
This is the simplest and usually produces the best still-image quality.

**What to send**
- Camera brand + model (example: Reolink RLC‑810WA)
- "I want scheduled JPEG uploads" (FTP/FTPs/SFTP)
- Desired upload interval (start at 5 minutes unless you have a reason not to)

**What happens next**
- AviationWX will reply with the **upload destination details** (server/port/username/path), so you can configure the camera.

If you're using Reolink, their support doc shows the general steps to enable FTP and set an upload schedule:

```text
https://support.reolink.com/hc/en-us/articles/360020081034-How-to-Set-up-FTP-for-Reolink-Products/
```

### Option B - RTSP stream (still image extracted)
**What to send**
- Camera brand + model
- RTSP URL (or enough info for us to derive it)
- A dedicated camera user + password (ideally a **view-only** account created specifically for AviationWX)

### Option C - Snapshot URL (HTTP/HTTPS)
**What to send**
- Snapshot URL
- Any credentials required (again: a dedicated "AviationWX" view-only account is ideal)

> Security note: please do **not** send your personal admin login if you can avoid it. When possible, create a dedicated read-only account for the camera/stream and share that instead.

---

## Step 4 - "What good looks like" (validation checklist)
After you email us, we'll validate:

### Camera validation
- The view is useful for pilots (see Guide 02: horizon + runway/approach context)
- Image is readable in common conditions (overcast, rain, fog, sunrise/sunset)
- Updates arrive reliably (and recover after outages)
- The install respects permission + privacy expectations (Guide 01)

### Weather validation
- Readings look reasonable (Guide 09 sanity checks)
- Wind exposure is adequate (or limitations are clearly noted)
- Updates are consistent over 24-72 hours

### Operations validation
- A local maintainer exists (and has access)
- There's a reasonable plan to keep it alive (and to remove it cleanly if needed)

If something doesn't pass, that's normal - we'll tell you exactly what to adjust, and we'll re-test.

---

## Email template (copy/paste)
Subject: `New airport submission - [AIRPORT IDENTIFIER]`

Body:

- Airport identifier:
- Airport name:
- City/State/Country:
- Local steward contact:
- Airport owner/manager approval contact:
- Install location summary:
- Power:
- Internet:
- Target update cadence:

**Weather source**
- Type: Tempest / Davis / Ambient / ASOS-AWOS / AWOSnet
- Details (token/key/etc):

**Cameras**
- Number of cameras:
- Method: FTP/FTPs/SFTP (preferred) / RTSP / Snapshot URL
- Camera models:
- Details (RTSP URL, snapshot URL, or request for FTP destination):

Attachments:
- Photos of install area (optional):
- Sample image (optional):

---

## "Nerdy" reference (optional)
If you're technical (or helping someone technical), the full configuration reference lives here:

```text
https://github.com/alexwitherspoon/aviationwx/blob/main/docs/CONFIGURATION.md
```

But you don't need to read that to submit an airport - the checklists above are enough.

