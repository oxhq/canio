# Example Laravel App

This folder now carries a bootstrap script plus app stubs so you can spin up a real reference app inside the repo.

## Bootstrap

```bash
./examples/laravel-app/create-project.sh
```

By default the app is created in [examples/laravel-app/app](/Users/garaekz/Documents/projects/packages/oxhq/canio/examples/laravel-app/app), configured as a path repository to [packages/laravel](/Users/garaekz/Documents/projects/packages/oxhq/canio/packages/laravel), and published with a local Canio config.

## What The Script Wires

- local package install from `../../../packages/laravel`
- invoice demo routes in [stubs/routes/web.php](/Users/garaekz/Documents/projects/packages/oxhq/canio/examples/laravel-app/stubs/routes/web.php)
- a Blade invoice template in [stubs/resources/views/pdf/invoice.blade.php](/Users/garaekz/Documents/projects/packages/oxhq/canio/examples/laravel-app/stubs/resources/views/pdf/invoice.blade.php)
- a JavaScript execution probe view in [stubs/resources/views/pdf/javascript-probe.blade.php](/Users/garaekz/Documents/projects/packages/oxhq/canio/examples/laravel-app/stubs/resources/views/pdf/javascript-probe.blade.php)
- `.env` defaults for embedded runtime autostart, Redis-backed jobs, webhook signing, Canio Cloud sync/managed settings, and an explicitly enabled local ops preset for the demo app
- a package-first flow where the first render auto-installs and auto-starts Stagehand if needed
- a cloud smoke script in [stubs/scripts/smoke-cloud.sh](/Users/garaekz/Documents/projects/packages/oxhq/canio/examples/laravel-app/stubs/scripts/smoke-cloud.sh)
- a cloud smoke test in [stubs/tests/Feature/CanioCloudSmokeTest.php](/Users/garaekz/Documents/projects/packages/oxhq/canio/examples/laravel-app/stubs/tests/Feature/CanioCloudSmokeTest.php)

## Local Flow

1. Start Redis locally.
2. Start Laravel:

```bash
cd examples/laravel-app/app
php artisan serve
```

Then visit:

- `/` for the example landing page
- `/invoices/preview` for inline PDF rendering; this will auto-install and auto-start the embedded runtime on first use
- `/invoices/dispatch` to queue a Redis-backed render and jump into the opt-in ops panel that the demo enables explicitly
- `/probes/javascript` for the raw HTML probe that injects a fixed badge at runtime
- `/probes/javascript/preview` for the same probe rendered through Canio PDF with debug artifacts
- `/canio/ops` for the operator dashboard

## Recommended Validation Flow

If you want to validate the current package story locally, use this order:

1. Open `/invoices/preview` to confirm the basic embedded Canio flow.
2. Open `/probes/javascript` and `/probes/javascript/preview` to confirm the runtime JS probe visually.
3. Run the CLI smoke and benchmark suites from the repository development guide.

That sequence validates the three core claims:

- Canio renders the example document end to end
- Canio executes JavaScript before capture
- Canio is competitive in the browser-grade comparison lane

Developer workflow and benchmark commands live in [docs/development.md](/Users/garaekz/Documents/projects/packages/oxhq/canio/docs/development.md) and [benchmarks/README.md](/Users/garaekz/Documents/projects/packages/oxhq/canio/benchmarks/README.md).

## Canio Cloud Smoke

Set these before using the cloud smoke helpers:

- `CANIO_CLOUD_BASE_URL`
- `CANIO_CLOUD_TOKEN`
- `CANIO_CLOUD_PROJECT`
- `CANIO_CLOUD_ENVIRONMENT`

Then use the example routes and helper script:

- `/canio/cloud/sync/preview`
- `/canio/cloud/managed/preview`
- `/canio/cloud/sync/dispatch`
- `/canio/cloud/managed/dispatch`
- `scripts/smoke-cloud.sh`

The smoke helper curls both cloud modes and checks that the response begins with `%PDF`.

If you want to prewarm the binary during setup instead of waiting for the first render, you can still run:

```bash
cd examples/laravel-app/app
php artisan canio:install
```

## Container Flow

If you prefer containers, bootstrap the app once and then run:

```bash
docker compose -f docker/docker-compose.example.yml up --build
```

That stack uses the same generated app path, so the local package and the example app stay in sync.
