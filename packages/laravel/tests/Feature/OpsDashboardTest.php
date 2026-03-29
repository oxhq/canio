<?php

declare(strict_types=1);

use Oxhq\Canio\CanioManager;
use Oxhq\Canio\Contracts\StagehandClient;
use Oxhq\Canio\Data\RenderJob;
use Oxhq\Canio\Data\RenderResult;
use Oxhq\Canio\Data\RenderSpec;
use Illuminate\Support\Facades\Route;

it('renders the ops dashboard and detail pages', function () {
    $fake = new OpsDashboardFakeClient(
        status: [
            'contractVersion' => 'canio.stagehand.runtime-status.v1',
            'version' => 'dev',
            'runtime' => [
                'state' => 'ready',
                'startedAt' => now()->subMinute()->toIso8601String(),
                'renderCount' => 42,
            ],
            'queue' => ['depth' => 3],
            'browserPool' => ['size' => 2, 'busy' => 1, 'warm' => 1],
            'workerPool' => ['size' => 2, 'busy' => 1, 'warm' => 1],
        ],
        jobs: [[
            'contractVersion' => 'canio.stagehand.job.v1',
            'id' => 'job-ops-1',
            'requestId' => 'req-ops-1',
            'status' => 'completed',
            'attempts' => 1,
            'submittedAt' => now()->subSeconds(10)->toIso8601String(),
            'completedAt' => now()->toIso8601String(),
            'result' => [
                'contractVersion' => 'canio.stagehand.render-result.v1',
                'requestId' => 'req-ops-1',
                'jobId' => 'job-runtime-ops-1',
                'status' => 'completed',
                'pdf' => [
                    'base64' => base64_encode('pdf'),
                    'contentType' => 'application/pdf',
                    'fileName' => 'ops.pdf',
                    'bytes' => 3,
                ],
                'artifacts' => [
                    'id' => 'art-ops-1',
                    'directory' => '/tmp/canio/artifacts/art-ops-1',
                    'files' => [
                        'pdf' => '/tmp/canio/artifacts/art-ops-1/ops.pdf',
                    ],
                ],
            ],
        ]],
        artifacts: [[
            'contractVersion' => 'canio.stagehand.artifact.v1',
            'id' => 'art-ops-1',
            'requestId' => 'req-ops-1',
            'status' => 'completed',
            'createdAt' => now()->toIso8601String(),
            'sourceType' => 'html',
            'directory' => '/tmp/canio/artifacts/art-ops-1',
            'output' => [
                'fileName' => 'ops.pdf',
                'bytes' => 3,
            ],
            'files' => [
                'pdf' => '/tmp/canio/artifacts/art-ops-1/ops.pdf',
                'metadata' => '/tmp/canio/artifacts/art-ops-1/metadata.json',
            ],
        ]],
        deadLetters: [],
    );

    swapOpsClient($fake);

    $this->get(route('canio.ops.index'))
        ->assertOk()
        ->assertSee('Canio Ops')
        ->assertSee('job-ops-1')
        ->assertSee('art-ops-1')
        ->assertSee('Local Ops Boundary')
        ->assertDontSee('dlq-job-ops-1');

    $this->get(route('canio.ops.jobs.show', ['job' => 'job-ops-1']))
        ->assertOk()
        ->assertSee('job-ops-1')
        ->assertSee('req-ops-1')
        ->assertSee('art-ops-1');

    $this->get(route('canio.ops.artifacts.show', ['artifact' => 'art-ops-1']))
        ->assertOk()
        ->assertSee('art-ops-1')
        ->assertSee('ops.pdf')
        ->assertSee('/tmp/canio/artifacts/art-ops-1');
});

it('keeps the local ops panel read-only', function () {
    expect(Route::has('canio.ops.jobs.cancel'))->toBeFalse()
        ->and(Route::has('canio.ops.jobs.retry'))->toBeFalse()
        ->and(Route::has('canio.ops.dead-letters.requeue'))->toBeFalse()
        ->and(Route::has('canio.ops.runtime.restart'))->toBeFalse();
});

