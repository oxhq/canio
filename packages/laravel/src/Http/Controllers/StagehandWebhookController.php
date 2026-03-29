<?php

declare(strict_types=1);

namespace Oxhq\Canio\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Oxhq\Canio\Contracts\CanioCloudSyncer;
use Oxhq\Canio\Events\CanioJobCancelled;
use Oxhq\Canio\Events\CanioJobCompleted;
use Oxhq\Canio\Events\CanioJobEventReceived;
use Oxhq\Canio\Events\CanioJobFailed;
use Oxhq\Canio\Events\CanioJobRetried;
use Oxhq\Canio\Support\WebhookVerifier;

final class StagehandWebhookController
{
    public function __invoke(Request $request, WebhookVerifier $verifier, CanioCloudSyncer $cloudSyncer): JsonResponse
    {
        $secret = (string) config('canio.runtime.push.webhook.secret', '');
        $body = (string) $request->getContent();

        if (! $verifier->verify(
            $body,
            $request->header('X-Canio-Delivery-Timestamp'),
            $request->header('X-Canio-Delivery-Signature'),
            $secret,
        )) {
            abort(401, 'Invalid Stagehand webhook signature.');
        }

        $payload = $request->json()->all();

        if (! is_array($payload)) {
            abort(400, 'Invalid Stagehand webhook payload.');
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
        }

        return response()->json(['status' => 'accepted'], 202);
    }
}
