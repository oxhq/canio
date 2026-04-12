<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Oxhq\Canio\Events\CanioJobCompleted;
use Oxhq\Canio\Events\CanioCloudSyncFailed;
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

it('rejects signed webhook deliveries outside the allowed skew window', function () {
    config()->set('canio.runtime.push.webhook.secret', 'secret-123');
    config()->set('canio.runtime.push.webhook.max_skew_seconds', 300);

    $now = Carbon::create(2026, 4, 12, 12, 0, 0, 'UTC');
    Carbon::setTestNow($now);

    try {
        $payload = [
            'contractVersion' => 'canio.stagehand.job-event.v1',
            'sequence' => 5,
            'id' => 'evt-5-abcd',
            'kind' => 'job.completed',
            'emittedAt' => $now->toIso8601String(),
            'job' => [
                'contractVersion' => 'canio.stagehand.job.v1',
                'id' => 'job-123',
                'requestId' => 'req-123',
                'status' => 'completed',
                'attempts' => 1,
                'submittedAt' => $now->copy()->subMinute()->toIso8601String(),
                'completedAt' => $now->toIso8601String(),
            ],
        ];

        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) $now->copy()->subSeconds(301)->timestamp;

        $this->postJson('/canio/webhooks/stagehand/jobs', $payload, [
            'X-Canio-Delivery-Timestamp' => $timestamp,
            'X-Canio-Delivery-Signature' => canioWebhookSignature($timestamp, $body, 'secret-123'),
        ])->assertUnauthorized();
    } finally {
        Carbon::setTestNow();
    }
});

it('rejects webhook deliveries when the signing secret is not configured', function () {
    config()->set('canio.runtime.push.webhook.secret', null);

    $payload = [
        'contractVersion' => 'canio.stagehand.job-event.v1',
        'sequence' => 6,
        'id' => 'evt-6-abcd',
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

    $this->postJson('/canio/webhooks/stagehand/jobs', $payload)->assertUnauthorized();
});

it('treats duplicate webhook deliveries as idempotent replays', function () {
    config()->set('canio.runtime.push.webhook.secret', 'secret-123');

    Event::fake([
        CanioJobEventReceived::class,
        CanioJobCompleted::class,
    ]);

    $payload = [
        'contractVersion' => 'canio.stagehand.job-event.v1',
        'sequence' => 7,
        'id' => 'evt-7-abcd',
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
    $headers = [
        'X-Canio-Delivery-Timestamp' => $timestamp,
        'X-Canio-Delivery-Signature' => canioWebhookSignature($timestamp, $body, 'secret-123'),
        'X-Canio-Event-Id' => 'evt-7-abcd',
    ];

    $this->postJson('/canio/webhooks/stagehand/jobs', $payload, $headers)->assertAccepted();
    $this->postJson('/canio/webhooks/stagehand/jobs', $payload, $headers)
        ->assertAccepted()
        ->assertJson(['duplicate' => true]);

    Event::assertDispatchedTimes(CanioJobEventReceived::class, 1);
    Event::assertDispatchedTimes(CanioJobCompleted::class, 1);
});

it('forwards async stagehand job events to canio cloud in sync mode', function () {
    config()->set('canio.runtime.push.webhook.secret', 'secret-123');
    config()->set('canio.cloud.mode', 'sync');
    config()->set('canio.cloud.base_url', 'https://cloud.canio.test');
    config()->set('canio.cloud.token', 'sync-token-123');
    config()->set('canio.cloud.project', 'project-gamma');
    config()->set('canio.cloud.environment', 'preview');

    Event::fake([
        CanioJobEventReceived::class,
        CanioJobCompleted::class,
    ]);

    Http::fake([
        'https://cloud.canio.test/api/sync/v1/job-events' => Http::response([
            'ok' => true,
        ]),
    ]);

    $payload = [
        'contractVersion' => 'canio.stagehand.job-event.v1',
        'sequence' => 9,
        'id' => 'evt-9-abcd',
        'kind' => 'job.completed',
        'emittedAt' => now()->toIso8601String(),
        'job' => [
            'contractVersion' => 'canio.stagehand.job.v1',
            'id' => 'job-456',
            'requestId' => 'req-456',
            'status' => 'completed',
            'attempts' => 1,
            'submittedAt' => now()->subMinute()->toIso8601String(),
            'completedAt' => now()->toIso8601String(),
        ],
    ];
    $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();

    $this->postJson('/canio/webhooks/stagehand/jobs', $payload, [
        'X-Canio-Delivery-Timestamp' => $timestamp,
        'X-Canio-Delivery-Signature' => canioWebhookSignature($timestamp, $body, 'secret-123'),
    ])->assertAccepted();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://cloud.canio.test/api/sync/v1/job-events'
            && data_get($data, 'contractVersion') === 'canio.cloud.job-event.v1'
            && data_get($data, 'source') === 'stagehand-webhook'
            && data_get($data, 'project') === 'project-gamma'
            && data_get($data, 'environment') === 'preview'
            && data_get($data, 'event.id') === 'evt-9-abcd';
    });
});

it('records webhook sync failures without breaking delivery acknowledgement', function () {
    config()->set('canio.runtime.push.webhook.secret', 'secret-123');
    config()->set('canio.cloud.mode', 'sync');
    config()->set('canio.cloud.base_url', 'https://cloud.canio.test');
    config()->set('canio.cloud.token', 'sync-token-123');
    config()->set('canio.cloud.project', 'project-gamma');
    config()->set('canio.cloud.environment', 'preview');

    Event::fake([CanioCloudSyncFailed::class]);

    Http::fake([
        'https://cloud.canio.test/api/sync/v1/job-events' => Http::response([
            'error' => 'sync failed',
        ], 500),
    ]);

    $payload = [
        'contractVersion' => 'canio.stagehand.job-event.v1',
        'sequence' => 10,
        'id' => 'evt-10-abcd',
        'kind' => 'job.completed',
        'emittedAt' => now()->toIso8601String(),
        'job' => [
            'contractVersion' => 'canio.stagehand.job.v1',
            'id' => 'job-789',
            'requestId' => 'req-789',
            'status' => 'completed',
            'attempts' => 1,
            'submittedAt' => now()->subMinute()->toIso8601String(),
            'completedAt' => now()->toIso8601String(),
        ],
    ];
    $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();

    $this->postJson('/canio/webhooks/stagehand/jobs', $payload, [
        'X-Canio-Delivery-Timestamp' => $timestamp,
        'X-Canio-Delivery-Signature' => canioWebhookSignature($timestamp, $body, 'secret-123'),
    ])->assertAccepted();

    Event::assertDispatched(CanioCloudSyncFailed::class, function (CanioCloudSyncFailed $event): bool {
        return $event->operation === 'job-event'
            && $event->context['eventId'] === 'evt-10-abcd'
            && $event->context['jobId'] === 'job-789'
            && $event->context['requestId'] === 'req-789'
            && $event->exceptionClass === RuntimeException::class
            && str_contains($event->exceptionMessage, 'Canio Cloud request to /api/sync/v1/job-events failed with status 500');
    });
});
