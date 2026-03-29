# Public Benchmark Summary

This is the public-facing benchmark claim for Canio.

## Claim

Canio is strongest when the document needs a real browser:

- browser-real layout
- JavaScript execution before capture
- explicit readiness
- high fidelity
- render artifacts and operational workflows

Canio is not claiming to be the fastest Laravel PDF engine for every workload.

## What The Checked-In Harnesses Show

- On the reference invoice fixture, Canio is the most faithful renderer in the matrix.
- In that browser-grade lane, Canio beats Browsershot and Snappy on useful performance.
- On repeated exact renders, Canio benefits from warm-path optimization and render cache.
- On the JavaScript probe, Canio correctly executes the runtime mutation before PDF capture.
- Dompdf and mPDF remain faster for simpler uncached static documents, which is expected because they do less work.

## Why That Matters

This makes Canio the right choice when correctness depends on browser behavior, not just on producing a PDF quickly.

## Reproduce

Methodology and raw harnesses live here:

- [benchmarks/README.md](../benchmarks/README.md)
