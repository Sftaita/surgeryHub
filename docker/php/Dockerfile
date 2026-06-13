FROM php:8.2-fpm

# ── Layer 1: System packages ──────────────────────────────────────────────────
# Separated from PHP extensions so apt cache survives extension-only rebuilds.
# --no-install-recommends trims ~50 MB of recommended-but-unused packages.
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    unzip \
    libicu-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libgmp-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# ── Layer 2: PHP core extensions ──────────────────────────────────────────────
# pcntl  — Symfony Messenger signal handling (SIGTERM graceful shutdown)
# gmp    — required by minishlink/web-push ^10 (composer.json ext-gmp: *)
# -j$(nproc) compiles extensions in parallel
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        intl \
        zip \
        gd \
        opcache \
        pcntl \
        gmp

# ── Layer 3: PECL extensions ──────────────────────────────────────────────────
# redis  — required by Symfony cache.adapter.redis (phpredis client)
# xdebug — disabled by default via XDEBUG_MODE=off (Xdebug 3 reads env natively)
RUN pecl install redis xdebug \
    && docker-php-ext-enable redis xdebug

# ── Layer 4: Composer 2 ───────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Layer 5: Runtime defaults ─────────────────────────────────────────────────
ENV XDEBUG_MODE=off

WORKDIR /var/www/backend

# ── Layer 6: Entrypoint ───────────────────────────────────────────────────────
COPY docker/php/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
