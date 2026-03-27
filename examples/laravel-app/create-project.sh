#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
APP_DIR="${1:-$ROOT_DIR/examples/laravel-app/app}"
PACKAGE_RELATIVE_PATH="../../../packages/laravel"
STUBS_DIR="$ROOT_DIR/examples/laravel-app/stubs"

if [[ ! -f "$APP_DIR/artisan" ]]; then
  rm -rf "$APP_DIR"
  composer create-project laravel/laravel "$APP_DIR"
fi

cd "$APP_DIR"

composer config repositories.canio "{\"type\":\"path\",\"url\":\"$PACKAGE_RELATIVE_PATH\",\"options\":{\"symlink\":true}}"
composer require oxhq/canio:@dev

php artisan vendor:publish --tag=canio-config --force

mkdir -p routes resources/views/pdf
cp "$STUBS_DIR/routes/web.php" routes/web.php
cp "$STUBS_DIR/resources/views/pdf/invoice.blade.php" resources/views/pdf/invoice.blade.php

if [[ -f .env ]]; then
  cp .env ".env.backup.$(date +%s)"
elif [[ -f .env.example ]]; then
  cp .env.example .env
fi

cat >> .env <<'EOF'

APP_URL=http://127.0.0.1:8000
CANIO_RUNTIME_BINARY=../../../bin/stagehand
CANIO_RUNTIME_BASE_URL=http://127.0.0.1:9514
CANIO_RUNTIME_SHARED_SECRET=canio-local-secret
CANIO_PUSH_WEBHOOK_ENABLED=true
CANIO_PUSH_WEBHOOK_SECRET=canio-local-secret
CANIO_RUNTIME_JOB_BACKEND=redis
CANIO_RUNTIME_REDIS_HOST=127.0.0.1
CANIO_RUNTIME_REDIS_PORT=6379
CANIO_OPS_PRESET=local-open
EOF

php artisan key:generate --force

printf '\nExample Laravel app is ready at %s\n' "$APP_DIR"
printf 'Start Redis, run ./scripts/build-stagehand.sh, then use php artisan serve and php artisan canio:serve.\n'
