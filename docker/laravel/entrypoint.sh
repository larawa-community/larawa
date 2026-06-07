#!/usr/bin/env sh
set -eu

cd /var/www/html

RUNTIME_ENV_FILE="${LARAWA_ENV_PATH:-/var/www/html/storage/app/larawa/.env}"

if [ -s "$RUNTIME_ENV_FILE" ]; then
  set -a
  # shellcheck disable=SC1090
  . "$RUNTIME_ENV_FILE"
  set +a
fi

APP_KEY_FILE="${APP_KEY_FILE:-/var/www/html/storage/app/larawa/app.key}"

if [ -z "${APP_KEY:-}" ]; then
  mkdir -p "$(dirname "$APP_KEY_FILE")"
  if [ ! -s "$APP_KEY_FILE" ]; then
    umask 077
    php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;' > "$APP_KEY_FILE"
  fi
  APP_KEY="$(cat "$APP_KEY_FILE")"
  export APP_KEY
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
  SQLITE_DATABASE="${DB_DATABASE:-/var/www/html/storage/database/database.sqlite}"
  case "$SQLITE_DATABASE" in
    /*) ;;
    *) SQLITE_DATABASE="/var/www/html/storage/$SQLITE_DATABASE" ;;
  esac
  DB_DATABASE="$SQLITE_DATABASE"
  export DB_DATABASE
fi

if [ -d /var/www/html/public.dist ]; then
  mkdir -p /var/www/html/public
  rm -rf /var/www/html/public/build
  cp -a /var/www/html/public.dist/. /var/www/html/public/
  chmod -R a+rX /var/www/html/public
fi

if [ "${LARAWA_BOOTSTRAP:-false}" = "true" ]; then
  if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    mkdir -p "$(dirname "$SQLITE_DATABASE")"
    if [ ! -s "$SQLITE_DATABASE" ] && [ -s /legacy-database/database.sqlite ]; then
      cp /legacy-database/database.sqlite "$SQLITE_DATABASE"
    fi
    touch "$SQLITE_DATABASE"
    chown -R www-data:www-data "$(dirname "$SQLITE_DATABASE")"
  fi

  if [ "${LARAWA_INSTALLED:-false}" = "true" ]; then
    php artisan package:discover --ansi --no-interaction
    php artisan migrate --force --no-interaction
    php artisan db:seed --force --no-interaction
    php artisan storage:link >/dev/null 2>&1 || true
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
  else
    php artisan optimize:clear >/dev/null 2>&1 || true
  fi

  chown -R www-data:www-data storage bootstrap/cache
fi

exec "$@"
