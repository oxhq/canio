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
git config --local --unset-all http.https://github.com/.extraheader || true

split_sha="$(git subtree split --prefix="$PREFIX" "$SOURCE_REF")"
current_branch_sha="$(git ls-remote "$TARGET_REMOTE" "refs/heads/$TARGET_BRANCH" | awk '{print $1}')"

if [[ "$current_branch_sha" != "$split_sha" ]]; then
  git push "$TARGET_REMOTE" "$split_sha:refs/heads/$TARGET_BRANCH" --force
fi

if [[ "$REF_TYPE" == "tag" && "$REF_NAME" == v* ]]; then
  current_tag_sha="$(git ls-remote "$TARGET_REMOTE" "refs/tags/$REF_NAME" | awk '{print $1}')"

  if [[ "$current_tag_sha" != "$split_sha" ]]; then
    git push "$TARGET_REMOTE" "$split_sha:refs/tags/$REF_NAME" --force
  fi
fi

echo "Published $PREFIX from $SOURCE_REF to $TARGET_REMOTE ($split_sha)"
