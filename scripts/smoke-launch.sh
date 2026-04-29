#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PACKAGE_CONSTRAINT="${CANIO_PACKAGE_CONSTRAINT:-^1.0}"
PACKAGE_SOURCE_MODE="${CANIO_PACKAGE_SOURCE_MODE:-vcs}"
PACKAGE_SOURCE_URL="${CANIO_PACKAGE_SOURCE_URL:-}"
RUNTIME_RELEASE_VERSION="${CANIO_RUNTIME_RELEASE_VERSION:-v1.0.2}"
RUNTIME_RELEASE_REPOSITORY="${CANIO_RUNTIME_RELEASE_REPOSITORY:-oxhq/canio}"
RUNTIME_RELEASE_SOURCE="${CANIO_RUNTIME_RELEASE_SOURCE:-local}"
RUNTIME_RELEASE_BASE_URL="${CANIO_RUNTIME_RELEASE_BASE_URL:-}"
RUNTIME_RELEASE_BINARY_PATH="${CANIO_RELEASE_SMOKE_BINARY:-}"
LARAVEL_VERSION="${CANIO_LARAVEL_VERSION:-^12.0}"
RELEASE_SMOKE_PORT="${CANIO_RELEASE_SMOKE_PORT:-}"
CANIO_RUNTIME_PORT_VALUE="${CANIO_RUNTIME_PORT:-}"
KEEP_WORKDIR="${CANIO_SMOKE_KEEP_WORKDIR:-0}"

PHP_BIN="${CANIO_PHP_BIN:-}"
COMPOSER_BIN="${CANIO_COMPOSER_BIN:-}"
PYTHON_BIN="${CANIO_PYTHON_BIN:-python3}"

TEMP_DIR=""
SERVER_PID=""

resolve_php_bin() {
  if [[ -n "$PHP_BIN" ]]; then
    printf '%s\n' "$PHP_BIN"
    return
  fi

  if [[ -x "$HOME/Library/Application Support/Herd/bin/php" ]]; then
    printf '%s\n' "$HOME/Library/Application Support/Herd/bin/php"
    return
  fi

  command -v php
}

resolve_composer_bin() {
  if [[ -n "$COMPOSER_BIN" ]]; then
    printf '%s\n' "$COMPOSER_BIN"
    return
  fi

  if [[ -x "$HOME/Library/Application Support/Herd/bin/composer" ]]; then
    printf '%s\n' "$HOME/Library/Application Support/Herd/bin/composer"
    return
  fi

  command -v composer
}

resolve_os() {
  case "$(uname -s)" in
    Darwin) printf 'darwin' ;;
    Linux) printf 'linux' ;;
    MINGW*|MSYS*|CYGWIN*|Windows_NT) printf 'windows' ;;
    *) echo "Unsupported host OS: $(uname -s)" >&2; exit 1 ;;
  esac
}

resolve_arch() {
  case "$(uname -m)" in
    x86_64|amd64) printf 'amd64' ;;
    arm64|aarch64) printf 'arm64' ;;
    *) echo "Unsupported host architecture: $(uname -m)" >&2; exit 1 ;;
  esac
}

cleanup() {
  if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi

  if [[ -n "$TEMP_DIR" && "$KEEP_WORKDIR" != "1" ]]; then
    cd / 2>/dev/null || true
    rm -rf "$TEMP_DIR" 2>/dev/null || true
  elif [[ -n "$TEMP_DIR" ]]; then
    echo "Keeping smoke workspace at $TEMP_DIR"
  fi
}

trap cleanup EXIT INT TERM

create_temp_dir() {
  mktemp -d "${TMPDIR:-/tmp}/canio-launch-smoke.XXXXXX"
}

resolve_free_port() {
  "$PYTHON_BIN" - <<'PY'
import socket

sock = socket.socket()
sock.bind(("127.0.0.1", 0))
print(sock.getsockname()[1])
sock.close()
PY
}

write_checksum_file() {
  local asset_dir="$1"
  local asset_name="$2"
  local asset_path="$asset_dir/$asset_name"
  local checksum

  checksum="$(sha256sum "$asset_path" | awk '{print $1}')"
  printf '%s  %s\n' "$checksum" "$asset_name" > "$asset_dir/checksums.txt"
}

