#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_PATH="${1:-$ROOT_DIR/bin/stagehand}"

mkdir -p "$(dirname "$OUTPUT_PATH")"

cd "$ROOT_DIR/runtime/stagehand"
go build -o "$OUTPUT_PATH" ./cmd/stagehand

echo "Built stagehand at $OUTPUT_PATH"
