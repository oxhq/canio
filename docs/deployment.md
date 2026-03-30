# Deployment Guide

This guide is the production-facing entry point for choosing and operating Canio runtimes.

## Runtime Modes

Canio supports two production shapes:

- `embedded`: the Laravel package installs and starts Stagehand on demand on the same host as the app
- `remote`: Laravel talks to a separately managed Stagehand runtime over HTTP

The right choice depends on where you want the browser process, runtime logs, and operational responsibility to live.

## Choose Embedded When

Use `embedded` when:

- you want the fastest path from install to first successful render
- the Laravel app host can run Chromium locally
- you are comfortable letting the package manage runtime install and startup
- you want a simple deployment target for a single app or small fleet

Recommended production path:

```bash
composer require oxhq/canio
php artisan canio:install
```

Useful environment variables:

```dotenv
CANIO_RUNTIME_MODE=embedded
CANIO_RUNTIME_AUTO_INSTALL=true
CANIO_RUNTIME_AUTO_START=true
CANIO_RUNTIME_STATE_PATH=/var/lib/canio
CANIO_RUNTIME_LOG_PATH=/var/log/canio/stagehand.log
CANIO_CHROMIUM_PATH=/usr/bin/google-chrome
CANIO_CHROMIUM_NO_SANDBOX=false
```

Operational notes:

- keep `CANIO_RUNTIME_STATE_PATH` on persistent storage if you care about artifacts, jobs, and replay data
- set `CANIO_RUNTIME_LOG_PATH` to a path collected by your normal host logging pipeline
- if Chromium is not on a standard path, set `CANIO_CHROMIUM_PATH`
- only set `CANIO_CHROMIUM_NO_SANDBOX=true` in environments that require it

## Choose Remote When

Use `remote` when:

- you want browser execution isolated from Laravel workers
- you need to run multiple app instances against a shared rendering service
- you want tighter control over Stagehand scaling, logs, or machine profile
- your app hosts should not carry browser dependencies

Recommended runtime separation:

- Laravel app instances talk to a stable Stagehand base URL
- Stagehand hosts own Chromium, runtime state, and runtime logs
- rollout and restart of the renderer happen independently from the app

Useful environment variables:

```dotenv
CANIO_RUNTIME_MODE=remote
CANIO_RUNTIME_BASE_URL=http://stagehand.internal:9514
CANIO_RUNTIME_STARTUP_TIMEOUT=30
```

Operational notes:

- health-check the Stagehand base URL separately from the Laravel app
- collect Stagehand logs independently from PHP logs
- make the network path between Laravel and Stagehand explicit and observable
- if you expose Stagehand beyond a trusted private network, configure request authentication

## Browser Requirements

No matter which mode you choose, Canio depends on a working Chromium executable.

Check these first:

1. The runtime host can execute Chrome or Chromium.
2. `CANIO_CHROMIUM_PATH` is set if auto-detection is not reliable on that host.
3. Locked-down Linux hosts only use `CANIO_CHROMIUM_NO_SANDBOX=true` when sandboxing cannot work.

## State, Logs, And Replay

Stagehand stores operational data under its state directory. That includes:

- jobs
- artifacts
- replay inputs
- cleanup targets

For production:

- put runtime state on persistent storage if replay or artifact retention matters
- put runtime logs somewhere collected and rotated
- define artifact and dead-letter retention intentionally instead of leaving cleanup to chance

## Operational Commands

Useful package-level commands:

- `php artisan canio:install`
- `php artisan canio:doctor`
- `php artisan canio:runtime:status`
- `php artisan canio:runtime:cleanup`

Useful runtime-facing operations in remote mode:

- `GET /status`
- `GET /metrics`
- `GET /jobs/{id}`
- `GET /artifacts/{id}`

## Practical Recommendation

Start with `embedded` unless you already know you need runtime isolation.

Move to `remote` when:

- browser resource usage needs independent scaling
- renderer uptime needs separate ownership
- multiple apps or workers should share one rendering layer
- you need cleaner operational boundaries than an app-local browser process can give you
