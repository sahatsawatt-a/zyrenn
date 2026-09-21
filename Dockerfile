# Local development image for the ZyrenN Laravel app.
# The project is bind-mounted at runtime, so nothing is COPYed in here.
#
# Both PHP and Node live in this one image: Vite plugins in the Laravel
# ecosystem (Wayfinder, for one) shell out to `php artisan`, so the Vite
# process needs PHP on its PATH.
FROM php:8.4-cli-bookworm

ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libpq-dev \
        libicu-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node from the official image; both bases are bookworm, so the glibc build matches
# the host-installed node_modules that get bind-mounted in.
COPY --from=node:24-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && ln -s /usr/local/lib/node_modules/corepack/dist/corepack.js /usr/local/bin/corepack

# The official image ships no php.ini, so memory_limit defaults to 128M, which
# PHPStan's parallel workers exhaust. CLI tooling wants it unbounded.
RUN printf 'memory_limit = -1\n' > /usr/local/etc/php/conf.d/zz-dev.ini

# Match the host user so bind-mounted files (storage/, bootstrap/cache/) stay writable.
RUN groupadd --gid "$GID" app \
    && useradd --uid "$UID" --gid "$GID" --create-home --shell /bin/bash app

USER app
WORKDIR /var/www/html
EXPOSE 8000 5173

# PHP's built-in server is single-threaded; workers keep Inertia's XHR from
# deadlocking behind the document request that triggered it. --no-reload is
# required for the variable to be honoured (PHP code changes still apply, since
# each request is interpreted fresh).
ENV PHP_CLI_SERVER_WORKERS=4

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
