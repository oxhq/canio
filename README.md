# Canio

`Canio` is the Laravel PDF package for browser-grade documents.
It keeps the public API Laravel-native and hides the bundled `Stagehand` runtime behind an embedded default flow.

The short version:

- if you need the fastest possible static HTML-to-PDF path, simpler engines such as Dompdf or mPDF can still win on raw latency
- if you need real browser layout, explicit readiness, JavaScript execution, debug artifacts, and reproducible fidelity checks, Canio is the package this repo is building toward

## Why Canio

Canio is aimed at the problems that older Laravel PDF packages handle poorly:

- documents that depend on real browser layout instead of HTML-to-PDF approximation
- documents that need JavaScript to finish rendering before the PDF is captured
- production debugging where you need the HTML source, DOM snapshot, screenshot, console log, or network log
- async or remote execution where rendering is treated as an operator-managed workload, not just a one-off helper

What Canio is not trying to be:

- the absolute fastest renderer for simple static invoices
- a Node-first PDF tool exposed directly to Laravel apps
- a package that makes users manage Chromium or a daemon manually in the happy path

## Positioning

The current benchmark story in this repo is consistent:

- on the example invoice fixture, Canio is the most faithful renderer in the matrix and already beats Browsershot and Snappy on useful performance
- on repeated exact renders, Canio can serve from render cache and drop to single-digit milliseconds
- on a dedicated JavaScript probe, only Canio and Browsershot execute the runtime-injected badge correctly in this harness, and Canio is materially faster in steady-state
- Dompdf and mPDF still win on raw uncached latency for simple static documents, which is expected because they do less work

That means the right claim is not “fastest Laravel PDF package overall”.
The right claim is: Canio is the Laravel PDF package for browser-real, high-fidelity documents.

## Repository Map

```text
/packages/laravel      Laravel-first public package
/runtime/stagehand     Mandatory Go runtime and daemon
/resources/profiles    Official document profiles
/resources/stubs       App-facing stubs for jobs/listeners
/docs                  Architecture and contract notes
/examples              Local usage examples
/benchmarks            Benchmark scaffolding and comparison harnesses
/docker                Image and deployment notes
/scripts               Build and release helpers
```

## Core Capabilities

- Laravel-native facade API: `Canio::view()`, `Canio::html()`, `Canio::url()`
- embedded default runtime mode with auto-install and auto-start
- real Chrome/Chromium rendering through CDP
- explicit readiness support through `window.__CANIO_READY__`
- debug/watch renders with persisted artifacts
- browser pooling, queue backpressure, and runtime metrics
- async jobs with retry, dead-lettering, replay, and Redis-backed transport
- signed Stagehand transport plus optional webhook callbacks into Laravel
- optional Laravel ops dashboard for operators
- render cache plus persistent-page optimizations for warm paths

## How It Works

1. Laravel builds a render request from a Blade view, raw HTML string, or URL.
2. The package sends that normalized render spec to `Stagehand`. In the default embedded mode, Canio installs and starts the runtime automatically when needed.
3. `Stagehand` renders through Chrome/Chromium, waits for explicit readiness when the document exposes `window.__CANIO_READY__`, prints the PDF, and optionally persists artifacts, metrics, and async job state.

## Benchmarks

The repo ships reproducible benchmark harnesses instead of hand-wavy claims.

Use them to answer different questions:

- invoice fidelity harness: “does Canio still render the reference invoice correctly?”
- invoice matrix: “how does Canio compare to Dompdf, mPDF, Snappy, and Browsershot on the same fixture?”
- JavaScript probe matrix: “which engines actually execute runtime JavaScript before PDF capture?”
- Stagehand soaks: “how does the runtime behave under pool pressure and job concurrency?”

Benchmark methodology lives in [benchmarks/README.md](/Users/garaekz/Documents/projects/packages/oxhq/canio/benchmarks/README.md).
Developer setup and local validation workflows live in [docs/development.md](/Users/garaekz/Documents/projects/packages/oxhq/canio/docs/development.md).

## Install In Laravel

Install the package:

```bash
composer require oxhq/canio
```

In the default embedded mode, Canio will install and start the matching `stagehand` runtime automatically on first use.

Minimal example:

```php
use Oxhq\Canio\Facades\Canio;

return Canio::view('pdf.invoice', ['invoice' => $invoice])
    ->profile('invoice')
    ->title('Invoice #123')
    ->stream('invoice.pdf');
```

Full Laravel package installation and API docs live in [packages/laravel/README.md](/Users/garaekz/Documents/projects/packages/oxhq/canio/packages/laravel/README.md).

## Example App

The repo carries a local reference Laravel app so the package can be exercised end to end:

```bash
make example-app
```

Useful example routes:

- `/invoices/preview`
- `/invoices/dispatch`
- `/probes/javascript`
- `/probes/javascript/preview`
- `/canio/ops`

Example app documentation lives in [examples/laravel-app/README.md](/Users/garaekz/Documents/projects/packages/oxhq/canio/examples/laravel-app/README.md).

## Development

Developer setup, tests, benchmark commands, bundling, and contributor workflows live in [docs/development.md](/Users/garaekz/Documents/projects/packages/oxhq/canio/docs/development.md).

## Design Notes

- `Stagehand` stays mandatory, but in the default Laravel experience it is treated as an implementation detail
- the public UX stays Laravel-first and does not require manual daemon management in the happy path
- the benchmark story is part of the product story, not a side document: fidelity, JS capability, and throughput are all checked in this repo
- Canio is strongest when the document needs a browser, not when the only goal is minimal uncached latency
