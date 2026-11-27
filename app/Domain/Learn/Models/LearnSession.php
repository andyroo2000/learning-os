<?php

namespace App\Domain\Learn\Models;

use App\Models\Concerns\ResolvesCanonicalUlidRouteBindings;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class LearnSession extends Model
{
    use HasUlids, ResolvesCanonicalUlidRouteBindings;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['state' => 'array', 'version' => 'integer', 'user_id' => 'integer', 'pending_at' => 'immutable_datetime'];
    }
}
