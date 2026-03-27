# Benchmarking And Defaults

Canio now includes a repeatable benchmark harness in `benchmarks/` that focuses on the three parts of the runtime that matter most under load: the browser pool, async job workers, and Redis-backed queue transport.

## How To Run

Use the helper script from the repository root:

```bash
./scripts/benchmark-stagehand.sh --scenario render-pool
./scripts/benchmark-stagehand.sh --scenario redis-jobs
./scripts/benchmark-stagehand.sh --scenario pressure
```

The harness launches a local HTML fixture, starts Stagehand with the selected scenario, runs the workload, and prints a JSON summary. Temporary state is kept on disk so you can inspect it if a run fails.

For async job scenarios, the summary now separates:

- `count`: requested submits
- `acceptedCount`: jobs that Stagehand actually accepted
- `submitFailures`: requests rejected up front, usually because backpressure is working as designed
- `completedCount`: accepted jobs that reached a terminal state during the run

## Recommended Defaults

These values are a good starting point for the current architecture:

- `browserPoolSize = 2` for a laptop or small single-tenant box
- `browserPoolWarm = 1` so the first request does not pay the full browser startup cost
- `browserQueueDepth = max(16, browserPoolSize * 4)` so short spikes can wait without failing immediately
- `jobWorkers = browserPoolSize` for balanced async throughput
- `jobQueueDepth = max(64, jobWorkers * 8)` for burst absorption without hiding sustained overload
- `jobLeaseTimeout = 45` seconds so a crashed Redis worker is reclaimed quickly but not too aggressively
- `jobHeartbeatInterval = 10` seconds so active jobs keep their lease alive without churning Redis
- `deadLetterTtlDays = 30` to keep failed jobs around long enough for debugging

Redis transport is worth enabling when:

- you run more than one Stagehand process
- you want requeue and lease recovery across restarts
- you need predictable queue depth during bursty traffic

## Tuning Rules

- If `browserPool.waiting` rises while `workerPool.waiting` stays near zero, increase `browserPoolSize` before you add more workers.
- If `workerPool.waiting` rises while the browser pool stays healthy, increase `jobWorkers`.
- If `queue.depth` grows but both pools are already busy, the system is saturated and `queue.depth` is only buffering the surge.
- If the pressure scenario falls over early, raise queue depth before increasing concurrency so you can see whether the bottleneck is contention or outright capacity.
- If the pressure scenario shows high `submitFailures`, that means the queue limit is actively protecting the runtime. Increase depth only if you want to absorb a larger burst.

## Suggested Starting Matrix

- Local development: `browserPoolSize=2`, `jobWorkers=2`, `jobQueueDepth=64`, `browserQueueDepth=16`, `backend=memory`
- Redis-backed single VM: `browserPoolSize=2-4`, `jobWorkers=browserPoolSize`, `jobQueueDepth=64-128`, `browserQueueDepth=4x browserPoolSize`, `backend=redis`
- Throughput-oriented host: `browserPoolSize ~= cores / 2`, `jobWorkers ~= browserPoolSize`, `browserQueueDepth=4x browserPoolSize`, `jobQueueDepth=8x jobWorkers`, `backend=redis`

These are heuristics, not fixed limits. The benchmark harness is the source of truth for your own hardware, and the healthiest setup is the one that keeps p95 wait times low without building an unbounded queue.
