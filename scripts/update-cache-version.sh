#!/bin/bash
# Update cache version in service worker and HTML files
# This script is called during deployment to bust caches

set -euo pipefail

DEPLOY_VERSION="${1:-$(date +%s)}"
SW_FILE="sw.js"
SW_REGISTRATION="airport-template.php"

echo "Updating cache version to: ${DEPLOY_VERSION}"

# Update service worker cache version
if [ -f "${SW_FILE}" ]; then
    # Update CACHE_VERSION in sw.js using sed
    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS sed requires -i '' and -E for extended regex
        sed -i '' -E "s/const CACHE_VERSION = 'v[0-9]+';/const CACHE_VERSION = 'v${DEPLOY_VERSION}';/" "${SW_FILE}"
    else
        # Linux sed
        sed -i "s/const CACHE_VERSION = 'v[0-9]\+';/const CACHE_VERSION = 'v${DEPLOY_VERSION}';/" "${SW_FILE}"
    fi
    echo "✓ Updated ${SW_FILE} cache version to v${DEPLOY_VERSION}"
else
    echo "⚠️  ${SW_FILE} not found, skipping service worker update"
fi

echo "✓ Cache version updated successfully"