build_release_asset() {
  local release_root="$1"
  local asset_os="$2"
  local asset_arch="$3"
  local asset_ext="$4"
  local asset_name="stagehand_${RUNTIME_RELEASE_VERSION}_${asset_os}_${asset_arch}${asset_ext}"
  local asset_dir="$release_root/$RUNTIME_RELEASE_REPOSITORY/releases/download/$RUNTIME_RELEASE_VERSION"
  local asset_path="$asset_dir/$asset_name"

  mkdir -p "$asset_dir"

  if [[ -n "$RUNTIME_RELEASE_BINARY_PATH" ]]; then
    cp "$RUNTIME_RELEASE_BINARY_PATH" "$asset_path"
  else
    "$ROOT_DIR/scripts/build-stagehand.sh" "$asset_path" >/dev/null
  fi

  write_checksum_file "$asset_dir" "$asset_name"
  printf '%s\n' "$asset_path"
}

download_release_assets() {
  local release_root="$1"
  local asset_os="$2"
  local asset_arch="$3"
  local asset_ext="$4"
  local asset_name="stagehand_${RUNTIME_RELEASE_VERSION}_${asset_os}_${asset_arch}${asset_ext}"
  local asset_dir="$release_root/$RUNTIME_RELEASE_REPOSITORY/releases/download/$RUNTIME_RELEASE_VERSION"
  local asset_path="$asset_dir/$asset_name"
  local checksums_path="$asset_dir/checksums.txt"
  local upstream_base="${RUNTIME_RELEASE_BASE_URL:-https://github.com}"
  local upstream_release_url="$upstream_base/$RUNTIME_RELEASE_REPOSITORY/releases/download/$RUNTIME_RELEASE_VERSION"

  mkdir -p "$asset_dir"

  curl -fsSL "$upstream_release_url/$asset_name" -o "$asset_path"
  curl -fsSL "$upstream_release_url/checksums.txt" -o "$checksums_path"

  printf '%s\n' "$asset_path"
}

prepare_release_asset() {
  local release_root="$1"
  local asset_os="$2"
  local asset_arch="$3"
  local asset_ext="$4"

  case "$RUNTIME_RELEASE_SOURCE" in
    local)
      build_release_asset "$release_root" "$asset_os" "$asset_arch" "$asset_ext"
      ;;
    github)
      download_release_assets "$release_root" "$asset_os" "$asset_arch" "$asset_ext"
      ;;
    *)
      echo "Unsupported CANIO_RUNTIME_RELEASE_SOURCE: $RUNTIME_RELEASE_SOURCE" >&2
      exit 1
      ;;
  esac
}

prepare_package_repo() {
  local repo_dir="$1"

  mkdir -p "$repo_dir"
  cp -R "$ROOT_DIR/packages/laravel/." "$repo_dir/"
  rm -rf "$repo_dir/vendor"

  git -C "$repo_dir" init -q
  git -C "$repo_dir" config user.email "codex@oxhq.local"
  git -C "$repo_dir" config user.name "Codex"
  git -C "$repo_dir" add -A
  git -C "$repo_dir" commit -qm "Prepare Canio launch smoke package split"
  git -C "$repo_dir" tag -a "$RUNTIME_RELEASE_VERSION" -m "$RUNTIME_RELEASE_VERSION"
}

file_uri_for_path() {
  local path="$1"

  case "$(uname -s)" in
    MINGW*|MSYS*|CYGWIN*|Windows_NT)
      printf 'file:///%s\n' "$(cygpath -am "$path")"
      ;;
    *)
      printf 'file://%s\n' "$path"
      ;;
  esac
}

wait_for_http() {
  local url="$1"
  local attempts="${2:-30}"

  for ((i=1; i<=attempts; i++)); do
    if curl -fsS "$url" >/dev/null 2>&1; then
      return 0
    fi

    sleep 1
  done

  echo "Timed out waiting for $url" >&2
  return 1
}

create_laravel_app() {
  local app_dir="$1"

  "$COMPOSER_BIN" create-project laravel/laravel "$app_dir" "$LARAVEL_VERSION" --no-interaction --prefer-dist >/dev/null
}

require_canio_from_source() {
  local app_dir="$1"
  local package_source_url="$2"

  if [[ "$PACKAGE_SOURCE_MODE" == "vcs" ]]; then
    "$COMPOSER_BIN" config repositories.canio vcs "$package_source_url" --working-dir="$app_dir" >/dev/null
  fi

  "$COMPOSER_BIN" require "oxhq/canio:${PACKAGE_CONSTRAINT}" \
    --working-dir="$app_dir" \
    --no-interaction \
    --prefer-dist \
    --update-with-all-dependencies >/dev/null
}

