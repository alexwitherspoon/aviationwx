# VPN Feature - Clarifying Questions

Based on your responses, I have a few clarifying questions to finalize the design:

## 1. PSK Storage in airports.json

**Decision**: Direct read from `airports.json` file

**Implementation**:
- VPN manager service reads PSKs directly from `airports.json`
- File is mounted read-only into containers (already mapped to web container)
- Expose `airports.json` to strongSwan/VPN manager containers as read-only volume
- No Docker secrets extraction needed
- Simple and straightforward approach

## 2. Wizard Utility

**Decision**: CLI script in organized utility folder

**Implementation**:
- Format: CLI interactive script (like `npm init`)
- Location: Organized utility scripts folder (e.g., `scripts/admin/` or `scripts/vpn/`)
- Functionality:
  - Read existing `airports.json` file
  - Generate secure PSK automatically
  - Generate `airports.json` configuration snippet for the site
  - **Validate** that the addition works with the rest of the config file
  - Generate remote site configuration instructions (UniFi-specific)
  - Provide step-by-step deployment checklist
- Output: 
  - Validated JSON snippet (ready to add to airports.json)
  - Remote site configuration instructions (with pre-filled values)
  - Deployment checklist
- Workflow: 
  - Wizard outputs validated JSON snippet
  - User takes output and runs separate deploy process to push to production
  - Wizard does NOT modify airports.json directly (deployment is separate)

## 3. Status Page Integration

**Decision**: Connection state + last connection time per airport VPN

**Implementation**:
- Status Details: Connection state (up/down/connecting) + last connection time
- Display Location: Separate component in airport status card (like "Weather API", "Webcams")
- Status Source: Status file (JSON) updated via cron, read by status page
- Visual Design: Same color scheme (green/yellow/red), standard status indicators
- Only show if VPN is enabled for that airport

## 4. Configuration Reload

**Decision**: No hot-reload needed - container restart on airports.json update

**Implementation**:
- When `airports.json` is updated via deployment, Docker Compose will restart containers
- No hot-reload functionality needed
- Simple and reliable approach
- Wizard can document the restart process

## 5. Docker Secrets Extraction

**Decision**: Not needed - direct read from airports.json

**Implementation**:
- VPN manager reads PSKs directly from `airports.json` (mounted read-only)
- No Docker secrets extraction required
- Simpler implementation

## 6. Remote Site Configuration

**Decision**: Both generic docs + wizard-generated site-specific instructions

**Implementation**:
- Format: Markdown documentation + wizard-generated site-specific output
- Content:
  - UniFi-specific step-by-step instructions
  - Generic IPsec setup for other equipment
  - Troubleshooting section
  - Configuration validation steps
- Generation: Wizard generates site-specific instructions with pre-filled values (PSK, server IP, etc.)

## Summary of Final Decisions

✅ **All questions answered - Design finalized!**

1. **PSK Access**: ✅ Direct read from airports.json (mounted read-only)
2. **Wizard Format**: ✅ CLI script in organized utility folder
3. **Wizard Functionality**: ✅ Generate PSK, config snippets, instructions, validation, checklist
4. **Status Page Details**: ✅ Connection state + last connection time per airport VPN
5. **Status Source**: ✅ Status file (JSON) updated via cron
6. **Hot-Reload**: ✅ Not needed - container restart on airports.json update
7. **Docker Secrets**: ✅ Not needed - direct file read
8. **Remote Site Instructions**: ✅ Both generic docs + wizard-generated site-specific

**Design is complete and ready for implementation!**

