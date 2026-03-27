<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('cleans up runtime state through artisan', function () {
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');

    Http::fake([
        'http://127.0.0.1:9514/v1/runtime/cleanup' => Http::response([
            'contractVersion' => 'canio.stagehand.runtime-cleanup.v1',
            'jobs' => ['count' => 2, 'removed' => [['id' => 'job-1', 'directory' => '/tmp/jobs/job-1']]],
            'artifacts' => ['count' => 1, 'removed' => [['id' => 'art-1', 'directory' => '/tmp/artifacts/art-1']]],
            'deadLetters' => ['count' => 1, 'removed' => [['id' => 'dlq-job-1', 'directory' => '/tmp/deadletters/job-1']]],
        ]),
    ]);

    $this->artisan('canio:runtime:cleanup', [
        '--jobs-older-than-days' => 7,
        '--artifacts-older-than-days' => 14,
        '--dead-letters-older-than-days' => 30,
        '--json' => true,
    ])
        ->expectsOutputToContain('canio.stagehand.runtime-cleanup.v1')
        ->assertSuccessful();

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode($request->body(), true);

        return $request->method() === 'POST'
            && $request->url() === 'http://127.0.0.1:9514/v1/runtime/cleanup'
            && is_array($payload)
            && ($payload['jobsOlderThanDays'] ?? null) === 7
            && ($payload['artifactsOlderThanDays'] ?? null) === 14
            && ($payload['deadLettersOlderThanDays'] ?? null) === 30;
    });
});
