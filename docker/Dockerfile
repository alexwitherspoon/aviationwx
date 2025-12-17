# AviationWX - Docker Container
# Syntax: dockerfile:1.4 (for BuildKit cache mounts)
#syntax=docker/dockerfile:1.4
FROM php:8.3-apache

# Install dependencies and PHP extensions
# Use BuildKit cache mounts to speed up apt package installation
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    curl \
    git \
    unzip \
    ffmpeg \
    cron \
    vsftpd \
    openssh-server \
    certbot \
    logrotate \
    db-util \
    net-tools \
    fail2ban \
    iptables \
    jq \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip \
    && pecl channel-update pecl.php.net \
    && pecl install -a apcu \
    && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy composer files first (for better layer caching)
# These change less frequently than application code
COPY composer.json composer.lock* ./

# Copy configuration files (rarely change)
COPY config/crontab /etc/cron.d/aviationwx-cron
COPY docker/docker-entrypoint.sh /usr/local/bin/
# vsftpd dual-stack configuration (IPv4 and IPv6 instances)
# Base config removed - using only dual configs for cleaner architecture
COPY docker/vsftpd_ipv4.conf /etc/vsftpd/vsftpd_ipv4.conf
COPY docker/vsftpd_ipv6.conf /etc/vsftpd/vsftpd_ipv6.conf
COPY docker/pam-vsftpd /etc/pam.d/vsftpd
COPY docker/sshd_config /etc/ssh/sshd_config
COPY docker/logrotate-vsftpd /etc/logrotate.d/vsftpd
COPY docker/logrotate-sshd /etc/logrotate.d/sshd
COPY docker/fail2ban-jail.conf /etc/fail2ban/jail.d/aviationwx.conf
COPY docker/fail2ban-vsftpd.conf /etc/fail2ban/filter.d/vsftpd.conf
COPY docker/fail2ban-sshd.conf /etc/fail2ban/filter.d/sshd-sftp.conf

# Copy scripts (change less frequently than application code)
COPY scripts/service-watchdog.sh /usr/local/bin/
COPY scripts/setup-letsencrypt.sh /usr/local/bin/
COPY scripts/enable-vsftpd-ssl.sh /usr/local/bin/
COPY scripts/create-sftp-user.sh /usr/local/bin/
COPY scripts/resolve-upload-ip.sh /usr/local/bin/

# Set permissions for copied files
RUN chmod 0644 /etc/cron.d/aviationwx-cron \
    && chown root:root /etc/cron.d/aviationwx-cron \
    && chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/service-watchdog.sh \
    && chmod +x /usr/local/bin/setup-letsencrypt.sh \
    && chmod +x /usr/local/bin/enable-vsftpd-ssl.sh \
    && chmod +x /usr/local/bin/create-sftp-user.sh \
    && chmod +x /usr/local/bin/resolve-upload-ip.sh \
    && chmod 644 /etc/vsftpd/vsftpd_ipv4.conf \
    && chmod 644 /etc/vsftpd/vsftpd_ipv6.conf \
    && chmod 644 /etc/ssh/sshd_config \
    && chmod 644 /etc/logrotate.d/vsftpd /etc/logrotate.d/sshd \
    && chmod 644 /etc/fail2ban/jail.d/aviationwx.conf \
    && chmod 644 /etc/fail2ban/filter.d/vsftpd.conf \
    && chmod 644 /etc/fail2ban/filter.d/sshd-sftp.conf

# Copy application files LAST (changes most frequently)
# This ensures code changes don't invalidate earlier layers
COPY --chown=www-data:www-data . /var/www/html/

# Create cache directory
RUN mkdir -p /var/www/html/cache/webcams \
    && chown -R www-data:www-data /var/www/html/cache

# Create directories for FTP/SFTP
# Note: Parent directories must be root-owned for SFTP chroot to work
# The entrypoint script will ensure correct permissions on startup
RUN mkdir -p /var/www/html/uploads/webcams \
    && mkdir -p /etc/vsftpd/users \
    && mkdir -p /var/run/vsftpd/empty \
    && mkdir -p /var/backups/aviationwx \
    && chown root:root /var/www/html/uploads \
    && chmod 755 /var/www/html/uploads \
    && chown root:root /var/www/html/uploads/webcams \
    && chmod 755 /var/www/html/uploads/webcams
# Note: getent is provided by glibc (already in base image)
# dnsutils provides dig as fallback, but getent should be sufficient

# Create webcam_users group
RUN groupadd -f webcam_users

# Create fail2ban configuration directories
RUN mkdir -p /etc/fail2ban/jail.d \
    && mkdir -p /var/log/fail2ban \
    && mkdir -p /var/run/fail2ban

RUN touch /var/log/vsftpd.log /var/log/auth.log \
    && chmod 644 /var/log/vsftpd.log /var/log/auth.log

# Create vsftpd virtual users database directory
RUN mkdir -p /etc/vsftpd && touch /etc/vsftpd/virtual_users.txt /etc/vsftpd/virtual_users.db

# PHP configuration
RUN echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "upload_max_filesize = 100M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "post_max_size = 100M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "max_execution_time = 60" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "apc.enabled = 1" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "apc.shm_size = 64M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "expose_php = Off" >> /usr/local/etc/php/conf.d/security.ini \
    && echo "display_errors = Off" >> /usr/local/etc/php/conf.d/security.ini \
    && echo "log_errors = On" >> /usr/local/etc/php/conf.d/security.ini \
    && echo "error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT" >> /usr/local/etc/php/conf.d/security.ini \
    && echo "error_log = /proc/self/fd/2" >> /usr/local/etc/php/conf.d/security.ini

# Configure Apache to listen on port 8080 (internal, behind nginx proxy)
# This avoids port conflict with nginx which listens on port 80
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf

# Configure Apache to write error log to stderr (for Docker logging)
# Add log format prefix to identify Apache access logs
RUN echo "LogFormat \"[apache_access] %h %l %u %t \\\"%r\\\" %>s %O \\\"%{Referer}i\\\" \\\"%{User-Agent}i\\\"\" combined" >> /etc/apache2/apache2.conf \
    && echo "ErrorLog /proc/self/fd/2" >> /etc/apache2/apache2.conf \
    && echo "CustomLog /proc/self/fd/1 combined" >> /etc/apache2/apache2.conf

# Use custom entrypoint
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

# Expose port 8080 (internal, behind nginx proxy on port 80)
EXPOSE 8080

# Health check
# Note: start-period is 15s to allow time for all services to initialize
# (cron, vsftpd, sshd, fail2ban, then Apache)
# Production docker-compose.prod.yml overrides this to 20s for extra safety
# Check Apache on port 8080 (internal port)
HEALTHCHECK --interval=30s --timeout=3s --start-period=15s --retries=3 \
    CMD curl -f http://localhost:8080/ || exit 1

