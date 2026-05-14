#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PACKAGE_VERSION_FILE="$ROOT_DIR/packages/laravel/src/Support/PackageVersion.php"
RUNTIME_VERSION_FILE="$ROOT_DIR/runtime/stagehand/internal/version/version.go"
PACKAGE_COMPOSER_FILE="$ROOT_DIR/packages/laravel/composer.json"
ROOT_README_FILE="$ROOT_DIR/README.md"
ROOT_CHANGELOG_FILE="$ROOT_DIR/CHANGELOG.md"
PACKAGE_CHANGELOG_FILE="$ROOT_DIR/packages/laravel/CHANGELOG.md"
DEVELOPMENT_DOC_FILE="$ROOT_DIR/docs/development.md"
SITE_INDEX_FILE="$ROOT_DIR/site/index.html"
SITE_INSTALL_FILE="$ROOT_DIR/site/install/index.html"
SITE_DEPLOY_FILE="$ROOT_DIR/site/deploy/index.html"
EXPECTED_TAG="${1:-}"

package_tag="$(sed -n "s/.*TAG = '\\(v[^']*\\)';/\\1/p" "$PACKAGE_VERSION_FILE")"
runtime_version="$(sed -n 's/.*const Value = "\(v[^\"]*\)"/\1/p' "$RUNTIME_VERSION_FILE")"
composer_version_field="$(php -r '
$json = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
echo array_key_exists("version", $json) ? (string) $json["version"] : "";
' "$PACKAGE_COMPOSER_FILE")"

assert_contains() {
  local file="$1"
  local needle="$2"
  local label="$3"

  if [[ ! -f "$file" ]]; then
    echo "Missing $label at $file" >&2
    exit 1
  fi

  if ! grep -Fq "$needle" "$file"; then
    echo "$label does not contain expected release surface: $needle" >&2
    exit 1
  fi
}

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

release_notes_file="$ROOT_DIR/docs/releases/$package_tag.md"
package_version="${package_tag#v}"

assert_contains "$release_notes_file" "# $package_tag" "release notes"
assert_contains "$ROOT_README_FILE" "docs/releases/$package_tag.md" "root README"
assert_contains "$ROOT_README_FILE" "current stable release line for the Laravel package is \`$package_tag\`" "root README"
assert_contains "$ROOT_CHANGELOG_FILE" "## [$package_version]" "root changelog"
assert_contains "$PACKAGE_CHANGELOG_FILE" "## [$package_version]" "package changelog"
assert_contains "$DEVELOPMENT_DOC_FILE" "published \`$package_tag\` GitHub release assets" "development docs"
assert_contains "$SITE_INDEX_FILE" "releases/tag/$package_tag" "site index"
assert_contains "$SITE_INSTALL_FILE" "releases/tag/$package_tag" "site install page"
assert_contains "$SITE_DEPLOY_FILE" "default local renderer in $package_tag" "site deploy page"

echo "Release surfaces aligned at $package_tag"