append_env() {
  local app_dir="$1"
  local runtime_state="$2"
  local runtime_log="$3"
  local asset_os="$4"
  local asset_arch="$5"
  local asset_ext="$6"
  local release_smoke_port="$7"
  local runtime_port="$8"

  cat >> "$app_dir/.env" <<EOF

APP_URL=http://127.0.0.1:18080
CANIO_RUNTIME_MODE=embedded
CANIO_RUNTIME_AUTO_START=true
CANIO_RUNTIME_AUTO_INSTALL=true
CANIO_RUNTIME_RELEASE_REPOSITORY=$RUNTIME_RELEASE_REPOSITORY
CANIO_RUNTIME_RELEASE_BASE_URL=http://127.0.0.1:$release_smoke_port
CANIO_RUNTIME_RELEASE_VERSION=$RUNTIME_RELEASE_VERSION
CANIO_RUNTIME_BASE_URL=http://127.0.0.1:$runtime_port
CANIO_RUNTIME_PORT=$runtime_port
CANIO_RUNTIME_STATE_PATH=$runtime_state
CANIO_RUNTIME_LOG_PATH=$runtime_log
CANIO_CHROMIUM_USER_DATA_DIR=$TEMP_DIR/chromium-profile
EOF
}

probe_runtime_checksum_failure() {
  local app_dir="$1"
  local asset_os="$2"
  local asset_arch="$3"
  local asset_ext="$4"
  local asset_name="stagehand_${RUNTIME_RELEASE_VERSION}_${asset_os}_${asset_arch}${asset_ext}"
  local asset_dir="$TEMP_DIR/release-assets/$RUNTIME_RELEASE_REPOSITORY/releases/download/$RUNTIME_RELEASE_VERSION"
  local probe_log="$TEMP_DIR/runtime-install-negative.log"

  printf '%064d  %s\n' 0 "$asset_name" > "$asset_dir/checksums.txt"

  (
    cd "$app_dir"
    "$PHP_BIN" artisan canio:runtime:install "$RUNTIME_RELEASE_VERSION" \
      --path=bin/stagehand \
      --os="$asset_os" \
      --arch="$asset_arch" \
      --force
  ) >"$probe_log" 2>&1 && {
    echo "Expected checksum failure did not occur." >&2
    exit 1
  } || true

  if ! grep -q "Checksum verification failed" "$probe_log"; then
    echo "Checksum probe did not report a checksum failure." >&2
    cat "$probe_log" >&2
    exit 1
  fi
}

restore_good_checksums() {
  local asset_os="$1"
  local asset_arch="$2"
  local asset_ext="$3"
  local asset_name="stagehand_${RUNTIME_RELEASE_VERSION}_${asset_os}_${asset_arch}${asset_ext}"
  local asset_dir="$TEMP_DIR/release-assets/$RUNTIME_RELEASE_REPOSITORY/releases/download/$RUNTIME_RELEASE_VERSION"
  local asset_path="$asset_dir/$asset_name"
  local checksum

  checksum="$(sha256sum "$asset_path" | awk '{print $1}')"
  printf '%s  %s\n' "$checksum" "$asset_name" > "$asset_dir/checksums.txt"
}

run_canio_install() {
  local app_dir="$1"

  (
    cd "$app_dir"
    "$PHP_BIN" artisan canio:install --force
  )
}

run_render_smoke() {
  local app_dir="$1"
  local output_path="$2"
  local smoke_php="$app_dir/canio-launch-smoke.php"

  cat > "$smoke_php" <<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Facade;
use Oxhq\Canio\Facades\Canio;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Facade::setFacadeApplication($app);

$html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <title>Canio Launch Smoke</title>
    <style>
      body { font-family: Arial, sans-serif; margin: 32px; }
      .marker { font-size: 32px; font-weight: 700; }
    </style>
  </head>
  <body>
    <div class="marker" id="marker">pending</div>
    <script>
      document.getElementById('marker').textContent = 'CANIO_JS_SMOKE_OK';
      window.__CANIO_READY__ = true;
    </script>
  </body>
</html>
HTML;

$result = Canio::html($html)
    ->profile('invoice')
    ->title('Canio Launch Smoke')
    ->debug()
    ->watch()
    ->save(__DIR__.'/canio-launch-smoke.pdf');

if (! $result->successful()) {
    fwrite(STDERR, "Render failed\n");
    exit(1);
}

$attributes = $result->toArray();
$artifact = $attributes['artifacts'] ?? null;
$domSnapshotPath = is_array($artifact) ? ($artifact['files']['domSnapshot'] ?? null) : null;

if (! is_string($domSnapshotPath) || $domSnapshotPath === '' || ! is_file($domSnapshotPath)) {
    fwrite(STDERR, "Missing domSnapshot artifact\n");
    exit(1);
}

$domSnapshot = file_get_contents($domSnapshotPath);

if (! is_string($domSnapshot) || ! str_contains($domSnapshot, 'CANIO_JS_SMOKE_OK')) {
    fwrite(STDERR, "DOM snapshot does not contain the JS mutation marker\n");
    exit(1);
}

$pdfFile = __DIR__.'/canio-launch-smoke.pdf';

if (! is_file($pdfFile)) {
    fwrite(STDERR, "Missing PDF output\n");
    exit(1);
}

$pdfBytes = file_get_contents($pdfFile);

if (! is_string($pdfBytes) || ! str_starts_with($pdfBytes, '%PDF-')) {
    fwrite(STDERR, "Output is not a PDF\n");
    exit(1);
}

printf("render-ok|pdf=%s|domSnapshot=%s\n", $pdfFile, $domSnapshotPath);
PHP

  (
    cd "$app_dir"
    "$PHP_BIN" "$smoke_php"
  )

  mv "$app_dir/canio-launch-smoke.pdf" "$output_path"
  rm -f "$smoke_php"
}

