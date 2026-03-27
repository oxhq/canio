#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BINARY_PATH="${CANIO_BENCH_BINARY:-$ROOT_DIR/bin/stagehand}"

if [[ ! -x "$BINARY_PATH" ]]; then
  "$ROOT_DIR/scripts/build-stagehand.sh" "$BINARY_PATH"
fi

export CANIO_BENCH_BINARY="$BINARY_PATH"
exec php "$ROOT_DIR/benchmarks/run.php" "$@"
