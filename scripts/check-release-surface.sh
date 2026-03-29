#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PACKAGE_VERSION_FILE="$ROOT_DIR/packages/laravel/src/Support/PackageVersion.php"
RUNTIME_VERSION_FILE="$ROOT_DIR/runtime/stagehand/internal/version/version.go"
PACKAGE_COMPOSER_FILE="$ROOT_DIR/packages/laravel/composer.json"
EXPECTED_TAG="${1:-}"

package_tag="$(sed -n "s/.*TAG = '\\(v[^']*\\)';/\\1/p" "$PACKAGE_VERSION_FILE")"
runtime_version="$(sed -n 's/.*const Value = "\(v[^\"]*\)"/\1/p' "$RUNTIME_VERSION_FILE")"
composer_version_field="$(php -r '
$json = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
echo array_key_exists("version", $json) ? (string) $json["version"] : "";
' "$PACKAGE_COMPOSER_FILE")"

if [[ -z "$package_tag" ]]; then
  echo "Unable to resolve package tag from $PACKAGE_VERSION_FILE" >&2
  exit 1
fi

if [[ -z "$runtime_version" ]]; then
  echo "Unable to resolve runtime version from $RUNTIME_VERSION_FILE" >&2
  exit 1
fi

if [[ -n "$composer_version_field" ]]; then
  echo "packages/laravel/composer.json must not declare an explicit version field." >&2
  exit 1
fi

if [[ "$package_tag" != "$runtime_version" ]]; then
  echo "Package tag ($package_tag) and runtime version ($runtime_version) diverge." >&2
  exit 1
fi

if [[ -n "$EXPECTED_TAG" && "$package_tag" != "$EXPECTED_TAG" ]]; then
  echo "Expected release tag $EXPECTED_TAG but repo surfaces declare $package_tag." >&2
  exit 1
fi

echo "Release surfaces aligned at $package_tag"
