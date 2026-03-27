<?php

declare(strict_types=1);

namespace Oxhq\Canio\Events;

use Oxhq\Canio\Data\RenderJob;

abstract class CanioJobEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {}

    public function job(): ?RenderJob
    {
        $job = $this->payload['job'] ?? null;

        return is_array($job) ? RenderJob::fromArray($job) : null;
    }
}
