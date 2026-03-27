<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Oxhq\Canio\CanioManager;

it('fetches a queued render job from stagehand', function () {
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');

    $pdfBytes = "%PDF-1.4\nqueued-result\n";

    Http::fake([
        'http://127.0.0.1:9514/v1/jobs/job-123' => Http::response([
            'contractVersion' => 'canio.stagehand.job.v1',
            'id' => 'job-123',
            'requestId' => 'req-123',
            'status' => 'completed',
            'attempts' => 1,
            'submittedAt' => now()->subSecond()->toIso8601String(),
            'startedAt' => now()->subSecond()->toIso8601String(),
            'completedAt' => now()->toIso8601String(),
            'result' => [
                'contractVersion' => 'canio.stagehand.render-result.v1',
                'requestId' => 'req-123',
                'jobId' => 'render-job-123',
                'status' => 'completed',
                'warnings' => [],
                'timings' => ['totalMs' => 22],
                'pdf' => [
                    'base64' => base64_encode($pdfBytes),
                    'contentType' => 'application/pdf',
                    'fileName' => 'queued.pdf',
                    'bytes' => strlen($pdfBytes),
                ],
                'artifacts' => [
                    'id' => 'art-job-123',
                    'directory' => '/tmp/canio/jobs/job-123',
                    'files' => [
                        'pdf' => '/tmp/canio/jobs/job-123/queued.pdf',
                    ],
                ],
            ],
        ]),
    ]);

    /** @var CanioManager $canio */
    $canio = app(CanioManager::class);
    $job = $canio->job('job-123');

    expect($job->completed())->toBeTrue()
        ->and($job->successful())->toBeTrue()
        ->and($job->result()?->artifactId())->toBe('art-job-123')
        ->and($job->result()?->fileName())->toBe('queued.pdf');
});
