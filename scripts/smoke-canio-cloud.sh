#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CLOUD_DIR="${CANIO_CLOUD_DIR:-$ROOT_DIR/../../../canio-cloud}"
EXAMPLE_ROOT="$ROOT_DIR/examples/laravel-app"
EXAMPLE_APP_DIR="${CANIO_EXAMPLE_APP_DIR:-$EXAMPLE_ROOT/app}"
STAGEHAND_BIN="$ROOT_DIR/bin/stagehand"
SCENARIO="${1:-all}"

if [[ ! -d "$CLOUD_DIR" ]]; then
  echo "Canio Cloud directory not found at $CLOUD_DIR" >&2
  exit 1
fi

resolve_php_bin() {
  if [[ -x "$HOME/Library/Application Support/Herd/bin/php85" ]]; then
    printf '%s\n' "$HOME/Library/Application Support/Herd/bin/php85"
    return
  fi

  if command -v php >/dev/null 2>&1; then
    command -v php
    return
  fi

  echo "Unable to resolve a PHP binary." >&2
  exit 1
}

resolve_composer_bin() {
  if [[ -x "$HOME/Library/Application Support/Herd/bin/composer" ]]; then
    printf '%s\n' "$HOME/Library/Application Support/Herd/bin/composer"
    return
  fi

  if command -v composer >/dev/null 2>&1; then
    command -v composer
    return
  fi

  echo "Unable to resolve Composer." >&2
  exit 1
}

PHP_BIN="$(resolve_php_bin)"
COMPOSER_BIN="$(resolve_composer_bin)"
export PATH="$(dirname "$PHP_BIN"):$(dirname "$COMPOSER_BIN"):$PATH"

CLOUD_PORT="${CANIO_CLOUD_PORT:-9081}"
MANAGED_STAGEHAND_PORT="${CANIO_MANAGED_STAGEHAND_PORT:-9514}"
SYNC_STAGEHAND_PORT="${CANIO_SYNC_STAGEHAND_PORT:-9521}"
EXAMPLE_PORT="${CANIO_EXAMPLE_PORT:-18080}"
TMP_DIR="${TMPDIR:-/tmp}/canio-cloud-smoke-$RANDOM"
CLOUD_DB_PATH="${CANIO_CLOUD_DB_PATH:-$TMP_DIR/canio-cloud.sqlite}"
KEEP_WORKDIR="${CANIO_SMOKE_KEEP_WORKDIR:-0}"

mkdir -p "$TMP_DIR"

cleanup() {
  local code=$?

  if [[ -n "${EXAMPLE_PID:-}" ]] && kill -0 "$EXAMPLE_PID" 2>/dev/null; then
    kill "$EXAMPLE_PID" 2>/dev/null || true
  fi

  if [[ -n "${CLOUD_PID:-}" ]] && kill -0 "$CLOUD_PID" 2>/dev/null; then
    kill "$CLOUD_PID" 2>/dev/null || true
  fi

  if [[ -n "${MANAGED_STAGEHAND_PID:-}" ]] && kill -0 "$MANAGED_STAGEHAND_PID" 2>/dev/null; then
    kill "$MANAGED_STAGEHAND_PID" 2>/dev/null || true
  fi

  if [[ -n "${CLOUD_QUEUE_WORKER_PID:-}" ]] && kill -0 "$CLOUD_QUEUE_WORKER_PID" 2>/dev/null; then
    kill "$CLOUD_QUEUE_WORKER_PID" 2>/dev/null || true
  fi

  stop_stagehand_on_port "$EXAMPLE_PORT"
  stop_stagehand_on_port "$SYNC_STAGEHAND_PORT"
  stop_stagehand_on_port "$MANAGED_STAGEHAND_PORT"

  if [[ -f "$TMP_DIR/example.env.backup" ]]; then
    cp "$TMP_DIR/example.env.backup" "$EXAMPLE_APP_DIR/.env"
  fi

  if [[ -f "$TMP_DIR/cloud.env.backup" ]]; then
    cp "$TMP_DIR/cloud.env.backup" "$CLOUD_DIR/.env"
  fi

  if [[ "$KEEP_WORKDIR" == "1" ]]; then
    echo "Keeping smoke workspace at $TMP_DIR"
  else
    rm -rf "$TMP_DIR"
  fi

  exit "$code"
}

