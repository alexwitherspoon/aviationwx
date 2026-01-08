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
    libavif-dev \
    libaom-dev \
    libimage-exiftool-perl \
    cron \
    vsftpd \
    openssh-server \
    certbot \
    openssl \
    logrotate \
    rsyslog \
    db-util \
    net-tools \
    fail2ban \
    iptables \
    jq \
    libcap2-bin \
    tini \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip pcntl \
    && pecl channel-update pecl.php.net \
    && pecl install -a apcu \
    && docker-php-ext-enable apcu \
    && setcap cap_sys_nice+ep /usr/bin/nice \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy composer files first (for better layer caching)
# These change less frequently than application code
COPY composer.json composer.lock* ./

# Install Composer and dependencies
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --optimize-autoloader --no-interaction \
    && rm -rf /root/.composer/cache

# Copy configuration files (rarely change)
COPY config/crontab /etc/cron.d/aviationwx-cron
COPY docker/docker-entrypoint.sh /usr/local/bin/
# vsftpd dual-stack configuration (single instance handles both IPv4 and IPv6)
COPY docker/vsftpd.conf /etc/vsftpd/vsftpd.conf
COPY docker/pam-vsftpd /etc/pam.d/vsftpd
COPY docker/sshd_config /etc/ssh/sshd_config
COPY docker/logrotate-vsftpd /etc/logrotate.d/vsftpd
COPY docker/logrotate-sshd /etc/logrotate.d/sshd
COPY docker/logrotate-aviationwx /etc/logrotate.d/aviationwx
COPY docker/logrotate-webcam-rejections /etc/logrotate.d/webcam-rejections
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
    && chmod 644 /etc/vsftpd/vsftpd.conf \
    && chmod 644 /etc/ssh/sshd_config \
    && chmod 644 /etc/logrotate.d/vsftpd /etc/logrotate.d/sshd /etc/logrotate.d/aviationwx /etc/logrotate.d/webcam-rejections \
    && chmod 644 /etc/fail2ban/jail.d/aviationwx.conf \
    && chmod 644 /etc/fail2ban/filter.d/vsftpd.conf \
    && chmod 644 /etc/fail2ban/filter.d/sshd-sftp.conf

# Copy application files LAST (changes most frequently)
# This ensures code changes don't invalidate earlier layers
COPY --chown=www-data:www-data . /var/www/html/

# Create cache directory
RUN mkdir -p /var/www/html/cache/webcams \
    && chown -R www-data:www-data /var/www/html/cache

# Create directories for FTP/SFTP uploads
# Uses cache/uploads/ to match vsftpd local_root configuration
# Parent directories must be root-owned for SFTP chroot to work
RUN mkdir -p /var/www/html/cache/uploads \
    && mkdir -p /etc/vsftpd/users \
    && mkdir -p /var/run/vsftpd/empty \
    && mkdir -p /var/backups/aviationwx \
    && chown root:root /var/www/html/cache/uploads \
    && chmod 755 /var/www/html/cache/uploads
# Note: getent is provided by glibc (already in base image)
# dnsutils provides dig as fallback, but getent should be sufficient

# Create webcam_users group
RUN groupadd -f webcam_users

# Add ftp user to www-data group for push webcam uploads
# This allows vsftpd (running as ftp) to write to www-data owned directories
# and allows www-data (PHP processor) to read/write files created by ftp
RUN usermod -aG www-data ftp

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
    && echo "apc.enable_cli = 1" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "apc.shm_size = 64M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "expose_php = Off" >> /usr/local/etc/php/conf.d/security.ini \
    && echo "display_errors = Off" >> /usr/local/etc/php/conf.d/security.ini \
    && echo "log_errors = On" >> /usr/local/etc/php/conf.d/security.ini \
    && echo "error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT" >> /usr/local/etc/php/conf.d/security.ini \
    && echo "error_log = /var/log/aviationwx/php-error.log" >> /usr/local/etc/php/conf.d/security.ini

# Configure Apache to listen on port 80 by default (for local/CI with bridge networking)
# Production will reconfigure to port 8080 bound to 127.0.0.1 via entrypoint script
# This allows local/CI to use bridge networking (port mapping) while production uses host networking
# Note: Entrypoint script handles dynamic configuration based on APP_ENV
# We just ensure the default config exists (Apache base image should already have this)
RUN if ! grep -q "^Listen 80" /etc/apache2/ports.conf 2>/dev/null; then \
        echo "Listen 80" >> /etc/apache2/ports.conf; \
    fi

# Configure Apache for file-based logging
# Logs are written to /var/log/aviationwx/ with rotation handled by logrotate
# (7 days retention, 100MB max per file)
RUN echo "LogFormat \"[apache_access] %h %l %u %t \\\"%r\\\" %>s %O \\\"%{Referer}i\\\" \\\"%{User-Agent}i\\\"\" combined" >> /etc/apache2/apache2.conf \
    && echo "# File-based logging with logrotate" >> /etc/apache2/apache2.conf \
    && echo "ErrorLog /var/log/aviationwx/apache-error.log" >> /etc/apache2/apache2.conf \
    && echo "CustomLog /var/log/aviationwx/apache-access.log combined" >> /etc/apache2/apache2.conf

# Use custom entrypoint
# Use tini as PID 1 to properly reap zombie processes
# This is critical for containers that spawn multiple background processes
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/docker-entrypoint.sh"]

# Expose port 80 by default (for local/CI with bridge networking)
# Production will use port 8080 bound to 127.0.0.1 (configured via entrypoint)
EXPOSE 80

# Health check
# Note: start-period is 15s to allow time for all services to initialize
# (cron, vsftpd, sshd, fail2ban, then Apache)
# Production docker-compose.prod.yml overrides this to 20s for extra safety
# Default healthcheck uses port 80 (local/CI), production overrides to port 8080
HEALTHCHECK --interval=30s --timeout=3s --start-period=15s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

