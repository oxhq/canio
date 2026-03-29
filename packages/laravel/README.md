# Canio Laravel Package

`oxhq/canio` is the Laravel-facing package for Canio.
It keeps the public API Laravel-native while delegating execution to the bundled `Stagehand` runtime.
For `view(...)` sources, Laravel renders Blade to HTML locally before the payload crosses into Stagehand.

This package is optimized for browser-grade PDFs, not for the narrowest possible static HTML-to-PDF latency.
If you need real browser layout, JavaScript execution, explicit readiness, and operator-grade debug artifacts, Canio is the target category.
If you only need the quickest uncached render for a simple static document, smaller engines may still win on raw speed.

## Install

```bash
composer require oxhq/canio
```

That is enough for the default experience. The package now runs in `embedded` mode by default, which means Canio will:

- auto-install the matching `stagehand` binary on first use when it is missing
- auto-start the local runtime the first time a facade call needs it
- keep `php artisan canio:serve` as an advanced/self-hosted escape hatch, not as a prerequisite

If you prefer to prewarm the binary during deployment instead of waiting for first use, you can still run:

```bash
php artisan canio:install
```

If you want to customize runtime, ops, or cloud behavior, publish the package config:

```bash
php artisan vendor:publish --tag=canio-config
```

## When To Choose Canio

Choose Canio when your Laravel app needs one or more of these:

- browser-real rendering instead of HTML approximation
- JavaScript that must run before the PDF is captured
- an explicit readiness contract through `window.__CANIO_READY__`
- persisted render artifacts for debugging
- async render workloads with retries, dead-letters, replay, and operator tooling

Do not choose Canio only because you want the smallest possible cold-render time for a static invoice.
That is not the primary lane of the package.

## How It Works

1. `Canio::view(...)` renders Blade inside Laravel. `Canio::html(...)` and `Canio::url(...)` skip that step and provide the source directly.
2. The package normalizes the request and sends it to the embedded or remote `Stagehand` runtime.
3. `Stagehand` renders with Chromium, honors explicit readiness signals, and returns either the PDF bytes, a saved file, or an async job handle.

## Benchmarks And Proof

This repo carries reproducible benchmarks so the package story stays grounded.

What those harnesses currently establish:

- on the reference invoice fixture, Canio is the most faithful engine in the matrix
- on that same fixture, Canio already beats Browsershot and Snappy in useful performance
- on the JavaScript probe, only Canio and Browsershot execute the runtime badge correctly in this harness
- Canio is materially faster than Browsershot on the warm path in that JavaScript-capable category
- Dompdf and mPDF still remain faster on simple uncached static renders, which is expected

Benchmark methodology lives in [benchmarks/README.md](/Users/garaekz/Documents/projects/packages/oxhq/canio/benchmarks/README.md).
Developer commands and local validation workflows live in [docs/development.md](/Users/garaekz/Documents/projects/packages/oxhq/canio/docs/development.md).

## Public API

```php
use Oxhq\Canio\Facades\Canio;

Canio::view('pdf.invoice', ['invoice' => $invoice])
    ->profile('invoice')
    ->title('Invoice #123')
    ->save('invoices/123.pdf');

$job = Canio::view('pdf.invoice', ['invoice' => $invoice])
    ->queue('redis', 'pdfs')
    ->dispatch();

$latest = Canio::job($job->id());

$jobs = Canio::jobs();

$artifact = Canio::artifact('art-123');

$artifacts = Canio::artifacts();

$events = Canio::streamJobEvents($job->id());

$retried = Canio::retryJob($job->id());

$cancelled = Canio::cancelJob($job->id());

$cleanup = Canio::runtimeCleanup(
    jobsOlderThanDays: 14,
    artifactsOlderThanDays: 14,
    deadLettersOlderThanDays: 30,
);

$replayed = Canio::replay('art-123');
```

Supported entrypoints:

- `Canio::view(...)`
- `Canio::html(...)`
- `Canio::url(...)`

Supported terminal operations in this scaffold:

- `->render()`
- `->dispatch()`
- `->save(...)`
- `->download(...)`
- `->stream(...)`
- `Canio::job($jobId)`
- `Canio::jobs($limit = 20)`
- `Canio::streamJobEvents($jobId, $since = null)`
- `Canio::artifact($artifactId)`
- `Canio::artifacts($limit = 20)`
- `Canio::retryJob($jobId)`
- `Canio::cancelJob($jobId)`
- `Canio::deadLetters()`
- `Canio::requeueDeadLetter($deadLetterId)`
- `Canio::cleanupDeadLetters($olderThanDays = null)`
- `Canio::runtimeCleanup($jobsOlderThanDays = null, $artifactsOlderThanDays = null, $deadLettersOlderThanDays = null)`
- `Canio::replay($artifactId)`

