#!/usr/bin/env bash

set -euo pipefail

APP_DIR="${APP_DIR:-/workspace/examples/laravel-app/app}"

cd "$APP_DIR"

if [[ -f composer.json && ! -f vendor/autoload.php ]]; then
  composer install --no-interaction --prefer-dist
fi

if [[ -f .env.example && ! -f .env ]]; then
  cp .env.example .env
fi

exec "$@"