trap cleanup EXIT INT TERM

stop_stagehand_on_port() {
  local port="$1"
  local pids=""

  if command -v lsof >/dev/null 2>&1; then
    pids="$(lsof -ti "tcp:$port" 2>/dev/null || true)"
  fi

  if [[ -n "$pids" ]]; then
    kill $pids 2>/dev/null || true
    sleep 1
  fi
}

wait_for_url() {
  local url="$1"
  local attempts="${2:-60}"

  for ((i=1; i<=attempts; i++)); do
    if curl -fsS "$url" >/dev/null 2>&1; then
      return 0
    fi

    sleep 1
  done

  echo "Timed out waiting for $url" >&2
  return 1
}

assert_db_count() {
  local db_path="$1"
  local sql="$2"
  local expected_min="$3"

  local count
  count="$("$PHP_BIN" -r '
    $db = new PDO("sqlite:" . $argv[1]);
    $value = $db->query($argv[2])->fetchColumn();
    echo (int) $value;
  ' "$db_path" "$sql")"

  if (( count < expected_min )); then
    echo "Expected at least $expected_min rows for query: $sql. Got $count." >&2
    exit 1
  fi
}

wait_for_db_count() {
  local db_path="$1"
  local sql="$2"
  local expected_min="$3"
  local timeout_seconds="${4:-30}"
  local elapsed=0

  while (( elapsed < timeout_seconds )); do
    local count
    count="$("$PHP_BIN" -r '
      $db = new PDO("sqlite:" . $argv[1]);
      $value = $db->query($argv[2])->fetchColumn();
      echo (int) $value;
    ' "$db_path" "$sql")"

    if (( count >= expected_min )); then
      return 0
    fi

    sleep 1
    elapsed=$((elapsed + 1))
  done

  echo "Expected at least $expected_min rows for query: $sql. Got less than $expected_min after ${timeout_seconds}s." >&2
  return 1
}

extract_bootstrap_value() {
  local output="$1"
  local field="$2"

  printf '%s\n' "$output" | awk -v field="$field" '
    $0 ~ "^" field ":[[:space:]]*" {
      sub("^" field ":[[:space:]]*", "", $0);
      print $0;
      next;
    }

    $0 ~ field && index($0, "|") {
      n = split($0, parts, "|");
      if (n >= 3) {
        gsub(/^[ \t]+|[ \t]+$/, "", parts[3]);
        print parts[3];
      }
    }
  ' | tail -n 1
}

prepare_example_app() {
  "$EXAMPLE_ROOT/create-project.sh" "$EXAMPLE_APP_DIR" >/dev/null
  cp "$EXAMPLE_APP_DIR/.env" "$TMP_DIR/example.env.backup"
  (
    cd "$EXAMPLE_APP_DIR"
    "$PHP_BIN" artisan vendor:publish --tag=canio-config --force >/dev/null
  )
}

inject_template_smoke_routes() {
  local routes_file="$EXAMPLE_APP_DIR/routes/web.php"

  if grep -q "/canio/cloud/template-smoke/run/preview" "$routes_file"; then
    return
  fi

  cat >> "$routes_file" <<'EOF'

Route::prefix('/canio/cloud/template-smoke/run')->group(function () use ($exampleInvoice): void {
    Route::get('preview', function () use ($exampleInvoice): Response {
        config()->set('canio.cloud.mode', 'managed');

        return Canio::template('invoice.default', ['invoice' => $exampleInvoice()])
            ->profile('invoice')
            ->title('Canio Cloud Template Preview')
            ->debug()
            ->watch()
            ->stream('example-template.pdf');
    });

    Route::get('dispatch', function () use ($exampleInvoice): JsonResponse {
        config()->set('canio.cloud.mode', 'managed');

        $job = Canio::template('invoice.default', ['invoice' => $exampleInvoice()])
            ->profile('invoice')
            ->title('Canio Cloud Template Async Job')
            ->debug()
            ->watch()
            ->dispatch();

        return response()->json([
            'mode' => 'template',
            'jobId' => $job->id(),
        ]);
    });
});
EOF
}