The runtime config now also exposes `runtime.mode`, `runtime.auto_start`, `runtime.auto_install`, `runtime.startup_timeout`, plus `runtime.pool` and `runtime.jobs` sections in `config/canio.php` so Laravel can choose between embedded vs remote execution, tune Stagehand browser/browser-worker concurrency, and optionally switch async jobs to a Redis transport.

When the runtime uses `runtime.jobs.backend = redis`, `->queue('redis', 'pdfs')` now routes into a named Redis stream derived from `runtime.jobs.redis.queue_key`. If the runtime is still on `memory`, Stagehand will reject explicit `redis` queue connections instead of silently downgrading them. The same config block also exposes `lease_timeout` and `heartbeat_interval` so Redis-backed jobs can survive worker crashes and be reclaimed safely by another `Stagehand`.

`RenderJob` now also exposes retry metadata such as `attempts()`, `maxRetries()`, `nextRetryAt()`, and `deadLetterId()` for failed jobs that exhausted retries. The `runtime.jobs` config also includes `dead_letter_ttl_days`, which Laravel passes through to `Stagehand` as the default cleanup window for archived dead-letters.

`runtime.auth` signs every request that Laravel sends to Stagehand. `runtime.push.webhook` lets the daemon call back into your app and dispatch Laravel events for job lifecycle changes.

`runtime.observability` now lets Laravel pass Stagehand logging settings through to the daemon. In particular:

- `runtime.observability.log_format`
- `runtime.observability.request_logging`

Stagehand always exposes `/metrics`, so in a container or reverse-proxy deployment you can scrape runtime health, queue depth, and request/render counters without adding another process.

## Runtime Modes

Canio now has two runtime modes:

- `embedded` (default): the package hides Stagehand behind the facade API and bootstraps it automatically on demand
- `remote`: Laravel talks to an already-running Stagehand daemon at `runtime.base_url`

Useful runtime keys:

- `runtime.mode`
- `runtime.auto_start`
- `runtime.auto_install`
- `runtime.startup_timeout`
- `runtime.binary`
- `runtime.install_path`
- `runtime.base_url`

Example overrides:

```dotenv
# default package experience
CANIO_RUNTIME_MODE=embedded
CANIO_RUNTIME_AUTO_START=true
CANIO_RUNTIME_AUTO_INSTALL=true
```

```dotenv
# advanced/self-hosted mode
CANIO_RUNTIME_MODE=remote
CANIO_RUNTIME_BASE_URL=http://127.0.0.1:9514
```

## Cloud Modes

Canio also exposes a separate cloud seam without changing the facade API.

- `off`: default OSS behavior; render through embedded or remote Stagehand only
- `sync`: keep rendering locally, but publish job snapshots and artifacts to Canio Cloud
- `managed`: send render and job execution to Canio Cloud instead of a local Stagehand runtime

Useful config keys:

- `cloud.mode`
- `cloud.base_url`
- `cloud.token`
- `cloud.project`
- `cloud.environment`
- `cloud.timeout`
- `cloud.sync.enabled`
- `cloud.sync.include_artifacts`

Example:

```dotenv
CANIO_CLOUD_MODE=sync
CANIO_CLOUD_BASE_URL=http://127.0.0.1:9081
CANIO_CLOUD_TOKEN=canio_xxx
CANIO_CLOUD_PROJECT=prj_xxx
CANIO_CLOUD_ENVIRONMENT=env_xxx
```

In `managed` mode, the package binds a cloud-backed `StagehandClient` and no longer requires a local Stagehand daemon.

## Ops Panel

Canio also ships a minimal web panel for operators, but it is now opt-in. The package core does not require the panel.

- dashboard path defaults to `/canio/ops`
- recent jobs and artifacts are listed in one place
- job detail pages can auto-refresh while a job is still running
- the web panel stays read-only; mutating actions remain in Artisan or move to Canio Cloud
- access can be protected by authenticated Laravel users, HTTP Basic Auth, or a custom authorizer class

Useful config keys in `config/canio.php`:

