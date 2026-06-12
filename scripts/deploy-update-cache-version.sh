#!/bin/bash
# Generate config/version.json for client version checking
# This script is called during deployment to record the deployed build

set -euo pipefail

DEPLOY_VERSION="${1:-$(date +%s)}"
VERSION_FILE="config/version.json"
AIRPORTS_FILE="config/airports.json"

echo "Generating version.json for deploy: ${DEPLOY_VERSION}"

# Get git information
GIT_HASH_SHORT=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
GIT_HASH_FULL=$(git rev-parse HEAD 2>/dev/null || echo "unknown")
DEPLOY_TIMESTAMP=$(date +%s)
DEPLOY_DATE=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

echo "Git hash: ${GIT_HASH_SHORT} (${GIT_HASH_FULL})"
echo "Deploy timestamp: ${DEPLOY_TIMESTAMP} (${DEPLOY_DATE})"

# Read configuration from airports.json (with defaults if not found)
# These settings control client version management and cleanup behavior
MAX_NO_UPDATE_DAYS=7
STUCK_CLIENT_CLEANUP="false"

if [ -f "${AIRPORTS_FILE}" ]; then
    echo "Reading configuration from ${AIRPORTS_FILE}..."
    
    # Extract values using grep/sed (avoids jq dependency)
    # dead_man_switch_days -> max_no_update_days
    DAYS_VALUE=$(grep -o '"dead_man_switch_days"[[:space:]]*:[[:space:]]*[0-9]*' "${AIRPORTS_FILE}" | grep -o '[0-9]*$' || echo "")
    if [ -n "${DAYS_VALUE}" ]; then
        MAX_NO_UPDATE_DAYS="${DAYS_VALUE}"
        echo "  dead_man_switch_days: ${MAX_NO_UPDATE_DAYS}"
    fi
    
    # stuck_client_cleanup
    if grep -q '"stuck_client_cleanup"[[:space:]]*:[[:space:]]*true' "${AIRPORTS_FILE}"; then
        STUCK_CLIENT_CLEANUP="true"
        echo "  stuck_client_cleanup: true"
    else
        echo "  stuck_client_cleanup: false (default)"
    fi
else
    echo "⚠️  ${AIRPORTS_FILE} not found, using defaults"
fi

# Generate version.json for client version checking
# This file is served by the version API and used for dead man's switch detection
# Settings are sourced from airports.json config section
cat > "${VERSION_FILE}" << EOF
{
    "hash": "${GIT_HASH_SHORT}",
    "hash_full": "${GIT_HASH_FULL}",
    "timestamp": ${DEPLOY_TIMESTAMP},
    "deploy_date": "${DEPLOY_DATE}",
    "max_no_update_days": ${MAX_NO_UPDATE_DAYS},
    "stuck_client_cleanup": ${STUCK_CLIENT_CLEANUP}
}
EOF

echo "✓ Generated ${VERSION_FILE}"

# Verify version.json was created
if [ -f "${VERSION_FILE}" ]; then
    echo "✓ Version file contents:"
    cat "${VERSION_FILE}"
else
    echo "⚠️  Warning: ${VERSION_FILE} was not created"
    exit 1
fi

echo ""
echo "✓ All version updates completed successfully"