start_cloud() {
  cp "$CLOUD_DIR/.env" "$TMP_DIR/cloud.env.backup"
  "$PHP_BIN" -r '
    $file = $argv[1];
    $baseUrl = $argv[2];
    $lines = file_exists($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
    $dropPrefixes = [
        "DB_CONNECTION=",
        "DB_DATABASE=",
        "QUEUE_CONNECTION=",
        "SESSION_DRIVER=",
        "CANIO_MANAGED_RUNTIME_BASE_URL=",
    ];
    $filtered = [];

    foreach ($lines as $line) {
        $shouldDrop = false;
        foreach ($dropPrefixes as $prefix) {
            if (str_starts_with($line, $prefix)) {
                $shouldDrop = true;
                break;
            }
        }

        if (! $shouldDrop) {
            $filtered[] = $line;
        }
    }

    $filtered[] = "DB_CONNECTION=sqlite";
    $filtered[] = "DB_DATABASE=".$argv[3];
    $filtered[] = "QUEUE_CONNECTION=database";
    $filtered[] = "SESSION_DRIVER=array";
    $filtered[] = "CANIO_MANAGED_RUNTIME_BASE_URL=".$baseUrl;
    file_put_contents($file, implode(PHP_EOL, $filtered).PHP_EOL);
  ' "$CLOUD_DIR/.env" "http://127.0.0.1:$MANAGED_STAGEHAND_PORT" "$CLOUD_DB_PATH"

  (
    cd "$CLOUD_DIR"
    "$PHP_BIN" artisan config:clear >/dev/null
    "$PHP_BIN" artisan serve --host=127.0.0.1 --port="$CLOUD_PORT" >"$TMP_DIR/canio-cloud.log" 2>&1
  ) &
  CLOUD_PID=$!
  wait_for_url "http://127.0.0.1:$CLOUD_PORT/up"
}

bootstrap_cloud() {
  local workspace_name="$1"
  local plan="${2:-pro}"
  local output

  output="$(
    cd "$CLOUD_DIR" && \
      "$PHP_BIN" artisan config:clear >/dev/null && \
      DB_CONNECTION=sqlite DB_DATABASE="$CLOUD_DB_PATH" QUEUE_CONNECTION=database SESSION_DRIVER=array "$PHP_BIN" artisan migrate --force >/dev/null && \
      DB_CONNECTION=sqlite DB_DATABASE="$CLOUD_DB_PATH" QUEUE_CONNECTION=database SESSION_DRIVER=array "$PHP_BIN" artisan canio:cloud:bootstrap "$workspace_name" "Invoices" "Production" --plan="$plan"
  )"

  CLOUD_PROJECT_KEY="$(extract_bootstrap_value "$output" 'Project')"
  CLOUD_ENVIRONMENT_KEY="$(extract_bootstrap_value "$output" 'Environment')"
  CLOUD_AGENT_TOKEN="$(extract_bootstrap_value "$output" 'Token')"

  if [[ -z "${CLOUD_PROJECT_KEY:-}" || -z "${CLOUD_ENVIRONMENT_KEY:-}" || -z "${CLOUD_AGENT_TOKEN:-}" ]]; then
    echo "Unable to extract Canio Cloud bootstrap values." >&2
    echo "$output" >&2
    exit 1
  fi

  if [[ -n "${CANIO_CLOUD_FIXTURE_SCRIPT:-}" ]]; then
    if [[ ! -f "$CANIO_CLOUD_FIXTURE_SCRIPT" ]]; then
      echo "Canio Cloud fixture script not found at $CANIO_CLOUD_FIXTURE_SCRIPT" >&2
      exit 1
    fi

    (
      cd "$CLOUD_DIR"
      DB_CONNECTION=sqlite \
      DB_DATABASE="$CLOUD_DB_PATH" \
      QUEUE_CONNECTION=database \
        SESSION_DRIVER=array \
        "$PHP_BIN" "$CANIO_CLOUD_FIXTURE_SCRIPT"
    )
  fi
}

