#!/usr/bin/env bash

set -euo pipefail

SOURCE_REPO="${1:-oxhq/canio}"
TARGET_REPO="${2:-oxhq/canio-laravel}"
VISIBILITY="${3:-public}"
TOKEN_SECRET_NAME="CANIO_LARAVEL_SPLIT_PUSH_TOKEN"

if ! command -v gh >/dev/null 2>&1; then
  echo "gh CLI is required." >&2
  exit 1
fi

if ! gh repo view "$TARGET_REPO" >/dev/null 2>&1; then
  gh repo create "$TARGET_REPO" "--${VISIBILITY}" --description "Public split repository for the oxhq/canio Laravel package" --disable-wiki
else
  gh repo edit "$TARGET_REPO" --visibility "$VISIBILITY" --accept-visibility-change-consequences --description "Public split repository for the oxhq/canio Laravel package"
fi

gh auth token | gh secret set "$TOKEN_SECRET_NAME" --repo "$SOURCE_REPO"

echo "Configured split repo $TARGET_REPO and stored $TOKEN_SECRET_NAME on $SOURCE_REPO"
