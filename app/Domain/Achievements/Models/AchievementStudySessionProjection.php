<?php

namespace App\Domain\Achievements\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class AchievementStudySessionProjection extends Model
{
    use HasUlids;

    protected $primaryKey = 'study_activity_session_id';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'study_day' => 'immutable_date',
            'ended_at' => 'immutable_datetime',
            'conversation_ms' => 'integer',
            'listening_ms' => 'integer',
            'source_updated_at' => 'immutable_datetime',
        ];
    }
}
