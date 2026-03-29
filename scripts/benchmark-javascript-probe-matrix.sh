#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

exec php "$ROOT_DIR/benchmarks/javascript_probe_matrix.php" "$@"
