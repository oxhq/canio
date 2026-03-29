<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use Oxhq\Canio\Contracts\CanioCloudSyncer;
use Oxhq\Canio\Data\RenderResult;
use Oxhq\Canio\Data\RenderSpec;

final class NullCanioCloudSyncer implements CanioCloudSyncer
{
    public function syncRender(RenderSpec $spec, RenderResult $result): void
    {
        //
    }

    public function syncJobEvent(array $payload): void
    {
        //
    }
}
