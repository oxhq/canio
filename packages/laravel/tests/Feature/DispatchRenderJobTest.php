<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Oxhq\Canio\Facades\Canio;

it('dispatches a render job through stagehand', function () {
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');

    Http::fake([
        'http://127.0.0.1:9514/v1/jobs' => Http::response([
            'contractVersion' => 'canio.stagehand.job.v1',
            'id' => 'job-queued-123',
            'requestId' => 'req-queued-123',
            'status' => 'queued',
            'attempts' => 0,
            'submittedAt' => now()->toIso8601String(),
        ], 202),
    ]);

    $job = Canio::html('<h1>Queued invoice</h1>')
        ->queue('redis', 'pdfs')
        ->dispatch();

    expect($job->id())->toBe('job-queued-123')
        ->and($job->queued())->toBeTrue()
        ->and($job->terminal())->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode($request->body(), true);

        return $request->url() === 'http://127.0.0.1:9514/v1/jobs'
            && is_array($payload)
            && data_get($payload, 'queue.enabled') === true
            && data_get($payload, 'queue.connection') === 'redis'
            && data_get($payload, 'queue.queue') === 'pdfs';
    });
});
