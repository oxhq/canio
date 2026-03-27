@extends('canio::ops.layout', [
    'title' => $opsTitle,
    'subtitle' => 'Artifact manifest, metadata, and concrete files on disk.',
])

@section('hero_actions')
    <a class="button secondary" href="{{ route('canio.ops.index') }}">Back To Dashboard</a>
@endsection

@section('content')
    @php($statusClass = match ((string) data_get($artifact, 'status', '')) {
        'completed' => 'completed',
        'running', 'queued' => (string) data_get($artifact, 'status', ''),
        'failed', 'cancelled' => (string) data_get($artifact, 'status', ''),
        default => '',
    })

    <div class="stack">
        <div class="detail-grid">
            <section class="panel">
                <div class="section-head">
                    <div>
                        <h2>Artifact Snapshot</h2>
                        <p><span class="badge {{ $statusClass }}">{{ data_get($artifact, 'status', 'unknown') }}</span></p>
                    </div>
                </div>
                <div class="section-body">
                    <dl class="kv">
                        <dt>ID</dt>
                        <dd class="mono">{{ data_get($artifact, 'id', '—') }}</dd>
                        <dt>Request</dt>
                        <dd class="mono">{{ data_get($artifact, 'requestId', '—') }}</dd>
                        <dt>Created</dt>
                        <dd class="mono">{{ data_get($artifact, 'createdAt', '—') }}</dd>
                        <dt>Source Type</dt>
                        <dd>{{ data_get($artifact, 'sourceType', '—') }}</dd>
                        <dt>Profile</dt>
                        <dd>{{ data_get($artifact, 'profile', '—') ?: '—' }}</dd>
                        <dt>Replay Of</dt>
                        <dd class="mono">{{ data_get($artifact, 'replayOf', '—') ?: '—' }}</dd>
                        <dt>Output</dt>
                        <dd class="mono">{{ data_get($artifact, 'output.fileName', '—') }} ({{ data_get($artifact, 'output.bytes', 0) }} bytes)</dd>
                        <dt>Directory</dt>
                        <dd class="mono">{{ data_get($artifact, 'directory', '—') }}</dd>
                    </dl>
                </div>
            </section>

            <section class="panel">
                <div class="section-head">
                    <div>
                        <h2>Debug Metadata</h2>
                        <p>Presence and counts of collected debug files.</p>
                    </div>
                </div>
                <div class="section-body">
                    <dl class="kv">
                        <dt>Screenshot</dt>
                        <dd class="mono">{{ data_get($artifact, 'debug.screenshotFile', '—') ?: '—' }}</dd>
                        <dt>DOM Snapshot</dt>
                        <dd class="mono">{{ data_get($artifact, 'debug.domSnapshot', '—') ?: '—' }}</dd>
                        <dt>Console Log</dt>
                        <dd class="mono">{{ data_get($artifact, 'debug.consoleLogFile', '—') ?: '—' }}</dd>
                        <dt>Network Log</dt>
                        <dd class="mono">{{ data_get($artifact, 'debug.networkLogFile', '—') ?: '—' }}</dd>
                        <dt>Console Events</dt>
                        <dd>{{ data_get($artifact, 'debug.consoleEvents', 0) }}</dd>
                        <dt>Network Events</dt>
                        <dd>{{ data_get($artifact, 'debug.networkEvents', 0) }}</dd>
                    </dl>
                </div>
            </section>
        </div>

        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Files</h2>
                    <p>Concrete files that belong to this artifact bundle.</p>
                </div>
            </div>
            <div class="section-body table-wrap">
                @if (! is_array(data_get($artifact, 'files')) || data_get($artifact, 'files') === [])
                    <div class="empty">This artifact does not expose any file paths.</div>
                @else
                    <table>
                        <thead>
                            <tr>
                                <th>Kind</th>
                                <th>Path</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ((array) data_get($artifact, 'files', []) as $kind => $path)
                                <tr>
                                    <td>{{ $kind }}</td>
                                    <td class="mono">{{ $path }}</td>
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
                    <h2>Raw Payload</h2>
                    <p>Exact manifest returned by Stagehand.</p>
                </div>
            </div>
            <div class="section-body">
                <pre>{{ json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </section>
    </div>
@endsection
