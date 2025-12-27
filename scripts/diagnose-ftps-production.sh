#!/bin/bash
# Diagnostic commands for FTPS/TLS on production
# Run these commands on the production server to diagnose FTPS issues

set -e

echo "=========================================="
echo "FTPS/TLS Production Diagnostic Commands"
echo "=========================================="
echo ""

echo "1. Check if SSL is enabled in vsftpd config:"
echo "   docker compose -f docker/docker-compose.prod.yml exec web grep '^ssl_enable=' /etc/vsftpd/vsftpd.conf"
echo ""

echo "2. Check certificate paths in vsftpd config:"
echo "   docker compose -f docker/docker-compose.prod.yml exec web grep '^rsa_cert_file=' /etc/vsftpd/vsftpd.conf"
echo "   docker compose -f docker/docker-compose.prod.yml exec web grep '^rsa_private_key_file=' /etc/vsftpd/vsftpd.conf"
echo ""

echo "3. Check if certificates exist at the configured paths:"
echo "   docker compose -f docker/docker-compose.prod.yml exec web ls -la /etc/letsencrypt/live/aviationwx.org/"
echo "   docker compose -f docker/docker-compose.prod.yml exec web test -f /etc/letsencrypt/live/aviationwx.org/fullchain.pem && echo 'Certificate exists' || echo 'Certificate missing'"
echo "   docker compose -f docker/docker-compose.prod.yml exec web test -f /etc/letsencrypt/live/aviationwx.org/privkey.pem && echo 'Private key exists' || echo 'Private key missing'"
echo ""

echo "4. Check certificate validity and expiration:"
echo "   docker compose -f docker/docker-compose.prod.yml exec web openssl x509 -in /etc/letsencrypt/live/aviationwx.org/fullchain.pem -noout -text | grep -A 2 'Subject Alternative Name'"
echo "   docker compose -f docker/docker-compose.prod.yml exec web openssl x509 -in /etc/letsencrypt/live/aviationwx.org/fullchain.pem -noout -dates"
echo ""

echo "5. Check certificate permissions:"
echo "   docker compose -f docker/docker-compose.prod.yml exec web ls -la /etc/letsencrypt/live/aviationwx.org/fullchain.pem"
echo "   docker compose -f docker/docker-compose.prod.yml exec web ls -la /etc/letsencrypt/live/aviationwx.org/privkey.pem"
echo ""

echo "6. Check vsftpd process status:"
echo "   docker compose -f docker/docker-compose.prod.yml exec web ps aux | grep vsftpd"
echo "   docker compose -f docker/docker-compose.prod.yml exec web pgrep -a vsftpd"
echo ""

echo "7. Check vsftpd logs for SSL/TLS errors:"
echo "   docker compose -f docker/docker-compose.prod.yml logs web | grep -i ssl | tail -20"
echo "   docker compose -f docker/docker-compose.prod.yml logs web | grep -i tls | tail -20"
echo "   docker compose -f docker/docker-compose.prod.yml logs web | grep -i vsftpd | tail -20"
echo ""

echo "8. Check vsftpd configuration syntax:"
echo "   docker compose -f docker/docker-compose.prod.yml exec web vsftpd -olisten=NO /etc/vsftpd/vsftpd.conf 2>&1 | head -10"
echo ""

echo "9. Check TLS version settings:"
echo "   docker compose -f docker/docker-compose.prod.yml exec web grep -E '^ssl_tlsv|^# ssl_tlsv' /etc/vsftpd/vsftpd.conf"
echo ""

echo "10. Check if vsftpd is listening on port 2121 (IPv4 and IPv6):"
echo "    docker compose -f docker/docker-compose.prod.yml exec web ss -tlnp | grep 2121"
echo ""

echo "11. Test certificate can be read by vsftpd user:"
echo "    docker compose -f docker/docker-compose.prod.yml exec web whoami"
echo "    docker compose -f docker/docker-compose.prod.yml exec web test -r /etc/letsencrypt/live/aviationwx.org/fullchain.pem && echo 'Certificate is readable' || echo 'Certificate is NOT readable'"
echo "    docker compose -f docker/docker-compose.prod.yml exec web test -r /etc/letsencrypt/live/aviationwx.org/privkey.pem && echo 'Private key is readable' || echo 'Private key is NOT readable'"
echo ""

echo "12. Check full vsftpd config for SSL-related settings:"
echo "    docker compose -f docker/docker-compose.prod.yml exec web grep -E 'ssl_|tls_' /etc/vsftpd/vsftpd.conf | grep -v '^#'"
echo ""

echo "13. Check container entrypoint logs for SSL enablement:"
echo "    docker compose -f docker/docker-compose.prod.yml logs web | grep -i 'ssl\|certificate' | tail -30"
echo ""

echo "14. Manually test enabling SSL:"
echo "    docker compose -f docker/docker-compose.prod.yml exec web /usr/local/bin/enable-vsftpd-ssl.sh"
echo ""

echo "=========================================="
echo "Quick Diagnostic (run all at once):"
echo "=========================================="
echo ""
echo "docker compose -f docker/docker-compose.prod.yml exec web bash -c '"
echo "  echo \"=== SSL Config ===\""
echo "  grep \"^ssl_enable=\" /etc/vsftpd/vsftpd.conf"
echo "  echo \"\""
echo "  echo \"=== Certificate Paths ===\""
echo "  grep \"^rsa_cert_file=\" /etc/vsftpd/vsftpd.conf"
echo "  grep \"^rsa_private_key_file=\" /etc/vsftpd/vsftpd.conf"
echo "  echo \"\""
echo "  echo \"=== Certificate Files ===\""
echo "  ls -la /etc/letsencrypt/live/aviationwx.org/ 2>&1"
echo "  echo \"\""
echo "  echo \"=== Certificate Validity ===\""
echo "  openssl x509 -in /etc/letsencrypt/live/aviationwx.org/fullchain.pem -noout -subject -dates 2>&1"
echo "  echo \"\""
echo "  echo \"=== vsftpd Process ===\""
echo "  ps aux | grep vsftpd | grep -v grep"
echo "  echo \"\""
echo "  echo \"=== TLS Versions ===\""
echo "  grep -E \"^ssl_tlsv\" /etc/vsftpd/vsftpd.conf"
echo "'"
echo ""
