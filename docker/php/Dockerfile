FROM php:8.4-cli

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions pdo_pgsql intl zip bcmath gd exif pcntl @composer

ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} app && useradd -u ${UID} -g ${GID} -m app

USER app
WORKDIR /app
