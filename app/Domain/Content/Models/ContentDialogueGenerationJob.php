<?php

namespace App\Domain\Content\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentDialogueGenerationJob extends Model
{
    protected $table = 'content_dialogue_generation_jobs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'progress' => 'integer',
            'input' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(ContentEpisode::class, 'episode_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
