#!/bin/bash
# Configure firewall ports for all production services
# This script is DECLARATIVE - it ensures the firewall matches the desired state
# by adding missing rules AND removing stale/orphaned rules.
#
# Production host firewall: reads optional config.network_ports from airports.json (defaults
# match docker/nginx/vsftpd/sshd). Exits with error if config.host_firewall is present.
# Override path: AIRPORTS_JSON=/path/to/airports.json

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AIRPORTS_JSON="${AIRPORTS_JSON:-$HOME/airports.json}"

# Defaults when airports.json is missing or unreadable
HTTP_PORT=80
HTTPS_PORT=443
FTP_CONTROL=2121
FTPS_EXPLICIT_TLS=2122
SFTP_PORT=2222
FTP_PASSIVE_MIN=50000
FTP_PASSIVE_MAX=51000
SSH_PORT=22
FTPS_ALT=""
AIRPORTS_CONFIG_READABLE=false

validate_tcp_port() {
    local p="$1"
    local name="$2"
    if ! [[ "$p" =~ ^[0-9]+$ ]] || [ "$p" -lt 1 ] || [ "$p" -gt 65535 ]; then
        echo "❌ ${name} must be an integer 1-65535 (got: ${p})" >&2
        exit 1
    fi
}

read_network_ports_config() {
    AIRPORTS_CONFIG_READABLE=false
    if [ ! -f "$AIRPORTS_JSON" ]; then
        echo "⚠️  airports.json not found at ${AIRPORTS_JSON} — using baked-in default firewall ports"
        return 0
    fi
    if ! command -v jq >/dev/null 2>&1; then
        echo "❌ jq is required when airports.json exists (network_ports)" >&2
        echo "   Install: sudo apt-get install -y jq" >&2
        exit 1
    fi
    if ! jq empty "$AIRPORTS_JSON" 2>/dev/null; then
        echo "❌ Invalid JSON: ${AIRPORTS_JSON}" >&2
        exit 1
    fi
    if jq -e '(.config | has("host_firewall"))' "$AIRPORTS_JSON" >/dev/null 2>&1; then
        echo "❌ config.host_firewall is not a valid key; TCP ports are configured in config.network_ports" >&2
        exit 1
    fi
    if jq -e '.config.network_ports != null' "$AIRPORTS_JSON" >/dev/null 2>&1; then
        if ! jq -e '(.config.network_ports | type == "object")' "$AIRPORTS_JSON" >/dev/null 2>&1; then
            echo "❌ config.network_ports must be a JSON object (not an array or string)" >&2
            exit 1
        fi
        if ! jq -e '(.config.network_ports | to_entries | map(select(.value != null and (.value | type != "number"))) | length == 0)' "$AIRPORTS_JSON" >/dev/null 2>&1; then
            echo "❌ config.network_ports must use JSON numbers for port fields (not strings)" >&2
            exit 1
        fi
    fi

    local merged='(.config.network_ports // {})'
    HTTP_PORT=$(jq -r "${merged} | .http // 80" "$AIRPORTS_JSON")
    HTTPS_PORT=$(jq -r "${merged} | .https // 443" "$AIRPORTS_JSON")
    FTP_CONTROL=$(jq -r "${merged} | .ftp_control // 2121" "$AIRPORTS_JSON")
    FTPS_EXPLICIT_TLS=$(jq -r "${merged} | .ftps_explicit_tls // 2122" "$AIRPORTS_JSON")
    SFTP_PORT=$(jq -r "${merged} | .sftp // 2222" "$AIRPORTS_JSON")
    FTP_PASSIVE_MIN=$(jq -r "${merged} | .ftp_passive_min // 50000" "$AIRPORTS_JSON")
    FTP_PASSIVE_MAX=$(jq -r "${merged} | .ftp_passive_max // 51000" "$AIRPORTS_JSON")
    SSH_PORT=$(jq -r "${merged} | .ssh // 22" "$AIRPORTS_JSON")
    local raw_alt
    raw_alt=$(jq -r "${merged} | .ftps_alt // empty" "$AIRPORTS_JSON")

    validate_tcp_port "$HTTP_PORT" "network_ports.http"
    validate_tcp_port "$HTTPS_PORT" "network_ports.https"
    validate_tcp_port "$FTP_CONTROL" "network_ports.ftp_control"
    validate_tcp_port "$FTPS_EXPLICIT_TLS" "network_ports.ftps_explicit_tls"
    validate_tcp_port "$SFTP_PORT" "network_ports.sftp"
    validate_tcp_port "$FTP_PASSIVE_MIN" "network_ports.ftp_passive_min"
    validate_tcp_port "$FTP_PASSIVE_MAX" "network_ports.ftp_passive_max"
    validate_tcp_port "$SSH_PORT" "network_ports.ssh"

    if [ "$FTP_PASSIVE_MIN" -ge "$FTP_PASSIVE_MAX" ]; then
        echo "❌ network_ports.ftp_passive_min must be less than ftp_passive_max" >&2
        exit 1
    fi

    FTPS_ALT=""
    if [ -n "$raw_alt" ] && [ "$raw_alt" != "null" ]; then
        if ! [[ "$raw_alt" =~ ^[0-9]+$ ]]; then
            echo "❌ network_ports.ftps_alt must be an integer (got: ${raw_alt})" >&2
            exit 1
        fi
        validate_tcp_port "$raw_alt" "network_ports.ftps_alt"
        if [ "$raw_alt" -eq "$FTP_CONTROL" ]; then
            echo "❌ network_ports.ftps_alt must differ from ftp_control (${FTP_CONTROL})" >&2
            exit 1
        fi
        FTPS_ALT="$raw_alt"
    fi

    AIRPORTS_CONFIG_READABLE=true
}

