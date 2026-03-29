#!/usr/bin/env bash

set -euo pipefail

APP_URL="${APP_URL:-http://127.0.0.1:8000}"

required_vars=(
  CANIO_CLOUD_BASE_URL
  CANIO_CLOUD_TOKEN
  CANIO_CLOUD_PROJECT
  CANIO_CLOUD_ENVIRONMENT
)

for var in "${required_vars[@]}"; do
  if [[ -z "${!var:-}" ]]; then
    echo "Missing required environment variable: ${var}" >&2
    exit 1
  fi
done

smoke_preview() {
  local mode="$1"
  local tmp
  tmp="$(mktemp -t "canio-${mode}.pdf")"
  trap 'rm -f "$tmp"' RETURN

  curl -fsS -o "$tmp" "${APP_URL}/canio/cloud/${mode}/preview"

  if [[ "$(head -c 4 "$tmp")" != '%PDF' ]]; then
    echo "Smoke request for ${mode} did not return a PDF." >&2
    exit 1
  fi

  echo "Smoke OK: ${mode}"
}

smoke_preview sync
smoke_preview managed

echo "Canio Cloud smoke complete."
