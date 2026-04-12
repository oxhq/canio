<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use Illuminate\Support\Facades\Log;
use Oxhq\Canio\Events\CanioCloudSyncFailed;
use Throwable;

final class CanioCloudSyncFailureRecorder
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $operation, array $context, Throwable $exception): void
    {
        try {
            event(new CanioCloudSyncFailed(
                operation: $operation,
                context: $context,
                exceptionClass: $exception::class,
                exceptionMessage: $exception->getMessage(),
                recordedAt: now('UTC')->toIso8601String(),
            ));

            Log::warning('Canio Cloud sync failed.', [
                'operation' => $operation,
                'context' => $context,
                'exception' => [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            ]);
        } catch (Throwable $recordingException) {
            report($recordingException);
        }
    }
}
