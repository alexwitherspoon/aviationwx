# 05 - Power Options

## Goal
Power the cameras + weather station in a way that is:
- **safe** (for people and airport operations),
- **reliable** through weather and seasons,
- **maintainable** by the local group,
- and realistic for the budget and install effort.

This guide assumes you’ve already chosen a location and mounting approach (Guides 02-03).

## Quick pick-your-path
Choose the simplest option that works:

- If you can use **existing grid power** near the install → **Option A**
- If you can get **grid power + PoE** (recommended) → **Option B**
- If grid power is unreliable and you want backup → **Option C**
- If there’s no grid power available → **Option D (Solar + battery)**

## Safety first (applies to every option)
- If you are running new electrical circuits, involve the airport sponsor/manager and use a **qualified electrician** as needed.
- Prefer **low-voltage** approaches when possible (e.g., PoE) to reduce complexity and risk.
- Plan for **lightning and surges**: use proper grounding, cable routing, and surge protection.
- Keep power and network gear **weather-protected** and **serviceable**.

## What you are powering (typical footprint)
A common setup is:
- **1-4 cameras** (still image snapshots every 1-15 minutes)
- **1 weather station** (wind, temp, pressure, rain, etc.)
- optional: a small network device (switch / router / LTE modem)

As a rough rule of thumb, plan the system around:
- cameras: low to moderate power each (varies by model, features, IR, etc.)
- networking gear: small but constant power draw
- solar/battery sizing: depends heavily on winter sun and local shading

## Option A - Use existing grid power (simplest)
If there’s an existing outlet or building power you can use safely and with permission, this is usually the best starting point.

**Checklist**
- Written permission includes access to the power source (Guide 01).
- Power source is safe, reliable, and realistically accessible for maintenance.
- You have a plan to keep adapters/enclosures protected from weather and tampering.

## Option B - Grid power + PoE (recommended when possible)
**PoE (Power over Ethernet)** lets one Ethernet cable carry both power and data.
This is often the safest and simplest “professional” approach when a building or existing infrastructure is nearby.

**Why it’s recommended**
- avoids running new high-voltage lines out to a pole
- simplifies installs and maintenance
- makes it easier to power multiple cameras cleanly

**Typical components**
- PoE switch or PoE injector (in a building or protected enclosure)
- outdoor-rated Ethernet cable and strain relief
- surge protection / grounding strategy (especially for long outdoor runs)

**Checklist**
- Cable route is approved and protected (no trip hazards, no improvised runs).
- Cable enters buildings cleanly and safely (approved entry point, weather sealing).
- Long runs are planned thoughtfully (avoid “mystery cables” that future maintainers can’t identify).

## Option C - Grid power + backup (UPS or hybrid)
If power outages are common, consider a backup plan. Even short outages can create long downtime if a modem or camera needs manual reboot.

**Simple approach**
- A small UPS (uninterruptible power supply) inside a building can keep PoE/network gear alive through short outages.

**More robust approach**
- Grid power + battery backup + solar assist (a “hybrid” setup) for critical locations.

**Checklist**
- Backup runtime is sized for your real outage pattern.
- If you rely on LTE or a router, confirm it will auto-recover after outages.
- Maintenance plan includes periodic checks (battery health, connections, weatherproofing).

## Option D - Solar + battery (when there’s no grid power)
This is common for remote strips or where running power is not feasible.

**High-level recipe**
- Solar panel (often ~30-100W depending on load and winter conditions)
- Charge controller
- Battery (often LiFePO₄ for durability)
- Weatherproof enclosure
- PoE switch/injector (if powering PoE cameras)
- Optional: a low-power LTE router / point-to-point radio

**Key realities**
- Winter sun is the hardest case. Size for the worst month, not the best month.
- Shade from trees/buildings can break a solar plan even when the panel “looks big enough.”
- A system that works in July can fail in December without enough margin.

**Checklist**
- Panel location is permitted and not a hazard.
- Enclosure is protected from water ingress and extreme heat.
- Battery chemistry and enclosure choices are appropriate for local temperatures.
- You have a plan to inspect and maintain the system (even if only seasonally).

## Lightning and surge protection (plain language)
Long outdoor cable runs can invite lightning and surge damage.
You can’t prevent all damage, but you can reduce risk and make recovery easier.

**Practical best practices**
- Ground equipment properly before cables enter a building (where applicable).
- Use surge protection and clean cable routing.
- Keep wiring tidy and documented so repairs don’t become guesswork.

(We’ll add a dedicated “lightning and grounding” appendix guide later if needed.)

## Decision (write it down)
- Power option: A / B / C / D
- Where power originates:
- Any backups (UPS / battery):
- Who is responsible for maintenance:
- How the system is shut off safely (disconnect plan):

## Next
Move to **Internet**: [`06-internet-options.md`](06-internet-options.md)