read_network_ports_config

# Stale-rule cleanup is safe when airports.json exists (authoritative desired state).
# If the file is missing, baked-in defaults may not match a host with custom ports.
if [ ! -f "$AIRPORTS_JSON" ]; then
    CLEANUP_STALE_RULES="${CLEANUP_STALE_RULES:-false}"
else
    CLEANUP_STALE_RULES="${CLEANUP_STALE_RULES:-true}"
fi

# =============================================================================
# DESIRED STATE: built from config.network_ports (or defaults above)
# =============================================================================
# Format: PORT:PROTOCOL:DESCRIPTION or START:END:PROTOCOL:DESCRIPTION for ranges
PORTS=(
    "${HTTP_PORT}:tcp:HTTP (Nginx)"
    "${HTTPS_PORT}:tcp:HTTPS (Nginx)"
    "${FTP_CONTROL}:tcp:FTP (Push webcams)"
    "${FTPS_EXPLICIT_TLS}:tcp:FTPS (Push webcams - explicit TLS)"
    "${SFTP_PORT}:tcp:SFTP (Push webcams)"
    "${FTP_PASSIVE_MIN}:${FTP_PASSIVE_MAX}:tcp:FTP passive mode (Push webcams)"
    "${SSH_PORT}:tcp:SSH (System access)"
)
if [ -n "$FTPS_ALT" ]; then
    PORTS+=( "${FTPS_ALT}:tcp:FTPS alt control (NAT to ${FTP_CONTROL})" )
fi

# Ports to explicitly deny (internal services that should not be publicly accessible)
DENY_PORTS=(
    "8080:tcp:Apache (internal, behind nginx - should only be accessible from localhost)"
)

# Protected: SSH admin port — never removed by stale-rule cleanup
PROTECTED_PORTS=("${SSH_PORT}/tcp" "${SSH_PORT}")

# =============================================================================
# CONFIGURATION
# =============================================================================
# Set to "true" to enable cleanup of stale rules (default when airports.json is present)

# Set to "true" for dry-run mode (shows what would be done without making changes)
DRY_RUN="${DRY_RUN:-false}"

echo "=============================================="
echo "Firewall Configuration (Declarative Mode)"
echo "=============================================="
echo "Cleanup stale rules: ${CLEANUP_STALE_RULES}"
echo "Dry run: ${DRY_RUN}"
echo "airports.json: ${AIRPORTS_JSON}"
echo "network_ports: http=${HTTP_PORT} https=${HTTPS_PORT} ftp=${FTP_CONTROL} ftps_tls=${FTPS_EXPLICIT_TLS} sftp=${SFTP_PORT} passive=${FTP_PASSIVE_MIN}-${FTP_PASSIVE_MAX} ssh=${SSH_PORT}"
if [ -n "$FTPS_ALT" ]; then
    echo "ftps_alt (NAT to ${FTP_CONTROL}): ${FTPS_ALT}"
