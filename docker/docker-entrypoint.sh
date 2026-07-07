#!/bin/sh
set -e

echo "WP_ENVIRONMENT_TYPE ${WP_ENVIRONMENT_TYPE}";
# Vérifier si l'environnement n'est PAS production
if [ "${WP_ENVIRONMENT_TYPE}" != "production" ]; then
  echo "composer install ...";
  XDEBUG_MODE=off composer install
  echo "composer install --working-dir=/var/www/html/public/plugins/egas ...";
  XDEBUG_MODE=off composer install --working-dir=/var/www/html/public/plugins/egas
  echo "yarn --cwd /var/www/html/public/plugins/egas install ...";
  yarn --cwd /var/www/html/public/plugins/egas install
  echo "--cwd /var/www/html/public/plugins/egas watch & ...";
  yarn --cwd /var/www/html/public/plugins/egas watch &
  echo "wp plugin install plugin-check --activate --allow-root ...";
  wp plugin install plugin-check --activate --allow-root
  echo "plugin install woocommerce --activate --allow-root ...";
  wp plugin install woocommerce --activate --allow-root
  echo "visit https://egas-plugin:4435/wp-admin";
fi

exec "$@"
