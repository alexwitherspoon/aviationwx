#!/bin/bash
# Resolve DNS hostname to get pasv_address for vsftpd dual-stack support
#
# Usage: resolve-upload-ip.sh [hostname] [mode]
#   hostname: DNS hostname to resolve (default: upload.aviationwx.org)
#   mode: both (default), ipv4, or ipv6
# Returns: IPv4 and/or IPv6 addresses (one per line)

set -euo pipefail

HOSTNAME="${1:-upload.aviationwx.org}"
MODE="${2:-both}"

# Validate mode parameter
if [ "$MODE" != "both" ] && [ "$MODE" != "ipv4" ] && [ "$MODE" != "ipv6" ]; then
    echo "ERROR: Invalid mode '$MODE'. Must be 'both', 'ipv4', or 'ipv6'" >&2
    exit 1
fi

# Resolve IPv4 (A record) - getent is more reliable than dig in containers
IPV4=""
if command -v getent >/dev/null 2>&1; then
    IPV4=$(getent ahostsv4 "$HOSTNAME" 2>/dev/null | awk '{print $1}' | head -1 || true)
elif command -v dig >/dev/null 2>&1; then
    IPV4=$(dig +short "$HOSTNAME" A 2>/dev/null | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | head -1 || true)
else
    echo "ERROR: Neither 'getent' nor 'dig' command found" >&2
    exit 1
fi

# Resolve IPv6 (AAAA record)
IPV6=""
if command -v getent >/dev/null 2>&1; then
    IPV6=$(getent ahostsv6 "$HOSTNAME" 2>/dev/null | awk '{print $1}' | head -1 || true)
elif command -v dig >/dev/null 2>&1; then
    IPV6=$(dig +short "$HOSTNAME" AAAA 2>/dev/null | grep -E '^[0-9a-fA-F:]+::?[0-9a-fA-F:]*$' | head -1 || true)
fi

# Return based on mode
case "$MODE" in
    ipv4)
        if [ -n "$IPV4" ]; then
            echo "$IPV4"
            exit 0
        else
            echo "ERROR: Could not resolve IPv4 for $HOSTNAME" >&2
            exit 1
        fi
        ;;
    ipv6)
        if [ -n "$IPV6" ]; then
            echo "$IPV6"
            exit 0
        else
            echo "ERROR: Could not resolve IPv6 for $HOSTNAME" >&2
            exit 1
        fi
        ;;
    both|*)
        if [ -n "$IPV4" ] && [ -n "$IPV6" ]; then
            echo "$IPV4"
            echo "$IPV6"
            exit 0
        elif [ -n "$IPV4" ]; then
            echo "$IPV4"
            exit 0
        elif [ -n "$IPV6" ]; then
            echo "$IPV6"
            exit 0
        else
            echo "ERROR: Could not resolve $HOSTNAME to any IP address" >&2
            exit 1
        fi
        ;;
esac

