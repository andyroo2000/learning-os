<?php

namespace App\Domain\Achievements\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class AchievementCardProjection extends Model
{
    use HasUlids;

    protected $primaryKey = 'card_id';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'maximum_stability' => 'float',
            'last_reviewed_at' => 'immutable_datetime',
            'source_updated_at' => 'immutable_datetime',
        ];
    }
}
