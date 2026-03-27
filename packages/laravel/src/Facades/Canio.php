<?php

declare(strict_types=1);

namespace Oxhq\Canio\Facades;

use Illuminate\Support\Facades\Facade;

final class Canio extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'canio';
    }
}