start_example_app() {
  if [[ -n "${EXAMPLE_PID:-}" ]] && kill -0 "$EXAMPLE_PID" 2>/dev/null; then
    kill "$EXAMPLE_PID" 2>/dev/null || true
    sleep 1
  fi

  stop_stagehand_on_port "$EXAMPLE_PORT"

  (
    cd "$EXAMPLE_APP_DIR"
    "$PHP_BIN" artisan config:clear >/dev/null
    "$PHP_BIN" artisan serve --host=127.0.0.1 --port="$EXAMPLE_PORT" >"$TMP_DIR/example.log" 2>&1
  ) &
  EXAMPLE_PID=$!
  wait_for_url "http://127.0.0.1:$EXAMPLE_PORT/"
}

write_example_env() {
  local mode="$1"
  local runtime_port="$2"
  local runtime_state="$TMP_DIR/example-runtime-$mode"
  local runtime_log="$TMP_DIR/example-runtime-$mode.log"
  local chromium_dir="$TMP_DIR/example-chromium-$mode"
  local env_file="$EXAMPLE_APP_DIR/.env"

  cp "$TMP_DIR/example.env.backup" "$EXAMPLE_APP_DIR/.env"
  "$PHP_BIN" -r '
    $file = $argv[1];
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $dropPrefixes = [
        "APP_URL=",
        "CANIO_RUNTIME_MODE=",
        "CANIO_RUNTIME_AUTO_START=",
        "CANIO_RUNTIME_AUTO_INSTALL=",
        "CANIO_RUNTIME_BASE_URL=",
        "CANIO_RUNTIME_PORT=",
        "CANIO_RUNTIME_STATE_PATH=",
        "CANIO_RUNTIME_LOG_PATH=",
        "CANIO_CHROMIUM_USER_DATA_DIR=",
        "CANIO_OPS_ENABLED=",
        "CANIO_CLOUD_MODE=",
        "CANIO_CLOUD_BASE_URL=",
        "CANIO_CLOUD_TOKEN=",
        "CANIO_CLOUD_PROJECT=",
        "CANIO_CLOUD_ENVIRONMENT=",
        "CANIO_CLOUD_TIMEOUT=",
        "CANIO_CLOUD_SYNC_ENABLED=",
        "CANIO_CLOUD_SYNC_INCLUDE_ARTIFACTS=",
    ];
    $filtered = [];

    foreach ($lines as $line) {
        $shouldDrop = false;
        foreach ($dropPrefixes as $prefix) {
            if (str_starts_with($line, $prefix)) {
                $shouldDrop = true;
                break;
            }
        }

        if (! $shouldDrop) {
            $filtered[] = $line;
        }
    }

    file_put_contents($file, implode(PHP_EOL, $filtered).PHP_EOL);
  ' "$env_file"

  cat >> "$EXAMPLE_APP_DIR/.env" <<EOF

APP_URL=http://127.0.0.1:$EXAMPLE_PORT
CANIO_RUNTIME_MODE=embedded
CANIO_RUNTIME_AUTO_START=true
CANIO_RUNTIME_AUTO_INSTALL=true
CANIO_RUNTIME_BASE_URL=http://127.0.0.1:$runtime_port
CANIO_RUNTIME_PORT=$runtime_port
CANIO_RUNTIME_STATE_PATH=$runtime_state
CANIO_RUNTIME_LOG_PATH=$runtime_log
CANIO_CHROMIUM_USER_DATA_DIR=$chromium_dir
CANIO_OPS_ENABLED=false
CANIO_CLOUD_MODE=$mode
CANIO_CLOUD_BASE_URL=http://127.0.0.1:$CLOUD_PORT
CANIO_CLOUD_TOKEN=$CLOUD_AGENT_TOKEN
CANIO_CLOUD_PROJECT=$CLOUD_PROJECT_KEY
CANIO_CLOUD_ENVIRONMENT=$CLOUD_ENVIRONMENT_KEY
EOF
}

start_managed_stagehand() {
  if [[ ! -x "$STAGEHAND_BIN" ]]; then
    "$ROOT_DIR/scripts/build-stagehand.sh" >/dev/null
  fi

  "$STAGEHAND_BIN" serve \
    --host 127.0.0.1 \
    --port "$MANAGED_STAGEHAND_PORT" \
    --state-dir "$TMP_DIR/managed-stagehand" \
    >"$TMP_DIR/managed-stagehand.log" 2>&1 &
  MANAGED_STAGEHAND_PID=$!

  wait_for_url "http://127.0.0.1:$MANAGED_STAGEHAND_PORT/healthz"
}

