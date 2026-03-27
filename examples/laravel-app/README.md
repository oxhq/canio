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
- `.env` defaults for local Stagehand, Redis-backed jobs, webhook signing, and the open local ops preset

## Local Flow

1. Start Redis locally.
2. Build the runtime:

```bash
./scripts/build-stagehand.sh
```

3. Start Laravel:

```bash
cd examples/laravel-app/app
php artisan serve
```

4. In another shell, start Stagehand through the package:

```bash
cd examples/laravel-app/app
php artisan canio:serve
```

Then visit:

- `/` for the example landing page
- `/invoices/preview` for inline PDF rendering
- `/invoices/dispatch` to queue a Redis-backed render and jump into the ops panel
- `/canio/ops` for the operator dashboard

## Container Flow

If you prefer containers, bootstrap the app once and then run:

```bash
docker compose -f docker/docker-compose.example.yml up --build
```

That stack uses the same generated app path, so the local package and the example app stay in sync.
