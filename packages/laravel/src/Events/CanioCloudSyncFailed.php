<?php

declare(strict_types=1);

namespace Oxhq\Canio\Events;

final class CanioCloudSyncFailed
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $operation,
        public readonly array $context,
        public readonly string $exceptionClass,
        public readonly string $exceptionMessage,
        public readonly string $recordedAt,
    ) {}
}