start_cloud_queue_worker() {
  if [[ -n "${CLOUD_QUEUE_WORKER_PID:-}" ]] && kill -0 "$CLOUD_QUEUE_WORKER_PID" 2>/dev/null; then
    return
  fi

  (
    cd "$CLOUD_DIR"
    DB_CONNECTION=sqlite \
    DB_DATABASE="$CLOUD_DB_PATH" \
    QUEUE_CONNECTION=database \
    SESSION_DRIVER=array \
      "$PHP_BIN" artisan queue:work --sleep=1 --tries=1 --queue=default,canio-managed >"$TMP_DIR/canio-cloud-queue.log" 2>&1
  ) &
  CLOUD_QUEUE_WORKER_PID=$!
}

run_sync_smoke() {
  echo "==> Running sync smoke"
  stop_stagehand_on_port "$SYNC_STAGEHAND_PORT"
  write_example_env "sync" "$SYNC_STAGEHAND_PORT"
  start_cloud_queue_worker
  start_example_app
  curl -fsS "http://127.0.0.1:$EXAMPLE_PORT/canio/cloud/sync/preview" >/dev/null
  wait_for_db_count "$CLOUD_DB_PATH" "select count(*) from render_job_records where source = 'oss-runtime'" 1
  wait_for_db_count "$CLOUD_DB_PATH" "select count(*) from artifact_records" 1
}

run_managed_smoke() {
  echo "==> Running managed smoke"
  stop_stagehand_on_port "$MANAGED_STAGEHAND_PORT"
  write_example_env "managed" "$MANAGED_STAGEHAND_PORT"
  start_cloud_queue_worker
  start_example_app
  start_managed_stagehand
  curl -fsS "http://127.0.0.1:$EXAMPLE_PORT/canio/cloud/managed/preview" >/dev/null
  wait_for_db_count "$CLOUD_DB_PATH" "select count(*) from render_job_records where source = 'managed'" 1
  kill "$MANAGED_STAGEHAND_PID" 2>/dev/null || true
  unset MANAGED_STAGEHAND_PID
}

run_template_smoke() {
  echo "==> Running cloud template smoke"
  stop_stagehand_on_port "$MANAGED_STAGEHAND_PORT"
  write_example_env "managed" "$MANAGED_STAGEHAND_PORT"
  start_cloud_queue_worker
  start_example_app
  start_managed_stagehand
  curl -fsS "http://127.0.0.1:$EXAMPLE_PORT/canio/cloud/template-smoke/run/preview" >/dev/null
  wait_for_db_count "$CLOUD_DB_PATH" "select count(*) from document_templates where slug = 'invoice.default'" 1
  wait_for_db_count "$CLOUD_DB_PATH" "select count(*) from document_template_versions where document_template_id is not null" 1
  wait_for_db_count "$CLOUD_DB_PATH" "select count(*) from template_releases where document_template_id is not null" 1
  wait_for_db_count "$CLOUD_DB_PATH" "select count(*) from render_job_records where source = 'managed' and document_template_id is not null" 1
  wait_for_db_count "$CLOUD_DB_PATH" "select count(*) from artifact_records where document_template_id is not null" 1
  kill "$MANAGED_STAGEHAND_PID" 2>/dev/null || true
  unset MANAGED_STAGEHAND_PID
}

prepare_example_app
inject_template_smoke_routes
start_cloud
bootstrap_cloud "Smoke $(date +%s)" "pro"

case "$SCENARIO" in
  sync)
    run_sync_smoke
    ;;
  managed)
    run_managed_smoke
    ;;
  template)
    run_template_smoke
    ;;
  all)
    run_sync_smoke
    run_managed_smoke
    run_template_smoke
    ;;
  *)
    echo "Usage: $0 [sync|managed|template|all]" >&2
    exit 1
    ;;
esac

echo "Canio Cloud smoke completed successfully for scenario: $SCENARIO"
