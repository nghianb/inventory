# syntax=docker/dockerfile:1

# Một Dockerfile cho cả hai môi trường. Tầng `base` giữ runtime dùng chung để dev và production
# không trôi dạt khỏi nhau: cùng phiên bản PHP, cùng danh sách extension, cùng giới hạn upload.
#
#   docker compose build                     -> target dev  (bind mount, có pcntl và Composer)
#   docker/build-prod.sh                     -> target prod (code + vendor + asset nướng vào image)

ARG FRANKENPHP_VERSION=1.12.7-php8.4.25
ARG NODE_VERSION=22.23.2-trixie-slim

# --------------------------------------------------------------------------- base
FROM dunglas/frankenphp:${FRANKENPHP_VERSION} AS base

# Image đã có sẵn mbstring, dom, fileinfo, xmlreader, sodium, opcache.
# Còn thiếu: pdo_pgsql (Postgres), intl (Number::fileSize), zip (openspout đọc/ghi XLSX),
# bcmath, gd, exif.
RUN install-php-extensions pdo_pgsql intl zip bcmath gd exif

COPY docker/php/conf.d/inventory.ini /usr/local/etc/php/conf.d/inventory.ini

# Nghe cổng cao để chạy được dưới user thường: bind cổng 80 cần quyền root.
ENV SERVER_NAME=":8000"
EXPOSE 8000

ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} app \
    && useradd -u ${UID} -g ${GID} -m app \
    && mkdir -p /app /data /config \
    && chown -R app:app /app /data /config

WORKDIR /app

# ------------------------------------------------------------------------- vendor
# Tách hẳn khỏi tầng chạy: Composer không có mặt trong image production.
FROM base AS vendor

RUN install-php-extensions @composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .

# .dockerignore cắt state của máy dev ra khỏi build context, nên các thư mục runtime của Laravel
# không đi theo. Dựng lại rỗng ở đây: thiếu storage/framework/views là Blade compiler chết ngay
# lúc boot ("Please provide a valid cache path"), trước cả khi chạy được lệnh artisan nào.
RUN mkdir -p storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/private \
        storage/app/public \
        storage/app/intake \
        bootstrap/cache

# post-autoload-dump chạy package:discover và filament:upgrade; filament:upgrade sinh asset
# Filament vào public/ (các thư mục này nằm trong .gitignore nên không có sẵn trong repo).
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && composer run-script post-autoload-dump

# ------------------------------------------------------------------------- assets
FROM node:${NODE_VERSION} AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci

COPY . .
# resources/css/app.css có @source trỏ vào blade phân trang của Laravel, nên Tailwind cần vendor/.
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---------------------------------------------------------------------------- dev
FROM base AS dev

# pcntl chỉ dùng cho suite Concurrency (pcntl_fork); production không cần.
RUN install-php-extensions @composer pcntl

USER app

# --------------------------------------------------------------------------- prod
FROM base AS prod

COPY docker/php/conf.d/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini

COPY --from=vendor --chown=app:app /app /app
COPY --from=assets --chown=app:app /app/public/build /app/public/build

COPY --chmod=0755 docker/prod/entrypoint.sh /usr/local/bin/inventory-entrypoint

# Cache route, view và component Filament nướng luôn vào image: chúng chỉ phụ thuộc code.
# Cache cấu hình thì KHÔNG, vì nó phụ thuộc secret — xem entrypoint.
RUN php artisan route:cache \
    && php artisan view:cache \
    && php artisan filament:optimize \
    && chown -R app:app /app/bootstrap/cache /app/storage

USER app

ENTRYPOINT ["inventory-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
