#!/bin/bash
# Configure firewall ports for all production services
# This script is DECLARATIVE - it ensures the firewall matches the desired state
# by adding missing rules AND removing stale/orphaned rules.

set -e

# =============================================================================
# DESIRED STATE: Define all ports that SHOULD be open
# =============================================================================
# Format: PORT:PROTOCOL:DESCRIPTION
# Note: SSH (22) is PROTECTED and will never be removed
PORTS=(
    "80:tcp:HTTP (Nginx)"
    "443:tcp:HTTPS (Nginx)"
    "2121:tcp:FTP (Push webcams)"
    "2122:tcp:FTPS (Push webcams - explicit TLS)"
    "2222:tcp:SFTP (Push webcams)"
    "50000:51000:tcp:FTP passive mode (Push webcams)"
    "22:tcp:SSH (System access)"
)

# Ports to explicitly deny (internal services that should not be publicly accessible)
DENY_PORTS=(
    "8080:tcp:Apache (internal, behind nginx - should only be accessible from localhost)"
)

# Protected ports that should NEVER be removed (safety net)
PROTECTED_PORTS=("22/tcp" "22")

# =============================================================================
# CONFIGURATION
# =============================================================================
# Set to "true" to enable cleanup of stale rules (recommended for production)
CLEANUP_STALE_RULES="${CLEANUP_STALE_RULES:-true}"

# Set to "true" for dry-run mode (shows what would be done without making changes)
DRY_RUN="${DRY_RUN:-false}"

echo "=============================================="
echo "Firewall Configuration (Declarative Mode)"
echo "=============================================="
echo "Cleanup stale rules: ${CLEANUP_STALE_RULES}"
echo "Dry run: ${DRY_RUN}"
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
# FINAL: Show current status
# =============================================================================
echo "=============================================="
echo "Final Firewall Status:"
echo "=============================================="
$SUDO ufw status numbered

# =============================================================================
# STEP 5: Ensure explicit iptables rules (safety net for DROP policy)
# =============================================================================
# When default iptables policy is DROP, ufw rules may not be sufficient.
# Add explicit ACCEPT rules at the beginning of INPUT chain as a safety measure.
# This ensures ports are accessible even if ufw integration has issues.
echo "Step 5: Ensuring explicit iptables rules for critical ports..."
CRITICAL_PORTS=("2121" "2222" "50000:51000")

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
# FINAL: Show current status
# =============================================================================
echo "=============================================="
echo "Final Firewall Status:"
echo "=============================================="
$SUDO ufw status numbered

echo ""
echo "✓ Firewall configuration complete"

