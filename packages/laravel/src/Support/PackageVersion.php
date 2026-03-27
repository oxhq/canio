<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

final class PackageVersion
{
    public const TAG = 'v0.1.0';

    public static function label(): string
    {
        return 'canio '.self::TAG;
    }
}
