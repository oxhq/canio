# Stagehand

`Stagehand` is the mandatory runtime for Canio.
This first scaffold keeps the runtime intentionally small while locking the operational shape:

- HTTP daemon mode
- JSON render contract
- runtime status, restart, and cleanup endpoints
- Prometheus-style metrics and structured runtime/request logs
- async job queue with polling endpoints, SSE job-event streams, cancellation, retries, and dead-letter persistence
- real PDF rendering via Chrome/Chromium and CDP
- browser pooling with bounded queue backpressure and observable `busy` / `waiting` metrics
- artifact persistence and replay when debug/watch is active, including screenshot, DOM snapshot, console log, and network log files
- optional Redis-backed async jobs transport for multi-process queueing on the same shared state directory, now with stream acknowledgements, leases, heartbeats, stale-delivery reclaim, and webhook push for lifecycle events

## Commands

```bash
go run ./cmd/stagehand serve --browser-pool-size 2 --browser-pool-warm 1
go run ./cmd/stagehand serve --renderer-driver rod-cdp
go run ./cmd/stagehand serve --renderer-driver local-cdp
go run ./cmd/stagehand serve --renderer-driver remote-cdp --remote-cdp-endpoint ws://127.0.0.1:9222/devtools/browser/<id>
go run ./cmd/stagehand serve --job-backend redis --job-redis-host 127.0.0.1 --job-redis-port 6379
go run ./cmd/stagehand serve --job-backend redis --job-lease-timeout 45 --job-heartbeat-interval 10
go run ./cmd/stagehand serve --auth-shared-secret secret-123 --event-webhook-url http://127.0.0.1:8000/canio/webhooks/stagehand/jobs --event-webhook-secret hook-secret
go run ./cmd/stagehand serve --log-format json --request-logging=true
go run ./cmd/stagehand render --request request.json
go run ./cmd/stagehand replay --artifact-id art-123 --state-dir /path/to/runtime-state
go run ./cmd/stagehand cleanup --state-dir /path/to/runtime-state --jobs-older-than-days 14 --artifacts-older-than-days 14
go run ./cmd/stagehand version
```

## Endpoints

- `GET /healthz`
- `GET /metrics`
- `GET /v1/runtime/status`
- `POST /v1/runtime/restart`
- `POST /v1/runtime/cleanup`
- `POST /v1/renders`
- `POST /v1/jobs`
- `GET /v1/jobs?limit=20`
- `GET /v1/jobs/{id}`
- `GET /v1/jobs/{id}/events`
- `POST /v1/jobs/{id}/cancel`
- `GET /v1/artifacts?limit=20`
- `GET /v1/artifacts/{id}`
- `GET /v1/dead-letters`
- `POST /v1/dead-letters/requeues`
- `POST /v1/dead-letters/cleanup`
- `POST /v1/replays`

When `debug.enabled` or `debug.watch` is active, the artifact directory now includes:

- `render-spec.json`
- `metadata.json`
- `source.html` when the request originated from normalized HTML
- `page-screenshot.png`
- `dom-snapshot.html`
- `console-log.json`
- `network-log.json`
- the final PDF

If `--user-data-dir` is set, Stagehand treats it as the base directory for per-browser pool profiles so multiple warmed browsers can run in parallel without profile lock conflicts.

## Renderer Drivers

Stagehand currently supports three renderer drivers:

- `rod-cdp`: the default native-Go path. Stagehand uses Rod for local browser process management and render operations while preserving the same `RenderSpec -> RenderResult` contract.
- `local-cdp`: direct CDP fallback. Stagehand launches local Chrome/Chromium and controls it through Chrome DevTools Protocol.
- `remote-cdp`: Stagehand connects to an existing Chrome/CDP endpoint through `--remote-cdp-endpoint`.

All drivers keep the same render contract, artifact storage, queue behavior, and Laravel API. Use `remote-cdp` when the app host should not install or supervise Chromium directly.

`rod-cdp` is validated by browser runtime tests for allocator startup/cleanup lifecycle, pool reuse and eviction behavior, HTML-to-PDF smoke parity against `local-cdp`, and Rod-native debug artifacts for screenshots, DOM snapshots, and console capture.
Treat it as the guarded default while platform and failure-mode coverage continue to expand.

When Stagehand is launched through the Laravel package, `php artisan canio:browser:install` can install a Chrome for Testing bundle and the package passes its executable path through `--chromium-path`. Both `local-cdp` and `rod-cdp` can use that bundled browser. Stagehand itself stays focused on rendering and process control.

