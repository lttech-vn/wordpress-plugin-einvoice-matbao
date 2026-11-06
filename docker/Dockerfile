# Dev/CI stack with Composer and WP-CLI on top of an official WordPress image.
# Defaults are the latest combination (WordPress 7.1.x on PHP 8.5); docker/min.env selects the minimum (6.6 on PHP 8.2).
ARG WORDPRESS_IMAGE=wordpress:7.1.4-php8.5-apache
ARG WPCLI_IMAGE=wordpress:cli-2.12.0-php8.5
FROM composer:2.9.8 AS composer
FROM ${WPCLI_IMAGE} AS wpcli
FROM ${WORDPRESS_IMAGE}

RUN apt-get update \
	&& apt-get install -y --no-install-recommends git unzip mariadb-client \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=wpcli /usr/local/bin/wp /usr/local/bin/wp
COPY docker/setup.sh /usr/local/bin/einv-setup
RUN chmod +x /usr/local/bin/einv-setup

ENV WP_CLI_ALLOW_ROOT=1 \
	COMPOSER_ALLOW_SUPERUSER=1
