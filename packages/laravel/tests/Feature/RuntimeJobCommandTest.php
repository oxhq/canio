<?php

declare(strict_types=1);

use Oxhq\Canio\CanioManager;
use Oxhq\Canio\Contracts\StagehandClient;
use Oxhq\Canio\Data\RenderJob;
use Oxhq\Canio\Data\RenderResult;
use Oxhq\Canio\Data\RenderSpec;

it('streams job events through artisan watch mode', function () {
    $job = [
        'contractVersion' => 'canio.stagehand.job.v1',
        'id' => 'job-123',
        'requestId' => 'req-123',
        'status' => 'queued',
        'attempts' => 0,
        'submittedAt' => now()->subSeconds(5)->toIso8601String(),
    ];

    $events = [
        [
            'id' => '1',
            'event' => 'job.running',
            'data' => [
                'kind' => 'job.running',
                'emittedAt' => now()->subSeconds(4)->toIso8601String(),
                'job' => [
                    'contractVersion' => 'canio.stagehand.job.v1',
                    'id' => 'job-123',
                    'requestId' => 'req-123',
                    'status' => 'running',
                    'attempts' => 1,
                    'submittedAt' => now()->subSeconds(5)->toIso8601String(),
                    'startedAt' => now()->subSeconds(4)->toIso8601String(),
                ],
            ],
        ],
        [
            'id' => '2',
            'event' => 'job.completed',
            'data' => [
                'kind' => 'job.completed',
                'emittedAt' => now()->toIso8601String(),
                'job' => [
                    'contractVersion' => 'canio.stagehand.job.v1',
                    'id' => 'job-123',
                    'requestId' => 'req-123',
                    'status' => 'completed',
                    'attempts' => 1,
                    'submittedAt' => now()->subSeconds(5)->toIso8601String(),
                    'startedAt' => now()->subSeconds(4)->toIso8601String(),
                    'completedAt' => now()->toIso8601String(),
                    'result' => [
                        'contractVersion' => 'canio.stagehand.render-result.v1',
                        'requestId' => 'req-123',
                        'jobId' => 'job-runtime-123',
                        'status' => 'completed',
                        'pdf' => [
                            'base64' => base64_encode('pdf'),
                            'contentType' => 'application/pdf',
                            'fileName' => 'job-123.pdf',
                            'bytes' => 3,
                        ],
                        'artifacts' => [
                            'id' => 'art-123',
                            'directory' => '/tmp/canio/artifacts/art-123',
                            'files' => [
                                'pdf' => '/tmp/canio/artifacts/art-123/job-123.pdf',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    app()->instance(StagehandClient::class, new RuntimeJobCommandFakeClient($job, $events));
    app()->forgetInstance('canio');
    app()->forgetInstance(CanioManager::class);

    $this->artisan('canio:runtime:job', [
        'id' => 'job-123',
        '--watch' => true,
    ])
        ->expectsOutputToContain('Streaming Stagehand events')
        ->expectsOutputToContain('job.running')
        ->expectsOutputToContain('job.completed')
        ->expectsOutputToContain('art-123')
        ->assertSuccessful();
});

final class RuntimeJobCommandFakeClient implements StagehandClient
{
    /**
     * @param  array<string, mixed>  $job
     * @param  list<array{id:string|null,event:string|null,data:array<string, mixed>}>  $events
     */
    public function __construct(
        private readonly array $job,
        private readonly array $events,
    ) {}

    public function render(RenderSpec $spec): RenderResult
    {
        throw new RuntimeException('not used');
    }

    public function dispatch(RenderSpec $spec): RenderJob
    {
        throw new RuntimeException('not used');
    }

    public function job(string $jobId): RenderJob
    {
        return RenderJob::fromArray($this->job);
    }

    public function jobs(int $limit = 20): array
    {
        return [
            'contractVersion' => 'canio.stagehand.jobs.v1',
            'count' => 1,
            'items' => [$this->job],
        ];
    }

    public function streamJobEvents(string $jobId, ?int $since = null): iterable
    {
        yield from $this->events;
    }

    public function cancelJob(string $jobId): RenderJob
    {
        throw new RuntimeException('not used');
    }

    public function artifact(string $artifactId): array
    {
        return [];
    }

    public function artifacts(int $limit = 20): array
    {
        return [
            'contractVersion' => 'canio.stagehand.artifacts.v1',
            'count' => 0,
            'items' => [],
        ];
    }

    public function deadLetters(): array
    {
        return [];
    }

    public function requeueDeadLetter(string $deadLetterId): RenderJob
    {
        throw new RuntimeException('not used');
    }

    public function cleanupDeadLetters(?int $olderThanDays = null): array
    {
        return [];
    }

    public function runtimeCleanup(?int $jobsOlderThanDays = null, ?int $artifactsOlderThanDays = null, ?int $deadLettersOlderThanDays = null): array
    {
        return [];
    }

    public function replay(string $artifactId): RenderResult
    {
        throw new RuntimeException('not used');
    }

    public function status(): array
    {
        return [];
    }

    public function restart(): array
    {
        return [];
    }
}
