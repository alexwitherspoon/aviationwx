# 08 - Installation Planning & Handoff

## Goal
Get from “approved idea” → “working, maintainable installation” with:
- clear ownership,
- clean installs,
- reliable updates,
- and a simple plan for maintenance and removal.

This guide ties together the earlier choices:
- permission (Guide 01)
- siting (Guide 02)
- mounting (Guide 03)
- power (Guide 05)
- internet (Guide 06)
- equipment (Guide 07)

## Quick overview: the phases
1) **Plan** (paperwork + roles + a parts list)
2) **Pre-stage** (configure and test before you go to the airport)
3) **Install** (mount + power + connectivity)
4) **Commission** (validate views + data + reliability)
5) **Handoff** (document, train, and agree on ownership)
6) **Operate** (lightweight maintenance)
7) **Decommission** (remove cleanly if needed)

---

## Phase 1 - Plan

### Define the “owner” (don’t skip this)
Write down who owns what:

- **Airport sponsor/owner:** ____________________
- **Local steward / maintainer:** ____________________
- **Equipment owner:** ____________________
- **Connectivity owner (who pays):** ____________________
- **AviationWX contact:** ____________________

If any of these are unclear, pause. Ambiguity becomes downtime later.

### Confirm the install scope
- Cameras: ___ (typical: 1-4)
- Weather station: ___ (typical: 1)
- Update frequency: ___ minutes (typical: 1-15)
- Power option: A / B / C / D (Guide 05)
- Internet option: A / B / C / D / E (Guide 06)

### Set acceptance criteria (what “done” means)
A good “go-live” definition:
- Camera view is **useful** (per Guide 02)
- Weather station readings look **reasonable** for the field
- Uploads are consistent for at least **24-72 hours**
- Access and maintenance plan is clear (including escort expectations)
- Documentation is stored somewhere the airport/steward can find it

---

## Phase 2 - Pre-stage (test before you go on-site)

### Pre-stage checklist
- ☐ Inventory all parts (camera(s), mounts, fasteners, cables, weather station, network gear)
- ☐ Label each device (simple labels are fine: “CAM1”, “CAM2”, “WX”, “ROUTER”)
- ☐ Configure camera upload method (FTP/FTPs/SFTP or RTSP or snapshot URL)
- ☐ Configure update interval (start conservative; 5 minutes is often a great default)
- ☐ Confirm time/date settings (so timestamps and “freshness” behave)
- ☐ Confirm credentials are stored responsibly (and not only in one person’s head)
- ☐ Dry-run the complete data path on a bench (camera → internet → destination)

### Bring spares (cheap insurance)
Recommended spares:
- ☐ extra SD card (if used), extra ethernet cable, spare power supply/PoE injector
- ☐ extra mounting hardware / zip ties / weatherproof tape / grommets
- ☐ a simple “known-good” test cable and small switch (optional)

---

## Phase 3 - Install (on-site)

### Arrival and coordination
- ☐ Check in with the airport sponsor/manager or designated contact
- ☐ Confirm any **escort requirement** and the boundaries of allowed work
- ☐ Walk the final site and confirm nothing changed (trees, new obstacles, new tenants)

### Install checklist (high-level)
**Mounting**
- ☐ Mount cameras and/or poles using outdoor-rated hardware
- ☐ Confirm mounts are stable (no vibration)
- ☐ Confirm weather station placement matches siting goals (Guide 02)

**Power**
- ☐ Power is safe, approved, and protected from weather (Guide 05)
- ☐ If using PoE, confirm cable strain relief and clean routing
- ☐ If using solar/battery, confirm enclosure sealing and safe placement

**Internet**
- ☐ Connection is stable and auto-reconnects after unplug/replug (Guide 06)
- ☐ Any wireless links are stable and aligned
- ☐ LTE signal is adequate (and antenna placement is approved)

**Cable hygiene**
- ☐ Cables are tidy, protected, and not a hazard
- ☐ Entry points are weather-sealed (if entering a building)
- ☐ Everything is labeled enough that a future maintainer can follow it

---

## Phase 4 - Commission (prove it works)

### Commission checklist (do this before you leave)
- ☐ Confirm each camera view is correct and useful (Guide 02)
- ☐ Confirm the weather station is reporting and looks reasonable
- ☐ Confirm uploads are arriving on schedule
- ☐ Confirm the “freshness” of updates is easy to verify
- ☐ Confirm the install does not create obvious privacy issues (Guide 01)
- ☐ Reboot test: power-cycle the network gear and confirm it recovers without intervention

### “Looks reasonable” sanity checks (plain language)
- Wind direction roughly matches what you’d expect for the field and conditions
- Temperature is not obviously heat-soaked (e.g., not wildly warmer than nearby)
- Pressure trend is plausible
- Camera exposure is usable (not constantly blown out or too dark)

---

## Phase 5 - Handoff (make it sustainable)

### Handoff checklist (pilots love checklists)
- ☐ Identify the primary local maintainer and a backup contact
- ☐ Document how to access equipment (keys, escort rules, contact numbers)
- ☐ Document how to verify the system is healthy (simple “green checks”)
- ☐ Document how to reboot safely (what to power-cycle first)
- ☐ Document who pays for ongoing costs (LTE plan, Starlink, etc.)
- ☐ Store credentials and configuration in a shared, controlled location
- ☐ Agree on an “if it breaks” plan and expected response time (even if informal)

### Minimal documentation template (copy/paste)
- **Airport:** ________
- **Location(s):** ________
- **Cameras:** CAM1 (model) ________ / CAM2 ________ / …
- **Weather station:** ________
- **Power:** ________
- **Internet:** ________
- **Update interval:** ________
- **Who maintains it:** ________
- **How to verify it’s working:** ________
- **How to reboot it:** ________
- **Spare parts location:** ________
- **Install date:** ________
- **Last updated:** ________

---

## Phase 6 - Operate (lightweight maintenance)

### Suggested maintenance cadence
- **Monthly:** quick visual inspection (mount stability, cable wear, cleanliness)
- **Seasonally:** review camera framing (tree growth), weather station exposure, solar panel cleanliness (if applicable)
- **After storms:** quick “is it alive?” check

### Common issues and simple fixes
- **No updates after an outage:** reboot network gear; confirm auto-reconnect settings
- **Camera view degraded:** clean lens, check focus, confirm mount stability
- **Weather readings look wrong:** check for new obstructions or heat sources

If you can’t solve it quickly, it’s okay-ask for help (airport sponsor/manager, local community, AviationWX support).

---

## Phase 7 - Decommission (clean removal plan)
Every install should have a graceful exit plan.

### Decommission checklist
- ☐ Confirm approval to remove (and timing / access rules)
- ☐ Remove equipment cleanly (no abandoned cables)
- ☐ Patch/seal any penetrations (if any were made)
- ☐ Remove accounts/subscriptions if owned for this project (LTE/Starlink)
- ☐ Update documentation and notify stakeholders
- ☐ If desired: keep the equipment for reuse at another field

**Note:** AviationWX is open source. Airports are not locked into a proprietary solution:
- they can participate in the shared network, or
- self-host and continue using their equipment independently.

---

## Example
A working example dashboard:
- kspb.aviationwx.org
