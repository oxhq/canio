@extends('canio::ops.layout', [
    'title' => $opsTitle,
    'subtitle' => 'Job detail with the latest durable snapshot and artifact linkage.',
])

@section('meta')
    @if (! $job->terminal())
        <meta http-equiv="refresh" content="{{ $refreshSeconds }}">
    @endif
@endsection

@section('hero_actions')
    <a class="button secondary" href="{{ route('canio.ops.index') }}">Back To Dashboard</a>
@endsection

@section('content')
    @php($statusClass = match ($job->status()) {
        'completed' => 'completed',
        'running', 'queued' => $job->status(),
        'failed', 'cancelled' => $job->status(),
        default => '',
    })

    <div class="stack">
        @if (! $job->terminal())
            <div class="notice">This page auto-refreshes every {{ $refreshSeconds }} seconds while the job is active.</div>
        @endif

        <div class="detail-grid">
            <section class="panel">
                <div class="section-head">
                    <div>
                        <h2>Job Snapshot</h2>
                        <p><span class="badge {{ $statusClass }}">{{ $job->status() }}</span></p>
                    </div>
                </div>
                <div class="section-body">
                    <dl class="kv">
                        <dt>ID</dt>
                        <dd class="mono">{{ $job->id() }}</dd>
                        <dt>Request</dt>
                        <dd class="mono">{{ $job->requestId() }}</dd>
                        <dt>Attempts</dt>
                        <dd>{{ $job->attempts() }}</dd>
                        <dt>Max Retries</dt>
                        <dd>{{ $job->maxRetries() }}</dd>
                        <dt>Submitted</dt>
                        <dd class="mono">{{ data_get($job->toArray(), 'submittedAt', '—') }}</dd>
                        <dt>Started</dt>
                        <dd class="mono">{{ data_get($job->toArray(), 'startedAt', '—') ?: '—' }}</dd>
                        <dt>Completed</dt>
                        <dd class="mono">{{ data_get($job->toArray(), 'completedAt', '—') ?: '—' }}</dd>
                        <dt>Next Retry</dt>
                        <dd class="mono">{{ $job->nextRetryAt() ?? '—' }}</dd>
                        <dt>Dead-Letter</dt>
                        <dd class="mono">{{ $job->deadLetterId() ?? '—' }}</dd>
                        <dt>Error</dt>
                        <dd>{{ $job->error() ?? '—' }}</dd>
                    </dl>
                </div>
            </section>

            <section class="panel">
                <div class="section-head">
                    <div>
                        <h2>Operator Note</h2>
                        <p>The local OSS panel is read-only by design.</p>
                    </div>
                </div>
                <div class="section-body stack">
                    @if ($job->artifactId())
                        <div class="actions">
                            <a class="button secondary" href="{{ route('canio.ops.artifacts.show', ['artifact' => $job->artifactId()]) }}">Open Artifact</a>
                        </div>
                    @endif

                    <div class="empty">
                        <strong class="mono">Tip</strong><br>
                        Use Artisan for local runtime actions and lifecycle inspection. Hosted retry, retention, and collaboration move to Canio Cloud.
                    </div>
                </div>
            </section>
        </div>

        @if ($artifact)
            <section class="panel">
                <div class="section-head">
                    <div>
                        <h2>Linked Artifact</h2>
                        <p>The persisted bundle produced by this job.</p>
                    </div>
                </div>
                <div class="section-body">
                    <dl class="kv">
                        <dt>Artifact</dt>
                        <dd class="mono">
                            <a href="{{ route('canio.ops.artifacts.show', ['artifact' => data_get($artifact, 'id')]) }}">{{ data_get($artifact, 'id') }}</a>
                        </dd>
                        <dt>Directory</dt>
                        <dd class="mono">{{ data_get($artifact, 'directory', '—') }}</dd>
                        <dt>Output</dt>
                        <dd class="mono">{{ data_get($artifact, 'output.fileName', '—') }} ({{ data_get($artifact, 'output.bytes', 0) }} bytes)</dd>
                    </dl>
                </div>
            </section>
        @endif

        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Raw Payload</h2>
                    <p>Exact JSON persisted by the Laravel bridge.</p>
                </div>
            </div>
            <div class="section-body">
                <pre>{{ json_encode($job->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </section>
    </div>
@endsection
