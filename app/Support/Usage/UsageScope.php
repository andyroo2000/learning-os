<?php

namespace App\Support\Usage;

use Closure;
use Illuminate\Support\Facades\Context;

final class UsageScope
{
    public static function run(int $userId, string $resourceId, string $operation, Closure $work): mixed
    {
        return Context::scope($work, hidden: ['ai_usage' => [
            'user_id' => $userId, 'resource_id' => $resourceId, 'operation' => $operation,
        ]]);
    }
}
