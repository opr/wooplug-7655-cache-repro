FROM wordpress:php8.4-fpm

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions apcu relay \
    && curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

COPY docker/php-repro.ini /usr/local/etc/php/conf.d/zz-wooplug-7655-repro.ini
COPY wp-content/object-cache.php /usr/src/wordpress/wp-content/object-cache.php
COPY wp-content/mu-plugins/wooplug-7655-repro.php /usr/src/wordpress/wp-content/mu-plugins/wooplug-7655-repro.php

RUN ln -s /woocommerce-source /usr/src/wordpress/wp-content/plugins/woocommerce
