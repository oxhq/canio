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
composer require barryvdh/laravel-dompdf
composer require barryvdh/laravel-snappy carlos-meneses/laravel-mpdf spatie/laravel-pdf spatie/browsershot
PUPPETEER_SKIP_DOWNLOAD=true npm install puppeteer --no-save

php artisan vendor:publish --tag=canio-config --force

mkdir -p routes resources/views/pdf
cp "$STUBS_DIR/routes/web.php" routes/web.php
cp "$STUBS_DIR/resources/views/pdf/invoice.blade.php" resources/views/pdf/invoice.blade.php
cp "$STUBS_DIR/resources/views/pdf/javascript-probe.blade.php" resources/views/pdf/javascript-probe.blade.php
cp "$STUBS_DIR/README.md" README.md
mkdir -p scripts tests/Feature
cp "$STUBS_DIR/scripts/smoke-cloud.sh" scripts/smoke-cloud.sh
cp "$STUBS_DIR/tests/Feature/CanioCloudSmokeTest.php" tests/Feature/CanioCloudSmokeTest.php
chmod +x scripts/smoke-cloud.sh

if [[ -f .env ]]; then
  cp .env ".env.backup.$(date +%s)"
elif [[ -f .env.example ]]; then
  cp .env.example .env
fi

cat >> .env <<'EOF'

APP_URL=http://127.0.0.1:8000
CANIO_RUNTIME_BASE_URL=http://127.0.0.1:9514
CANIO_RUNTIME_SHARED_SECRET=canio-local-secret
CANIO_PUSH_WEBHOOK_ENABLED=true
CANIO_PUSH_WEBHOOK_SECRET=canio-local-secret
CANIO_RUNTIME_JOB_BACKEND=redis
CANIO_RUNTIME_REDIS_HOST=127.0.0.1
CANIO_RUNTIME_REDIS_PORT=6379
CANIO_OPS_ENABLED=true
CANIO_OPS_PRESET=local-open
CANIO_CLOUD_MODE=off
CANIO_CLOUD_BASE_URL=http://127.0.0.1:9081
CANIO_CLOUD_TOKEN=
CANIO_CLOUD_PROJECT=
CANIO_CLOUD_ENVIRONMENT=
CANIO_CLOUD_TIMEOUT=30
CANIO_CLOUD_SYNC_ENABLED=true
CANIO_CLOUD_SYNC_INCLUDE_ARTIFACTS=true
WKHTML_PDF_BINARY=/opt/homebrew/bin/wkhtmltopdf
WKHTML_IMG_BINARY=/opt/homebrew/bin/wkhtmltoimage
EOF

php artisan key:generate --force

printf '\nExample Laravel app is ready at %s\n' "$APP_DIR"
printf 'Start Redis, run php artisan serve, and open /invoices/preview. Canio will install/start Stagehand automatically on first use.\n'
printf 'The JavaScript probe is available at /probes/javascript and /probes/javascript/preview.\n'
printf 'For Canio Cloud, set CANIO_CLOUD_* env vars and try /canio/cloud/sync/preview, /canio/cloud/managed/preview, or scripts/smoke-cloud.sh.\n'
