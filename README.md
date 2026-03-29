# Canio

Canio is the Laravel PDF package for browser-grade documents.

It exists for the cases where classic HTML-to-PDF engines stop being enough: browser-real layout, JavaScript that must finish before capture, explicit readiness, debug artifacts, and operational rendering flows.

If the only requirement is the lowest possible uncached latency on a simple static document, smaller engines such as Dompdf or mPDF can still be faster. That is not the category Canio is optimized for.

## Why Canio

Use Canio when your Laravel app needs one or more of these:

- real Chromium layout instead of approximation
- JavaScript execution before the PDF is captured
- an explicit readiness contract through `window.__CANIO_READY__`
- persisted artifacts such as HTML source, DOM snapshot, screenshot, console log, or network log
- async rendering, retries, dead letters, replay, and runtime operations

## Quick Start

```bash
composer require oxhq/canio
php artisan canio:install
```

```php
use Oxhq\Canio\Facades\Canio;

return Canio::view('pdf.invoice', ['invoice' => $invoice])
    ->profile('invoice')
    ->title('Invoice #123')
    ->stream('invoice.pdf');
```

By default, Canio runs in `embedded` mode:

- the Laravel package installs the matching Stagehand runtime when needed
- the first render auto-starts the local runtime
- the public API stays Laravel-native: `view()`, `html()`, `url()`

Full installation, runtime modes, and package API live in [packages/laravel/README.md](packages/laravel/README.md).

## Product Positioning

The claim this repository supports is narrow on purpose:

`Canio is the Laravel package for browser-real, high-fidelity PDFs.`

The benchmark harnesses in this repo currently support that claim:

- Canio is the most faithful renderer on the checked-in invoice fixture
- Canio beats Browsershot and Snappy on useful performance in that browser-grade lane
- Canio executes runtime JavaScript correctly in the probe harness
- Dompdf and mPDF still win on raw uncached latency for simple static renders

Public benchmark summary: [docs/benchmark-summary.md](docs/benchmark-summary.md)  
Reproducible harnesses: [benchmarks/README.md](benchmarks/README.md)

## Cloud

Canio OSS works standalone.

Cloud is an optional paid layer on top of the package. It is not part of the required install path and it is not the core OSS story. The public package should stand on its own without any cloud dependency.

## Repository Layout

```text
/packages/laravel      Laravel-facing public package
/runtime/stagehand     Go runtime used by embedded and remote modes
/resources/profiles    Official document profiles
/benchmarks            Reproducible fidelity, fairness, and JS-capability harnesses
/examples              Local example app and demo stubs
/docs                  Development, architecture, and benchmark notes
/docker                Container and deployment assets
```

## Documentation

- Package install and usage: [packages/laravel/README.md](packages/laravel/README.md)
- Public benchmark summary: [docs/benchmark-summary.md](docs/benchmark-summary.md)
- Contributor setup: [docs/development.md](docs/development.md)
- Example app: [examples/laravel-app/README.md](examples/laravel-app/README.md)
- Architecture notes: [docs/architecture.md](docs/architecture.md)
- Render contract: [docs/render-contract.md](docs/render-contract.md)

## Status

This repository is launching Canio as `v1.0.0` for the Laravel package.

That means:

- public install path is `composer require oxhq/canio`
- Stagehand release assets remain published from this monorepo
- the monorepo stays the source of truth
- the Laravel package may be mirrored into a split repository for Packagist distribution, but the product source remains here