Async jobs are persisted under `state/jobs/<jobId>/` with the submitted `render-spec.json` plus `job.json`, so completed jobs can still be queried after a runtime restart and interrupted jobs are marked as failed on boot.

When `--job-backend redis` is enabled, Stagehand still keeps job metadata on disk, but it uses Redis as the queue transport. This is useful when you want multiple Stagehand processes to drain the same queue while sharing the same state directory.

Named queues are now routed for real:

- the default queue uses the configured `--job-redis-queue-key` directly
- `queue.queue = "pdfs"` maps to `<queue_key>:pdfs`
- `queue.connection` must either be omitted or match the configured backend, so a runtime on `memory` will reject jobs that explicitly request `redis`
- Redis transport uses streams plus consumer groups, so jobs are only acknowledged after a terminal result is persisted
- `--job-lease-timeout` controls when another worker may reclaim a stale Redis delivery
- `--job-heartbeat-interval` keeps active renders leased while the worker is still alive
- `execution.retries` now schedules real retries with exponential backoff (`1s`, `2s`, `4s`, capped at `30s` by default)
- failed jobs that exhaust retries are copied into `state/deadletters/<jobId>/` with `job.json`, `render-spec.json`, and `dead-letter.json`
- `GET /v1/dead-letters` lists those persisted dead-letters with file paths for inspection
- `POST /v1/dead-letters/requeues` re-submits a dead-letter as a fresh async job without mutating the archived failure record
- `POST /v1/dead-letters/cleanup` removes old dead-letters, defaulting to `--job-dead-letter-ttl-days` when no explicit window is provided
- `POST /v1/runtime/cleanup` removes old completed/failed/cancelled jobs, artifacts, and dead-letters in one pass
- `GET /v1/jobs/{id}/events` emits `text/event-stream` frames for `job.queued`, `job.running`, `job.retried`, `job.completed`, `job.failed`, and `job.cancelled`
- `POST /v1/jobs/{id}/cancel` cancels queued jobs immediately and interrupts running renders through the worker context when possible
- `GET /v1/jobs?limit=20` returns the most recent persisted jobs sorted by submission time
- `GET /v1/artifacts?limit=20` returns the most recent persisted artifact manifests sorted by creation time
- `GET /v1/artifacts/{id}` returns the persisted artifact manifest, including metadata and concrete file paths for the bundle

## Auth And Push

If `--auth-shared-secret` is configured, every `/v1/*` endpoint except `/healthz` requires HMAC-signed requests:

- `--auth-algorithm` defaults to `canio-v1`
- `--auth-timestamp-header` defaults to `X-Canio-Timestamp`
- `--auth-signature-header` defaults to `X-Canio-Signature`
- `--auth-max-skew` defaults to `300` seconds

The signature canonicalizes `METHOD`, request path, timestamp, and the SHA-256 digest of the raw body. Laravel signs these requests automatically through the package client when `runtime.auth.shared_secret` is configured.

## Observability

- `GET /metrics` exposes a Prometheus text payload for request counts/duration, render outcomes, job lifecycle totals, webhook deliveries, queue depth, and pool gauges
- `--log-format` supports `json` or `text`, with `json` as the production default
- `--request-logging=false` disables the per-request access log while keeping runtime event logs
- webhook deliveries, render completions/failures, and job lifecycle events now emit structured logs as well

If auth is enabled, `/metrics` stays unsigned on purpose so Prometheus or a sidecar scraper can reach it without HMAC signing. Protect that endpoint at the network or reverse-proxy layer in production.

If `--event-webhook-url` is configured, Stagehand also pushes terminal and retry job events to that URL:

- deliveries use `X-Canio-Delivery-Timestamp` and `X-Canio-Delivery-Signature`
- webhook signatures use the configured `--event-webhook-secret`
- the payload matches `canio.stagehand.job-event.v1`

Dead-letter CLI helpers:

- `go run ./cmd/stagehand deadletters list --state-dir /path/to/runtime-state`
- `go run ./cmd/stagehand deadletters requeue --id dlq-job-123 --base-url http://127.0.0.1:9514`
- `go run ./cmd/stagehand deadletters cleanup --state-dir /path/to/runtime-state --older-than-days 30`

## Release Assets

The GitHub release workflow now publishes:

- `stagehand_vX.Y.Z_linux_amd64`
- `stagehand_vX.Y.Z_linux_arm64`
- `stagehand_vX.Y.Z_darwin_amd64`
- `stagehand_vX.Y.Z_darwin_arm64`
- `stagehand_vX.Y.Z_windows_amd64.exe`
- `stagehand_vX.Y.Z_windows_arm64.exe`
- `checksums.txt`

Those names intentionally match what `php artisan canio:runtime:install` expects.
