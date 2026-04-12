<?php

declare(strict_types=1);

namespace Oxhq\Canio\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Oxhq\Canio\Contracts\CanioCloudSyncer;
use Oxhq\Canio\Events\CanioJobCancelled;
use Oxhq\Canio\Events\CanioJobCompleted;
use Oxhq\Canio\Events\CanioJobEventReceived;
use Oxhq\Canio\Events\CanioJobFailed;
use Oxhq\Canio\Events\CanioJobRetried;
use Oxhq\Canio\Support\CanioCloudSyncFailureRecorder;
use Oxhq\Canio\Support\WebhookVerifier;

final class StagehandWebhookController
{
    public function __invoke(Request $request, WebhookVerifier $verifier, CanioCloudSyncer $cloudSyncer, CanioCloudSyncFailureRecorder $syncFailureRecorder): JsonResponse
    {
        $secret = (string) config('canio.runtime.push.webhook.secret', '');
        $maxSkewSeconds = (int) config(
            'canio.runtime.push.webhook.max_skew_seconds',
            (int) config('canio.runtime.auth.max_skew_seconds', 300),
        );
        $body = (string) $request->getContent();

        if (! $verifier->verify(
            $body,
            $request->header('X-Canio-Delivery-Timestamp'),
            $request->header('X-Canio-Delivery-Signature'),
            $secret,
            $maxSkewSeconds,
        )) {
            abort(401, 'Invalid Stagehand webhook signature.');
        }

        $payload = $request->json()->all();

        if (! is_array($payload)) {
            abort(400, 'Invalid Stagehand webhook payload.');
        }

        $deliveryKey = trim((string) $request->header('X-Canio-Event-Id'));
        if ($deliveryKey === '') {
            $deliveryKey = trim((string) data_get($payload, 'id'));
        }

        if ($deliveryKey === '') {
            $deliveryKey = hash('sha256', implode('|', [
                (string) $request->header('X-Canio-Delivery-Timestamp'),
                (string) $request->header('X-Canio-Delivery-Signature'),
                $body,
            ]));
        }

        $cacheTtlSeconds = max(60, $maxSkewSeconds + 60);
        if (! Cache::add('canio:stagehand:webhook:'.$deliveryKey, now()->toIso8601String(), $cacheTtlSeconds)) {
            return response()->json(['status' => 'accepted', 'duplicate' => true], 202);
        }

        event(new CanioJobEventReceived($payload));

        match ((string) ($payload['kind'] ?? '')) {
            'job.completed' => event(new CanioJobCompleted($payload)),
            'job.failed' => event(new CanioJobFailed($payload)),
            'job.retried' => event(new CanioJobRetried($payload)),
            'job.cancelled' => event(new CanioJobCancelled($payload)),
            default => null,
        };

        try {
            $cloudSyncer->syncJobEvent($payload);
        } catch (\Throwable $exception) {
            report($exception);
            $syncFailureRecorder->record('job-event', [
                'eventId' => data_get($payload, 'id'),
                'kind' => data_get($payload, 'kind'),
                'sequence' => data_get($payload, 'sequence'),
                'requestId' => data_get($payload, 'job.requestId'),
                'jobId' => data_get($payload, 'job.id'),
                'status' => data_get($payload, 'job.status'),
                'emittedAt' => data_get($payload, 'emittedAt'),
            ], $exception);
        }

        return response()->json(['status' => 'accepted'], 202);
    }
}
