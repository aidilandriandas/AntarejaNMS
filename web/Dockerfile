# Build dari root repo: docker build -f web/Dockerfile -t antareja-web .
FROM node:22-alpine AS assets
WORKDIR /src
COPY web/package.json web/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY web/ ./
RUN npm run build

# Basis: PHP 8.4-FPM + nginx (webdevops/php-nginx, lisensi MIT) — sudah berisi pdo_pgsql & opcache.
FROM webdevops/php-nginx:8.4-alpine
ENV WEB_DOCUMENT_ROOT=/app/public \
    PHP_DISMOD=ioncube \
    php.opcache.validate_timestamps=0 \
    php.expose_php=0 \
    php.memory_limit=256M \
    php.upload_max_filesize=16M \
    php.post_max_size=20M
WORKDIR /app
COPY web/composer.json web/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY web/ ./
COPY --from=assets /src/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev \
    && rm -rf node_modules tests docker/README* \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R application:application storage bootstrap/cache
COPY web/docker/10-antareja.sh /opt/docker/provision/entrypoint.d/10-antareja.sh
COPY web/docker/live.conf /opt/docker/etc/nginx/vhost.common.d/20-antareja-live.conf
RUN chmod +x /opt/docker/provision/entrypoint.d/10-antareja.sh
