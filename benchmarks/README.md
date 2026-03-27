# Benchmarks

This directory contains the repeatable Canio benchmark and soak harness.

The current harness focuses on the three knobs that matter most in the architecture:

- browser pool size and queue backpressure
- async job worker count and queue depth
- Redis-backed dispatch and recovery behavior

## Quick Start

Run a local browser-pool soak:

```bash
./scripts/benchmark-stagehand.sh --scenario render-pool
```

Run the Redis-backed async job soak:

```bash
./scripts/benchmark-stagehand.sh --scenario redis-jobs
```

Run the pressure scenario that intentionally drives queue depth:

```bash
./scripts/benchmark-stagehand.sh --scenario pressure
```

The harness prints a JSON summary to stdout and also keeps the temporary runtime state on disk for inspection when a run fails.

For async job scenarios, the JSON summary distinguishes requested submits from accepted jobs so queue backpressure is visible instead of being hidden in the aggregates.

## Files

- `run.php` is the benchmark runner and orchestrator.
- `scenarios.json` defines the repeatable baseline workloads.
- `fixture/index.php` serves a deterministic HTML page that delays `__CANIO_READY__` so browser-pool pressure is measurable.
