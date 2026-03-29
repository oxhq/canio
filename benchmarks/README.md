# Benchmarks

This directory contains the reproducible benchmark and smoke harnesses for Canio.
Repository setup, test commands, and runtime build steps live in [docs/development.md](../docs/development.md).

The benchmark story in this repo is intentionally split by question, because one number does not explain a PDF engine:

- fidelity: how close is the output to the reference document?
- fair performance: how fast is the engine when fed the same input under the same rules?
- JavaScript capability: does the engine actually execute runtime JS before PDF capture?
- runtime throughput: how does Stagehand behave under concurrency and queue pressure?

## Quick Start

```bash
./scripts/benchmark-example-invoice.sh
./scripts/benchmark-example-invoice-matrix.sh --fair --warmups=1 --iterations=5
./scripts/benchmark-javascript-probe-matrix.sh --warmups=0 --iterations=1
./scripts/smoke-javascript-probe.sh
./scripts/benchmark-stagehand.sh --scenario render-pool
./scripts/benchmark-stagehand.sh --scenario redis-jobs
```

## Suites

### Example Invoice Fidelity

```bash
./scripts/benchmark-example-invoice.sh
```

This renders the example Laravel invoice through Canio with debug artifacts enabled and checks:

- render time
- required artifacts
- expected invoice text
- visual similarity against a golden screenshot

Use this when the question is: “did Canio regress on the reference invoice?”

### Example Invoice Compare

```bash
./scripts/benchmark-example-invoice-compare.sh
```

This compares Canio and Dompdf on the same invoice fixture using a shared golden PDF raster.

Use this when the question is: “how does Canio compare to a classic Laravel PDF baseline on the same document?”

### Example Invoice Matrix

```bash
./scripts/benchmark-example-invoice-matrix.sh --fair --warmups=1 --iterations=5
```

This expands the comparison to:

- `oxhq/canio (warm-miss)`
- `oxhq/canio (cache-hit)`
- `barryvdh/laravel-dompdf`
- `carlos-meneses/laravel-mpdf`
- `barryvdh/laravel-snappy`
- `spatie/laravel-pdf (browsershot)`

Important details:

- `--fair` feeds the same rendered HTML string to every engine
- in `--fair`, Canio runs without debug artifacts
- in `--fair`, Browsershot waits on the same `window.__CANIO_READY__` signal
- one verified cold run is captured first
- `warmups` are discarded
- `iterations` are aggregated into steady-state min/median/max summaries

Canio is intentionally split into two rows:

- `warm-miss`: forces a unique title per render so the runtime stays warm but render cache is bypassed
- `cache-hit`: primes the render cache once and then measures exact repeated renders

Use this when the question is: “how does Canio compare fairly to other Laravel PDF packages on a browser-style invoice?”

### JavaScript Probe Smoke

```bash
./scripts/smoke-javascript-probe.sh
```

This renders a dedicated probe document through Canio and validates:

- the badge does not exist in the server HTML
- the badge does exist in the `domSnapshot`
- the explicit readiness flag was respected

Use this when the question is: “did Canio really execute JS before the PDF was captured?”

### JavaScript Probe Matrix

```bash
./scripts/benchmark-javascript-probe-matrix.sh --warmups=0 --iterations=1
```

This compares the same Laravel PDF packages on a JS probe document whose badge is injected only at runtime.

The matrix reports:

- full-page similarity against the Canio golden raster
- a focused badge signal in the top-left corner
- a `JS yes/no` classification

The current badge classification is based on the badge’s own visual signature in the corner crop:

- a dark background ratio
- a green text ratio

This avoids false negatives caused by small layout shifts between engines.

Use this when the question is: “which engines in this matrix are actually executing the JS probe?”

### Stagehand Runtime Soaks

```bash
./scripts/benchmark-stagehand.sh --scenario render-pool
./scripts/benchmark-stagehand.sh --scenario redis-jobs
./scripts/benchmark-stagehand.sh --scenario pressure
```

These exercise the runtime itself rather than cross-package rendering.

They focus on:

- browser pool size and queue backpressure
- async worker count and queue depth
- Redis-backed dispatch and recovery behavior

Use these when the question is: “how does Stagehand behave as a runtime under load?”

## Current Takeaway

The checked-in harnesses currently support this product story:

- Canio is strongest in the browser-grade lane, not the minimum-latency static lane
- on the reference invoice, Canio is the most faithful engine in the matrix
- Canio already beats Browsershot and Snappy on useful performance for that invoice fixture
- on repeated exact renders, Canio can drop to cache-hit timings
- on the JavaScript probe, only Canio and Browsershot execute the runtime badge correctly in this harness

That is why the claim should be:

`Canio is the Laravel PDF package for high-fidelity, browser-real documents.`

Not:

`Canio is the fastest Laravel PDF package for every workload.`

## Files

- `run.php` is the Stagehand benchmark orchestrator.
- `scenarios.json` defines repeatable runtime workloads.
- `fixture/index.php` serves the deterministic HTML fixture used by the Stagehand soaks.
- `example_invoice_reference.php` defines the invoice fixture and thresholds.
- `example_invoice_fidelity.php` runs the Canio-only invoice fidelity check.
- `example_invoice_compare.php` compares Canio and Dompdf on the invoice fixture.
- `example_invoice_matrix.php` compares multiple Laravel PDF packages on the invoice fixture.
- `example_invoice_tune.php` explores a constrained tuning space for Canio settings.
- `javascript_probe_reference.php` defines the runtime-JS probe fixture.
- `javascript_probe_smoke.php` validates that Canio actually mutates the DOM before capture.
- `javascript_probe_matrix.php` compares JS capability across the package matrix.
