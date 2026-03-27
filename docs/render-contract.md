# Stagehand Render Contract v1

## Request

`contractVersion = "canio.stagehand.render-spec.v1"`

Important fields:

- `requestId`
- `source.type` = `view | html | url`
- `source.payload`
- `profile`
- `presentation`
- `document`
- `execution`
- `postprocess`
- `debug`
- `queue`
- `output`

Queue-related notes:

- `queue.timeout` can be used to cap how long a render waits for a browser slot before backpressure fails the request
- `POST /v1/jobs` accepts the same render spec contract and queues it for async execution
- `queue.connection` is a transport hint and must match the runtime backend when it is explicitly set
- `queue.queue` selects the logical queue name; on Redis the default queue uses the configured base key and named queues append `:<queue>`
- `execution.retries`, `execution.retryBackoff`, and `execution.retryBackoffMax` drive retry scheduling and final dead-letter archival
- `POST /v1/jobs/{id}/cancel` requests cancellation for a queued or running async job

## Response

`contractVersion = "canio.stagehand.render-result.v1"`

Important fields:

- `requestId`
- `jobId`
- `status`
- `warnings`
- `timings`
- `pdf.base64`
- `pdf.contentType`
- `pdf.fileName`
- `artifacts.id`
- `artifacts.files.renderSpec`
- `artifacts.files.metadata`
- `artifacts.files.pdf`
- `artifacts.files.pageScreenshot`
- `artifacts.files.domSnapshot`
- `artifacts.files.consoleLog`
- `artifacts.files.networkLog`

## Artifact Response

`contractVersion = "canio.stagehand.artifact.v1"`

Important fields:

- `id`
- `requestId`
- `status`
- `createdAt`
- `sourceType`
- `profile`
- `replayOf`
- `output.fileName`
- `output.bytes`
- `debug.screenshotFile`
- `debug.domSnapshot`
- `debug.consoleLogFile`
- `debug.networkLogFile`
- `files`

`GET /v1/artifacts/{id}` returns this manifest so operators can inspect a persisted artifact bundle without reading the runtime state directory manually.

## Artifact List Response

`contractVersion = "canio.stagehand.artifacts.v1"`

Important fields:

- `count`
- `items[]`

`GET /v1/artifacts?limit=20` returns this payload with artifact manifests sorted newest-first.

## Job Response

`contractVersion = "canio.stagehand.job.v1"`

Important fields:

- `id`
- `requestId`
- `status` = `queued | running | completed | failed | cancelled`
- `error`
- `attempts`
- `maxRetries`
- `submittedAt`
- `startedAt`
- `completedAt`
- `nextRetryAt`
- `deadLetter.id`
- `deadLetter.files.job`
- `deadLetter.files.renderSpec`
- `deadLetter.files.metadata`
- `result`

Cancelled jobs use the same job contract and return `status = cancelled` with the latest persisted job snapshot.

## Job List Response

`contractVersion = "canio.stagehand.jobs.v1"`

Important fields:

- `count`
- `items[]`

`GET /v1/jobs?limit=20` returns this payload with persisted jobs sorted newest-first.

## Job Event Response

`contractVersion = "canio.stagehand.job-event.v1"`

Important fields:

- `sequence`
- `id`
- `kind` = `job.queued | job.running | job.retried | job.completed | job.failed | job.cancelled`
- `emittedAt`
- `queue`
- `reason`
- `retryAt`
- `job`

`GET /v1/jobs/{id}/events` returns these payloads over `text/event-stream`. Clients can resume from a known event with the `since` query string or `Last-Event-ID` header.

## Dead-Letter APIs

`contractVersion = "canio.stagehand.dead-letters.v1"`

Important fields:

- `count`
- `items[].id`
- `items[].jobId`
- `items[].requestId`
- `items[].attempts`
- `items[].maxRetries`
- `items[].failedAt`
- `items[].error`
- `items[].directory`
- `items[].files.job`
- `items[].files.renderSpec`
- `items[].files.metadata`

`contractVersion = "canio.stagehand.dead-letter-cleanup.v1"`

Important fields:

- `count`
- `removed[]`

## Runtime Cleanup API

`contractVersion = "canio.stagehand.runtime-cleanup.v1"`

Important fields:

- `jobs.count`
- `jobs.removed[]`
- `artifacts.count`
- `artifacts.removed[]`
- `deadLetters.count`
- `deadLetters.removed[]`

Runtime deployment notes:

- Stagehand can keep the default in-memory job queue or switch to a Redis transport backend
- even with Redis enabled, job metadata and render specs still live in `state/jobs/<jobId>/`
- Redis-backed jobs discover named queues dynamically, so multiple Stagehand processes can drain the same queue set without extra config
- Redis-backed jobs are acknowledged only after the job reaches a terminal persisted state
- Redis deliveries use a lease/heartbeat model; if a worker disappears, another worker can reclaim the pending delivery after the lease timeout
- `execution.retries` is now enforced by Stagehand with backoff between attempts and dead-letter persistence when retries are exhausted
- dead-letters live in `state/deadletters/<jobId>/` and can be listed, requeued, or cleaned up without touching the original archived failure payload
- queued jobs can be cancelled before execution and running jobs can be interrupted through the worker context
- runtime cleanup removes old terminal jobs, artifacts, and dead-letters independently with their own TTL windows
- when auth is enabled, `/v1/*` requests must include the configured timestamp and signature headers
- when webhook push is enabled, job-event payloads are POSTed to the configured callback URL with their own delivery timestamp/signature headers

## Runtime Status

`contractVersion = "canio.stagehand.runtime-status.v1"`

Important fields:

- `version`
- `runtime.state`
- `runtime.startedAt`
- `queue.depth`
- `browserPool.size`
- `browserPool.warm`
- `browserPool.busy`
- `browserPool.starting`
- `browserPool.waiting`
- `browserPool.queueLimit`
- `workerPool.size`
- `workerPool.warm`
- `workerPool.busy`
- `workerPool.queueLimit`

This first version stays JSON-friendly while already carrying richer artifact, replay, queue, and observability payloads.
