#!/bin/bash
# Update cache version in service worker and HTML files
# This script is called during deployment to bust caches

set -euo pipefail

DEPLOY_VERSION="${1:-$(date +%s)}"
SW_FILE="public/js/service-worker.js"
VERSION_FILE="config/version.json"
AIRPORTS_FILE="config/airports.json"

echo "Updating cache version to: ${DEPLOY_VERSION}"

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
FORCE_CLEANUP="false"
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
    
    # force_cleanup (look for the one in config section, not in comments)
    if grep -q '"force_cleanup"[[:space:]]*:[[:space:]]*true' "${AIRPORTS_FILE}"; then
        FORCE_CLEANUP="true"
        echo "  force_cleanup: true"
    else
        echo "  force_cleanup: false (default)"
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
    "force_cleanup": ${FORCE_CLEANUP},
    "max_no_update_days": ${MAX_NO_UPDATE_DAYS},
    "stuck_client_cleanup": ${STUCK_CLIENT_CLEANUP}
}
EOF

echo "✓ Generated ${VERSION_FILE}"

# Update service worker cache version
if [ ! -f "${SW_FILE}" ]; then
    echo "⚠️  ${SW_FILE} not found, creating it with default version"
    # Create a basic sw.js if it doesn't exist (shouldn't happen, but handle gracefully)
    cat > "${SW_FILE}" << 'EOF'
// AviationWX Service Worker
// Provides offline support and background sync for weather data

const CACHE_VERSION = 'v1';
const CACHE_NAME = `aviationwx-${CACHE_VERSION}`;
EOF
fi

# Update CACHE_VERSION in sw.js using sed
# Match any version string: 'v2', 'v123', 'vabc-123', etc.
# Escape single quotes and special characters in DEPLOY_VERSION for sed
ESCAPED_VERSION=$(echo "${DEPLOY_VERSION}" | sed "s/'/\\\'/g" | sed 's/[\/&]/\\&/g')

if [[ "$OSTYPE" == "darwin"* ]]; then
    # macOS sed requires -i '' and -E for extended regex
    # Match any version after 'v' (alphanumeric, hyphens, dots, etc.)
    sed -i '' -E "s/const CACHE_VERSION = 'v[^']*';/const CACHE_VERSION = 'v${ESCAPED_VERSION}';/" "${SW_FILE}"
else
    # Linux sed - match any version after 'v'
    sed -i "s/const CACHE_VERSION = 'v[^']*';/const CACHE_VERSION = 'v${ESCAPED_VERSION}';/" "${SW_FILE}"
fi

echo "✓ Updated ${SW_FILE} cache version to v${DEPLOY_VERSION}"

# Verify the update worked
if grep -q "const CACHE_VERSION = 'v${ESCAPED_VERSION}';" "${SW_FILE}"; then
    echo "✓ Verified cache version update successful"
else
    echo "⚠️  Warning: Could not verify cache version update"
    echo "Current CACHE_VERSION line:"
    grep "const CACHE_VERSION" "${SW_FILE}" || echo "Not found!"
    exit 1
fi

echo "✓ Cache version updated successfully"

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

