#!/bin/bash
#
# Optional production helper: netfilter NAT REDIRECT from an alternate inbound TCP port
# to vsftpd's control port (VSFTPD_LISTEN_PORT, default 2121). Preserves the real
# client source IP (unlike a userspace TCP proxy to 127.0.0.1).
#
# Persistence: rules are written into UFW's before.rules / before6.rules so they
# survive `ufw reload`. Remove with the `remove` subcommand.
#
# Usage:
#   sudo ./scripts/production-ftps-alt-port-nat.sh install 8021
#   sudo ./scripts/production-ftps-alt-port-nat.sh ensure 8021   # idempotent (CD)
#   sudo ./scripts/production-ftps-alt-port-nat.sh ensure ''     # clear NAT rules
#   sudo ./scripts/production-ftps-alt-port-nat.sh status
#   sudo ./scripts/production-ftps-alt-port-nat.sh remove
#
# Requirements: ufw, awk, cp. Run on the production host (not inside Docker).
# See docs/OPERATIONS.md for context and rollback.

set -euo pipefail

# REDIRECT target is vsftpd's listen port; production sets VSFTPD_LISTEN_PORT from config.network_ports.ftp_control.
VSFTPD_LISTEN_PORT="${VSFTPD_LISTEN_PORT:-2121}"

MARKER_BEGIN='# BEGIN AVIATIONWX_FTPS_ALT_NAT'
MARKER_END='# END AVIATIONWX_FTPS_ALT_NAT'
BEFORE_RULES='/etc/ufw/before.rules'
BEFORE6_RULES='/etc/ufw/before6.rules'

usage() {
    echo "Usage: $0 install <alt_port> | ensure [<alt_port>] | remove | status" >&2
    echo "  install <port>  Insert NAT REDIRECT (IPv4+IPv6) alt_port -> ${VSFTPD_LISTEN_PORT} (VSFTPD_LISTEN_PORT) in UFW before*.rules" >&2
    echo "  ensure [port]   Idempotent: set redirect to port, or clear if port empty (deployments)" >&2
    echo "  remove          Delete the marked block from before*.rules and reload ufw" >&2
    echo "  status          Show whether the marker is present and list nat PREROUTING redirects" >&2
    exit 1
}

validate_port() {
    local p="$1"
    if ! [[ "$p" =~ ^[0-9]+$ ]] || [ "$p" -lt 1 ] || [ "$p" -gt 65535 ]; then
        echo "❌ Invalid port: $p" >&2
        exit 1
    fi
    if [ "$p" -eq "$VSFTPD_LISTEN_PORT" ]; then
        echo "❌ Alt port must differ from vsftpd listen port (${VSFTPD_LISTEN_PORT})." >&2
        exit 1
    fi
}

require_root() {
    if [ "${EUID:-}" -ne 0 ]; then
        echo "❌ Run as root (e.g. sudo $0 ...)" >&2
        exit 1
    fi
}

file_has_nat_table() {
    grep -q '^\*nat$' "$1"
}

# UFW rule files must keep their original owner/mode after mktemp+mv (GNU coreutils; production host is Linux).
apply_file_metadata_from() {
    local src="$1"
    local dst="$2"
    if chown --reference="$src" "$dst" 2>/dev/null; then
        :
    else
        chown "$(stat -c '%u:%g' "$src")" "$dst"
    fi
    if chmod --reference="$src" "$dst" 2>/dev/null; then
        :
    else
        chmod "$(stat -c '%a' "$src")" "$dst"
    fi
}

# Ensures we never IPv4-only half-install when IPv6 rules file exists but cannot be edited.
assert_prereq_nat_tables() {
    if ! file_has_nat_table "$BEFORE_RULES"; then
        echo "❌ No *nat table in $BEFORE_RULES — add NAT REDIRECT manually (see docs/OPERATIONS.md)." >&2
        exit 1
    fi
    if [ -f "$BEFORE6_RULES" ] && ! file_has_nat_table "$BEFORE6_RULES"; then
        echo "❌ $BEFORE6_RULES exists but has no *nat table; refusing to change IPv4 only." >&2
        exit 1
    fi
}

