#!/bin/bash
# Diagnostic commands for FTP/FTPS upload daemon (ProFTPD) on production.

set -euo pipefail

COMPOSE="docker compose -f docker/docker-compose.prod.yml"

echo "=========================================="
echo "Upload FTP/FTPS Production Diagnostics"
echo "=========================================="
echo ""

echo "1. ProFTPD process and config syntax:"
echo "   $COMPOSE exec web pgrep -a proftpd"
echo "   $COMPOSE exec web proftpd -t -c /etc/proftpd/proftpd.conf"
echo ""

echo "2. Runtime ports (conf.d/runtime.conf) and endpoint cache:"
echo "   $COMPOSE exec web grep -E '^(Port|PassivePorts|MaxInstances)' /etc/proftpd/conf.d/runtime.conf"
echo "   $COMPOSE exec web cat /var/lib/aviationwx/upload-endpoints.json"
echo "   $COMPOSE exec web cat /etc/proftpd/conf.d/masquerade.conf"
echo ""

echo "3. TLS configuration (conf.d/tls.conf):"
echo "   $COMPOSE exec web grep -E 'TLSEngine|TLSRSACertificate' /etc/proftpd/conf.d/tls.conf"
echo ""

echo "4. Certificate files:"
echo "   $COMPOSE exec web ls -la /etc/letsencrypt/live/aviationwx.org/"
echo "   $COMPOSE exec web openssl x509 -in /etc/letsencrypt/live/aviationwx.org/fullchain.pem -noout -dates"
echo ""

echo "5. Auth user count (no secrets):"
echo "   $COMPOSE exec web wc -l /etc/proftpd/ftpd.passwd"
echo ""

echo "6. ProFTPD log (PASV/EPSV responses):"
echo "   $COMPOSE exec web tail -100 /var/log/proftpd.log"
echo ""

echo "7. fail2ban proftpd jail:"
echo "   $COMPOSE exec web fail2ban-client status proftpd"
echo ""

echo "8. Local PASV validation (inside container):"
echo "   $COMPOSE exec web bash /var/www/html/scripts/validate-upload-daemon.sh"
echo ""

echo "9. Enable TLS after cert renewal:"
echo "   $COMPOSE exec web /usr/local/bin/enable-upload-ftps.sh"
echo ""

echo "=========================================="
echo "Quick diagnostic (run inside container):"
echo "=========================================="
echo ""
cat <<'EOF'
docker compose -f docker/docker-compose.prod.yml exec web bash -c '
  echo "=== ProFTPD ==="
  pgrep -a proftpd || echo "not running"
  proftpd -t -c /etc/proftpd/proftpd.conf && echo "config syntax OK"
  echo ""
  echo "=== Runtime ==="
  cat /etc/proftpd/conf.d/runtime.conf
  echo ""
  echo "=== TLS ==="
  grep -E "TLSEngine|TLSRSA" /etc/proftpd/conf.d/tls.conf
  echo ""
  echo "=== Auth users ==="
  wc -l /etc/proftpd/ftpd.passwd
  echo ""
  echo "=== Recent log ==="
  tail -20 /var/log/proftpd.log
'
EOF
