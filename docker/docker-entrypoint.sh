#!/bin/sh
set -e

# Vérifier si l'environnement n'est PAS production
if [ "${WP_ENVIRONMENT_TYPE}" != "production" ]; then
  composer install
  composer install --working-dir=/var/www/html/public/plugins/egas
  yarn --cwd /var/www/html/public/plugins/egas install
  yarn --cwd /var/www/html/public/plugins/egas watch &
fi

exec "$@"
