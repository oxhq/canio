#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TAG="${1:-}"
PACKAGE_NAME="${CANIO_PACKAGE_NAME:-oxhq/canio}"
SPLIT_REPO="${CANIO_SPLIT_REPO:-oxhq/canio-laravel}"
MAX_ATTEMPTS="${CANIO_DISTRIBUTION_VERIFY_ATTEMPTS:-30}"
SLEEP_SECONDS="${CANIO_DISTRIBUTION_VERIFY_SLEEP_SECONDS:-10}"

if [[ -z "$TAG" ]]; then
  echo "Usage: $0 <tag>" >&2
  exit 1
fi

assert_release_assets() {
  local names
  names="$(gh release view "$TAG" --repo "$PACKAGE_NAME" --json assets --jq '.assets[].name')"

  for expected in \
    "checksums.txt" \
    "stagehand_${TAG}_linux_amd64" \
    "stagehand_${TAG}_linux_arm64" \
    "stagehand_${TAG}_darwin_amd64" \
    "stagehand_${TAG}_darwin_arm64" \
    "stagehand_${TAG}_windows_amd64.exe" \
    "stagehand_${TAG}_windows_arm64.exe"; do
    if ! grep -qx "$expected" <<<"$names"; then
      echo "Missing release asset $expected on $PACKAGE_NAME release $TAG" >&2
      exit 1
    fi
  done
}

wait_for_split_tag() {
  local attempt
  for ((attempt=1; attempt<=MAX_ATTEMPTS; attempt++)); do
    if gh api "repos/$SPLIT_REPO/git/ref/tags/$TAG" >/dev/null 2>&1; then
      return 0
    fi
    sleep "$SLEEP_SECONDS"
  done

  echo "Timed out waiting for split repo tag $TAG on $SPLIT_REPO" >&2
  exit 1
}

wait_for_packagist() {
  local attempt
  local version
  for ((attempt=1; attempt<=MAX_ATTEMPTS; attempt++)); do
    version="$(curl -fsSL "https://repo.packagist.org/p2/${PACKAGE_NAME}.json" | jq -r ".packages[\"$PACKAGE_NAME\"][] | select(.version == \"$TAG\") | .version" 2>/dev/null || true)"
    if [[ "$version" == "$TAG" ]]; then
      return 0
    fi
    sleep "$SLEEP_SECONDS"
  done

  echo "Timed out waiting for Packagist version $TAG for $PACKAGE_NAME" >&2
  exit 1
}

main() {
  assert_release_assets
  wait_for_split_tag
  wait_for_packagist

  export CANIO_PACKAGE_SOURCE_MODE=packagist
  export CANIO_PACKAGE_CONSTRAINT="$TAG"
  export CANIO_RUNTIME_RELEASE_SOURCE=github
  export CANIO_RUNTIME_RELEASE_VERSION="$TAG"

  cd "$ROOT_DIR"
  ./scripts/smoke-launch.sh
}

main "$@"