insert_redirect_block() {
    local file="$1"
    local alt_port="$2"
    local begin="${MARKER_BEGIN} (${alt_port}->${VSFTPD_LISTEN_PORT})"
    local rule="-A PREROUTING -p tcp --dport ${alt_port} -j REDIRECT --to-ports ${VSFTPD_LISTEN_PORT}"

    if ! file_has_nat_table "$file"; then
        echo "❌ No *nat table in $file — add NAT REDIRECT manually (see docs/OPERATIONS.md)." >&2
        return 1
    fi

    # Write to a temp file first, then mv, so a partial awk run cannot truncate the live rules.
    local tmp
    tmp="$(mktemp)"
    awk -v begin="$begin" -v end="$MARKER_END" -v rule="$rule" '
        /^\*nat$/ { in_nat = 1 }
        in_nat && /^COMMIT$/ {
            if (!done) {
                print begin
                print rule
                print end
                done = 1
            }
            in_nat = 0
        }
        { print }
    ' "$file" > "$tmp"
    apply_file_metadata_from "$file" "$tmp"
    mv "$tmp" "$file"
    echo "✓ Updated $file"
}

# Prints "alt target" from the marker line (e.g. "8021 2121"), or empty.
extract_nat_ports_from_file() {
    local file="$1"
    if [ ! -f "$file" ]; then
        echo ""
        return 0
    fi
    local line
    line=$(grep -F "${MARKER_BEGIN}" "$file" 2>/dev/null | head -n1) || true
    if [ -z "$line" ]; then
        echo ""
        return 0
    fi
    sed -n 's/.*(\([0-9][0-9]*\)->\([0-9][0-9]*\)).*/\1 \2/p' <<<"$line"
}

cmd_ensure() {
    local want="${1:-}"
    require_root

    if [ ! -f "$BEFORE_RULES" ]; then
        echo "❌ $BEFORE_RULES not found (is ufw installed?)" >&2
        exit 1
    fi

    if [ -z "$want" ]; then
        remove_marked_block "$BEFORE_RULES"
        if [ -f "$BEFORE6_RULES" ]; then
            remove_marked_block "$BEFORE6_RULES"
        fi
        ufw reload
        echo "✓ FTPS NAT redirect cleared (no alternate port configured)"
        return 0
    fi

    validate_port "$want"
    assert_prereq_nat_tables

    local cur_v4 cur_v6 cur_alt4 cur_tgt4 cur_alt6 cur_tgt6
    cur_v4=$(extract_nat_ports_from_file "$BEFORE_RULES")
    cur_alt4="${cur_v4%% *}"
    cur_tgt4="${cur_v4##* }"
    cur_v6=""
    cur_alt6=""
    cur_tgt6=""
    if [ -f "$BEFORE6_RULES" ]; then
        cur_v6=$(extract_nat_ports_from_file "$BEFORE6_RULES")
        cur_alt6="${cur_v6%% *}"
        cur_tgt6="${cur_v6##* }"
    fi

    local need_update=true
    if [ -n "$cur_alt4" ] && [ "$cur_alt4" = "$want" ] && [ "$cur_tgt4" = "$VSFTPD_LISTEN_PORT" ]; then
        if [ ! -f "$BEFORE6_RULES" ]; then
            need_update=false
        elif [ -n "$cur_alt6" ] && [ "$cur_alt6" = "$want" ] && [ "$cur_tgt6" = "$VSFTPD_LISTEN_PORT" ]; then
            need_update=false
        fi
    fi
    if [ "$need_update" = false ]; then
        echo "✓ FTPS NAT redirect already set to ${want} -> ${VSFTPD_LISTEN_PORT}"
        return 0
    fi

    if [ -n "$cur_v4" ] || [ -n "$cur_v6" ]; then
        echo "Applying FTPS NAT redirect ${want} -> ${VSFTPD_LISTEN_PORT}..."
        remove_marked_block "$BEFORE_RULES"
        if [ -f "$BEFORE6_RULES" ]; then
            remove_marked_block "$BEFORE6_RULES"
        fi
    else
        echo "Adding FTPS NAT redirect ${want} -> ${VSFTPD_LISTEN_PORT}..."
    fi

    cp -a "$BEFORE_RULES" "${BEFORE_RULES}.bak.$(date +%Y%m%d%H%M%S)"
    insert_redirect_block "$BEFORE_RULES" "$want"

    if [ -f "$BEFORE6_RULES" ]; then
        cp -a "$BEFORE6_RULES" "${BEFORE6_RULES}.bak.$(date +%Y%m%d%H%M%S)"
        insert_redirect_block "$BEFORE6_RULES" "$want"
    else
        echo "⚠️  $BEFORE6_RULES not found — IPv6 redirect not added."
    fi

    ufw reload
    echo "✓ FTPS NAT redirect ensured: ${want} -> ${VSFTPD_LISTEN_PORT}"
}

