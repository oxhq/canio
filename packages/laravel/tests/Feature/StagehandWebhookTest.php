<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Oxhq\Canio\Events\CanioJobCompleted;
use Oxhq\Canio\Events\CanioJobEventReceived;

function canioWebhookSignature(string $timestamp, string $body, string $secret): string
{
    return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
}

it('dispatches laravel events from stagehand webhooks', function () {
    config()->set('canio.runtime.push.webhook.secret', 'secret-123');

    Event::fake([
        CanioJobEventReceived::class,
        CanioJobCompleted::class,
    ]);

    $payload = [
        'contractVersion' => 'canio.stagehand.job-event.v1',
        'sequence' => 4,
        'id' => 'evt-4-abcd',
        'kind' => 'job.completed',
        'emittedAt' => now()->toIso8601String(),
        'job' => [
            'contractVersion' => 'canio.stagehand.job.v1',
            'id' => 'job-123',
            'requestId' => 'req-123',
            'status' => 'completed',
            'attempts' => 1,
            'submittedAt' => now()->subMinute()->toIso8601String(),
            'completedAt' => now()->toIso8601String(),
        ],
    ];
    $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();

    $response = $this->postJson('/canio/webhooks/stagehand/jobs', $payload, [
        'X-Canio-Delivery-Timestamp' => $timestamp,
        'X-Canio-Delivery-Signature' => canioWebhookSignature($timestamp, $body, 'secret-123'),
    ]);

    $response->assertAccepted();

    Event::assertDispatched(CanioJobEventReceived::class);
    Event::assertDispatched(CanioJobCompleted::class);
});
