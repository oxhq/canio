<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Oxhq\Canio\Facades\Canio;

function canioSentHeader(Request $request, string $name): string
{
    $value = $request->header($name);

    if (is_array($value)) {
        return (string) ($value[0] ?? '');
    }

    return (string) $value;
}

function canioExpectedSignature(string $method, string $path, string $body, string $timestamp, string $secret, string $algorithm = 'canio-v1'): string
{
    $canonical = implode("\n", [
        strtoupper(trim($method)),
        trim($path),
        $timestamp,
        hash('sha256', $body === '' ? '' : $body),
    ]);

    return $algorithm.'='.hash_hmac('sha256', $canonical, $secret);
}

it('signs stagehand requests with an hmac header pair', function () {
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');
    config()->set('canio.runtime.jobs.auth.shared_secret', 'secret-123');

    Carbon::setTestNow(Carbon::parse('2026-03-27 12:00:00', 'UTC'));

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

    try {
        $job = Canio::html('<h1>Queued invoice</h1>')
            ->queue('redis', 'pdfs')
            ->dispatch();

        expect($job->id())->toBe('job-queued-123');

        $recorded = Http::recorded();
        expect($recorded)->toHaveCount(1);

        [$request] = $recorded->first();
        expect($request)->toBeInstanceOf(Request::class)
            ->and($request->url())->toBe('http://127.0.0.1:9514/v1/jobs');

        $timestamp = canioSentHeader($request, 'X-Canio-Timestamp');
        $signature = canioSentHeader($request, 'X-Canio-Signature');

        expect($timestamp)->not->toBeEmpty()
            ->and($signature)->toBe(canioExpectedSignature('POST', '/v1/jobs', $request->body(), $timestamp, 'secret-123'));
    } finally {
        Carbon::setTestNow();
    }
});

it('signs runtime fetches with the same hmac header pair', function () {
    config()->set('canio.runtime.base_url', 'http://127.0.0.1:9514');
    config()->set('canio.runtime.jobs.auth.shared_secret', 'secret-123');

    Carbon::setTestNow(Carbon::parse('2026-03-27 12:00:00', 'UTC'));

    Http::fake([
        'http://127.0.0.1:9514/v1/jobs/job-123' => Http::response([
            'contractVersion' => 'canio.stagehand.job.v1',
            'id' => 'job-123',
            'requestId' => 'req-123',
            'status' => 'completed',
            'attempts' => 1,
            'submittedAt' => now()->toIso8601String(),
        ]),
    ]);

    try {
        Canio::job('job-123');

        $recorded = Http::recorded();
        expect($recorded)->toHaveCount(1);

        [$request] = $recorded->first();
        expect($request)->toBeInstanceOf(Request::class)
            ->and($request->url())->toBe('http://127.0.0.1:9514/v1/jobs/job-123');

        $timestamp = canioSentHeader($request, 'X-Canio-Timestamp');
        $signature = canioSentHeader($request, 'X-Canio-Signature');

        expect($timestamp)->not->toBeEmpty()
            ->and($signature)->toBe(canioExpectedSignature('GET', '/v1/jobs/job-123', '', $timestamp, 'secret-123'));
    } finally {
        Carbon::setTestNow();
    }
});
