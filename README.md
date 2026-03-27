# Canio

`Canio` is the opinionated document execution engine for Laravel described in the product brief.
This repository starts as a single product repo with the Laravel package and the Go runtime versioned together.

## Repository Map

```text
/packages/laravel      Laravel-first public package
/runtime/stagehand     Mandatory Go runtime and daemon
/resources/profiles    Official document profiles
/resources/stubs       App-facing stubs for jobs/listeners
/docs                  Architecture and contract notes
/examples              Local usage examples
/benchmarks            Benchmark scaffolding
/docker                Image and deployment notes
/scripts               Build and release helpers
```

## Current Scaffold

- Laravel package with fluent `Canio::view() / html() / url()` builder
- Laravel-side view normalization so Blade is rendered to HTML before Stagehand sees it
- install, doctor, serve, runtime status, restart, job inspect/watch, artifact inspect, retry, cancel, cleanup, dead-letter, and logs Artisan commands
- Stagehand HTTP runtime with `/healthz`, `/v1/runtime/status`, `/v1/runtime/restart`, `/v1/runtime/cleanup`, `/v1/renders`, `/v1/jobs`, `/v1/dead-letters`, and `/v1/replays`
- real CDP-backed PDF rendering through local Chrome/Chromium with browser pooling, bounded queue backpressure, and runtime status metrics for `busy` / `waiting`
- async render jobs with persisted job state, polling, SSE job-event streams, queue/running cancellation, retries with backoff, dead-letter persistence, dead-letter requeue/cleanup tooling, and either in-process or Redis-backed queue transport, including real named-queue routing for `queue('redis', 'pdfs')` plus Redis lease/heartbeat recovery
- artifact persistence for debug/watch renders plus runtime replay from stored `render-spec.json`, including screenshot, DOM snapshot, console log, and network log files
- signed Stagehand requests plus optional webhook push back into Laravel for `job.completed`, `job.failed`, `job.retried`, and `job.cancelled`
- operator UX through Artisan plus a built-in Laravel ops panel for live job streams, artifact inspection, dead-letter requeue, and runtime actions without manual endpoint calls, with production-friendly access control through app auth, Basic Auth, or a custom authorizer
- Prometheus-style metrics at `/metrics`, structured JSON logs, and request-level runtime instrumentation for production debugging
- runtime-wide cleanup for jobs, artifacts, and dead-letters plus a repeatable benchmark harness with recommended concurrency defaults
- Dockerfiles, a reference `docker-compose` stack, and a `systemd` unit/env template for deploying Stagehand behind Laravel
- GitHub Actions for PHP/Go CI, Stagehand release assets with checksums, and a publishable `ghcr.io/.../canio-stagehand` image
- a bootstrap script for a local example Laravel app wired to Redis, Stagehand, invoice routes, and the ops dashboard
- profile/stub/doc layout aligned with the product thesis

## Quick Start

Build the runtime binary:

```bash
./scripts/build-stagehand.sh
```

Install PHP dependencies for the package:

```bash
cd packages/laravel
composer install
```

Run the Stagehand test suite:

```bash
cd runtime/stagehand
go test ./...
```

Run the Laravel package test suite:

```bash
cd packages/laravel
composer test
```

Run the benchmark harness:

```bash
./scripts/benchmark-stagehand.sh --scenario render-pool
./scripts/benchmark-stagehand.sh --scenario redis-jobs
```

Bootstrap the reference example app:

```bash
make example-app
```

Bring up the container reference stack:

```bash
docker compose -f docker/docker-compose.example.yml up --build
```

Open the Laravel ops panel locally:

```text
/canio/ops
```

The panel is enabled by default in `local` and `testing`, lists recent jobs/artifacts/dead-letters, and lets operators inspect, cancel, retry, requeue, or restart the runtime from the browser. Outside those environments the default preset is now `laravel-auth`, which requires an authenticated app user plus the `viewCanioOps` ability unless you switch to a different preset.

## Design Notes

- `Stagehand` stays mandatory and bundled with the product.
- The public UX stays Laravel-first and does not expose Node.
- The runtime contract stays intentionally small, but now already covers CDP rendering, replay, richer artifacts, browser-pool observability, metrics/logging, job events, signed transport, and lifecycle cleanup without changing the package shape.
- The repo now carries the first production envelope too: release automation, container recipes, a host-service template, and a runnable example app.
