<?php

declare(strict_types=1);

namespace Oxhq\Canio\Contracts;

use Oxhq\Canio\Data\RenderResult;
use Oxhq\Canio\Data\RenderSpec;

interface CanioCloudSyncer
{
    public function syncRender(RenderSpec $spec, RenderResult $result): void;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncJobEvent(array $payload): void;
}