- `ops.enabled`
- `ops.path`
- `ops.title`
- `ops.refresh_seconds`
- `ops.middleware`
- `ops.access.preset`
- `ops.access.require_auth`
- `ops.access.guards`
- `ops.access.ability`
- `ops.access.authorizer`
- `ops.access.basic.enabled`
- `ops.access.basic.username`
- `ops.access.basic.password`
- `ops.access.basic.realm`

Related environment variables:

- `CANIO_OPS_ENABLED`
- `CANIO_OPS_PATH`
- `CANIO_OPS_TITLE`
- `CANIO_OPS_REFRESH_SECONDS`
- `CANIO_OPS_MIDDLEWARE`
- `CANIO_OPS_PRESET`
- `CANIO_OPS_REQUIRE_AUTH`
- `CANIO_OPS_GUARDS`
- `CANIO_OPS_ABILITY`
- `CANIO_OPS_AUTHORIZER`
- `CANIO_OPS_BASIC_ENABLED`
- `CANIO_OPS_BASIC_USERNAME`
- `CANIO_OPS_BASIC_PASSWORD`
- `CANIO_OPS_BASIC_REALM`

Built-in presets:

- `local-open`: leaves the panel open, intended for `local` and `testing`
- `laravel-auth`: requires an authenticated Laravel user and defaults `ops.access.ability` to `viewCanioOps`
- `basic-auth`: enables HTTP Basic Auth for the panel
- `hybrid-auth`: combines `laravel-auth` plus Basic Auth fallback

If `ops.access.require_auth` is enabled, the middleware checks the configured Laravel guards first. If no user is authenticated, it can fall back to HTTP Basic Auth when `ops.access.basic.enabled` is on. `ops.access.ability` is evaluated for authenticated Laravel users, while `ops.access.authorizer` can point to a container-resolvable class that implements [OpsAccessAuthorizer.php](/Users/garaekz/Documents/projects/packages/oxhq/canio/packages/laravel/src/Contracts/OpsAccessAuthorizer.php) for custom rules.

Production presets you can enable without extra package code:

```dotenv
# Laravel auth + Gate ability
CANIO_OPS_PRESET=laravel-auth
CANIO_OPS_GUARDS=web
CANIO_OPS_ABILITY=viewCanioOps
```

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewCanioOps', fn ($user) => (bool) ($user->is_admin ?? false));
```

```dotenv
# Basic Auth, useful behind a reverse proxy
CANIO_OPS_PRESET=basic-auth
CANIO_OPS_BASIC_ENABLED=true
CANIO_OPS_BASIC_USERNAME=ops
CANIO_OPS_BASIC_PASSWORD=change-me
CANIO_OPS_BASIC_REALM="Canio Ops"
```

Enable the panel explicitly when you want the advanced operator layer:

```dotenv
CANIO_OPS_ENABLED=true
CANIO_OPS_PRESET=laravel-auth
CANIO_OPS_GUARDS=web
CANIO_OPS_ABILITY=viewCanioOps
```

## Job Push Events

When `runtime.push.webhook.enabled` is `true`, the daemon will point Stagehand at your app route and Laravel will verify incoming signatures automatically. In `embedded` mode this happens transparently; in `remote` mode you can still run `php artisan canio:serve` yourself.

Dispatched Laravel events:

- `Oxhq\\Canio\\Events\\CanioJobEventReceived`
- `Oxhq\\Canio\\Events\\CanioJobCompleted`
- `Oxhq\\Canio\\Events\\CanioJobFailed`
- `Oxhq\\Canio\\Events\\CanioJobRetried`
- `Oxhq\\Canio\\Events\\CanioJobCancelled`

The default callback path is `POST /canio/webhooks/stagehand/jobs`, but you can change it with `runtime.push.webhook.path`.

## Runtime Commands

```bash
php artisan canio:install
php artisan canio:doctor
# advanced / remote mode
php artisan canio:serve
php artisan canio:runtime:install
php artisan canio:runtime:status
php artisan canio:runtime:restart
php artisan canio:runtime:job job-123
php artisan canio:runtime:job job-123 --watch
php artisan canio:runtime:artifact art-123
php artisan canio:runtime:retry job-123
php artisan canio:runtime:cancel job-123
php artisan canio:runtime:cleanup --jobs-older-than-days=14 --artifacts-older-than-days=14 --dead-letters-older-than-days=30
php artisan canio:runtime:deadletters
php artisan canio:runtime:deadletters:requeue dlq-job-123
php artisan canio:runtime:deadletters:cleanup --older-than-days=30
php artisan canio:runtime:logs
```
