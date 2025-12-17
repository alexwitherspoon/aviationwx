#!/bin/bash
# Fix certificate permissions and validate for FTPS
# Run this on the production server

set -e

echo "=== Fixing FTPS Certificate Permissions ==="
echo ""

# Check if running in container
if [ -f /.dockerenv ] || grep -qa docker /proc/1/cgroup 2>/dev/null; then
    echo "Running inside container"
    CONTAINER_MODE=true
else
    echo "Running on host - will use docker compose"
    CONTAINER_MODE=false
fi

if [ "$CONTAINER_MODE" = true ]; then
    CERT_DIR="/etc/letsencrypt/live/aviationwx.org"
    CERT_FILE="${CERT_DIR}/fullchain.pem"
    KEY_FILE="${CERT_DIR}/privkey.pem"
    
    echo "1. Checking certificate files..."
    ls -la "$CERT_DIR/"
    echo ""
    
    echo "2. Checking if symlinks are valid..."
    readlink -f "$CERT_FILE" || echo "Certificate symlink broken"
    readlink -f "$KEY_FILE" || echo "Private key symlink broken"
    echo ""
    
    echo "3. Checking actual file locations..."
    REAL_CERT=$(readlink -f "$CERT_FILE" 2>/dev/null || echo "")
    REAL_KEY=$(readlink -f "$KEY_FILE" 2>/dev/null || echo "")
    
    if [ -n "$REAL_CERT" ]; then
        echo "Certificate file: $REAL_CERT"
        ls -la "$REAL_CERT"
    fi
    
    if [ -n "$REAL_KEY" ]; then
        echo "Private key file: $REAL_KEY"
        ls -la "$REAL_KEY"
    fi
    echo ""
    
    echo "4. Testing certificate validity..."
    if [ -f "$CERT_FILE" ]; then
        openssl x509 -in "$CERT_FILE" -noout -text >/dev/null 2>&1 && echo "✓ Certificate is valid" || echo "✗ Certificate is invalid"
    else
        echo "✗ Certificate file not found"
    fi
    echo ""
    
    echo "5. Testing private key validity..."
    if [ -f "$KEY_FILE" ]; then
        openssl rsa -in "$KEY_FILE" -check -noout >/dev/null 2>&1 && echo "✓ Private key is valid" || echo "✗ Private key is invalid"
        echo "Testing with different methods..."
        openssl pkey -in "$KEY_FILE" -noout >/dev/null 2>&1 && echo "  ✓ openssl pkey works" || echo "  ✗ openssl pkey fails"
        openssl rsa -in "$KEY_FILE" -noout >/dev/null 2>&1 && echo "  ✓ openssl rsa works" || echo "  ✗ openssl rsa fails"
    else
        echo "✗ Private key file not found"
    fi
    echo ""
    
    echo "6. Checking file permissions..."
    echo "Certificate:"
    ls -la "$CERT_FILE" 2>&1 || echo "Cannot access certificate"
    echo "Private key:"
    ls -la "$KEY_FILE" 2>&1 || echo "Cannot access private key"
    echo ""
    
    echo "7. Testing file readability..."
    test -r "$CERT_FILE" && echo "✓ Certificate is readable" || echo "✗ Certificate is NOT readable"
    test -r "$KEY_FILE" && echo "✓ Private key is readable" || echo "✗ Private key is NOT readable"
    echo ""
    
    echo "8. Checking file content (first few bytes)..."
    echo "Certificate (first 50 chars):"
    head -c 50 "$CERT_FILE" 2>/dev/null | cat -A || echo "Cannot read certificate"
    echo ""
    echo "Private key (first 50 chars):"
    head -c 50 "$KEY_FILE" 2>/dev/null | cat -A || echo "Cannot read private key"
    echo ""
    
else
    echo "Run these commands inside the container:"
    echo ""
    echo "docker compose -f docker/docker-compose.prod.yml exec web bash -c '"
    echo "  CERT_DIR=\"/etc/letsencrypt/live/aviationwx.org\""
    echo "  CERT_FILE=\"\$CERT_DIR/fullchain.pem\""
    echo "  KEY_FILE=\"\$CERT_DIR/privkey.pem\""
    echo "  echo \"=== Certificate Files ===\""
    echo "  ls -la \$CERT_DIR/"
    echo "  echo \"\""
    echo "  echo \"=== Symlink Targets ===\""
    echo "  readlink -f \$CERT_FILE"
    echo "  readlink -f \$KEY_FILE"
    echo "  echo \"\""
    echo "  echo \"=== Certificate Validity ===\""
    echo "  openssl x509 -in \$CERT_FILE -noout -text >/dev/null 2>&1 && echo \"Certificate valid\" || echo \"Certificate invalid\""
    echo "  echo \"\""
    echo "  echo \"=== Private Key Validity ===\""
    echo "  openssl rsa -in \$KEY_FILE -check -noout >/dev/null 2>&1 && echo \"Private key valid\" || echo \"Private key invalid\""
    echo "  echo \"\""
    echo "  echo \"=== File Permissions ===\""
    echo "  ls -la \$CERT_FILE"
    echo "  ls -la \$KEY_FILE"
    echo "  echo \"\""
    echo "  echo \"=== File Readability ===\""
    echo "  test -r \$CERT_FILE && echo \"Certificate readable\" || echo \"Certificate NOT readable\""
    echo "  test -r \$KEY_FILE && echo \"Private key readable\" || echo \"Private key NOT readable\""
    echo "  echo \"\""
    echo "  echo \"=== File Content Check ===\""
    echo "  head -c 50 \$CERT_FILE | cat -A"
    echo "  head -c 50 \$KEY_FILE | cat -A"
    echo "'"
fi

