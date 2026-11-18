# syntax=docker/dockerfile:1
#
# One image, three process types (web, queue worker, scheduler): the SAME image and therefore the SAME PHP
# binary and the SAME timezone database. Select the role with APP_ROLE or by overriding the start command
# (see docker-entrypoint.sh and docs/phase-1-foundation/STAGING_PHP_RUNTIME_IMAGE.md).
#
# Why a Dockerfile: PHP's timezone database is compiled INTO the PHP binary (not read from the OS tzdata), so
# the Railpack (FrankenPHP) PHP build keeps whatever tzdb its PHP release shipped (2025.3 on staging). Morocco's legal time
# changed on 2026-09-20 (Decree 2.26.530, IANA tz 2026c+): a runtime older than 2026c shifts every Moroccan
# business date by an hour. This image pins the PECL `timezonedb` extension (same pin as CI) on top of a current
# official PHP image, and the BUILD FAILS if the resulting runtime does not implement Morocco's legal time.

ARG PHP_IMAGE=php:8.4-apache-bookworm
ARG TIMEZONEDB_VERSION=2026.6

# --------------------------------------------------------------------------------------- front-end assets
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.ts tsconfig.json ./
COPY resources ./resources
COPY public ./public
# Needs outbound network (Bunny Fonts are self-hosted at build time by the Vite plugin).
RUN npm run build

# ------------------------------------------------------------------------- PHP runtime (shared by every role)
FROM ${PHP_IMAGE} AS runtime
ARG TIMEZONEDB_VERSION
RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libicu-dev unzip \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" bcmath gd intl opcache pcntl pdo_mysql zip \
 && pecl install "timezonedb-${TIMEZONEDB_VERSION}" \
 && docker-php-ext-enable timezonedb \
 && rm -rf /var/lib/apt/lists/* /tmp/pear \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && a2enmod rewrite headers \
 && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf \
 && sed -ri 's!AllowOverride None!AllowOverride All!' /etc/apache2/apache2.conf

# PHP limits sized for what the application accepts: backup archives up to ORG_BACKUP_MAX_ARCHIVE_BYTES (~205 MiB),
# fulfillment attachments 5 x 10 MiB per request, catalog imports 5 MiB, PDF / ZIP exports. The production
# php.ini defaults (2M upload, 8M post, 128M memory, 30 s) would reject these. Immutable image => no opcache
# timestamp checks. CLI processes (worker, scheduler) have no execution time limit.
RUN { \
      echo "upload_max_filesize=224M"; echo "post_max_size=256M"; echo "memory_limit=512M"; \
      echo "max_execution_time=300"; echo "max_input_time=300"; \
      echo "opcache.validate_timestamps=0"; \
    } > "$PHP_INI_DIR/conf.d/zz-erp.ini"

# Apache (prefork + mod_php): bounded workers so a small staging VPS is not exhausted; request Timeout 300 s.
RUN printf 'ServerName localhost\nTimeout 300\nKeepAliveTimeout 5\n<IfModule mpm_prefork_module>\n    StartServers 2\n    MinSpareServers 2\n    MaxSpareServers 6\n    MaxRequestWorkers 40\n    MaxConnectionsPerChild 500\n</IfModule>\n' > /etc/apache2/conf-available/erp.conf \
 && a2enconf erp
WORKDIR /var/www/html

# Build gate: refuse to build an image whose PHP does not implement Morocco's legal time.
COPY scripts/check-timezone-runtime.php scripts/check-timezone-runtime.php
RUN php scripts/check-timezone-runtime.php

# ------------------------------------------------------------------------------------- PHP dependencies
FROM runtime AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts --no-interaction

# ----------------------------------------------------------------------------------------- final image
FROM runtime AS final
COPY --from=vendor /var/www/html /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
CMD []
