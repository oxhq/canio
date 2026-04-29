# Development

This document is the developer-facing entry point for local setup, tests, benchmarking, and runtime bundling.

Public product positioning lives in:

- [README.md](../README.md)
- [packages/laravel/README.md](../packages/laravel/README.md)

## Prerequisites

Recommended local toolchain:

- PHP plus Composer
- Go for the `Stagehand` runtime
- Node.js and npm for Browsershot and the example app helper flows
- Redis for async job and runtime pressure scenarios
- `wkhtmltopdf` if you want the full Snappy benchmark row

On macOS in this repo, Herd is a valid PHP runtime. The benchmark scripts in this repo have already been exercised with Herd PHP plus Homebrew-installed `wkhtmltopdf`.

## Repository Setup

From the repository root:

```bash
composer install --working-dir packages/laravel
go test ./runtime/stagehand/...
```

If you want the packaged binary locally:

```bash
make build-stagehand
```

That writes the runtime to [bin/stagehand](../bin/stagehand).

## Test Commands

Use the top-level `Makefile` when you want the standard repo workflow:

```bash
make test
make test-go
make test-php
```

Equivalent direct commands:

```bash
cd packages/laravel && composer test
cd runtime/stagehand && go test ./...
```

For launch validation, use the clean-install smoke:

```bash
./scripts/smoke-launch.sh
```

By default it builds a temporary split-style VCS repo from `packages/laravel`, creates a fresh Laravel app, installs `oxhq/canio:^1.0`, validates `canio:install`, and checks one browser-JS render path.

To validate against the published Packagist package and the published `v1.0.4` GitHub release assets instead of local sources:

```bash
CANIO_PACKAGE_SOURCE_MODE=packagist \
CANIO_PACKAGE_CONSTRAINT=v1.0.4 \
CANIO_RUNTIME_RELEASE_SOURCE=github \
CANIO_RUNTIME_RELEASE_VERSION=v1.0.4 \
./scripts/smoke-launch.sh
```

## Example App

Bootstrap the local Laravel reference app with:

```bash
make example-app
```

Or directly:

```bash
./examples/laravel-app/create-project.sh
```

The generated app lives at [examples/laravel-app/app](../examples/laravel-app/app) and is wired to the local package source through a Composer path repository.

Common local flow:

```bash
cd examples/laravel-app/app
php artisan serve
```

Useful routes:

- `/invoices/preview`
- `/invoices/dispatch`
- `/probes/javascript`
- `/probes/javascript/preview`
- `/canio/ops`

Example-app-specific notes live in [examples/laravel-app/README.md](../examples/laravel-app/README.md).

## Benchmarking

Cross-engine and runtime benchmark suites live in [benchmarks/README.md](../benchmarks/README.md).

Most useful entry points:

```bash
./scripts/benchmark-example-invoice.sh
./scripts/benchmark-example-invoice-matrix.sh --fair --warmups=1 --iterations=5
./scripts/smoke-javascript-probe.sh
./scripts/benchmark-javascript-probe-matrix.sh --warmups=0 --iterations=1
./scripts/benchmark-stagehand.sh --scenario render-pool
```

Runtime tuning guidance lives in [docs/benchmarks.md](./benchmarks.md).

## Bundling And Runtime Builds

Build the local runtime binary with:

```bash
./scripts/build-stagehand.sh
```

Or:

```bash
make build-stagehand
```

Runtime implementation and operational surface are documented in [runtime/stagehand/README.md](../runtime/stagehand/README.md).

Container and deployment assets live in [docker/README.md](../docker/README.md).

## Architecture Docs

Use these when you need internals instead of product-level usage:

- [docs/architecture.md](./architecture.md)
- [docs/render-contract.md](./render-contract.md)
- [docs/benchmarks.md](./benchmarks.md)
