# 06 - Internet Options

## Goal
Get images and weather data from the airport to AviationWX in a way that is:
- **reliable** (works in bad weather and during outages when possible),
- **simple to maintain** (especially by volunteers),
- **cost-appropriate** for the airport,
- and **safe and permitted** (no unsafe cabling, no surprise installs).

AviationWX is designed to work on low bandwidth: still images every **1-15 minutes** plus weather data is usually a small data footprint.

## Quick pick-your-path
Choose the simplest option that works:

- If there is existing **wired internet (LAN/broadband)** nearby → **Option A**
- If you can “bridge” to an existing connection with **point-to-point wireless** → **Option B**
- If no local internet is available (or it’s too hard) → **Option C (LTE/5G)**
- If the site is truly remote and needs a self-contained solution → **Option D (Starlink)**
- If reliability is critical → consider **Hybrid (Option E)**

## Before you choose (applies to every option)
- Confirm **who controls the internet connection** (airport, tenant, FBO, nearby property owner).
- Get **permission** for any equipment mounting, cable routing, and network use (Guide 01).
- Prefer solutions that are **easy to recover** after power outages (auto-reconnect is important).
- Keep installs **serviceable**: labeled, documented, and not dependent on “tribal knowledge.”

## What you’re sending (why bandwidth is usually manageable)
A common setup is:
- 1-4 cameras uploading still images every 1-15 minutes
- one weather station sending periodic readings
- optional: small “bridge” device that forwards the data

Because AviationWX focuses on still images (not continuous video), bandwidth costs are usually much lower and more predictable.

## Option A - Existing LAN / broadband (best when available)
If a building or tenant already has broadband, this is often the most reliable and lowest-cost approach.

**Common patterns**
- Plug into an available LAN port (with permission)
- Use a small router or bridge device to keep the camera network isolated if needed
- Place the uploading device inside the building when possible

**Pros**
- usually the most stable connection
- often the lowest ongoing cost
- simplest long-term maintenance

**Cons**
- requires coordination with whoever controls the network
- may require cable routing approvals

**Checklist**
- Who is providing internet access and who approves it?
- Where can cables be run safely (and permanently)?
- Does the setup auto-recover after outages?
- Is there a plan if the building is locked / inaccessible?

## Option B - Point-to-point wireless (great when the view is “far from the building”)
If the best camera location is away from existing internet, a point-to-point wireless bridge can connect it back to a building that has broadband.

**Common patterns**
- A wireless link from a hangar/office to a pole or camera location
- A short-range bridge across the field (only where safe and permitted)
- A local WISP (wireless ISP) connection if available

**Pros**
- avoids trenching or long cable runs
- can be very reliable when installed correctly
- often cheaper than LTE over time

**Cons**
- requires line-of-sight planning
- can be disrupted by trees, snow, or new obstacles
- more “installation craft” than plugging into a LAN

**Checklist**
- Clear line of sight between endpoints
- Stable mounts (wireless links hate vibration)
- Weather-rated gear and clean cable protection
- Document the link so others can maintain it

## Option C - LTE / 5G (simple and flexible, but ongoing cost)
Cellular internet works well when you can’t access a building network or when the site is isolated.

**Pros**
- fast to deploy
- works almost anywhere with coverage
- doesn’t require using an airport/tenant network

**Cons**
- monthly cost (sometimes the largest recurring expense)
- coverage can be inconsistent at some airports
- may need external antennas in weak-signal locations

**Checklist**
- Confirm signal strength on-site (test with a phone on the intended carrier)
- Plan the monthly budget and ownership of the SIM/account
- Ensure the device auto-reconnects after outages
- Plan where the LTE router and antennas can be mounted safely

## Option D - Starlink (remote, self-contained, higher cost)
Starlink can be a great fit for very remote airports or fields with no cell coverage and no broadband options.

**Pros**
- works in many remote locations
- high capacity (more than you need for still images)
- independent of local infrastructure

**Cons**
- higher monthly cost than many alternatives
- needs a clear view of the sky
- power draw is higher than LTE (matters for solar)

**Checklist**
- Clear sky view for the dish (seasonal tree growth matters)
- Power plan supports the dish year-round
- Equipment mounting is safe and approved
- Ongoing subscription ownership is clear

## Option E - Hybrid for reliability (recommended for critical locations)
If the airport is safety-critical or outages are common, consider a hybrid approach:
- primary connection: LAN/broadband or point-to-point wireless
- backup connection: LTE (or Starlink in rare cases)
- power backup: UPS or battery assist (Guide 05)

The goal is not perfection-just improved uptime and fewer “someone has to go reboot it” moments.

## Security and “being a good network citizen” (plain language)
- Use only the connectivity needed for the job.
- Prefer encrypted transfers when possible (FTPs / SFTP) and avoid exposing camera admin interfaces to the public internet.
- Keep credentials documented and managed responsibly (so the airport isn’t stuck if a volunteer moves on).

(We can add a dedicated “network security basics” appendix later if needed.)

## Common failure modes (and what to do)
- **Everything works… until the first power outage:** choose hardware that auto-recovers and add a small UPS (Guide 05).
- **Wireless link drops in winter:** check line-of-sight and mount stability; trees and weather matter.
- **LTE works but costs too much:** reduce image frequency, or explore point-to-point wireless / local WISP options.
- **The best camera location has no connectivity:** this is a great time to ask the airport sponsor/manager for help-often there’s an existing building, conduit path, or partner who can help bridge the gap.

## Decision (write it down)
- Internet option: A / B / C / D / E
- Who provides / pays for the connection:
- Install locations (endpoints):
- Backup plan (if any):
- Who maintains it:
- How to verify it is “healthy” (simple check):

## Next
Move to **Equipment**: [`07-equipment-recommendations.md`](07-equipment-recommendations.md)