else
    if [ "$AIRPORTS_CONFIG_READABLE" = "true" ]; then
        echo "ftps_alt: (unset — NAT cleared when ensure runs with empty ftps_alt)"
    else
        echo "ftps_alt: (unset — NAT not reconciled: airports.json missing or unreadable at ${AIRPORTS_JSON})"
    fi
fi
echo ""

# Check if ufw is installed
if ! command -v ufw >/dev/null 2>&1; then
    echo "❌ ufw is not installed"
    echo "Install with: sudo apt-get install ufw"
    exit 1
fi

# Check if running as root or with sudo
if [ "$EUID" -ne 0 ]; then
    echo "⚠️  Not running as root, using sudo..."
    SUDO="sudo"
else
    SUDO=""
fi

# Build list of desired port/protocol combinations for comparison
declare -A DESIRED_ALLOW_RULES
declare -A DESIRED_DENY_RULES

for port_config in "${PORTS[@]}"; do
    IFS=':' read -ra parts <<< "$port_config"
    if [[ "${parts[1]}" =~ ^[0-9]+$ ]]; then
        # Port range
        port_range="${parts[0]}:${parts[1]}"
        protocol="${parts[2]}"
        DESIRED_ALLOW_RULES["${port_range}/${protocol}"]=1
    else
        # Single port
        port="${parts[0]}"
        protocol="${parts[1]}"
        DESIRED_ALLOW_RULES["${port}/${protocol}"]=1
    fi
done

for deny_config in "${DENY_PORTS[@]}"; do
    IFS=':' read -ra parts <<< "$deny_config"
    port="${parts[0]}"
    protocol="${parts[1]}"
    DESIRED_DENY_RULES["${port}/${protocol}"]=1
done

