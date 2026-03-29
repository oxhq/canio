<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

use Oxhq\Canio\Contracts\StagehandRuntimeBootstrapper;

final class NullStagehandRuntimeBootstrapper implements StagehandRuntimeBootstrapper
{
    public function ensureAvailable(): void
    {
        // Intentionally left blank for remote runtimes and test doubles.
    }
}