main() {
  PHP_BIN="$(resolve_php_bin)"
  COMPOSER_BIN="$(resolve_composer_bin)"
  TEMP_DIR="$(create_temp_dir)"

  local app_dir="$TEMP_DIR/laravel-app"
  local package_repo_dir="$TEMP_DIR/canio-laravel-split"
  local release_root="$TEMP_DIR/release-assets"
  local runtime_state="$TEMP_DIR/runtime-state"
  local runtime_log="$TEMP_DIR/runtime.log"
  local asset_os
  local asset_arch
  local asset_ext=""
  local package_source_url="$PACKAGE_SOURCE_URL"
  local launch_pdf="$TEMP_DIR/canio-launch-smoke.pdf"
  local release_smoke_port="${RELEASE_SMOKE_PORT:-}"
  local runtime_port="${CANIO_RUNTIME_PORT_VALUE:-}"

  asset_os="$(resolve_os)"
  asset_arch="$(resolve_arch)"
  if [[ "$asset_os" == "windows" ]]; then
    asset_ext=".exe"
  fi
  if [[ -z "$release_smoke_port" ]]; then
    release_smoke_port="$(resolve_free_port)"
  fi
  if [[ -z "$runtime_port" ]]; then
    runtime_port="$(resolve_free_port)"
  fi

  if [[ "$PACKAGE_SOURCE_MODE" == "vcs" && -z "$package_source_url" ]]; then
    prepare_package_repo "$package_repo_dir"
    package_source_url="$(file_uri_for_path "$package_repo_dir")"
  fi

  prepare_release_asset "$release_root" "$asset_os" "$asset_arch" "$asset_ext" >/dev/null

  "$PYTHON_BIN" -m http.server "$release_smoke_port" \
    --bind 127.0.0.1 \
    --directory "$release_root" \
    >"$TEMP_DIR/release-server.log" 2>&1 &
  SERVER_PID=$!

  wait_for_http "http://127.0.0.1:$release_smoke_port/$RUNTIME_RELEASE_REPOSITORY/releases/download/$RUNTIME_RELEASE_VERSION/checksums.txt"

  create_laravel_app "$app_dir"
  require_canio_from_source "$app_dir" "$package_source_url"
  append_env "$app_dir" "$runtime_state" "$runtime_log" "$asset_os" "$asset_arch" "$asset_ext" "$release_smoke_port" "$runtime_port"

  probe_runtime_checksum_failure "$app_dir" "$asset_os" "$asset_arch" "$asset_ext"
  restore_good_checksums "$asset_os" "$asset_arch" "$asset_ext"
  run_canio_install "$app_dir"

  local installed_binary="$app_dir/bin/stagehand"
  if [[ "$asset_ext" == ".exe" ]]; then
    installed_binary="$installed_binary.exe"
  fi

  if [[ ! -x "$installed_binary" ]]; then
    echo "Expected installed Stagehand binary at $installed_binary" >&2
    exit 1
  fi

  run_render_smoke "$app_dir" "$launch_pdf"

  echo "Launch smoke passed"
  echo "package_source_mode=$PACKAGE_SOURCE_MODE"
  echo "package_source_url=${package_source_url:-packagist}"
  echo "runtime_release_version=$RUNTIME_RELEASE_VERSION"
  echo "runtime_release_base_url=http://127.0.0.1:$release_smoke_port"
  echo "runtime_port=$runtime_port"
  echo "pdf_path=$launch_pdf"
}

main "$@"
