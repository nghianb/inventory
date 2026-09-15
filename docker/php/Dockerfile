FROM php:8.4-cli

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions pdo_pgsql intl zip bcmath gd exif pcntl @composer

# File nhập hàng tới 10 MB (INVENTORY_INTAKE_MAX_BYTES): mặc định PHP chỉ nhận 2 MB mỗi file.
RUN printf "upload_max_filesize=12M\npost_max_size=16M\nmemory_limit=512M\n" > /usr/local/etc/php/conf.d/inventory-intake.ini

ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} app && useradd -u ${UID} -g ${GID} -m app

USER app
WORKDIR /app
