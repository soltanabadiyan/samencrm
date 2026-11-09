ARG PHP_VERSION=8.2
# bookworm: trixie-based images no longer ship libc-client-dev (needed for the IMAP extension)
FROM php:${PHP_VERSION}-apache-bookworm
# deb.debian.org is not reachable from the server -> use an Iranian mirror (override with --build-arg)
ARG DEBIAN_MIRROR=http://mirror.iranserver.com

# system libs + PHP extensions Vtiger needs
RUN sed -i -e "s#http://deb.debian.org#${DEBIAN_MIRROR}#g" /etc/apt/sources.list.d/debian.sources \
    && apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libc-client-dev libkrb5-dev \
        libxml2-dev libicu-dev libcurl4-openssl-dev libonig-dev unzip curl ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install -j"$(nproc)" gd mysqli pdo_mysql zip imap soap bcmath exif intl opcache \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*

COPY php-vtiger.ini /usr/local/etc/php/conf.d/zz-vtiger.ini
COPY apache-vtiger.conf /etc/apache2/conf-available/vtiger.conf
RUN a2enconf vtiger

# Vtiger source (placed here by install.sh – downloaded from SourceForge or supplied by you)
COPY vtigercrm.tar.gz /tmp/vtigercrm.tar.gz
RUN mkdir -p /usr/src/vtigercrm \
    && tar -xzf /tmp/vtigercrm.tar.gz -C /usr/src/vtigercrm --strip-components=1 \
    && rm /tmp/vtigercrm.tar.gz \
    && test -f /usr/src/vtigercrm/vtigerversion.php

COPY entrypoint.sh /usr/local/bin/vtiger-entrypoint.sh
RUN chmod +x /usr/local/bin/vtiger-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/vtiger-entrypoint.sh"]
CMD ["apache2-foreground"]
