<?php

declare(strict_types=1);

namespace Oxhq\Canio\Contracts;

interface StagehandRuntimeBootstrapper
{
    public function ensureAvailable(): void;
}