remove_marked_block() {
    local file="$1"
    if [ ! -f "$file" ]; then
        echo "  (skip missing $file)"
        return 0
    fi
    if ! grep -qF "$MARKER_BEGIN" "$file"; then
        echo "  (no AVIATIONWX block in $file)"
        return 0
    fi
    cp -a "$file" "${file}.bak.$(date +%Y%m%d%H%M%S)"
    local tmp
    tmp="$(mktemp)"
    awk -v begin="$MARKER_BEGIN" -v end="$MARKER_END" '
        index($0, begin) == 1 { skip = 1; next }
        index($0, end) == 1 { skip = 0; next }
        skip == 0 { print }
    ' "$file" > "$tmp"
    apply_file_metadata_from "$file" "$tmp"
    mv "$tmp" "$file"
    echo "✓ Removed AVIATIONWX FTPS alt block from $file"
}

cmd_install() {
    local alt_port="${1:-}"
    [ -n "$alt_port" ] || usage
    validate_port "$alt_port"
    require_root

    if [ ! -f "$BEFORE_RULES" ]; then
        echo "❌ $BEFORE_RULES not found (is ufw installed?)" >&2
        exit 1
    fi

    assert_prereq_nat_tables

    if grep -qF "$MARKER_BEGIN" "$BEFORE_RULES" \
        || { [ -f "$BEFORE6_RULES" ] && grep -qF "$MARKER_BEGIN" "$BEFORE6_RULES"; }; then
        echo "❌ AVIATIONWX FTPS alt block already present. Run: $0 remove" >&2
        exit 1
    fi

    cp -a "$BEFORE_RULES" "${BEFORE_RULES}.bak.$(date +%Y%m%d%H%M%S)"
    insert_redirect_block "$BEFORE_RULES" "$alt_port"

    if [ -f "$BEFORE6_RULES" ]; then
        cp -a "$BEFORE6_RULES" "${BEFORE6_RULES}.bak.$(date +%Y%m%d%H%M%S)"
        insert_redirect_block "$BEFORE6_RULES" "$alt_port"
    else
        echo "⚠️  $BEFORE6_RULES not found — IPv6 redirect not added."
    fi

    echo "Reloading ufw..."
    ufw reload
    echo ""
    echo "Done. Cameras can use FTPS/FTP control port ${alt_port} (same host and credentials as port ${VSFTPD_LISTEN_PORT})."
    echo "Passive data ports follow config.network_ports.ftp_passive_min/max on the host (defaults 50000–51000)."
    echo "CD: set config.network_ports.ftps_alt; deploy-configure-firewall.sh allows the port and runs ensure each deploy."
}

cmd_remove() {
    require_root
    if [ ! -f "$BEFORE_RULES" ]; then
        echo "❌ $BEFORE_RULES not found (is ufw installed?)" >&2
        exit 1
    fi
    remove_marked_block "$BEFORE_RULES"
    if [ -f "$BEFORE6_RULES" ]; then
        remove_marked_block "$BEFORE6_RULES"
    fi
    ufw reload
    echo "✓ ufw reloaded"
}

cmd_status() {
    if [ "${EUID:-}" -ne 0 ]; then
        echo "⚠️  Not root: iptables/ip6tables listings below may be empty (use sudo for full output)." >&2
    fi
    echo "=== Marker in UFW rules files ==="
    if [ -f "$BEFORE_RULES" ]; then
        grep -n 'AVIATIONWX_FTPS_ALT' "$BEFORE_RULES" || echo "  (none in before.rules)"
    else
        echo "  (missing $BEFORE_RULES)"
    fi
    if [ -f "$BEFORE6_RULES" ]; then
        grep -n 'AVIATIONWX_FTPS_ALT' "$BEFORE6_RULES" || echo "  (none in before6.rules)"
    else
        echo "  (missing $BEFORE6_RULES)"
    fi
    echo ""
    echo "=== iptables nat PREROUTING (IPv4, first 40 lines) ==="
    # stderr hidden: permission denied without root is expected when not using sudo.
    iptables -t nat -L PREROUTING -n -v 2>/dev/null | head -40 || echo "  (cannot list iptables)"
    echo ""
    echo "=== ip6tables nat PREROUTING (IPv6, first 40 lines) ==="
    ip6tables -t nat -L PREROUTING -n -v 2>/dev/null | head -40 || echo "  (cannot list ip6tables)"
}

case "${1:-}" in
    install)
        cmd_install "${2:-}"
        ;;
    ensure)
        cmd_ensure "${2:-}"
        ;;
    remove)
        cmd_remove
        ;;
    status)
        cmd_status
        ;;
    -h|--help|help)
        usage
        ;;
    *)
        usage
        ;;
esac
