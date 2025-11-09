# Deployment Guide

Quick reference for deployment options. For detailed guides, see the linked documentation.

## Deployment Guides

### For Fresh Ubuntu LTS VPS Setup

**See [PRODUCTION_DEPLOYMENT.md](docs/PRODUCTION_DEPLOYMENT.md)** - Complete step-by-step guide:
- Initial server setup (Ubuntu 22.04 LTS)
- Docker & Docker Compose installation
- DNS configuration
- SSL certificate setup (Let's Encrypt)
- Application deployment
- Cron jobs (automatic inside container)
- Certificate renewal automation
- GitHub Actions setup

### For Detailed Deployment Process

**See [DOCKER_DEPLOYMENT.md](docs/DOCKER_DEPLOYMENT.md)** - Comprehensive guide:
- Docker architecture overview
- GitHub Actions CI/CD setup
- SSL certificate management
- Production configuration details
- Maintenance and updates
- Troubleshooting

### For Local Development

**See [LOCAL_SETUP.md](docs/LOCAL_SETUP.md)** - Local development setup:
- Docker-based development environment
- Configuration and testing
- Development workflow

## Quick Deployment Notes

- **Configuration**: `airports.json` automatically deployed via GitHub Actions to `/home/aviationwx/airports.json`, bind-mount into container (read-only)
- **Webcam Refresh**: Automatically runs every minute via cron inside the Docker container
- **Weather Refresh**: Automatically runs every minute via cron inside the Docker container
- **DNS**: Configure wildcard DNS (A records for `@` and `*`)
- **SSL**: Nginx handles HTTPS redirects; certificates mounted into container
- **Caching**: Weather data cached server-side; webcam images cached on disk (cache in `/tmp/aviationwx-cache`, ephemeral, cleared on reboot)
- **Logging**: All logs go to Docker stdout/stderr (automatic rotation, no host setup needed)
- **Minimal Host Setup**: No manual cache/log directory setup, no cron setup, no manual airports.json setup required

For complete details, see [PRODUCTION_DEPLOYMENT.md](docs/PRODUCTION_DEPLOYMENT.md).

