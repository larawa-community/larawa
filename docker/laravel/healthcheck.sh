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

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
  SQLITE_DATABASE="${DB_DATABASE:-/var/www/html/storage/database/database.sqlite}"
  case "$SQLITE_DATABASE" in
    /*) ;;
    *) SQLITE_DATABASE="/var/www/html/storage/$SQLITE_DATABASE" ;;
  esac
  mkdir -p "$(dirname "$SQLITE_DATABASE")"
  touch "$SQLITE_DATABASE"
  DB_DATABASE="$SQLITE_DATABASE"
  export DB_DATABASE
fi

exec php artisan larawa:health --quiet