function swapOpsClient(StagehandClient $client): void
{
    app()->instance(StagehandClient::class, $client);
    app()->forgetInstance('canio');
    app()->forgetInstance(CanioManager::class);
}

final class OpsDashboardFakeClient implements StagehandClient
{
    /**
     * @param  array<string, mixed>  $status
     * @param  list<array<string, mixed>>  $jobs
     * @param  list<array<string, mixed>>  $artifacts
     * @param  list<array<string, mixed>>  $deadLetters
     */
    public function __construct(
        private readonly array $status,
        private array $jobs,
        private readonly array $artifacts,
        private readonly array $deadLetters,
    ) {}

    /** @var list<string> */
    public array $cancelledJobs = [];

    /** @var list<string> */
    public array $retriedJobs = [];

    /** @var list<string> */
    public array $requeuedDeadLetters = [];

    public int $restartCount = 0;

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
        foreach ($this->jobs as $job) {
            if (($job['id'] ?? null) === $jobId) {
                if (($job['status'] ?? null) === 'failed') {
                    $this->retriedJobs[] = $jobId;
                }

                return RenderJob::fromArray($job);
            }
        }

        throw new RuntimeException("Unknown job {$jobId}");
    }

    public function jobs(int $limit = 20): array
    {
        return [
            'contractVersion' => 'canio.stagehand.jobs.v1',
            'count' => min($limit, count($this->jobs)),
            'items' => array_slice($this->jobs, 0, $limit),
        ];
    }

    public function streamJobEvents(string $jobId, ?int $since = null): iterable
    {
        return [];
    }

    public function cancelJob(string $jobId): RenderJob
    {
        $this->cancelledJobs[] = $jobId;

        return RenderJob::fromArray([
            'contractVersion' => 'canio.stagehand.job.v1',
            'id' => $jobId,
            'requestId' => 'req-'.$jobId,
            'status' => 'cancelled',
            'attempts' => 1,
            'submittedAt' => now()->subMinute()->toIso8601String(),
            'completedAt' => now()->toIso8601String(),
        ]);
    }

    public function artifact(string $artifactId): array
    {
        foreach ($this->artifacts as $artifact) {
            if (($artifact['id'] ?? null) === $artifactId) {
                return $artifact;
            }
        }

        throw new RuntimeException("Unknown artifact {$artifactId}");
    }

    public function artifacts(int $limit = 20): array
    {
        return [
            'contractVersion' => 'canio.stagehand.artifacts.v1',
            'count' => min($limit, count($this->artifacts)),
            'items' => array_slice($this->artifacts, 0, $limit),
        ];
    }

    public function deadLetters(): array
    {
        return [
            'contractVersion' => 'canio.stagehand.dead-letters.v1',
            'count' => count($this->deadLetters),
            'items' => $this->deadLetters,
        ];
    }

    public function requeueDeadLetter(string $deadLetterId): RenderJob
    {
        $this->requeuedDeadLetters[] = $deadLetterId;

        $jobId = count($this->requeuedDeadLetters) === 1 ? 'job-retried-1' : 'job-requeued-1';
        $requestId = count($this->requeuedDeadLetters) === 1 ? 'req-retried-1' : 'req-requeued-1';

        return RenderJob::fromArray([
            'contractVersion' => 'canio.stagehand.job.v1',
            'id' => $jobId,
            'requestId' => $requestId,
            'status' => 'queued',
            'attempts' => 0,
            'submittedAt' => now()->toIso8601String(),
        ]);
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
        return $this->status;
    }

    public function restart(): array
    {
        $this->restartCount++;

        return [
            'contractVersion' => 'canio.stagehand.runtime-status.v1',
            'runtime' => [
                'state' => 'ready',
            ],
        ];
    }

    public function retryJob(string $jobId): RenderJob
    {
        throw new RuntimeException('not used');
    }
}
