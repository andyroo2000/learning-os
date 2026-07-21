<?php

namespace App\Domain\Content\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContentEpisode extends Model
{
    protected $table = 'content_episodes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'auto_generate_audio' => 'boolean',
            'audio_generation_attempt' => 'integer',
            'dialogue_generation_attempt' => 'integer',
            'is_sample_content' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dialogue(): HasOne
    {
        return $this->hasOne(ContentDialogue::class, 'episode_id');
    }

    public function dialogueGenerationJobs(): HasMany
    {
        return $this->hasMany(ContentDialogueGenerationJob::class, 'episode_id');
    }

    public function audioGenerationJobs(): HasMany
    {
        return $this->hasMany(ContentAudioGenerationJob::class, 'episode_id');
    }

    public function audioScript(): HasOne
    {
        return $this->hasOne(ContentAudioScript::class, 'episode_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ContentImage::class, 'episode_id');
    }

    public function courseEpisodes(): HasMany
    {
        return $this->hasMany(ContentEpisodeCourse::class, 'episode_id');
    }
}
