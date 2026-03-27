<?php

declare(strict_types=1);

use Oxhq\Canio\Data\RenderJob;

it('exposes retry and dead-letter metadata from stagehand jobs', function () {
    $job = RenderJob::fromArray([
        'contractVersion' => 'canio.stagehand.job.v1',
        'id' => 'job-123',
        'requestId' => 'req-123',
        'status' => 'failed',
        'error' => 'permanent failure',
        'attempts' => 3,
        'maxRetries' => 2,
        'submittedAt' => now()->subSeconds(5)->toIso8601String(),
        'completedAt' => now()->toIso8601String(),
        'deadLetter' => [
            'id' => 'dlq-job-123',
            'directory' => '/tmp/canio/deadletters/job-123',
            'files' => [
                'job' => '/tmp/canio/deadletters/job-123/job.json',
                'renderSpec' => '/tmp/canio/deadletters/job-123/render-spec.json',
                'metadata' => '/tmp/canio/deadletters/job-123/dead-letter.json',
            ],
        ],
    ]);

    expect($job->attempts())->toBe(3)
        ->and($job->maxRetries())->toBe(2)
        ->and($job->deadLetterId())->toBe('dlq-job-123')
        ->and($job->deadLetter()['files']['metadata'])->toBe('/tmp/canio/deadletters/job-123/dead-letter.json');
});

it('treats cancelled jobs as terminal and exposes artifact ids from results', function () {
    $job = RenderJob::fromArray([
        'contractVersion' => 'canio.stagehand.job.v1',
        'id' => 'job-456',
        'requestId' => 'req-456',
        'status' => 'cancelled',
        'attempts' => 1,
        'submittedAt' => now()->subSeconds(10)->toIso8601String(),
        'completedAt' => now()->toIso8601String(),
        'result' => [
            'contractVersion' => 'canio.stagehand.render-result.v1',
            'requestId' => 'req-456',
            'jobId' => 'job-runtime-456',
            'status' => 'completed',
            'pdf' => [
                'base64' => base64_encode('pdf'),
                'contentType' => 'application/pdf',
                'fileName' => 'job-456.pdf',
                'bytes' => 3,
            ],
            'artifacts' => [
                'id' => 'art-456',
                'directory' => '/tmp/canio/artifacts/art-456',
                'files' => [
                    'pdf' => '/tmp/canio/artifacts/art-456/job-456.pdf',
                ],
            ],
        ],
    ]);

    expect($job->cancelled())->toBeTrue()
        ->and($job->terminal())->toBeTrue()
        ->and($job->artifactId())->toBe('art-456');
});
