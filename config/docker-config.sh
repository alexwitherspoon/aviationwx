#!/bin/bash
# Docker Configuration Helper
# This script helps set up environment-specific configurations

set -e

CONFIG_DIR="config"
ENV_FILE=".env"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}AviationWX Docker Configuration${NC}"
echo "=================================="

# Check if .env exists
if [ ! -f "$ENV_FILE" ]; then
    echo -e "${YELLOW}Creating .env from env.example...${NC}"
    cp env.example .env
    echo -e "${GREEN}✓ Created .env file${NC}"
    echo ""
    echo -e "${YELLOW}Please edit .env with your configuration:${NC}"
    echo "  nano .env"
    echo ""
    exit 0
fi

# Source .env to get variables
source .env

echo "Current Configuration:"
echo "  Domain: $DOMAIN"
echo "  Port: $APP_PORT"
echo "  PHP Memory: $PHP_MEMORY_LIMIT"
echo ""

# Create config directory if it doesn't exist
mkdir -p $CONFIG_DIR

# Generate nginx config from environment
echo -e "${GREEN}Generating nginx configuration...${NC}"
cat > $CONFIG_DIR/nginx.conf <<EOF
# Auto-generated nginx configuration
# Edit config/docker-config.sh and run: make config

upstream aviationwx {
    server web:80;
}

server {
    listen 80;
    server_name ${DOMAIN} ${SUBDOMAIN_WILDCARD};

    # Wildcard subdomain support
    # ANY subdomain will work: kspb.aviationwx.org, kxxx.aviationwx.org, etc.
    location / {
        proxy_pass http://aviationwx;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        
        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
    }

    # Cache static files
    location ~* \.(jpg|jpeg|png|gif|ico|css|js)$ {
        proxy_pass http://aviationwx;
        proxy_cache_valid 200 ${CACHE_MAX_AGE}s;
        expires ${CACHE_MAX_AGE}s;
        add_header Cache-Control "public, immutable";
    }

    # Security - block sensitive files
    location ~ /airports\.json$ {
        deny all;
        return 404;
    }
}
EOF

echo -e "${GREEN}✓ Configuration generated${NC}"
echo ""
echo "Next steps:"
echo "  1. Edit .env with your domain and settings"
echo "  2. Run: docker compose up -d"
echo "  3. Set up DNS wildcard record in Cloudflare"
echo ""

