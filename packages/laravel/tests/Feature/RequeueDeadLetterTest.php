<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('requeues dead-letters through artisan', function () {
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');

    Http::fake([
        'http://127.0.0.1:9514/v1/dead-letters/requeues' => Http::response([
            'contractVersion' => 'canio.stagehand.job.v1',
            'id' => 'job-requeued-123',
            'requestId' => 'req-123',
            'status' => 'queued',
            'attempts' => 0,
            'maxRetries' => 1,
            'submittedAt' => now()->toIso8601String(),
        ], 202),
    ]);

    $this->artisan('canio:runtime:deadletters:requeue', [
        'id' => 'dlq-job-123',
        '--json' => true,
    ])
        ->expectsOutputToContain('job-requeued-123')
        ->assertSuccessful();

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode($request->body(), true);

        return $request->method() === 'POST'
            && $request->url() === 'http://127.0.0.1:9514/v1/dead-letters/requeues'
            && is_array($payload)
            && ($payload['deadLetterId'] ?? null) === 'dlq-job-123';
    });
});
