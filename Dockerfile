FROM dunglas/frankenphp:1-php8.4

ARG USER_ID=1000
ARG GROUP_ID=1000

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_INI_SCAN_DIR=":/etc/frankenphp/conf.d"

RUN install-php-extensions \
        pdo_pgsql \
        pcntl \
        opcache \
        intl \
        zip \
        bcmath

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN (getent group ${GROUP_ID} || groupadd -g ${GROUP_ID} app) \
 && (id -u app >/dev/null 2>&1 || useradd -m -u ${USER_ID} -g ${GROUP_ID} -s /bin/bash app) \
 && mkdir -p /app /data/caddy /config/caddy \
 && chown -R ${USER_ID}:${GROUP_ID} /app /data /config

WORKDIR /app

EXPOSE 8000

USER app

CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
