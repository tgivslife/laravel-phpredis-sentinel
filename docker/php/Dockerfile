# The Linux environment the tests run in against the servers: PHP 8.5 on Debian, where the Linux error messages were
# measured (glibc words some socket errors differently from Alpine's musl), phpredis at the package's floor, pcntl
# and posix, Composer, and the Docker CLI, which drives the servers through the host's Docker socket.
FROM php:8.5-cli-trixie

RUN apt-get update \
    && apt-get install --yes --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pcntl \
    && mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=docker:29-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker:29-cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose
