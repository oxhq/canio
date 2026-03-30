# Canio Laravel Package

`oxhq/canio` is the Laravel-facing package for Canio.

It keeps the API Laravel-native and delegates execution to the Stagehand runtime. In the default `embedded` mode, that runtime is installed and started automatically when the package needs it.

## Supported Versions

- PHP `^8.2`
- Laravel `^10.0 | ^11.0 | ^12.0 | ^13.0`

## Install

```bash
composer require oxhq/canio
php artisan canio:install
```

Public docs: [oxhq.github.io/canio](https://oxhq.github.io/canio/)

The second command is recommended for deployment and validation because it:

- publishes the default config
- downloads the matching Stagehand binary
- verifies release checksums
- runs a local doctor check

The package can also auto-install and auto-start the runtime on first render in `embedded` mode, but explicit install is the cleaner production path.

If you need the config file:

```bash
php artisan vendor:publish --tag=canio-config
```

## Quick Start

```php
use Oxhq\Canio\Facades\Canio;

return Canio::view('pdf.invoice', ['invoice' => $invoice])
    ->profile('invoice')
    ->title('Invoice #123')
    ->stream('invoice.pdf');
```

Supported entrypoints:

- `Canio::view(...)`
- `Canio::html(...)`
- `Canio::url(...)`

Common terminal operations:

- `->render()`
- `->save(...)`
- `->download(...)`
- `->stream(...)`
- `->dispatch()`

## When To Choose Canio

Choose Canio when you need:

- real browser layout
- JavaScript execution before capture
- explicit readiness with `window.__CANIO_READY__`
- render artifacts for debugging
- async render jobs and runtime operations

Do not choose Canio only because you want the smallest possible cold-render time on a static HTML invoice. That is not the category this package is trying to win.

## Runtime Model

### Embedded mode

`embedded` is the default and recommended package experience.

- Laravel talks to Stagehand through the package
- the runtime is installed automatically when missing
- the runtime is auto-started on demand
- the application does not need a manually managed daemon in the happy path

### Remote mode

Use `remote` when you want Laravel to talk to an already-running Stagehand daemon.

Useful config keys:

- `runtime.mode`
- `runtime.base_url`
- `runtime.auto_install`
- `runtime.auto_start`
- `runtime.binary`
- `runtime.install_path`
- `runtime.startup_timeout`

Production deployment guide: [embedded vs remote runtimes](https://github.com/oxhq/canio/blob/main/docs/deployment.md)

## Troubleshooting

If the first render fails, check these first:

1. Run `php artisan canio:doctor`
2. Confirm `php artisan canio:install` succeeded
3. If the host needs an explicit browser path, set `CANIO_CHROMIUM_PATH`
4. In locked-down Linux environments, you may also need `CANIO_CHROMIUM_NO_SANDBOX=true`

If you want a self-hosted runtime instead of embedded mode:

```dotenv
CANIO_RUNTIME_MODE=remote
CANIO_RUNTIME_BASE_URL=http://127.0.0.1:9514
```

## Benchmarks And Proof

Canio is positioned as the browser-grade option, not the minimum-latency static option.

The checked-in harnesses currently establish:

- Canio is the most faithful engine on the reference invoice fixture
- Canio beats Browsershot and Snappy on useful performance in that lane
- Canio executes runtime JavaScript correctly in the probe harness
- Dompdf and mPDF still win on raw uncached latency for simpler static renders

Public benchmark summary: [oxhq/canio benchmark summary](https://github.com/oxhq/canio/blob/main/docs/benchmark-summary.md)  
Full harnesses: [oxhq/canio benchmarks](https://github.com/oxhq/canio/blob/main/benchmarks/README.md)

## Optional Cloud Layer

This package works without any cloud dependency.

Cloud is an optional paid layer on top of Canio OSS. Keep it secondary in the package story: local or self-hosted rendering is fully supported without it.

## Commands And Operations

The package also exposes runtime and job commands such as:

- `php artisan canio:install`
- `php artisan canio:doctor`
- `php artisan canio:serve`
- `php artisan canio:runtime:status`
- `php artisan canio:runtime:job {id}`
- `php artisan canio:runtime:artifact {id}`
- `php artisan canio:runtime:cleanup`

The facade also exposes job and artifact helpers, including:

- `Canio::job($jobId)`
- `Canio::jobs($limit = 20)`
- `Canio::artifact($artifactId)`
- `Canio::artifacts($limit = 20)`
- `Canio::retryJob($jobId)`
- `Canio::cancelJob($jobId)`
- `Canio::replay($artifactId)`

## More Documentation

- Public docs: [oxhq.github.io/canio](https://oxhq.github.io/canio/)
- Monorepo overview: [oxhq/canio](https://github.com/oxhq/canio)
- Production deployment guide: [deployment guide](https://github.com/oxhq/canio/blob/main/docs/deployment.md)
- Contributor setup: [development guide](https://github.com/oxhq/canio/blob/main/docs/development.md)
- Architecture notes: [architecture](https://github.com/oxhq/canio/blob/main/docs/architecture.md)
- Render contract: [render contract](https://github.com/oxhq/canio/blob/main/docs/render-contract.md)
