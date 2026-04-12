<?php

declare(strict_types=1);

namespace Oxhq\Canio\Bridge;

use Oxhq\Canio\Contracts\StagehandClient;
use Oxhq\Canio\Data\RenderJob;
use Oxhq\Canio\Data\RenderResult;
use Oxhq\Canio\Data\RenderSpec;
use Oxhq\Canio\Support\CanioCloudRequestor;
use Oxhq\Canio\Support\SseDecoder;
use RuntimeException;

final class CloudStagehandClient implements StagehandClient
{
    public function __construct(
        private readonly CanioCloudRequestor $requestor,
    ) {
        $this->decoder = new SseDecoder;
    }

    private SseDecoder $decoder;

    public function render(RenderSpec $spec): RenderResult
    {
        return RenderResult::fromArray(
            $this->requestor->json('post', '/api/runtime/v1/renders', $spec->toArray()),
        );
    }

    public function dispatch(RenderSpec $spec): RenderJob
    {
        return RenderJob::fromArray(
            $this->requestor->json('post', '/api/runtime/v1/jobs', $spec->toArray()),
        );
    }

    public function job(string $jobId): RenderJob
    {
        return RenderJob::fromArray(
            $this->requestor->json('get', '/api/runtime/v1/jobs/'.rawurlencode($jobId)),
        );
    }

    public function jobs(int $limit = 20): array
    {
        return $this->requestor->json('get', '/api/runtime/v1/jobs', query: [
            'limit' => max(1, $limit),
        ]);
    }

    public function streamJobEvents(string $jobId, ?int $since = null): iterable
    {
        $stream = $this->requestor->stream('/api/runtime/v1/jobs/'.rawurlencode($jobId).'/events', array_filter([
            'since' => $since,
        ], static fn (mixed $value): bool => $value !== null));

        return (function () use ($stream): iterable {
            try {
                yield from $this->decoder->decode($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        })();
    }

    public function cancelJob(string $jobId): RenderJob
    {
        return RenderJob::fromArray(
            $this->requestor->json('post', '/api/runtime/v1/jobs/'.rawurlencode($jobId).'/cancel'),
        );
    }

    public function artifact(string $artifactId): array
    {
        return $this->requestor->json('get', '/api/runtime/v1/artifacts/'.rawurlencode($artifactId));
    }

    public function artifacts(int $limit = 20): array
    {
        return $this->requestor->json('get', '/api/runtime/v1/artifacts', query: [
            'limit' => max(1, $limit),
        ]);
    }

    public function deadLetters(): array
    {
        return $this->requestor->json('get', '/api/runtime/v1/dead-letters');
    }

    public function requeueDeadLetter(string $deadLetterId): RenderJob
    {
        return RenderJob::fromArray(
            $this->requestor->json('post', '/api/runtime/v1/dead-letters/requeues', [
                'deadLetterId' => $deadLetterId,
            ]),
        );
    }

    public function cleanupDeadLetters(?int $olderThanDays = null): array
    {
        return $this->requestor->json('post', '/api/runtime/v1/dead-letters/cleanup', array_filter([
            'olderThanDays' => $olderThanDays,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function runtimeCleanup(?int $jobsOlderThanDays = null, ?int $artifactsOlderThanDays = null, ?int $deadLettersOlderThanDays = null): array
    {
        return $this->requestor->json('post', '/api/runtime/v1/cleanup', array_filter([
            'jobsOlderThanDays' => $jobsOlderThanDays,
            'artifactsOlderThanDays' => $artifactsOlderThanDays,
            'deadLettersOlderThanDays' => $deadLettersOlderThanDays,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function replay(string $artifactId): RenderResult
    {
        return RenderResult::fromArray(
            $this->requestor->json('post', '/api/runtime/v1/replays', [
                'artifactId' => $artifactId,
            ]),
        );
    }

    public function status(): array
    {
        return $this->requestor->json('get', '/api/runtime/v1/status');
    }

    public function restart(): array
    {
        throw new RuntimeException('Canio Cloud managed runtime restart is unavailable from the Laravel package.');
    }
}
