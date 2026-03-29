@extends('canio::ops.layout', [
    'title' => $opsTitle,
    'subtitle' => 'Recent jobs, artifacts, and runtime pressure in one place.',
])

@section('content')
    @if ($status)
        <section class="grid metrics">
            <article class="panel metric">
                <span>Runtime State</span>
                <strong>{{ data_get($status, 'runtime.state', 'unknown') }}</strong>
            </article>
            <article class="panel metric">
                <span>Queue Depth</span>
                <strong>{{ data_get($status, 'queue.depth', 0) }}</strong>
            </article>
            <article class="panel metric">
                <span>Browser Pool</span>
                <strong>{{ data_get($status, 'browserPool.busy', 0) }}/{{ data_get($status, 'browserPool.size', 0) }}</strong>
            </article>
            <article class="panel metric">
                <span>Worker Pool</span>
                <strong>{{ data_get($status, 'workerPool.busy', 0) }}/{{ data_get($status, 'workerPool.size', 0) }}</strong>
            </article>
        </section>
    @endif

    <section class="panel">
        <div class="section-head">
            <div>
                <h2>Recent Jobs</h2>
                <p>Newest async jobs known by Stagehand.</p>
            </div>
        </div>
        <div class="section-body table-wrap">
            @if ($jobs === [])
                <div class="empty">No jobs are persisted yet.</div>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Job</th>
                            <th>Request</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Artifact</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($jobs as $job)
                            @php($statusClass = match ($job->status()) {
                                'completed' => 'completed',
                                'running', 'queued' => $job->status(),
                                'failed', 'cancelled' => $job->status(),
                                default => '',
                            })
                            <tr>
                                <td class="mono">
                                    <a href="{{ route('canio.ops.jobs.show', ['job' => $job->id()]) }}">{{ $job->id() }}</a>
                                </td>
                                <td class="mono">{{ $job->requestId() }}</td>
                                <td><span class="badge {{ $statusClass }}">{{ $job->status() }}</span></td>
                                <td class="mono">{{ data_get($job->toArray(), 'submittedAt', '—') }}</td>
                                <td class="mono">
                                    @if ($job->artifactId())
                                        <a href="{{ route('canio.ops.artifacts.show', ['artifact' => $job->artifactId()]) }}">{{ $job->artifactId() }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    <div class="grid split-panels">
        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Recent Artifacts</h2>
                    <p>Latest persisted debug bundles and replay sources.</p>
                </div>
            </div>
            <div class="section-body table-wrap">
                @if ($artifacts === [])
                    <div class="empty">No persisted artifacts yet.</div>
                @else
                    <table>
                        <thead>
                            <tr>
                                <th>Artifact</th>
                                <th>Request</th>
                                <th>Status</th>
                                <th>Output</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($artifacts as $artifact)
                                @php($artifactStatusClass = match ((string) data_get($artifact, 'status', '')) {
                                    'completed' => 'completed',
                                    'running', 'queued' => (string) data_get($artifact, 'status', ''),
                                    'failed', 'cancelled' => (string) data_get($artifact, 'status', ''),
                                    default => '',
                                })
                                <tr>
                                    <td class="mono">
                                        <a href="{{ route('canio.ops.artifacts.show', ['artifact' => data_get($artifact, 'id')]) }}">{{ data_get($artifact, 'id', '—') }}</a>
                                    </td>
                                    <td class="mono">{{ data_get($artifact, 'requestId', '—') }}</td>
                                    <td><span class="badge {{ $artifactStatusClass }}">{{ data_get($artifact, 'status', 'unknown') }}</span></td>
                                    <td class="mono">{{ data_get($artifact, 'output.fileName', '—') }}</td>
                                    <td class="mono">{{ data_get($artifact, 'createdAt', '—') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </section>

        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Local Ops Boundary</h2>
                    <p>This panel stays read-only. Use Artisan for local runtime actions, or Canio Cloud for the hosted control plane.</p>
                </div>
            </div>
            <div class="section-body">
                <div class="empty">
                    <strong class="mono">Heads up</strong><br>
                    The OSS package still owns rendering and local debugging. Collaboration, longer retention, hosted links, and managed runtime belong in Canio Cloud.
                </div>
            </div>
        </section>
    </div>
@endsection
