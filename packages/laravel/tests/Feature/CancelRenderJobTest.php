<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('cancels a render job through artisan', function () {
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');

    Http::fake([
        'http://127.0.0.1:9514/v1/jobs/job-123/cancel' => Http::response([
            'contractVersion' => 'canio.stagehand.job.v1',
            'id' => 'job-123',
            'requestId' => 'req-123',
            'status' => 'cancelled',
            'error' => 'job was cancelled',
            'attempts' => 1,
            'submittedAt' => now()->subMinute()->toIso8601String(),
            'completedAt' => now()->toIso8601String(),
        ], 202),
    ]);

    $this->artisan('canio:runtime:cancel', [
        'id' => 'job-123',
        '--json' => true,
    ])
        ->expectsOutputToContain('cancelled')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://127.0.0.1:9514/v1/jobs/job-123/cancel');
});
