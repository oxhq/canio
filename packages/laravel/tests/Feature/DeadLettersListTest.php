<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('lists runtime dead-letters through artisan', function () {
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');

    Http::fake([
        'http://127.0.0.1:9514/v1/dead-letters' => Http::response([
            'contractVersion' => 'canio.stagehand.dead-letters.v1',
            'count' => 1,
            'items' => [
                [
                    'id' => 'dlq-job-123',
                    'jobId' => 'job-123',
                    'requestId' => 'req-123',
                    'attempts' => 2,
                    'maxRetries' => 1,
                    'failedAt' => now()->toIso8601String(),
                    'error' => 'permanent failure',
                    'directory' => '/tmp/canio/deadletters/job-123',
                    'files' => [
                        'job' => '/tmp/canio/deadletters/job-123/job.json',
                        'renderSpec' => '/tmp/canio/deadletters/job-123/render-spec.json',
                        'metadata' => '/tmp/canio/deadletters/job-123/dead-letter.json',
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('canio:runtime:deadletters', ['--json' => true])
        ->expectsOutputToContain('canio.stagehand.dead-letters.v1')
        ->assertSuccessful();

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'http://127.0.0.1:9514/v1/dead-letters';
    });
});
