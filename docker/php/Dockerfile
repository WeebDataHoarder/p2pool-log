FROM php:8.0-cli-bullseye

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupadd -r php && useradd -r -g php php && \
    DEBIAN_FRONTEND=noninteractive apt update && \
    DEBIAN_FRONTEND=noninteractive apt install -y \
    unzip git locales \
    libpq-dev libgmp-dev && \
    sed -i 's/^# *\(en_US.UTF-8\)/\1/' /etc/locale.gen && locale-gen && update-locale LANG="en_US.UTF-8" && \
    docker-php-ext-install -j$(nproc) pgsql && \
    docker-php-ext-install -j$(nproc) bcmath && \
    docker-php-ext-install -j$(nproc) gmp

WORKDIR /app

USER php