<?php

declare(strict_types=1);

namespace Oxhq\Canio\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

interface OpsAccessAuthorizer
{
    public function authorize(Request $request, ?Authenticatable $user): bool;
}
