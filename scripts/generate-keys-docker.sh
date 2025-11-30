#!/bin/bash
# Generate WireGuard keys using Docker
# This uses the wireguard-tools image to generate keys

set -e

echo "Generating WireGuard keys using Docker..."
echo ""

# Generate keys using alpine with wireguard-tools
docker run --rm \
    alpine:latest \
    sh -c "apk add --no-cache wireguard-tools > /dev/null 2>&1 && \
           echo '=== Server Keys ===' && \
           SERVER_PRIV=\$(wg genkey) && \
           SERVER_PUB=\$(echo \"\$SERVER_PRIV\" | wg pubkey) && \
           echo \"server_private_key: \$SERVER_PRIV\" && \
           echo \"server_public_key: \$SERVER_PUB\" && \
           echo '' && \
           echo '=== Client Keys ===' && \
           CLIENT_PRIV=\$(wg genkey) && \
           CLIENT_PUB=\$(echo \"\$CLIENT_PRIV\" | wg pubkey) && \
           echo \"client_private_key: \$CLIENT_PRIV\" && \
           echo \"client_public_key: \$CLIENT_PUB\" && \
           echo '' && \
           echo '=== JSON Format ===' && \
           echo '{' && \
           echo '  \"server_private_key\": \"'\"\$SERVER_PRIV\"'\",' && \
           echo '  \"server_public_key\": \"'\"\$SERVER_PUB\"'\",' && \
           echo '  \"client_private_key\": \"'\"\$CLIENT_PRIV\"'\",' && \
           echo '  \"client_public_key\": \"'\"\$CLIENT_PUB\"'\"' && \
           echo '}'"