# =============================================================================
# STEP 1: Remove stale/orphaned rules
# =============================================================================
if [ "$CLEANUP_STALE_RULES" = "true" ]; then
    echo "Step 1: Checking for stale firewall rules..."
    echo ""
    
    # Get current rules and compare against desired state
    # We parse the simple 'ufw status' output which is more reliable
    RULES_TO_DELETE=()
    
    while IFS= read -r line; do
        # Skip header lines and empty lines
        [[ "$line" =~ ^To|^--|^Status|^$ ]] && continue
        
        # Parse lines like: "50000:50019/tcp            ALLOW       Anywhere"
        # or: "22/tcp                     ALLOW       Anywhere                   # SSH"
        # Extract port/protocol and action
        if [[ "$line" =~ ^([0-9:]+)/([a-z]+)[[:space:]]+(ALLOW|DENY) ]]; then
            port="${BASH_REMATCH[1]}"
            protocol="${BASH_REMATCH[2]}"
            action="${BASH_REMATCH[3]}"
            port_proto="${port}/${protocol}"
            
            # Skip IPv6 rules (they have "(v6)" in them) - we handle them separately
            [[ "$line" =~ \(v6\) ]] && continue
            
            # Check if this is a protected port
            is_protected=false
            for protected in "${PROTECTED_PORTS[@]}"; do
                if [[ "$port_proto" == "$protected" || "$port" == "$protected" ]]; then
                    is_protected=true
                    break
                fi
            done
            
            if [ "$is_protected" = "true" ]; then
                echo "  🔒 Protected: ${port_proto} (${action}) - will not remove"
                continue
            fi
            
            # Check if rule is in our desired state
            if [ "$action" = "ALLOW" ]; then
                if [ -z "${DESIRED_ALLOW_RULES[$port_proto]}" ]; then
                    echo "  🗑️  Stale ALLOW rule: ${port_proto} - marked for removal"
                    RULES_TO_DELETE+=("$port_proto:ALLOW")
                fi
            elif [ "$action" = "DENY" ]; then
                if [ -z "${DESIRED_DENY_RULES[$port_proto]}" ]; then
                    echo "  🗑️  Stale DENY rule: ${port_proto} - marked for removal"
                    RULES_TO_DELETE+=("$port_proto:DENY")
                fi
            fi
        fi
    done < <($SUDO ufw status 2>/dev/null)
    
    # Delete stale rules
    if [ ${#RULES_TO_DELETE[@]} -gt 0 ]; then
        echo ""
        echo "Removing ${#RULES_TO_DELETE[@]} stale rule(s)..."
        for rule in "${RULES_TO_DELETE[@]}"; do
            port_proto="${rule%:*}"
            action="${rule##*:}"
            if [ "$DRY_RUN" = "true" ]; then
                echo "  [DRY RUN] Would delete: ${action} ${port_proto}"
            else
                echo "  Deleting: ${action} ${port_proto}"
                # Use --force to avoid confirmation prompts, try both with and without 'IN'
                if [ "$action" = "ALLOW" ]; then
                    $SUDO ufw --force delete allow "${port_proto}" 2>/dev/null || \
                    $SUDO ufw --force delete allow in "${port_proto}" 2>/dev/null || true
                else
                    $SUDO ufw --force delete deny "${port_proto}" 2>/dev/null || \
                    $SUDO ufw --force delete deny in "${port_proto}" 2>/dev/null || true
                fi
            fi
        done
        echo "✓ Stale rules removed"
    else
        echo "✓ No stale rules found"
    fi
    echo ""
fi

# =============================================================================
# STEP 2: Enable ufw if not already enabled
# =============================================================================
echo "Step 2: Ensuring ufw is enabled..."
if ! $SUDO ufw status | grep -q "Status: active"; then
    if [ "$DRY_RUN" = "true" ]; then
        echo "  [DRY RUN] Would enable ufw"
    else
        echo "  Enabling ufw..."
        echo "y" | $SUDO ufw --force enable
    fi
fi
echo "✓ ufw is active"
echo ""

# =============================================================================
# STEP 3: Add/verify required ALLOW rules
# =============================================================================
echo "Step 3: Configuring required ALLOW rules..."
for port_config in "${PORTS[@]}"; do
    IFS=':' read -ra parts <<< "$port_config"
    
    if [[ "${parts[1]}" =~ ^[0-9]+$ ]]; then
        # Port range format
        port_start="${parts[0]}"
        port_end="${parts[1]}"
        protocol="${parts[2]}"
        description="${parts[3]}"
        port_range="${port_start}:${port_end}"
        
        if $SUDO ufw status | grep -q "${port_range}/${protocol}"; then
            echo "  ✓ ${port_range}/${protocol} (${description}) - already configured"
        else
            if [ "$DRY_RUN" = "true" ]; then
                echo "  [DRY RUN] Would add: ${port_range}/${protocol} (${description})"
            else
                echo "  + Adding ${port_range}/${protocol} (${description})..."
                $SUDO ufw allow ${port_range}/${protocol} comment "${description}"
            fi
        fi
    else
        # Single port format
        port="${parts[0]}"
        protocol="${parts[1]}"
        description="${parts[2]}"
        
        if $SUDO ufw status | grep -q "${port}/${protocol}.*ALLOW"; then
            echo "  ✓ ${port}/${protocol} (${description}) - already configured"
        else
            if [ "$DRY_RUN" = "true" ]; then
                echo "  [DRY RUN] Would add: ${port}/${protocol} (${description})"
            else
                echo "  + Adding ${port}/${protocol} (${description})..."
                $SUDO ufw allow ${port}/${protocol} comment "${description}"
            fi
        fi
    fi
done
echo ""

# =============================================================================
# STEP 4: Add/verify required DENY rules
# =============================================================================
echo "Step 4: Configuring required DENY rules..."
for deny_config in "${DENY_PORTS[@]}"; do
    IFS=':' read -ra parts <<< "$deny_config"
    port="${parts[0]}"
    protocol="${parts[1]}"
    description="${parts[2]}"
    
    if $SUDO ufw status | grep -qE "${port}/${protocol}.*DENY"; then
        echo "  ✓ ${port}/${protocol} (${description}) - already denied"
    else
        if [ "$DRY_RUN" = "true" ]; then
            echo "  [DRY RUN] Would deny: ${port}/${protocol} (${description})"
        else
            echo "  + Denying ${port}/${protocol} (${description})..."
            $SUDO ufw deny ${port}/${protocol} comment "${description}"
        fi
    fi
done
echo ""

# =============================================================================
# STEP 5: Ensure explicit iptables rules (safety net for DROP policy)
# =============================================================================
# When default iptables policy is DROP, ufw rules may not be sufficient.
# Add explicit ACCEPT rules at the beginning of INPUT chain as a safety measure.
# This ensures ports are accessible even if ufw integration has issues.
echo "Step 5: Ensuring explicit iptables rules for critical ports..."
CRITICAL_PORTS=("${FTP_CONTROL}" "${SFTP_PORT}" "${FTP_PASSIVE_MIN}:${FTP_PASSIVE_MAX}")
if [ -n "$FTPS_ALT" ]; then
    CRITICAL_PORTS+=("$FTPS_ALT")
fi

for port in "${CRITICAL_PORTS[@]}"; do
    # Check if rule already exists (anywhere in the chain)
    if $SUDO iptables -C INPUT -p tcp --dport ${port} -j ACCEPT 2>/dev/null; then
        echo "  ✓ iptables rule for ${port}/tcp already exists"
    else
        if [ "$DRY_RUN" = "true" ]; then
            echo "  [DRY RUN] Would add iptables rule: ${port}/tcp"
        else
            echo "  + Adding explicit iptables rule for ${port}/tcp..."
            # Insert at position 1 to ensure it's before ufw chains
            $SUDO iptables -I INPUT 1 -p tcp --dport ${port} -j ACCEPT
        fi
    fi
done

# Save iptables rules if iptables-persistent is available
if command -v iptables-save >/dev/null 2>&1; then
    if [ -d "/etc/iptables" ]; then
        if [ "$DRY_RUN" = "true" ]; then
            echo "  [DRY RUN] Would save iptables rules to /etc/iptables/rules.v4"
        else
            echo "  Saving iptables rules..."
            $SUDO mkdir -p /etc/iptables
            $SUDO iptables-save | $SUDO tee /etc/iptables/rules.v4 >/dev/null || true
        fi
    fi
fi

echo "✓ iptables rules verified"
echo ""

# =============================================================================
# STEP 6: FTPS alternate control port — NAT REDIRECT in UFW before*.rules
# =============================================================================
# Only reconcile when airports.json was readable (avoids clearing NAT if config path is wrong).
NAT_SCRIPT="$SCRIPT_DIR/production-ftps-alt-port-nat.sh"
if [ "$AIRPORTS_CONFIG_READABLE" = true ] && [ -f "$NAT_SCRIPT" ]; then
    echo "Step 6: FTPS alternate control port (NAT redirect)..."
    chmod +x "$NAT_SCRIPT" 2>/dev/null || true
    if [ "$DRY_RUN" = "true" ]; then
        if [ -n "$FTPS_ALT" ]; then
            echo "  [DRY RUN] Would run: $SUDO env VSFTPD_LISTEN_PORT=${FTP_CONTROL} $NAT_SCRIPT ensure $FTPS_ALT"
        else
            echo "  [DRY RUN] Would run: $SUDO env VSFTPD_LISTEN_PORT=${FTP_CONTROL} $NAT_SCRIPT ensure '' (clear NAT)"
        fi
    else
        if [ -n "$FTPS_ALT" ]; then
            $SUDO env VSFTPD_LISTEN_PORT="${FTP_CONTROL}" "$NAT_SCRIPT" ensure "$FTPS_ALT"
        else
            $SUDO env VSFTPD_LISTEN_PORT="${FTP_CONTROL}" "$NAT_SCRIPT" ensure ""
        fi
    fi
    echo ""
elif [ "$AIRPORTS_CONFIG_READABLE" = false ]; then
    echo "Step 6: Skipped NAT redirect reconcile (airports.json not found at ${AIRPORTS_JSON})"
    echo ""
else
    echo "⚠️  Step 6: $NAT_SCRIPT not found — NAT redirect not reconciled"
    echo ""
fi

# =============================================================================
# FINAL: Show current status
# =============================================================================
echo "=============================================="
echo "Final Firewall Status:"
echo "=============================================="
$SUDO ufw status numbered

echo ""
echo "✓ Firewall configuration complete"

