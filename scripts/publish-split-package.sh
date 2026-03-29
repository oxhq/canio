#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

TARGET_REMOTE="${SPLIT_REPO_REMOTE_URL:-${SPLIT_REPO_SSH_URL:-}}"
SOURCE_REF="${SPLIT_SOURCE_REF:-${GITHUB_SHA:-HEAD}}"
TARGET_BRANCH="${SPLIT_TARGET_BRANCH:-main}"
PREFIX="${SPLIT_PREFIX:-packages/laravel}"
REF_TYPE="${GITHUB_REF_TYPE:-branch}"
REF_NAME="${GITHUB_REF_NAME:-main}"

if [[ -z "$TARGET_REMOTE" ]]; then
  echo "SPLIT_REPO_SSH_URL is required." >&2
  exit 1
fi

git config user.name "${GIT_AUTHOR_NAME:-github-actions[bot]}"
git config user.email "${GIT_AUTHOR_EMAIL:-41898282+github-actions[bot]@users.noreply.github.com}"

split_sha="$(git subtree split --prefix="$PREFIX" "$SOURCE_REF")"

git push "$TARGET_REMOTE" "$split_sha:refs/heads/$TARGET_BRANCH" --force

if [[ "$REF_TYPE" == "tag" && "$REF_NAME" == v* ]]; then
  git push "$TARGET_REMOTE" "$split_sha:refs/tags/$REF_NAME" --force
fi

echo "Published $PREFIX from $SOURCE_REF to $TARGET_REMOTE ($split_sha)"
