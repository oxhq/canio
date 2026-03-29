<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use Oxhq\Canio\Contracts\CanioCloudSyncer;
use Oxhq\Canio\Data\RenderResult;
use Oxhq\Canio\Data\RenderSpec;

final class HttpCanioCloudSyncer implements CanioCloudSyncer
{
    public function __construct(
        private readonly CanioCloudRequestor $requestor,
        private readonly array $config = [],
    ) {}

    public function syncRender(RenderSpec $spec, RenderResult $result): void
    {
        $event = $this->syntheticCompletedEvent($result);

        $this->requestor->json('post', '/api/sync/v1/job-events', [
            'contractVersion' => 'canio.cloud.job-event.v1',
            'source' => 'oss-render',
            'project' => trim((string) ($this->config['project'] ?? '')),
            'environment' => trim((string) ($this->config['environment'] ?? '')),
            'spec' => $spec->toArray(),
            'event' => $event,
        ]);

        $this->syncArtifactFromJob($event['job'] ?? null, $event['emittedAt'] ?? null);
    }

    public function syncJobEvent(array $payload): void
    {
        $this->requestor->json('post', '/api/sync/v1/job-events', [
            'contractVersion' => 'canio.cloud.job-event.v1',
            'source' => 'stagehand-webhook',
            'project' => trim((string) ($this->config['project'] ?? '')),
            'environment' => trim((string) ($this->config['environment'] ?? '')),
            'event' => $payload,
        ]);

        $this->syncArtifactFromJob($payload['job'] ?? null, $payload['emittedAt'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function syntheticCompletedEvent(RenderResult $result): array
    {
        $timestamp = now()->toIso8601String();
        $jobId = $result->jobId();
        $requestId = $result->requestId();

        if ($jobId === '') {
            $jobId = 'sync-'.substr(sha1($requestId.':'.$timestamp), 0, 16);
        }

        $kind = match ($result->status()) {
            'failed' => 'job.failed',
            'cancelled' => 'job.cancelled',
            default => 'job.completed',
        };

        return [
            'contractVersion' => 'canio.stagehand.job-event.v1',
            'sequence' => 0,
            'id' => 'sync-evt-'.substr(sha1($jobId.':'.$timestamp), 0, 16),
            'kind' => $kind,
            'emittedAt' => $timestamp,
            'job' => [
                'contractVersion' => 'canio.stagehand.job.v1',
                'id' => $jobId,
                'requestId' => $requestId,
                'status' => $result->status(),
                'attempts' => 1,
                'submittedAt' => $timestamp,
                'completedAt' => $timestamp,
                'result' => $result->toArray(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $job
     */
    private function syncArtifactFromJob(?array $job, ?string $emittedAt): void
    {
        if (! is_array($job)) {
            return;
        }

        $result = $job['result'] ?? null;
        if (! is_array($result)) {
            return;
        }

        $artifact = $result['artifacts'] ?? null;
        if (! is_array($artifact) || ($artifact['id'] ?? null) === null) {
            return;
        }

        $files = [];
        if ((bool) data_get($this->config, 'sync.include_artifacts', true)) {
            foreach ((array) ($artifact['files'] ?? []) as $key => $path) {
                if (is_string($path) && $path !== '' && is_file($path)) {
                    $files[(string) $key] = $path;
                }
            }
        }

        $this->requestor->multipart('/api/sync/v1/artifacts', [
            'contractVersion' => 'canio.cloud.artifact.v1',
            'source' => 'oss-runtime',
            'project' => trim((string) ($this->config['project'] ?? '')),
            'environment' => trim((string) ($this->config['environment'] ?? '')),
            'receivedAt' => $emittedAt ?: now()->toIso8601String(),
            'job' => $job,
            'artifact' => $artifact,
        ], $files);
    }
}
