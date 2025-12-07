#!/bin/bash
# Deployment helper functions for better error messages
# Used in GitHub Actions deployment workflow

deployment_error() {
  local step=$1
  local error=$2
  local troubleshooting=$3
  
  echo ""
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  echo "❌ DEPLOYMENT FAILED: $step"
  echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
  echo ""
  echo "Error: $error"
  echo ""
  if [ -n "$troubleshooting" ]; then
    echo "Troubleshooting:"
    echo "$troubleshooting"
    echo ""
  fi
  echo "For more help, see:"
  echo "  - Deployment docs: docs/DEPLOYMENT.md"
  echo "  - Operations guide: docs/OPERATIONS.md"
  echo ""
  echo "Recent logs:"
  docker compose -f docker/docker-compose.prod.yml logs --tail=30 2>&1 || echo "Could not retrieve logs"
  echo ""
  exit 1
}

# Example usage:
# deployment_error \
#   "Docker Compose Build/Start" \
#   "Failed to build or start containers" \
#   "1. Check Docker daemon is running: systemctl status docker
#    2. Check disk space: df -h
#    3. Check Docker logs: journalctl -u docker
#    4. Try manual build: docker compose -f docker/docker-compose.prod.yml build"

