# https://github.com/StephenMiracle/frankenwp/blob/main/Dockerfile
# https://hub.docker.com/r/wpeverywhere/frankenwp/tags

FROM wpeverywhere/frankenwp:latest-php8.2 AS app_php

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

WORKDIR /var/www/html/

COPY --link ./ ./
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
COPY --link ./docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link ./docker/Caddyfile /etc/caddy/Caddyfile
COPY --link ./docker/php.ini /usr/local/etc/php/php.ini
RUN chmod +x /usr/local/bin/docker-entrypoint

RUN install-php-extensions xdebug-stable

RUN apt-get update -y && \
    apt-get upgrade -y && \
    curl -sL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get install -y nodejs procps less iputils-ping && \
    npm install --global yarn && \
    yarn add -D webpack-cli && \
    XDEBUG_MODE=off composer install && \
    XDEBUG_MODE=off composer install --working-dir=/var/www/html/public/plugins/egas --no-dev --optimize-autoloader && \
    yarn --cwd /var/www/html/public/plugins/egas install && \
    yarn --cwd /var/www/html/public/plugins/egas build

RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && \
    chmod +x wp-cli.phar && \
    mv wp-cli.phar /usr/local/bin/wp && \
    wp plugin install plugin-check --activate --allow-root && \
    wp plugin install woocommerce --activate --allow-root

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
