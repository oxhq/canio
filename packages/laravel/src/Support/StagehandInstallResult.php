<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

final class StagehandInstallResult
{
    public function __construct(
        public readonly string $tag,
        public readonly string $os,
        public readonly string $arch,
        public readonly string $path,
    ) {}
}
