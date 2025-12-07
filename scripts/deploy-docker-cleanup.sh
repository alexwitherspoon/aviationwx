#!/bin/bash
# Docker cleanup script for production server
# Removes old Docker images, build cache, and unused resources
# Safe to run manually or via cron

set -euo pipefail

# Configuration
BUILD_CACHE_RETENTION_HOURS=${BUILD_CACHE_RETENTION_HOURS:-24}  # Keep build cache for 24 hours
IMAGE_RETENTION_HOURS=${IMAGE_RETENTION_HOURS:-168}  # Keep old images for 7 days (168 hours)
DRY_RUN=${DRY_RUN:-false}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Docker Cleanup Script"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Show current disk usage
echo "Current Docker disk usage:"
docker system df
echo ""

if [ "$DRY_RUN" = "true" ]; then
    echo "⚠️  DRY RUN MODE - No changes will be made"
    echo ""
fi

# Function to run cleanup command (with dry-run support)
run_cleanup() {
    local description=$1
    local command=$2
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "$description"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    
    if [ "$DRY_RUN" = "true" ]; then
        echo "Would run: $command"
        # For dry-run, show what would be removed
        case "$description" in
            *"build cache"*)
                docker builder du 2>/dev/null || echo "Build cache info not available"
                ;;
            *"dangling images"*)
                docker images -f "dangling=true" -q | wc -l | xargs echo "Dangling images:"
                ;;
            *"unused images"*)
                docker images --format "{{.Repository}}:{{.Tag}}" | grep -v "<none>" || true
                ;;
            *"unused volumes"*)
                docker volume ls -f "dangling=true" -q | wc -l | xargs echo "Unused volumes:"
                ;;
        esac
    else
        eval "$command" || {
            echo "⚠️  Warning: Cleanup command failed (this may be expected if nothing to clean)"
        }
    fi
    echo ""
}

# 1. Clean up build cache (keep recent for faster builds)
run_cleanup "1. Cleaning build cache (keeping last ${BUILD_CACHE_RETENTION_HOURS} hours)" \
    "docker builder prune -f --filter \"until=${BUILD_CACHE_RETENTION_HOURS}h\""

# 2. Remove dangling images (untagged images from previous builds)
run_cleanup "2. Removing dangling images" \
    "docker image prune -f"

# 3. Remove old unused images (keep images from last N hours, keep currently used images)
run_cleanup "3. Removing old unused images (keeping last ${IMAGE_RETENTION_HOURS} hours)" \
    "docker image prune -af --filter \"until=${IMAGE_RETENTION_HOURS}h\""

# 4. Remove unused volumes (be careful - only removes volumes not used by any container)
run_cleanup "4. Removing unused volumes" \
    "docker volume prune -f"

# 5. Final cleanup: remove any remaining unused resources (networks, etc.)
run_cleanup "5. Final cleanup of unused resources" \
    "docker system prune -f --filter \"until=${IMAGE_RETENTION_HOURS}h\""

# Show disk usage after cleanup
if [ "$DRY_RUN" != "true" ]; then
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "Docker disk usage after cleanup:"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    docker system df
    echo ""
    
    # Show space reclaimed (approximate)
    echo "✓ Cleanup complete"
else
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "Dry run complete - no changes were made"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
fi

