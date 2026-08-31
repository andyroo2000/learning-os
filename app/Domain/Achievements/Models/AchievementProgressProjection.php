<?php

namespace App\Domain\Achievements\Models;

use Illuminate\Database\Eloquent\Model;

final class AchievementProgressProjection extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'projection_version' => 'integer',
            'metric_values' => 'array',
            'threshold_reached_at' => 'array',
            'current_correct_run' => 'integer',
            'conversation_ms' => 'integer',
            'listening_ms' => 'integer',
            'last_review_created_at' => 'immutable_datetime',
            'latest_reviewed_at' => 'immutable_datetime',
            'latest_study_ended_at' => 'immutable_datetime',
            'needs_rebuild' => 'boolean',
        ];
    }
}
