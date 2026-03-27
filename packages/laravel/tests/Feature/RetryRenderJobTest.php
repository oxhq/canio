<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('retries a failed render job through artisan', function () {
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');

    Http::fake([
        'http://127.0.0.1:9514/v1/jobs/job-123' => Http::response([
            'contractVersion' => 'canio.stagehand.job.v1',
            'id' => 'job-123',
            'requestId' => 'req-123',
            'status' => 'failed',
            'error' => 'permanent failure',
            'attempts' => 2,
            'submittedAt' => now()->subMinutes(2)->toIso8601String(),
            'completedAt' => now()->subMinute()->toIso8601String(),
            'deadLetter' => [
                'id' => 'dlq-job-123',
                'directory' => '/tmp/canio/deadletters/job-123',
                'files' => [
                    'job' => '/tmp/canio/deadletters/job-123/job.json',
                ],
            ],
        ]),
        'http://127.0.0.1:9514/v1/dead-letters/requeues' => Http::response([
            'contractVersion' => 'canio.stagehand.job.v1',
            'id' => 'job-retried-123',
            'requestId' => 'req-123',
            'status' => 'queued',
            'attempts' => 0,
            'submittedAt' => now()->toIso8601String(),
        ], 202),
    ]);

    $this->artisan('canio:runtime:retry', [
        'id' => 'job-123',
    ])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://127.0.0.1:9514/v1/jobs/job-123');

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode($request->body(), true);

        return $request->method() === 'POST'
            && $request->url() === 'http://127.0.0.1:9514/v1/dead-letters/requeues'
            && is_array($payload)
            && ($payload['deadLetterId'] ?? null) === 'dlq-job-123';
    });
});
