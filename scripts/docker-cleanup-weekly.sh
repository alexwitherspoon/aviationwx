#!/bin/bash
# Weekly Docker cleanup script for production server (host-level)
# Aggressively removes ALL unused Docker resources
# Deployed via CD to /etc/cron.d/ for weekly execution
#
# This is the "suspenders" to the CD workflow's "belt" cleanup:
# - CD cleanup: Runs conditionally (disk > 40%) during deployments
# - This script: Runs weekly regardless, removes everything unused
#
# Safe to run manually: sudo /home/aviationwx/aviationwx/scripts/docker-cleanup-weekly.sh

set -euo pipefail

LOG_PREFIX="[docker-cleanup-weekly]"
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

log() {
    echo "${TIMESTAMP} ${LOG_PREFIX} $1"
}

log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
log "Weekly Docker Cleanup - Removing ALL unused resources"
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Show disk usage before cleanup
log "Disk usage before cleanup:"
df -h / | head -2

log ""
log "Docker disk usage before cleanup:"
docker system df 2>/dev/null || log "Could not retrieve Docker disk usage"

log ""
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
log "Running aggressive cleanup (docker system prune -af)..."
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Aggressive cleanup: Remove ALL unused containers, networks, images, and build cache
# -a: Remove all unused images, not just dangling ones
# -f: Don't prompt for confirmation
# --volumes: Also remove unused volumes (data safety: only removes truly unused volumes)
if docker system prune -af --volumes 2>&1; then
    log "✓ Docker system prune completed successfully"
else
    log "⚠️  Docker system prune encountered issues (may be expected if nothing to clean)"
fi

log ""
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
log "Cleanup complete"
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Show disk usage after cleanup
log ""
log "Disk usage after cleanup:"
df -h / | head -2

log ""
log "Docker disk usage after cleanup:"
docker system df 2>/dev/null || log "Could not retrieve Docker disk usage"

log ""
log "✓ Weekly Docker cleanup finished at $(date '+%Y-%m-%d %H:%M:%S')"

